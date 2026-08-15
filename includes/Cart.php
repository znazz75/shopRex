<?php
/**
 * Session-based shopping cart - represents "everything this visitor has
 * put in their basket so far". It's a plain static class rather than an
 * object you instantiate: every method reaches directly into
 * $_SESSION['cart'], so the cart's state simply IS the visitor's PHP
 * session, with nothing else to construct or pass around.
 * Cart contents are stored in $_SESSION['cart'] as:
 *   [ "productId:v1-v2" => ['product_id'=>, 'option_value_ids'=>[...], 'quantity'=> ] ]
 * A product can have more than one option group (e.g. Size + Color); every
 * chosen value id is kept so nothing is lost, and hydrated from the
 * database on demand so prices/stock are always fresh.
 *
 * Stock for a product with any options is tracked per exact combination via
 * product_variants/product_variant_values (see findVariant() below and the
 * schema.sql comment on product_variants) - not per single option value.
 *
 * Why this class still exists as-is: it's one of the "legacy classes kept
 * as-is" from before the OOP rewrite (see CLAUDE.md's "Legacy classes kept
 * as-is" section) - required directly via `require_once` in
 * src/container.php rather than ported into the ShopRex\ namespace,
 * because includes/header.php (a template intentionally left unconverted)
 * still calls the unqualified `Cart::count()` global function directly.
 * The newer `Models\Cart` (an instance held in the dependency-injection
 * Container, used by the rest of the OOP code) wraps/delegates to this
 * class's logic rather than replacing it outright.
 *
 * Security note: getItems() below must scope its option-value lookup by
 * that option's own product_id - without that check, a crafted option-value
 * ID belonging to a *different* product could be used to apply that other
 * product's price_modifier/stock to this product's cart line. This was a
 * real bug that was found and fixed - see docs/SECURITY_AUDIT.md finding
 * #1 and the comment at the query in getItems() below.
 */

