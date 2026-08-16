<?php

namespace ShopRex\Services;

/**
 * Applies/reverses the stock changes tied to an order's line items -
 * extracted from CheckoutService::placeOrder()'s inline stock block so
 * order creation, order editing, and order cancellation
 * (Services\OrderEditingService) all share the one oversell-safe
 * implementation, rather than a second copy of the guarded-UPDATE +
 * rowCount() pattern that could drift out of sync with it.
 *
 * Both methods here must be called from WITHIN a transaction the caller
 * already opened - neither begins or commits one itself, matching how
 * this logic worked inline inside CheckoutService::placeOrder() before
 * this extraction.
 */
final class OrderStockService
{
    /**
     * Decrements stock for a set of priced order line items (see
     * Models\Cart::priceLine()'s return shape - each entry needs
     * product_id, name, quantity, variant_id, option_value_ids). Skipped
     * entirely for test orders (matching every other stock path's
     * test-account exemption - CLAUDE.md's "Test accounts" section), but
     * an inventory_log row is still written either way so a trial run
     * stays visible/auditable.
     *
     * @throws \RuntimeException if a line has just sold out from under a
     *         concurrent caller (rowCount() === 0 on the guarded UPDATE) -
     *         the caller is expected to catch this and roll back, exactly
     *         as CheckoutService::placeOrder() already did before this code moved here.
     */
    public function decrementForItems(array $items, string $orderNumber, bool $isTestOrder, \PDO $pdo, string $reason = 'sale'): void
    {
        // The "AND stock_quantity >= ?" guard (plus the rowCount() check
        // below) is what actually prevents overselling under concurrent
        // callers for the same item - a plain pre-check elsewhere can't,
        // since it reads stock before this transaction started. A guarded
        // UPDATE is atomic per-row in MySQL/InnoDB, so two simultaneous
        // transactions for the last unit can't both succeed: whichever
        // commits second sees the row already short and its UPDATE simply
        // matches zero rows.
        $stockStmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
        $variantStockStmt = $pdo->prepare('UPDATE product_variants SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
        // Legacy fallback only (see Models\Cart::findVariant()'s fallback path).
        $optStockStmt = $pdo->prepare('UPDATE product_option_values SET stock_quantity = stock_quantity - ? WHERE id = ? AND stock_quantity >= ?');
        $logStmt = $pdo->prepare(
            'INSERT INTO inventory_log (product_id, option_value_id, product_variant_id, change_qty, reason, reference, is_test) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($items as $item) {
            // Which stock column gets decremented depends on how this line
            // item was configured: a plain product with no options, a
            // product tracked via a specific variant combination
            // (product_variants), or - the legacy fallback path - a set of
            // individual option value IDs (see Models\Cart's docblock and
            // docs/SECURITY_AUDIT.md finding #1 for why that fallback path
            // must stay carefully scoped elsewhere - already handled by
            // Cart::priceLine() before this method ever sees the item).
            if (empty($item['option_value_ids'])) {
                if (!$isTestOrder) {
                    $stockStmt->execute([$item['quantity'], $item['product_id'], $item['quantity']]);
                    if ($stockStmt->rowCount() === 0) {
                        throw new \RuntimeException('"' . $item['name'] . '" just sold out - please try again.');
                    }
                }
                $logStmt->execute([$item['product_id'], null, null, -$item['quantity'], $reason, $orderNumber, $isTestOrder ? 1 : 0]);
            } elseif (!empty($item['variant_id'])) {
                if (!$isTestOrder) {
                    $variantStockStmt->execute([$item['quantity'], $item['variant_id'], $item['quantity']]);
                    if ($variantStockStmt->rowCount() === 0) {
                        throw new \RuntimeException('"' . $item['name'] . '" just sold out - please try again.');
                    }
                }
                $logStmt->execute([$item['product_id'], null, $item['variant_id'], -$item['quantity'], $reason, $orderNumber, $isTestOrder ? 1 : 0]);
            } else {
                foreach ($item['option_value_ids'] as $optionValueId) {
                    if (!$isTestOrder) {
                        $optStockStmt->execute([$item['quantity'], $optionValueId, $item['quantity']]);
                        if ($optStockStmt->rowCount() === 0) {
                            throw new \RuntimeException('"' . $item['name'] . '" just sold out - please try again.');
                        }
                    }
                    $logStmt->execute([$item['product_id'], $optionValueId, null, -$item['quantity'], $reason, $orderNumber, $isTestOrder ? 1 : 0]);
                }
            }
        }
    }

    /**
     * Inverse of decrementForItems() - restores stock for a set of order
     * line items (a cancelled order, or the removed/reduced portion of an
     * edited order). GREATEST(0, ...) floors stock at zero, matching
     * InventoryAdminController::adjust()'s existing manual-restock guard,
     * in case stock was already corrected some other way in the meantime.
     * $reason must be one of inventory_log.reason's existing ENUM values
     * (see sql/schema.sql) - 'cancellation' for a full order cancel,
     * 'adjustment' for a partial edit-triggered restock.
     */
    public function restockForItems(array $items, string $orderNumber, bool $isTestOrder, \PDO $pdo, string $reason): void
    {
        $stockStmt = $pdo->prepare('UPDATE products SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id = ?');
        $variantStockStmt = $pdo->prepare('UPDATE product_variants SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id = ?');
        $optStockStmt = $pdo->prepare('UPDATE product_option_values SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id = ?');
        $logStmt = $pdo->prepare(
            'INSERT INTO inventory_log (product_id, option_value_id, product_variant_id, change_qty, reason, reference, is_test) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        foreach ($items as $item) {
            if (empty($item['option_value_ids'])) {
                if (!$isTestOrder) {
                    $stockStmt->execute([$item['quantity'], $item['product_id']]);
                }
                $logStmt->execute([$item['product_id'], null, null, $item['quantity'], $reason, $orderNumber, $isTestOrder ? 1 : 0]);
            } elseif (!empty($item['variant_id'])) {
                if (!$isTestOrder) {
                    $variantStockStmt->execute([$item['quantity'], $item['variant_id']]);
                }
                $logStmt->execute([$item['product_id'], null, $item['variant_id'], $item['quantity'], $reason, $orderNumber, $isTestOrder ? 1 : 0]);
            } else {
                foreach ($item['option_value_ids'] as $optionValueId) {
                    if (!$isTestOrder) {
                        $optStockStmt->execute([$item['quantity'], $optionValueId]);
                    }
                    $logStmt->execute([$item['product_id'], $optionValueId, null, $item['quantity'], $reason, $orderNumber, $isTestOrder ? 1 : 0]);
                }
            }
        }
    }
}
