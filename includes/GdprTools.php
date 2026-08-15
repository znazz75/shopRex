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
 *
 * Why this class still exists as-is: one of the "legacy classes kept
 * as-is" (see CLAUDE.md) - `Services\GdprService` is a direct, fully
 * ported version of this exact logic (exportData()/deleteCustomer()) for
 * use by the new OOP admin/storefront controllers, but this original class
 * remains in use as-is too, because admin/cron/gdpr_cleanup.php (a
 * standalone CLI script run from a real system cron job, outside of any
 * web request/Container) requires it directly, and includes/GdprCleanup.php
 * (the automated inactivity sweep) calls GdprTools::deleteCustomer() below
 * directly rather than through the Services layer.
 */
class GdprTools
{
    /**
     * Builds the full "everything we know about you" export for one
     * customer - their profile, saved addresses, and order history
     * (including each order's line items) - as a plain array ready to be
     * turned into a downloadable file (e.g. JSON) for the "right to access
     * / data portability" request. Returns null if the customer id doesn't
     * exist.
     */
    public static function exportData(int $customerId): ?array
    {
        $pdo = db();
        // Only the fields a customer would actually want back - not
        // internal bookkeeping columns like password hashes.
        $stmt = $pdo->prepare('SELECT id, first_name, last_name, email, phone, language, status, created_at FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            // No such customer - nothing to export.
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

        // Attach each order's line items, and drop the internal numeric
        // `id` afterwards - it's a database implementation detail with no
        // meaning to the customer receiving this export, and order_number
        // already identifies the order in a human-readable way.
        foreach ($orders as &$order) {
            $itemStmt = $pdo->prepare('SELECT product_name, option_summary, sku, quantity, unit_price, total_price FROM order_items WHERE order_id = ?');
            $itemStmt->execute([$order['id']]);
            $order['items'] = $itemStmt->fetchAll();
            unset($order['id']);
        }
        // Break the by-reference loop variable so a later foreach over
        // $orders elsewhere can't accidentally overwrite the last order
        // through a leftover reference (see the same pattern/reasoning in
        // includes/Cart.php's getActiveShippingMethods()).
        unset($order);

        return [
            'exported_at' => date('c'),
            'profile'     => $customer,
            'addresses'   => $addresses,
            'orders'      => $orders,
        ];
    }

    /**
     * Permanently anonymises and removes one customer's personal data
     * ("right to erasure"). Runs as a single all-or-nothing database
     * transaction so a mid-way failure (e.g. a lost DB connection) can
     * never leave the customer half-scrubbed, half-intact.
     */
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

            // Both statements succeeded - make the erasure permanent.
            $pdo->commit();
        } catch (Throwable $e) {
            // Something went wrong partway through - undo everything above
            // rather than leaving the customer in an inconsistent
            // half-scrubbed state, then re-throw so the caller knows it failed.
            $pdo->rollBack();
            throw $e;
        }
    }
}
