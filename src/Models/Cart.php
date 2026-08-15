<?php

namespace ShopRex\Models;

use ShopRex\Core\Session;
use ShopRex\Services\DiscountCalculator;
use ShopRex\Services\I18n;
use ShopRex\Services\TaxCalculator;
use ShopRex\Services\TranslationOverlay;

/**
 * Session-based shopping cart - a request-scoped instance (held as a
 * Container singleton, so every controller in one request shares the same
 * Cart object) backed by the Session wrapper instead of touching
 * $_SESSION['cart'] directly. Every read/write to the cart in the app goes
 * through this one class's API - Controllers\Storefront\CartController and
 * the getCartItemCount() view-helper shim (used by the storefront nav's
 * cart badge) never touch $_SESSION itself.
 *
 * SECURITY (see docs/SECURITY_AUDIT.md finding #1): every per-option-value
 * lookup in getItems() below is scoped by `po.product_id = ?` - do not
 * remove that scoping, it is what stops an option-value id borrowed from a
 * *different* product's option group from having its price_modifier/
 * stock_quantity applied here.
 */
final class Cart
{
    // The $_SESSION array key everything is stored under, via the Session
    // wrapper - kept as a single constant so it's only ever written in one place.
    private const SESSION_KEY = 'cart';

    public function __construct(
        private readonly Session $session,
        private readonly \PDO $pdo,
        private readonly TranslationOverlay $translations,
        private readonly DiscountCalculator $discounts,
        private readonly TaxCalculator $tax,
    ) {
    }

    /** Raw cart line array as stored in the session - each entry is a lightweight {product_id, option_value_ids, quantity} record, NOT the priced/hydrated data getItems() returns; deliberately private so nothing outside this class touches the session's raw shape directly. */
    private function lines(): array
    {
        return $this->session->get(self::SESSION_KEY, []);
    }

    private function setLines(array $lines): void
    {
        $this->session->set(self::SESSION_KEY, $lines);
    }

    /** Builds the cart line's unique lookup key from a product + its chosen options - sorting the option value IDs first means "size M + color red" and "color red + size M" (same options, different submission order) always produce the same key, so they merge into one line instead of two. */
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
        // Normalizes the input: cast every id to int (defends against
        // non-numeric junk), drop duplicates, and re-index - so the same
        // logical selection always compares equal regardless of how it
        // arrived (order, duplicate values, string vs int types).
        $optionValueIds = array_values(array_unique(array_map('intval', $optionValueIds)));
        if (empty($optionValueIds)) {
            return null;
        }

        // A product's variant matrix must be matched by picking exactly one
        // value from EVERY one of its option groups - so if the number of
        // submitted option value IDs doesn't equal the product's actual
        // number of option groups, it can't possibly be a complete,
        // therefore valid, selection.
        $groupCountStmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_options WHERE product_id = ?');
        $groupCountStmt->execute([$productId]);
        $groupCount = (int)$groupCountStmt->fetchColumn();
        if ($groupCount === 0 || $groupCount !== count($optionValueIds)) {
            return null;
        }

