<?php
/**
 * Data export ("right to access/portability") and account deletion ("right
 * to erasure") shared by the admin back office (admin/customer_view.php)
 * and the customer's own account page (account_export.php/account_delete.php).
 *
 * Deletion note: order rows are NOT deleted - shipping_name/address/notes
 * on them are scrubbed, but the order totals/line items stay so accounting
 * and tax records remain intact (a common reading of GDPR Art. 17(3)(b),
 * which exempts data needed to meet a legal retention obligation). The
 * customers row itself, and its addresses, are hard-deleted. If your
 * jurisdiction's retention rules differ, adjust deleteCustomer() accordingly.
 */
class GdprTools
{
    public static function exportData(int $customerId): ?array
    {
        $pdo = db();
        $stmt = $pdo->prepare('SELECT id, first_name, last_name, email, phone, language, status, created_at FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            return null;
        }

        $addrStmt = $pdo->prepare(
            'SELECT type, full_name, address_line1, address_line2, city, state, postal_code, country, is_default
             FROM customer_addresses WHERE customer_id = ?'
        );
        $addrStmt->execute([$customerId]);
        $addresses = $addrStmt->fetchAll();

        $orderStmt = $pdo->prepare(
            'SELECT id, order_number, status, payment_method, payment_status, subtotal, shipping_cost, tax_total, total,
                    shipping_name, shipping_address1, shipping_address2, shipping_city, shipping_postal_code, shipping_country,
                    customer_notes, created_at
             FROM orders WHERE customer_id = ? ORDER BY created_at DESC'
        );
        $orderStmt->execute([$customerId]);
        $orders = $orderStmt->fetchAll();

        foreach ($orders as &$order) {
            $itemStmt = $pdo->prepare('SELECT product_name, option_summary, sku, quantity, unit_price, total_price FROM order_items WHERE order_id = ?');
            $itemStmt->execute([$order['id']]);
            $order['items'] = $itemStmt->fetchAll();
            unset($order['id']);
        }
        unset($order);

        return [
            'exported_at' => date('c'),
            'profile'     => $customer,
            'addresses'   => $addresses,
            'orders'      => $orders,
        ];
    }

    public static function deleteCustomer(int $customerId): void
    {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            // Scrub personal data from their orders (financial totals and
            // line items are kept - see class docblock) before the FK's
            // ON DELETE SET NULL disconnects them from the customer row.
            $pdo->prepare(
                "UPDATE orders SET shipping_name = 'Deleted Customer', shipping_address1 = '', shipping_address2 = NULL,
                 shipping_city = '', shipping_postal_code = '', shipping_state = NULL, customer_notes = NULL, guest_email = NULL
                 WHERE customer_id = ?"
            )->execute([$customerId]);

            // customer_addresses cascade-deletes via its FK.
            $pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
