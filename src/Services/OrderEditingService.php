<?php

namespace ShopRex\Services;

use ShopRex\Models\Cart;
use ShopRex\Models\Order;

/**
 * Admin order create/edit/cancel (Admin -> Orders) - manager and Super
 * Admin can create an order manually and edit an existing order's line
 * items; only Super Admin can cancel one (see AdminAuth::CAPABILITIES'
 * 'orders'/'orders_delete' split). Every write here is transactional and
 * logged to order_edit_log (an order row is never SQL-DELETEd - see that
 * table's docblock in sql/schema.sql and Services\GdprService's
 * accounting/tax-retention reasoning, which this class deliberately
 * preserves).
 *
 * KNOWN LIMITATION (documented, not hidden): editing an order's items
 * after it's already been invoiced regenerates the existing invoice PDF
 * IN PLACE, reusing the same invoice number (InvoiceGenerator's existing
 * "safe to call twice" behavior) - this is not a compliant credit-note
 * flow. A real corrective-invoice/credit-note system is a separate,
 * larger feature this class does not attempt.
 */
final class OrderEditingService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly Cart $cart,
        private readonly OrderStockService $stock,
        private readonly NumberSequenceService $sequences,
    ) {
    }

    /**
     * Creates an order manually (e.g. a phone/mail order an admin enters
     * on a customer's behalf) - same server-side pricing/stock discipline
     * as the real storefront checkout (Cart::priceLine()/
     * OrderStockService), just without a cart, a live payment gateway
     * call, or an automatic confirmation email/invoice (an admin-entered
     * order isn't the customer-facing checkout flow - CLAUDE.md's "never
     * trust client input for anything security/pricing-load-bearing"
     * posture still applies: every price/tax/stock figure here is
     * re-derived server-side, never taken from $post directly).
     *
     * @param array $post Raw form input - customer_id, guest_email,
     *        shipping_name/address1/address2/city/postal_code/country,
     *        shipping_method_id, payment_method, payment_status
     *        ('pending'|'paid'), customer_notes, admin_notes, and the
     *        repeating line-item fields (see parseLineItems()).
     * @throws \RuntimeException on any validation failure - the caller
     *         (OrderAdminController) is expected to catch this and
     *         re-render the form with the message.
     */
    public function createManualOrder(array $post, ?array $customer, int $adminId): Order
    {
        $email = $customer['email'] ?? filter_var($post['guest_email'] ?? '', FILTER_VALIDATE_EMAIL);
        $paymentMethod = (string)($post['payment_method'] ?? '');
        $paymentStatus = ($post['payment_status'] ?? 'pending') === 'paid' ? 'paid' : 'pending';

        $shippingName = trim((string)($post['shipping_name'] ?? ''));
        $shippingAddress1 = trim((string)($post['shipping_address1'] ?? ''));
        $shippingAddress2 = trim((string)($post['shipping_address2'] ?? ''));
        $shippingCity = trim((string)($post['shipping_city'] ?? ''));
        $shippingPostal = trim((string)($post['shipping_postal_code'] ?? ''));
        $shippingCountry = trim((string)($post['shipping_country'] ?? ''));
        $customerNotes = trim((string)($post['customer_notes'] ?? ''));
        $adminNotes = trim((string)($post['admin_notes'] ?? ''));

        if (!$email) {
            throw new \RuntimeException(__('admin.orders.create.email_required'));
        }
        if ($shippingName === '' || $shippingAddress1 === '' || $shippingCity === '' || $shippingPostal === '' || $shippingCountry === '') {
            throw new \RuntimeException(__('admin.orders.create.address_required'));
        }
        if ($paymentMethod === '') {
            throw new \RuntimeException(__('admin.orders.create.payment_method_required'));
        }

        $lines = $this->parseLineItems($post);
        if (empty($lines)) {
            throw new \RuntimeException(__('admin.orders.create.no_items'));
        }

        $priced = [];
        $subtotal = 0.0;
        $weightKg = 0.0;
        $totalQty = 0;
        foreach ($lines as $line) {
            $item = $this->cart->priceLine($line['product_id'], $line['quantity'], $line['option_value_ids']);
            if ($item === null) {
                throw new \RuntimeException(__('admin.orders.create.product_not_found', ['id' => $line['product_id']]));
            }
            $priced[] = $item;
            $subtotal += $item['line_total'];
            $weightKg += $item['weight_kg'] * $item['quantity'];
            $totalQty += $item['quantity'];
        }
        $subtotal = round($subtotal, 2);

        $shippingMethodId = (int)($post['shipping_method_id'] ?? 0) ?: null;
        $shippingCost = 0.0;
        $shippingMethodName = null;
        if ($shippingMethodId) {
            $methodStmt = $this->pdo->prepare('SELECT name FROM shipping_methods WHERE id = ? AND is_active = 1');
            $methodStmt->execute([$shippingMethodId]);
            $shippingMethodName = $methodStmt->fetchColumn() ?: null;
            if ($shippingMethodName === null) {
                $shippingMethodId = null;
            } else {
                $shippingCost = $this->cart->calculateShippingForMethod($shippingMethodId, $weightKg, $subtotal, $totalQty);
            }
        }

        $taxTotal = round(array_sum(array_column($priced, 'tax_amount')), 2);
        $total = round($subtotal + $shippingCost + $taxTotal, 2);
        $isTestOrder = !empty($customer['is_test_account']);

        $this->pdo->beginTransaction();
        try {
            $orderNumber = CheckoutService::generateOrderNumber();

            $stmt = $this->pdo->prepare(
                'INSERT INTO orders (order_number, customer_id, guest_email, status, payment_method, payment_status, is_test_order, language,
                                      subtotal, shipping_cost, shipping_method_id, shipping_method_name, tax_total, total,
                                      shipping_name, shipping_address1, shipping_address2, shipping_city, shipping_postal_code, shipping_country,
                                      billing_same_as_shipping, customer_notes, admin_notes)
                 VALUES (?, ?, ?, "pending", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $stmt->execute([
                $orderNumber, $customer['id'] ?? null, $customer ? null : $email, $paymentMethod, $paymentStatus, $isTestOrder ? 1 : 0, I18n::current(),
                $subtotal, $shippingCost, $shippingMethodId, $shippingMethodName, $taxTotal, $total,
                $shippingName, $shippingAddress1, $shippingAddress2, $shippingCity, $shippingPostal, $shippingCountry,
                $customerNotes !== '' ? $customerNotes : null, $adminNotes !== '' ? $adminNotes : null,
            ]);
            $orderId = (int)$this->pdo->lastInsertId();

            $itemStmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, option_summary, product_variant_id, option_value_ids, quantity, unit_price, total_price, tax_rate_percent, tax_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($priced as $item) {
                $itemStmt->execute([
                    $orderId, $item['product_id'], $item['name'], $item['option_label'],
                    $item['variant_id'] ?: null,
                    !empty($item['option_value_ids']) ? implode(',', $item['option_value_ids']) : null,
                    $item['quantity'], $item['unit_price'], $item['line_total'],
                    $item['tax_rate'], $item['tax_amount'],
                ]);
            }
            $this->stock->decrementForItems($priced, $orderNumber, $isTestOrder, $this->pdo);

            $this->pdo->prepare('INSERT INTO payments (order_id, payment_method, amount, currency, status) VALUES (?, ?, ?, ?, ?)')
                ->execute([$orderId, $paymentMethod, $total, 'EUR', $paymentStatus === 'paid' ? 'completed' : 'pending']);

            // Matches the ledger convention OrderAdminController::save()
            // already uses when an order transitions into 'paid' - a manual
            // order entered as already-paid needs the same 'sale' row so
            // Finance's transaction list isn't missing an entry a live
            // checkout/status-save would always have written.
            if ($paymentStatus === 'paid' && !$isTestOrder) {
                $this->pdo->prepare('INSERT INTO transactions (order_id, type, amount, note, created_by) VALUES (?, "sale", ?, "Order created manually by admin", ?)')
                    ->execute([$orderId, $total, $adminId]);
            }

            $this->pdo->prepare('INSERT INTO order_edit_log (order_id, admin_id, action, summary) VALUES (?, ?, "created", ?)')
                ->execute([$orderId, $adminId, __('admin.orders.log.created', ['count' => count($priced)])]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return Order::findByNumber($orderNumber);
    }

    /**
     * Replaces an existing order's line items with $post's submitted set
     * (add/remove/change quantity), recomputing totals/tax/shipping/stock
     * server-side - never trusting a submitted price. See class docblock
     * for the invoice-regeneration caveat.
     */
    public function applyLineItemChanges(Order $order, array $post, int $adminId): Order
    {
        $existingRows = $order->items();
        $existingById = [];
        foreach ($existingRows as $row) {
            $existingById[(int)$row['id']] = $row;
        }

        $submittedLines = $this->parseLineItems($post, includeOrderItemId: true);
        $finalLines = [];
        $submittedIds = [];
        foreach ($submittedLines as $line) {
            $priced = $this->cart->priceLine($line['product_id'], $line['quantity'], $line['option_value_ids']);
            if ($priced === null) {
                throw new \RuntimeException(__('admin.orders.edit.product_not_found', ['id' => $line['product_id']]));
            }
            $existingId = $line['order_item_id'];
            $old = ($existingId && isset($existingById[$existingId])) ? $existingById[$existingId] : null;
            if ($old) {
                $submittedIds[] = $existingId;
            }
            $finalLines[] = ['order_item_id' => $old ? $existingId : null, 'old' => $old, 'priced' => $priced];
        }
        if (empty($finalLines)) {
            throw new \RuntimeException(__('admin.orders.edit.no_items'));
        }

        $removedRows = array_filter($existingRows, fn ($row) => !in_array((int)$row['id'], $submittedIds, true));

        // Block removing or reducing a line that already has RMA/withdrawal
        // history against it, rather than silently cascading it away
        // (order_items(id) is ON DELETE CASCADE from both child tables -
        // see sql/schema.sql).
        foreach ($removedRows as $row) {
            $this->assertNoReturnHistory((int)$row['id'], $row['product_name']);
        }
        foreach ($finalLines as $line) {
            if ($line['old'] !== null && $line['priced']['quantity'] < (int)$line['old']['quantity']) {
                $this->assertNoReturnHistory((int)$line['order_item_id'], $line['old']['product_name']);
            }
        }

        $oldTotal = $order->total;
        $newSubtotal = 0.0;
        $newTax = 0.0;
        $stockDecrementItems = [];
        $stockRestockItems = [];
        $summaryParts = [];

        $this->pdo->beginTransaction();
        try {
            foreach ($removedRows as $row) {
                $this->pdo->prepare('DELETE FROM order_items WHERE id = ?')->execute([(int)$row['id']]);
                $stockRestockItems[] = $this->toStockShape($row);
                $summaryParts[] = __('admin.orders.log.removed', ['qty' => $row['quantity'], 'name' => $row['product_name']]);
            }

            $insertStmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, product_name, option_summary, product_variant_id, option_value_ids, quantity, unit_price, total_price, tax_rate_percent, tax_amount)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $updateStmt = $this->pdo->prepare(
                'UPDATE order_items SET product_id=?, product_name=?, option_summary=?, product_variant_id=?, option_value_ids=?, quantity=?, unit_price=?, total_price=?, tax_rate_percent=?, tax_amount=? WHERE id=?'
            );

            foreach ($finalLines as $line) {
                $p = $line['priced'];
                $newSubtotal += $p['line_total'];
                $newTax += $p['tax_amount'];
                $optIdsStr = !empty($p['option_value_ids']) ? implode(',', $p['option_value_ids']) : null;

                if ($line['order_item_id'] === null) {
                    $insertStmt->execute([
                        $order->id, $p['product_id'], $p['name'], $p['option_label'],
                        $p['variant_id'] ?: null, $optIdsStr, $p['quantity'], $p['unit_price'], $p['line_total'], $p['tax_rate'], $p['tax_amount'],
                    ]);
                    $stockDecrementItems[] = $p;
                    $summaryParts[] = __('admin.orders.log.added', ['qty' => $p['quantity'], 'name' => $p['name']]);
                } else {
                    $old = $line['old'];
                    $oldQty = (int)$old['quantity'];
                    $newQty = $p['quantity'];
                    $updateStmt->execute([
                        $p['product_id'], $p['name'], $p['option_label'], $p['variant_id'] ?: null, $optIdsStr,
                        $p['quantity'], $p['unit_price'], $p['line_total'], $p['tax_rate'], $p['tax_amount'], $line['order_item_id'],
                    ]);
                    if ($newQty > $oldQty) {
                        $delta = $p;
                        $delta['quantity'] = $newQty - $oldQty;
                        $stockDecrementItems[] = $delta;
                        $summaryParts[] = __('admin.orders.log.qty_changed', ['name' => $p['name'], 'old' => $oldQty, 'new' => $newQty]);
                    } elseif ($newQty < $oldQty) {
                        $deltaShape = $this->toStockShape($old);
                        $deltaShape['quantity'] = $oldQty - $newQty;
                        $stockRestockItems[] = $deltaShape;
                        $summaryParts[] = __('admin.orders.log.qty_changed', ['name' => $p['name'], 'old' => $oldQty, 'new' => $newQty]);
                    }
                }
            }

            if ($stockDecrementItems) {
                $this->stock->decrementForItems($stockDecrementItems, $order->orderNumber, $order->isTestOrder, $this->pdo, 'sale');
            }
            if ($stockRestockItems) {
                $this->stock->restockForItems($stockRestockItems, $order->orderNumber, $order->isTestOrder, $this->pdo, 'adjustment');
            }

            $shippingCost = $order->shippingCost;
            $shippingMethodId = $order->shippingMethodId;
            $shippingMethodName = $order->shippingMethodName;
            $newShippingMethodId = array_key_exists('shipping_method_id', $post) ? ((int)$post['shipping_method_id'] ?: null) : $shippingMethodId;
            if ($newShippingMethodId !== $shippingMethodId) {
                if ($newShippingMethodId === null) {
                    $shippingCost = 0.0;
                    $shippingMethodName = null;
                } else {
                    $methodStmt = $this->pdo->prepare('SELECT name FROM shipping_methods WHERE id = ? AND is_active = 1');
                    $methodStmt->execute([$newShippingMethodId]);
                    $name = $methodStmt->fetchColumn();
                    if ($name !== false) {
                        $weightKg = 0.0;
                        $totalQty = 0;
                        foreach ($finalLines as $line) {
                            $weightKg += $line['priced']['weight_kg'] * $line['priced']['quantity'];
                            $totalQty += $line['priced']['quantity'];
                        }
                        $shippingCost = $this->cart->calculateShippingForMethod($newShippingMethodId, $weightKg, $newSubtotal, $totalQty);
                        $shippingMethodName = $name;
                    } else {
                        $newShippingMethodId = null;
                        $shippingCost = 0.0;
                        $shippingMethodName = null;
                    }
                }
                $shippingMethodId = $newShippingMethodId;
            }

            $newSubtotal = round($newSubtotal, 2);
            $newTax = round($newTax, 2);
            $newTotal = round($newSubtotal + $shippingCost + $newTax, 2);

            $this->pdo->prepare('UPDATE orders SET subtotal=?, shipping_cost=?, shipping_method_id=?, shipping_method_name=?, tax_total=?, total=? WHERE id=?')
                ->execute([$newSubtotal, $shippingCost, $shippingMethodId, $shippingMethodName, $newTax, $newTotal, $order->id]);

            // Keeps the finance ledger internally consistent with the live
            // order total for an already-paid order (see class docblock -
            // this is a deliberate, confirmed design choice: line-item
            // edits ARE allowed on paid orders, with this reconciling entry
            // as the safeguard).
            if ($order->paymentStatus === 'paid' && !$order->isTestOrder && abs($newTotal - $oldTotal) >= 0.01) {
                $this->pdo->prepare('INSERT INTO transactions (order_id, type, amount, note, created_by) VALUES (?, "adjustment", ?, "Order items edited by admin", ?)')
                    ->execute([$order->id, round($newTotal - $oldTotal, 2), $adminId]);
            }

            $summary = $summaryParts ? implode('; ', $summaryParts) : __('admin.orders.log.no_changes');
            $this->pdo->prepare('INSERT INTO order_edit_log (order_id, admin_id, action, summary) VALUES (?, ?, "items_edited", ?)')
                ->execute([$order->id, $adminId, $summary]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $order = Order::findByNumber($order->orderNumber);

        // Best-effort, outside the transaction - matches
        // CheckoutService::placeOrder()'s own posture on invoice
        // generation (logged on failure, never fatal to the edit itself).
        // See class docblock for the "reuses the same invoice number"
        // caveat.
        $invoiceStmt = $this->pdo->prepare('SELECT id FROM invoices WHERE order_id = ?');
        $invoiceStmt->execute([$order->id]);
        if ($invoiceStmt->fetchColumn()) {
            try {
                InvoiceGenerator::generateForOrder($order->toRow(), $order->items(), $this->sequences);
            } catch (\Throwable $e) {
                error_log('Invoice regeneration failed for order ' . $order->orderNumber . ' after an admin edit: ' . $e->getMessage());
            }
        }

        return $order;
    }

    /**
     * "Delete" an order, per this project's standing rule that an order
     * row is never SQL-DELETEd (Services\GdprService's accounting/tax
     * reasoning) - sets status to 'cancelled', restores every line's
     * stock, and reverses the ledger if the order had been paid. Super
     * Admin only (AdminAuth::CAPABILITIES['orders_delete']) - the one
     * irreversible action in this whole feature, gated separately from
     * plain create/edit.
     */
    public function cancelAndRestock(Order $order, int $adminId): Order
    {
        // Idempotent no-op if already cancelled - same style as
        // Order::markPaid()'s own guard, avoids double-restocking/
        // double-refunding on a re-submitted cancel request.
        if ($order->status === 'cancelled') {
            return $order;
        }

        $items = $order->items();
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('UPDATE orders SET status = "cancelled" WHERE id = ?')->execute([$order->id]);

            $stockItems = array_map(fn ($row) => $this->toStockShape($row), $items);
            if ($stockItems) {
                $this->stock->restockForItems($stockItems, $order->orderNumber, $order->isTestOrder, $this->pdo, 'cancellation');
            }

            if ($order->paymentStatus === 'paid' && !$order->isTestOrder) {
                $this->pdo->prepare('INSERT INTO transactions (order_id, type, amount, note, created_by) VALUES (?, "refund", ?, "Order cancelled by admin", ?)')
                    ->execute([$order->id, -$order->total, $adminId]);
            }

            $this->pdo->prepare('INSERT INTO order_edit_log (order_id, admin_id, action, summary) VALUES (?, ?, "cancelled", ?)')
                ->execute([$order->id, $adminId, __('admin.orders.log.cancelled')]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        return Order::findByNumber($order->orderNumber);
    }

    /** Throws if $orderItemId already has an RMA ticket or withdrawal-request line filed against it - see order_items(id)'s ON DELETE CASCADE from both child tables in sql/schema.sql. */
    private function assertNoReturnHistory(int $orderItemId, string $productName): void
    {
        $rmaStmt = $this->pdo->prepare('SELECT COUNT(*) FROM rma_tickets WHERE order_item_id = ?');
        $rmaStmt->execute([$orderItemId]);
        if ((int)$rmaStmt->fetchColumn() > 0) {
            throw new \RuntimeException(__('admin.orders.edit.blocked_rma', ['name' => $productName]));
        }
        $wdStmt = $this->pdo->prepare('SELECT COUNT(*) FROM withdrawal_request_items WHERE order_item_id = ?');
        $wdStmt->execute([$orderItemId]);
        if ((int)$wdStmt->fetchColumn() > 0) {
            throw new \RuntimeException(__('admin.orders.edit.blocked_withdrawal', ['name' => $productName]));
        }
    }

    /** Translates a raw order_items DB row (snake_case array) into the shape Services\OrderStockService expects - see that column's docblock in sql/schema.sql for the "NULL on pre-existing orders" degrade-gracefully note. */
    private function toStockShape(array $orderItemRow): array
    {
        return [
            'product_id'       => (int)$orderItemRow['product_id'],
            'name'             => $orderItemRow['product_name'],
            'quantity'         => (int)$orderItemRow['quantity'],
            'variant_id'       => $orderItemRow['product_variant_id'] ? (int)$orderItemRow['product_variant_id'] : null,
            'option_value_ids' => !empty($orderItemRow['option_value_ids']) ? array_map('intval', explode(',', $orderItemRow['option_value_ids'])) : [],
        ];
    }

    /**
     * Parses the repeating line-item form fields (product_id[], quantity[],
     * option_value_ids[] - the last a comma-separated free-text field per
     * row, see src/Views/admin/orders/create.php's hint text) into a plain
     * array of ['product_id' => int, 'quantity' => int, 'option_value_ids' => int[], 'order_item_id' => ?int].
     * Rows with no product selected are silently skipped (an empty
     * trailing row from the JS add-row control, not a real line).
     */
    private function parseLineItems(array $post, bool $includeOrderItemId = false): array
    {
        $productIds = (array)($post['product_id'] ?? []);
        $quantities = (array)($post['quantity'] ?? []);
        $optionStrings = (array)($post['option_value_ids'] ?? []);
        $orderItemIds = (array)($post['order_item_id'] ?? []);

        $lines = [];
        foreach ($productIds as $i => $rawProductId) {
            $productId = (int)$rawProductId;
            if ($productId <= 0) {
                continue;
            }
            $quantity = max(1, (int)($quantities[$i] ?? 1));
            $optionValueIds = array_values(array_filter(array_map(
                'intval',
                array_filter(explode(',', (string)($optionStrings[$i] ?? '')), fn ($v) => trim($v) !== '')
            )));
            $line = ['product_id' => $productId, 'quantity' => $quantity, 'option_value_ids' => $optionValueIds];
            if ($includeOrderItemId) {
                $line['order_item_id'] = (int)($orderItemIds[$i] ?? 0) ?: null;
            }
            $lines[] = $line;
        }
        return $lines;
    }
}
