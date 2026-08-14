<?php
/**
 * Session-based shopping cart.
 * Cart contents are stored in $_SESSION['cart'] as:
 *   [ "productId:v1-v2" => ['product_id'=>, 'option_value_ids'=>[...], 'quantity'=> ] ]
 * A product can have more than one option group (e.g. Size + Color); every
 * chosen value id is kept so nothing is lost, and hydrated from the
 * database on demand so prices/stock are always fresh.
 *
 * Stock for a product with any options is tracked per exact combination via
 * product_variants/product_variant_values (see findVariant() below and the
 * schema.sql comment on product_variants) - not per single option value.
 */

class Cart
{
    public static function key(int $productId, array $optionValueIds): string
    {
        sort($optionValueIds);
        return $productId . ':' . (empty($optionValueIds) ? '0' : implode('-', $optionValueIds));
    }

    /**
     * The product_variants row for this exact set of chosen option values
     * (order-independent), or null if the product has no variants defined
     * (e.g. it has no options at all, or its options predate this feature
     * and were never given a variant matrix in Admin -> Products -> edit).
     * A match requires every one of the product's option groups to be
     * represented - a partial/empty selection never matches.
     */
    public static function findVariant(int $productId, array $optionValueIds): ?array
    {
        $optionValueIds = array_values(array_unique(array_map('intval', $optionValueIds)));
        if (empty($optionValueIds)) {
            return null;
        }

        $groupCountStmt = db()->prepare('SELECT COUNT(*) FROM product_options WHERE product_id = ?');
        $groupCountStmt->execute([$productId]);
        $groupCount = (int)$groupCountStmt->fetchColumn();
        if ($groupCount === 0 || $groupCount !== count($optionValueIds)) {
            return null;
        }

        $placeholders = implode(',', array_fill(0, count($optionValueIds), '?'));
        $stmt = db()->prepare(
            "SELECT pv.* FROM product_variants pv
             JOIN product_variant_values pvv ON pvv.product_variant_id = pv.id
             WHERE pv.product_id = ? AND pvv.product_option_value_id IN ($placeholders)
             GROUP BY pv.id
             HAVING COUNT(*) = ?"
        );
        $stmt->execute([$productId, ...$optionValueIds, $groupCount]);
        $matches = $stmt->fetchAll();
        // A variant could in theory match on a subset if it has fewer
        // option_value rows than $groupCount, but the HAVING COUNT(*) = the
        // number of *chosen* ids only guarantees "at least this many
        // overlap" if the variant itself has more values than that - guard
        // by also checking the variant's own value count equals $groupCount.
        foreach ($matches as $variant) {
            $countStmt = db()->prepare('SELECT COUNT(*) FROM product_variant_values WHERE product_variant_id = ?');
            $countStmt->execute([$variant['id']]);
            if ((int)$countStmt->fetchColumn() === $groupCount) {
                return $variant;
            }
        }
        return null;
    }

