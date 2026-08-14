<?php

namespace ShopRex\Models;

use ShopRex\Core\Session;
use ShopRex\Services\DiscountCalculator;
use ShopRex\Services\I18n;
use ShopRex\Services\TaxCalculator;
use ShopRex\Services\TranslationOverlay;

/**
 * Session-based shopping cart - direct, method-for-method port of
 * includes/Cart.php, converted from a static-only class to a request-
 * scoped instance (held as a Container singleton, so every controller in
 * one request shares the same Cart object) backed by the Session wrapper
 * instead of touching $_SESSION['cart'] directly. This closes the two
 * encapsulation breaks the original cart_action.php had (reading/writing
 * $_SESSION['cart'] directly at lines 53/97 instead of going through the
 * Cart class) - Controllers\Storefront\CartController never touches
 * $_SESSION itself, only this class's API.
 *
 * includes/Cart.php's original static `Cart` class is deliberately left
 * completely untouched and still active for the legacy app (still-live
 * cart.php/cart_action.php, and includes/header.php's `Cart::count()`
 * call, which is not rewritten - see Core\Renderer's docblock). Both
 * implementations read/write the exact same $_SESSION['cart'] structure
 * independently, so a cart built via either code path stays consistent
 * during the migration - no bridging/delegation needed between them.
 *
 * SECURITY (see docs/SECURITY_AUDIT.md finding #1): every per-option-value
 * lookup in getItems() below is scoped by `po.product_id = ?` - do not
 * remove that scoping, it is what stops an option-value id borrowed from a
 * *different* product's option group from having its price_modifier/
 * stock_quantity applied here.
 */
final class Cart
{
    private const SESSION_KEY = 'cart';

    public function __construct(
        private readonly Session $session,
        private readonly \PDO $pdo,
        private readonly TranslationOverlay $translations,
        private readonly DiscountCalculator $discounts,
        private readonly TaxCalculator $tax,
    ) {
    }

    private function lines(): array
    {
        return $this->session->get(self::SESSION_KEY, []);
    }

    private function setLines(array $lines): void
    {
        $this->session->set(self::SESSION_KEY, $lines);
    }

    public function key(int $productId, array $optionValueIds): string
    {
        sort($optionValueIds);
        return $productId . ':' . (empty($optionValueIds) ? '0' : implode('-', $optionValueIds));
    }

    /**
     * The product_variants row for this exact set of chosen option values
     * (order-independent), or null if the product has no variant matrix
     * for it. A match requires every one of the product's option groups
     * to be represented - a partial/empty selection never matches.
     */
    public function findVariant(int $productId, array $optionValueIds): ?array
    {
        $optionValueIds = array_values(array_unique(array_map('intval', $optionValueIds)));
        if (empty($optionValueIds)) {
            return null;
        }

        $groupCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_options WHERE product_id = ?');
        $groupCountStmt->execute([$productId]);
        $groupCount = (int)$groupCountStmt->fetchColumn();
        if ($groupCount === 0 || $groupCount !== count($optionValueIds)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($optionValueIds), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT pv.* FROM product_variants pv
             JOIN product_variant_values pvv ON pvv.product_variant_id = pv.id
             WHERE pv.product_id = ? AND pvv.product_option_value_id IN ($placeholders)
             GROUP BY pv.id
             HAVING COUNT(*) = ?"
        );
        $stmt->execute([$productId, ...$optionValueIds, $groupCount]);
        $matches = $stmt->fetchAll();
        foreach ($matches as $variant) {
            $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_variant_values WHERE product_variant_id = ?');
            $countStmt->execute([$variant['id']]);
            if ((int)$countStmt->fetchColumn() === $groupCount) {
                return $variant;
            }
        }
        return null;
    }

    public function add(int $productId, array $optionValueIds, int $quantity): void
    {
        $lines = $this->lines();
        $optionValueIds = array_values(array_filter(array_map('intval', $optionValueIds)));
        $key = $this->key($productId, $optionValueIds);

        if (isset($lines[$key])) {
            $lines[$key]['quantity'] += $quantity;
        } else {
            $lines[$key] = [
                'product_id'       => $productId,
                'option_value_ids' => $optionValueIds,
                'quantity'         => $quantity,
            ];
        }
        $this->setLines($lines);
    }