        // Finds every product_variants row (scoped to pv.product_id = ? -
        // this product only) that has a product_variant_values row for
        // EVERY submitted option value id: GROUP BY + HAVING COUNT(*) =
        // $groupCount means "this variant matched at least $groupCount of
        // the submitted values", which combined with the equal-group-count
        // check above rules out a partial match.
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
        // Belt-and-braces re-check: for each candidate variant, confirm its
        // TOTAL number of option values (not just how many matched the
        // submission) also equals $groupCount - this catches a variant that
        // has MORE option values than were submitted, which the HAVING
        // clause above alone wouldn't exclude.
        foreach ($matches as $variant) {
            $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM product_variant_values WHERE product_variant_id = ?');
            $countStmt->execute([$variant['id']]);
            if ((int)$countStmt->fetchColumn() === $groupCount) {
                return $variant;
            }
        }
        return null;
    }

    /** Adds a product (with an optional set of chosen option values) to the cart, or increases the quantity of a matching line that's already there. Note this only stores raw IDs/quantities - no pricing/stock validation happens here, that's all deferred to getItems() being called later (see its docblock). */
    public function add(int $productId, array $optionValueIds, int $quantity): void
    {
        $lines = $this->lines();
        // array_filter drops zero/falsy values after casting to int, so a
        // stray "0" or empty option slot doesn't get stored as a real option selection.
        $optionValueIds = array_values(array_filter(array_map('intval', $optionValueIds)));
        $key = $this->key($productId, $optionValueIds);

        if (isset($lines[$key])) {
            // Same product + same options already in the cart - just bump
            // the existing line's quantity rather than creating a duplicate line.
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

    /** Sets a cart line to an exact quantity (used by the cart page's quantity inputs) - setting it to 0 or less removes the line entirely rather than leaving a zero-quantity row behind. */
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

    /** Removes one line from the cart entirely, identified by its key() value. */
    public function remove(string $key): void
    {
        $lines = $this->lines();
        unset($lines[$key]);
        $this->setLines($lines);
    }

    /** Empties the whole cart - called after an order is successfully placed. */
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

    /** Whether a line with this exact key is currently in the cart. */
    public function has(string $key): bool
    {
        return isset($this->lines()[$key]);
    }

    /** Total number of individual units across every cart line (not the number of distinct lines) - what a cart icon's badge count typically shows. */
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
        // Running totals of tax owed, grouped by tax RATE (e.g. "19% ->
        // €4.75 total") rather than per line item - this is what lets the
        // cart/checkout page show "includes 19% VAT: €4.75" style summaries
        // even when different products have different tax rates.
        $taxBreakdown = [];
        $lang = I18n::current();

        // Every stored cart line is re-read from the database HERE, on
        // every call - nothing about price, stock, or translated text is
        // trusted from what was stored earlier (session data is just
        // product_id + chosen option IDs + quantity). This is what keeps
        // prices/stock honest even if a product was edited, discounted, or
        // ran out of stock after it was added to the cart.
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
            // Overlays this visitor's language onto the product's
            // name/description before it's shown anywhere in the cart.
            $product = $this->translations->applyToProduct($product, $lang);

            $unitPrice = $this->discounts->effectivePrice($product);
            $taxRate = $this->tax->percentFor($product);
            $optionLabels = [];
            $availableStock = (int)$product['stock_quantity'];
            // Supports both the current storage shape (option_value_ids
            // array) and an older single option_value_id shape, so a cart
            // built under a previous version of this code still loads correctly.
            $optionValueIds = $entry['option_value_ids'] ?? (isset($entry['option_value_id']) && $entry['option_value_id'] ? [$entry['option_value_id']] : []);
            $variantId = null;
            $variant = null;

            if ($optionValueIds) {
                // Preferred path: a real product_variants row for this exact
                // combination, with its own dedicated stock count.
                $variant = $this->findVariant((int)$product['id'], $optionValueIds);
                if ($variant) {
                    $variantId = (int)$variant['id'];
                    $availableStock = (int)$variant['stock_quantity'];
                } else {
                    // No matching variant row - stock will instead be
                    // derived from the per-option-value fallback below
                    // (min() across each chosen value's own stock_quantity),
                    // so start high and let that loop narrow it down.
                    $availableStock = PHP_INT_MAX;
                }

                foreach ($optionValueIds as $optionValueId) {
                    // SECURITY (docs/SECURITY_AUDIT.md finding #1): this
                    // query is deliberately scoped by "po.product_id = ?" -
                    // without that condition, an attacker could submit an
                    // option_value_id that belongs to a completely
                    // different, unrelated product and have ITS
                    // price_modifier/stock_quantity applied to THIS
                    // product's line (e.g. subtracting a large negative
                    // modifier to get this item almost free, or borrowing a
                    // higher stock_quantity to bypass a real low-stock
                    // limit). Do not remove "AND po.product_id = ?" here.
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
                    // A crafted/stale option_value_id that doesn't belong to
                    // this product simply returns no row (thanks to the
                    // product_id scoping above) and is silently skipped
                    // instead of contributing anything to price/stock.
                    if ($option) {
                        $unitPrice += (float)$option['price_modifier'];
                        $optionLabels[] = $option['option_name'] . ': ' . $option['value'];
                        if (!$variant) {
                            // Legacy fallback stock model: the line's
                            // available stock is the SMALLEST stock_quantity
                            // across all its chosen option values (e.g. if
                            // "Red" has 3 left and "Large" has 10 left, only
                            // 3 can be sold) - see class docblock's note on
                            // the legacy Cart.php this mirrors.
                            $availableStock = min($availableStock, (int)$option['stock_quantity']);
                        }
                    }
                }
                // If no option value actually matched (all skipped above),
                // $availableStock never got narrowed down from PHP_INT_MAX -
                // fall back to the plain product stock rather than reporting
                // an effectively-infinite quantity available.
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

        // Sorts the tax-rate breakdown by rate ascending (e.g. 7% before
        // 19%) purely for a predictable, readable display order.
        ksort($taxBreakdown);

        return [
            'items'         => $items,
            'subtotal'      => round($subtotal, 2),
            'tax_total'     => round($taxTotal, 2),
            'tax_breakdown' => $taxBreakdown,
        ];
    }

    /** Total shipping weight of everything currently in the cart, in kilograms - re-derives from getItems() (so it uses fresh product weights) rather than trusting any cached figure, then feeds into calculateShippingForMethod()'s weight-tier lookup. */
    public function getWeightKg(): float
    {
        $weight = 0.0;
        foreach ($this->getItems()['items'] as $item) {
            $weight += $item['weight_kg'] * $item['quantity'];
        }
        return $weight;
    }

    /** Every shipping method an admin has enabled, each with its weight-based price tiers attached - used to populate the checkout page's shipping method choices. */
    public function getActiveShippingMethods(): array
    {
        $methods = $this->pdo->query('SELECT * FROM shipping_methods WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
        // Attaches each method's own weight tiers (e.g. "up to 2kg: €5, up
        // to 5kg: €8, ...") as a nested array - one extra query per method,
        // acceptable since the number of active shipping methods is small.
        foreach ($methods as &$method) {
            $stmt = $this->pdo->prepare('SELECT * FROM shipping_weight_tiers WHERE shipping_method_id = ? ORDER BY up_to_weight_kg ASC');
            $stmt->execute([$method['id']]);
            $method['weight_tiers'] = $stmt->fetchAll();
        }
        unset($method);
        return $methods;
    }

    /** Works out what shipping actually costs for one method, given the cart's real weight/subtotal/quantity - this is the single source of truth CheckoutService::placeOrder() calls to price shipping server-side, never trusting a client-submitted shipping cost. */
    public function calculateShippingForMethod(int $methodId, float $cartWeightKg, float $subtotal, int $totalQuantity): float
    {
        // Only an active method can be priced - a deactivated/nonexistent
        // method id just costs nothing (the caller is expected to have
        // already fallen back to "no shipping method" in that case).
        $stmt = $this->pdo->prepare('SELECT * FROM shipping_methods WHERE id = ? AND is_active = 1');
        $stmt->execute([$methodId]);
        $method = $stmt->fetch();
        if (!$method) {
            return 0.0;
        }

        // Two independent "free shipping" thresholds an admin can configure
        // per method - order value OR quantity, whichever is met first wins.
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

        // Tiers are checked lightest-first (ORDER BY up_to_weight_kg ASC) -
        // the first tier whose weight ceiling the cart fits under is the
        // one that applies.
        foreach ($tiers as $tier) {
            if ($cartWeightKg <= (float)$tier['up_to_weight_kg']) {
                return (float)$tier['price'];
            }
        }

        // Cart is heavier than every configured tier - use the heaviest
        // tier's price as a base, then add a per-step surcharge for the
        // excess weight above that tier's ceiling (if the admin configured
        // an "extra weight" step price at all).
        $topTier = end($tiers);
        $cost = (float)$topTier['price'];
        if ($method['extra_weight_step_kg'] > 0 && $method['extra_weight_step_price'] !== null) {
            $overWeight = $cartWeightKg - (float)$topTier['up_to_weight_kg'];
            // Rounds UP to the next whole step - e.g. 0.1kg over a 1kg step
            // still counts as a full extra step, matching how carriers
            // typically round shipping weight brackets.
            $steps = (int)ceil($overWeight / (float)$method['extra_weight_step_kg']);
            $cost += $steps * (float)$method['extra_weight_step_price'];
        }
        return round($cost, 2);
    }
}