class Cart
{
    /**
     * Builds the array key that identifies one distinct cart line: a
     * product plus one exact combination of chosen option values (e.g.
     * "Size: M, Color: Red"). Sorting the ids first makes the key
     * order-independent, so picking "Color then Size" and "Size then
     * Color" in the UI both land on the same cart line instead of
     * accidentally creating two separate ones.
     */
    public static function key(int $productId, array $optionValueIds): string
    {
        // Sort so the key doesn't depend on the order options were chosen in.
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
        // Normalise: force every id to an int (defends against non-numeric
        // junk from a crafted request) and drop duplicates, since a valid
        // selection never picks the same option value twice.
        $optionValueIds = array_values(array_unique(array_map('intval', $optionValueIds)));
        if (empty($optionValueIds)) {
            return null;
        }

        // A selection only counts as "complete" if it has exactly one value
        // per option group the product defines (e.g. both Size AND Color) -
        // fewer values than that can never uniquely identify one variant row.
        $groupCountStmt = db()->prepare('SELECT COUNT(*) FROM product_options WHERE product_id = ?');
        $groupCountStmt->execute([$productId]);
        $groupCount = (int)$groupCountStmt->fetchColumn();
        if ($groupCount === 0 || $groupCount !== count($optionValueIds)) {
            return null;
        }

        // Build a "?,?,?" placeholder list sized to match the number of
        // chosen ids - still fully parameterised (nothing here is
        // string-concatenated into the SQL), only the *count* of
        // placeholders is computed dynamically.
        $placeholders = implode(',', array_fill(0, count($optionValueIds), '?'));
        // Every variant row that has at least $groupCount of its
        // option-value links matching the chosen ids.
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

    /**
     * Adds $quantity of a product (with an optional set of chosen option
     * values, e.g. Size+Color) to the cart. If that exact combination is
     * already in the cart, its quantity is increased instead of creating a
     * second, duplicate line - this is what makes "Add to Cart" on a
     * product you already have in your basket feel like "add more" rather
     * than "start a new line".
     */
    public static function add(int $productId, array $optionValueIds, int $quantity): void
    {
        // Lazily create the session array the first time anything is added.
        if (!isset($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        // array_filter drops any falsy id (e.g. "0" from an unselected
        // <select>) before it becomes part of the cart key.
        $optionValueIds = array_values(array_filter(array_map('intval', $optionValueIds)));
        $key = self::key($productId, $optionValueIds);

        if (isset($_SESSION['cart'][$key])) {
            // Same product + same exact option combination already in the
            // cart - just increase its quantity rather than duplicating it.
            $_SESSION['cart'][$key]['quantity'] += $quantity;
        } else {
            $_SESSION['cart'][$key] = [
                'product_id'       => $productId,
                'option_value_ids' => $optionValueIds,
                'quantity'         => $quantity,
            ];
        }
    }

    /**
     * Sets a cart line's quantity to an exact value (unlike add(), which is
     * additive) - this is what the cart page's quantity input field posts.
     * A quantity of 0 or below removes the line entirely, since a cart line
     * for zero units doesn't make sense to keep around.
     */
    public static function updateQuantity(string $key, int $quantity): void
    {
        if ($quantity <= 0) {
            // Treat "set to zero/negative" the same as "remove this line".
            unset($_SESSION['cart'][$key]);
            return;
        }
        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['quantity'] = $quantity;
        }
    }

    /** Removes one cart line entirely, identified by its key() - e.g. clicking a "Remove" button on the cart page. */
    public static function remove(string $key): void
    {
        unset($_SESSION['cart'][$key]);
    }

    /** Empties the entire cart - e.g. called right after an order is successfully placed. */
    public static function clear(): void
    {
        $_SESSION['cart'] = [];
    }

    /** True when the cart currently has no lines at all - used to hide the cart icon badge, block checkout, etc. */
    public static function isEmpty(): bool
    {
        return empty($_SESSION['cart']);
    }

    /**
     * Total number of individual units across every cart line (2 shirts +
     * 1 hat = 3), not the number of distinct lines - this is the number
     * shown in the cart icon badge in the site header.
     */
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
        // Current visitor's language (implicit-current-language style, same
        // as getGrossPrice()/getCurrentLanguage() callers elsewhere) - this
        // is what makes the order_items snapshot checkout_process.php
        // writes automatically capture the customer's language, same as
        // orders.language already drives invoice/email language.
        $lang = getCurrentLanguage();

        foreach ($_SESSION['cart'] ?? [] as $key => $entry) {
            // Re-fetch the product fresh from the DB on every read (never
            // trust anything cached in the session) so price changes,
            // discounts, or stock updates made in Admin -> Products show up
            // immediately, even for items already sitting in someone's
            // cart. The correlated subqueries pull the tax rate percentage
            // and the primary product image alongside the product row.
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
            // Overlay $lang's translated name/description onto the base
            // (default-language) row - falls back to the base text
            // per-field whenever no translation exists for that field.
            $product = applyProductTranslation($product, $lang);

            // getEffectivePrice() returns the active discounted price if
            // one applies right now, otherwise the regular price - always
            // NET (before tax). getTaxRatePercent() is 0 outright whenever
            // VAT is disabled site-wide (Admin -> Settings).
            $unitPrice = getEffectivePrice($product);
            $taxRate = getTaxRatePercent($product);
            $optionLabels = [];
            $availableStock = (int)$product['stock_quantity'];
            // Legacy fallback: a session cart created before multi-option
            // support existed may still hold a single 'option_value_id'
            // instead of an 'option_value_ids' array - normalise it into
            // the same array shape so the rest of this method only needs
            // one code path.
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
                    // (see docs/SECURITY_AUDIT.md, finding #1). COALESCE overlays
                    // $lang's option-group-name/value translation (Admin ->
                    // Products -> edit), falling back to the base text - same
                    // per-field fallback as applyProductTranslation().
                    $optStmt = db()->prepare(
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
                        // Add this option's price modifier (e.g. "+2.00 for
                        // size XL") onto the running unit price, and build a
                        // human-readable label like "Size: XL". When there's
                        // no variant matrix, be conservative and shrink the
                        // available stock down to the smallest number found
                        // across the chosen option values, so the cart line
                        // never overstates what's actually in stock.
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
            // Round at the line level (not just once at the very end) so the
            // individual line totals shown on screen always add up exactly
            // to the displayed subtotal - avoids the classic "the numbers
            // don't quite add up" rounding complaint.
            $lineTotal = round($unitPrice * $entry['quantity'], 2);
            $lineTax = round($lineTotal * $taxRate / 100, 2);
            $subtotal += $lineTotal;
            $taxTotal += $lineTax;
            if ($taxRate > 0) {
                // Group tax by rate (e.g. all 19% lines together, all 7%
                // lines together) for the "VAT 19%: X / VAT 7%: Y" style
                // breakdown shown on the cart page and printed on invoices.
                $taxBreakdown[$taxRate] = ($taxBreakdown[$taxRate] ?? 0) + $lineTax;
            }

            // One row per cart line, shaped for direct use by the cart page,
            // the checkout summary, and the order_items snapshot written at
            // checkout time.
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

        // Sort by rate ascending, so a display like "7% ... 19%" is always
        // in a stable, predictable order rather than insertion order.
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
        // &$method (by reference) lets each row be mutated in place to
        // attach its weight tiers, without having to rebuild the whole
        // $methods array. unset($method) right after the loop is important -
        // without it, $method would keep pointing at the last array element,
        // and a later, unrelated `foreach ($methods as $method)` elsewhere
        // in the codebase could silently overwrite that last row (a classic
        // PHP foreach-by-reference gotcha).
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
            // Unknown or deactivated method id - nothing to charge. Checkout
            // would already have filtered this out of the method picker, so
            // this is just defense-in-depth against a stale/tampered id.
            return 0.0;
        }

        // Either free-shipping condition alone is enough to qualify - they
        // are not both required (e.g. "free over 50 EUR" OR "free for 10+ items").
        if ($method['free_shipping_min_order_value'] !== null && $subtotal >= (float)$method['free_shipping_min_order_value']) {
            return 0.0;
        }
        if ($method['free_shipping_min_quantity'] !== null && $totalQuantity >= (int)$method['free_shipping_min_quantity']) {
            return 0.0;
        }

        // Weight tiers ordered lightest-to-heaviest (see the ORDER BY), so
        // the loop below finds the cheapest tier whose ceiling still covers
        // the cart.
        $tierStmt = db()->prepare('SELECT * FROM shipping_weight_tiers WHERE shipping_method_id = ? ORDER BY up_to_weight_kg ASC');
        $tierStmt->execute([$methodId]);
        $tiers = $tierStmt->fetchAll();
        if (!$tiers) {
            return 0.0;
        }

        // First (i.e. cheapest) tier whose weight ceiling covers the cart.
        foreach ($tiers as $tier) {
            if ($cartWeightKg <= (float)$tier['up_to_weight_kg']) {
                return (float)$tier['price'];
            }
        }

        // Heavier than every defined tier - start from the top (heaviest)
        // tier's price (see point 3 in the docblock above) and, if an extra
        // weight step is configured, add one extra_weight_step_price charge
        // for every started extra_weight_step_kg beyond that tier's ceiling.
        // ceil() means even 1kg into a new step charges for the whole step.
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