    public function updateQuantity(string $key, int $quantity): void
    {
        $lines = $this->lines();
        if ($quantity <= 0) {
            unset($lines[$key]);
            $this->setLines($lines);
            return;
        }
        if (isset($lines[$key])) {
            $lines[$key]['quantity'] = $quantity;
            $this->setLines($lines);
        }
    }

    public function remove(string $key): void
    {
        $lines = $this->lines();
        unset($lines[$key]);
        $this->setLines($lines);
    }

    public function clear(): void
    {
        $this->setLines([]);
    }

    public function isEmpty(): bool
    {
        return empty($this->lines());
    }

    /** Existing quantity already in the cart for this exact key - used by CartController's add-quantity-cap checks. */
    public function quantityFor(string $key): int
    {
        return (int)($this->lines()[$key]['quantity'] ?? 0);
    }

    public function has(string $key): bool
    {
        return isset($this->lines()[$key]);
    }

    public function count(): int
    {
        $count = 0;
        foreach ($this->lines() as $item) {
            $count += (int)$item['quantity'];
        }
        return $count;
    }

    /**
     * Hydrate cart lines with current product/option data from the DB.
     * Returns line items plus a NET subtotal, a total tax amount, and a
     * tax breakdown grouped by rate. All prices here are NET.
     */
    public function getItems(): array
    {
        $items = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $taxBreakdown = [];
        $lang = I18n::current();

        foreach ($this->lines() as $key => $entry) {
            $stmt = $this->pdo->prepare(
                'SELECT p.id, p.name, p.slug, p.price, p.discount_type, p.discount_value, p.discount_starts_at, p.discount_ends_at, p.stock_quantity, p.weight_kg, p.max_order_quantity,
                        (SELECT rate FROM tax_rates WHERE id = p.tax_rate_id) AS tax_rate_percent,
                        (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_image,
                        (SELECT cropped_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_cropped_image
                 FROM products p WHERE p.id = ?'
            );
            $stmt->execute([$entry['product_id']]);
            $product = $stmt->fetch();
            if (!$product) {
                continue; // product removed/deleted
            }
            $product = $this->translations->applyToProduct($product, $lang);

            $unitPrice = $this->discounts->effectivePrice($product);
            $taxRate = $this->tax->percentFor($product);
            $optionLabels = [];
            $availableStock = (int)$product['stock_quantity'];
            $optionValueIds = $entry['option_value_ids'] ?? (isset($entry['option_value_id']) && $entry['option_value_id'] ? [$entry['option_value_id']] : []);
            $variantId = null;
            $variant = null;

            if ($optionValueIds) {
                $variant = $this->findVariant((int)$product['id'], $optionValueIds);
                if ($variant) {
                    $variantId = (int)$variant['id'];
                    $availableStock = (int)$variant['stock_quantity'];
                } else {
                    $availableStock = PHP_INT_MAX;
                }

                foreach ($optionValueIds as $optionValueId) {
                    // SECURITY: scoped to this product's own option groups
                    // (po.product_id = ?) - see class docblock / finding #1.
                    $optStmt = $this->pdo->prepare(
                        'SELECT COALESCE(povt.value, ov.value) AS value, ov.price_modifier, ov.stock_quantity,
                                COALESCE(pot.name, po.name) AS option_name
                         FROM product_option_values ov
                         JOIN product_options po ON po.id = ov.product_option_id
                         LEFT JOIN product_option_translations pot ON pot.product_option_id = po.id AND pot.language = ?
                         LEFT JOIN product_option_value_translations povt ON povt.product_option_value_id = ov.id AND povt.language = ?
                         WHERE ov.id = ? AND po.product_id = ?'
                    );
                    $optStmt->execute([$lang, $lang, $optionValueId, $product['id']]);
                    $option = $optStmt->fetch();
                    if ($option) {
                        $unitPrice += (float)$option['price_modifier'];
                        $optionLabels[] = $option['option_name'] . ': ' . $option['value'];
                        if (!$variant) {
                            $availableStock = min($availableStock, (int)$option['stock_quantity']);
                        }
                    }
                }
                if (!$variant && $availableStock === PHP_INT_MAX) {
                    $availableStock = (int)$product['stock_quantity'];
                }
            }

            // Never let a (mis-scoped or otherwise unexpected) negative
            // option modifier push a line into negative territory.
            $unitPrice = max(0.0, $unitPrice);
            $lineTotal = round($unitPrice * $entry['quantity'], 2);
            $lineTax = round($lineTotal * $taxRate / 100, 2);
            $subtotal += $lineTotal;
            $taxTotal += $lineTax;
            if ($taxRate > 0) {
                $taxBreakdown[$taxRate] = ($taxBreakdown[$taxRate] ?? 0) + $lineTax;
            }

            $items[] = [
                'key'                => $key,
                'product_id'         => $product['id'],
                'option_value_ids'   => $optionValueIds,
                'variant_id'         => $variantId,
                'name'               => $product['name'],
                'slug'               => $product['slug'],
                'image'              => getPrimaryImage($product),
                'option_label'       => $optionLabels ? implode(', ', $optionLabels) : null,
                'unit_price'         => $unitPrice,
                'quantity'           => (int)$entry['quantity'],
                'line_total'         => $lineTotal,
                'tax_rate'           => $taxRate,
                'tax_amount'         => $lineTax,
                'available_stock'    => $availableStock,
                'weight_kg'          => (float)($product['weight_kg'] ?? 0),
                'max_order_quantity' => $product['max_order_quantity'] !== null ? (int)$product['max_order_quantity'] : null,
            ];
        }

        ksort($taxBreakdown);

        return [
            'items'         => $items,
            'subtotal'      => round($subtotal, 2),
            'tax_total'     => round($taxTotal, 2),
            'tax_breakdown' => $taxBreakdown,
        ];
    }

    public function getWeightKg(): float
    {
        $weight = 0.0;
        foreach ($this->getItems()['items'] as $item) {
            $weight += $item['weight_kg'] * $item['quantity'];
        }
        return $weight;
    }

    public function getActiveShippingMethods(): array
    {
        $methods = $this->pdo->query('SELECT * FROM shipping_methods WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
        foreach ($methods as &$method) {
            $stmt = $this->pdo->prepare('SELECT * FROM shipping_weight_tiers WHERE shipping_method_id = ? ORDER BY up_to_weight_kg ASC');
            $stmt->execute([$method['id']]);
            $method['weight_tiers'] = $stmt->fetchAll();
        }
        unset($method);
        return $methods;
    }

    public function calculateShippingForMethod(int $methodId, float $cartWeightKg, float $subtotal, int $totalQuantity): float
    {
        $stmt = $this->pdo->prepare('SELECT * FROM shipping_methods WHERE id = ? AND is_active = 1');
        $stmt->execute([$methodId]);
        $method = $stmt->fetch();
        if (!$method) {
            return 0.0;
        }

        if ($method['free_shipping_min_order_value'] !== null && $subtotal >= (float)$method['free_shipping_min_order_value']) {
            return 0.0;
        }
        if ($method['free_shipping_min_quantity'] !== null && $totalQuantity >= (int)$method['free_shipping_min_quantity']) {
            return 0.0;
        }

        $tierStmt = $this->pdo->prepare('SELECT * FROM shipping_weight_tiers WHERE shipping_method_id = ? ORDER BY up_to_weight_kg ASC');
        $tierStmt->execute([$methodId]);
        $tiers = $tierStmt->fetchAll();
        if (!$tiers) {
            return 0.0;
        }

        foreach ($tiers as $tier) {
            if ($cartWeightKg <= (float)$tier['up_to_weight_kg']) {
                return (float)$tier['price'];
            }
        }

        $topTier = end($tiers);
        $cost = (float)$topTier['price'];
        if ($method['extra_weight_step_kg'] > 0 && $method['extra_weight_step_price'] !== null) {
            $overWeight = $cartWeightKg - (float)$topTier['up_to_weight_kg'];
            $steps = (int)ceil($overWeight / (float)$method['extra_weight_step_kg']);
            $cost += $steps * (float)$method['extra_weight_step_price'];
        }
        return round($cost, 2);
    }
}