    public static function add(int $productId, array $optionValueIds, int $quantity): void
    {
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        $optionValueIds = array_values(array_filter(array_map('intval', $optionValueIds)));
        $key = self::key($productId, $optionValueIds);

        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$key] = [
                'product_id'       => $productId,
                'option_value_ids' => $optionValueIds,
                'quantity'         => $quantity,
            ];
        }
    }

    public static function updateQuantity(string $key, int $quantity): void
    {
        if ($quantity <= 0) {
            unset($_SESSION['cart'][$key]);
            return;
        }
        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['quantity'] = $quantity;
        }
    }

    public static function remove(string $key): void
    {
        unset($_SESSION['cart'][$key]);
    }

    public static function clear(): void
    {
        $_SESSION['cart'] = [];
    }

    public static function isEmpty(): bool
    {
        return empty($_SESSION['cart']);
    }

    public static function count(): int
    {
        $count = 0;
        foreach ($_SESSION['cart'] ?? [] as $item) {
            $count += (int)$item['quantity'];
        }
        return $count;
    }

    /**
     * Hydrate cart lines with current product/option data from the DB.
     * Returns line items plus a NET subtotal, a total tax amount, and a
     * tax breakdown grouped by rate (e.g. [19.00 => 9.50, 7.00 => 1.05])
     * for the "net price (plus tax)" cart display and the order/invoice.
     * All prices here are NET - see getGrossPrice() for what the
     * storefront listing/product pages show instead.
     */
    public static function getItems(): array
    {
        $items = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $taxBreakdown = [];

        foreach ($_SESSION['cart'] ?? [] as $key => $entry) {
            $stmt = db()->prepare(
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

            $unitPrice = getEffectivePrice($product);
            $taxRate = getTaxRatePercent($product);
            $optionLabels = [];
            $availableStock = (int)$product['stock_quantity'];
            $optionValueIds = $entry['option_value_ids'] ?? (isset($entry['option_value_id']) && $entry['option_value_id'] ? [$entry['option_value_id']] : []);
            $variantId = null;

            if ($optionValueIds) {
                $variant = self::findVariant((int)$product['id'], $optionValueIds);
                if ($variant) {
                    $variantId = (int)$variant['id'];
                    $availableStock = (int)$variant['stock_quantity'];
                } else {
                    // No variant matrix defined for this combination (legacy
                    // data, or options added but never given stock in
                    // Admin -> Products -> edit) - fall back to the min()
                    // across the chosen values' own (legacy) stock numbers
                    // rather than pretending the combination is unavailable.
                    $availableStock = PHP_INT_MAX;
                }

                foreach ($optionValueIds as $optionValueId) {
                    // Scoped to this product's own option groups (po.product_id) -
                    // without this, an option value id borrowed from a *different*
                    // product's option group would still be accepted, letting its
                    // price_modifier/stock_quantity be applied to this product
                    // (see docs/SECURITY_AUDIT.md, finding #1).
                    $optStmt = db()->prepare(
                        'SELECT ov.value, ov.price_modifier, ov.stock_quantity, po.name AS option_name
                         FROM product_option_values ov
                         JOIN product_options po ON po.id = ov.product_option_id
                         WHERE ov.id = ? AND po.product_id = ?'
                    );
                    $optStmt->execute([$optionValueId, $product['id']]);
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
                    // No variant AND no legacy option-value rows matched
                    // either (fully orphaned selection) - don't claim
                    // infinite stock, fall back to the base product's.
                    $availableStock = (int)$product['stock_quantity'];
                }
            }

            // Never let a (mis-scoped or otherwise unexpected) negative option
            // modifier push a line into negative territory - a unit price is
            // never less than free.
            $unitPrice = max(0.0, $unitPrice);
            $lineTotal = round($unitPrice * $entry['quantity'], 2);
            $lineTax = round($lineTotal * $taxRate / 100, 2);
            $subtotal += $lineTotal;
            $taxTotal += $lineTax;
            if ($taxRate > 0) {
                $taxBreakdown[$taxRate] = ($taxBreakdown[$taxRate] ?? 0) + $lineTax;
            }

            $items[] = [
                'key'              => $key,
                'product_id'       => $product['id'],
                'option_value_ids' => $optionValueIds,
                'variant_id'       => $variantId,
                'name'             => $product['name'],
                'slug'             => $product['slug'],
                'image'            => getPrimaryImage($product),
                'option_label'     => $optionLabels ? implode(', ', $optionLabels) : null,
                'unit_price'       => $unitPrice,
                'quantity'         => (int)$entry['quantity'],
                'line_total'       => $lineTotal,
                'tax_rate'         => $taxRate,
                'tax_amount'       => $lineTax,
                'available_stock'  => $availableStock,
                'weight_kg'        => (float)($product['weight_kg'] ?? 0),
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

    /**
     * Total weight of everything in the cart (product.weight_kg * quantity,
     * NULL/unset weight treated as 0kg) - the input to weight-based
     * shipping tiers. Option values don't carry their own weight.
     */
    public static function getWeightKg(): float
    {
        $weight = 0.0;
        foreach (self::getItems()['items'] as $item) {
            $weight += $item['weight_kg'] * $item['quantity'];
        }
        return $weight;
    }

    /**
     * Every active shipping method (Admin -> Shipping), each with its
     * weight_tiers[] loaded (ordered by up_to_weight_kg ascending) - for
     * checkout's method picker.
     */
    public static function getActiveShippingMethods(): array
    {
        $methods = db()->query('SELECT * FROM shipping_methods WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
        foreach ($methods as &$method) {
            $stmt = db()->prepare('SELECT * FROM shipping_weight_tiers WHERE shipping_method_id = ? ORDER BY up_to_weight_kg ASC');
            $stmt->execute([$method['id']]);
            $method['weight_tiers'] = $stmt->fetchAll();
        }
        unset($method);
        return $methods;
    }

    /**
     * Shipping cost for one method given the cart's weight/value/quantity.
     * 1) Free if the method's free_shipping_min_order_value or
     *    free_shipping_min_quantity is met (either condition, whichever is set).
     * 2) Else the price of the first weight tier whose up_to_weight_kg
     *    covers the cart's weight.
     * 3) If the cart is heavier than every tier: start from the heaviest
     *    tier's price and add extra_weight_step_price on top for every
     *    extra_weight_step_kg (or part thereof) beyond it - "once the
     *    maximum weight tier is reached, the next step is added on top".
     *    Without an extra-step configured, the heaviest tier's price is
     *    used no matter how far over it the cart goes (never errors).
     * Returns 0.0 for a method with no tiers at all (nothing to charge).
     */
    public static function calculateShippingForMethod(int $methodId, float $cartWeightKg, float $subtotal, int $totalQuantity): float
    {
        $stmt = db()->prepare('SELECT * FROM shipping_methods WHERE id = ? AND is_active = 1');
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

        $tierStmt = db()->prepare('SELECT * FROM shipping_weight_tiers WHERE shipping_method_id = ? ORDER BY up_to_weight_kg ASC');
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

        // Heavier than every defined tier.
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
