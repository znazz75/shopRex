<?php

namespace ShopRex\Services;

/**
 * Direct port of includes/GdprTools.php (exportData/deleteCustomer) and
 * includes/GdprCleanup.php (runInactivityCleanup) - unlike InvoiceGenerator/
 * Mailer (see src/container.php's docblock on why those stay as-is for
 * now), these two are small and self-contained enough to fully port in
 * this same pass rather than deferring.
 *
 * Deletion note (preserved from the original docblock): order rows are
 * NOT deleted - shipping_name/address/notes on them are scrubbed, but
 * totals/line items stay so accounting/tax records remain intact (a
 * common reading of GDPR Art. 17(3)(b)). The customers row itself, and
 * its addresses, are hard-deleted.
 */
final class GdprService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly SettingsRepository $settings,
    ) {
    }

    public function exportData(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, first_name, last_name, email, phone, language, status, created_at FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        if (!$customer) {
            return null;
        }

        $addrStmt = $this->pdo->prepare(
            'SELECT type, full_name, address_line1, address_line2, city, state, postal_code, country, is_default
             FROM customer_addresses WHERE customer_id = ?'
        );
        $addrStmt->execute([$customerId]);
        $addresses = $addrStmt->fetchAll();

        $orderStmt = $this->pdo->prepare(
            'SELECT id, order_number, status, payment_method, payment_status, subtotal, shipping_cost, tax_total, total,
                    shipping_name, shipping_address1, shipping_address2, shipping_city, shipping_postal_code, shipping_country,
                    customer_notes, created_at
             FROM orders WHERE customer_id = ? ORDER BY created_at DESC'
        );
        $orderStmt->execute([$customerId]);
        $orders = $orderStmt->fetchAll();

        foreach ($orders as &$order) {
            $itemStmt = $this->pdo->prepare('SELECT product_name, option_summary, sku, quantity, unit_price, total_price FROM order_items WHERE order_id = ?');
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

    public function deleteCustomer(int $customerId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                "UPDATE orders SET shipping_name = 'Deleted Customer', shipping_address1 = '', shipping_address2 = NULL,
                 shipping_city = '', shipping_postal_code = '', shipping_state = NULL, customer_notes = NULL, guest_email = NULL
                 WHERE customer_id = ?"
            )->execute([$customerId]);

            // customer_addresses cascade-deletes via its FK.
            $this->pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Automated inactivity cleanup - see includes/GdprCleanup.php's
     * original docblock for the full warn-then-delete rationale. Run from
     * admin/cron/gdpr_cleanup.php or on demand from Admin -> Settings.
     */
    public function runInactivityCleanup(): array
    {
        $months = max(4, (int)$this->settings->get('gdpr_inactivity_months', '24'));

        $warnCutoff = date('Y-m-d H:i:s', strtotime('-' . ($months - 3) . ' months'));
        $toWarn = $this->pdo->prepare(
            "SELECT c.* FROM customers c
             WHERE c.is_test_account = 0 AND c.status = 'active' AND c.deletion_warning_sent_at IS NULL
               AND GREATEST(
                     COALESCE(c.last_login_at, c.created_at),
                     c.created_at,
                     COALESCE((SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.id), c.created_at)
                   ) < ?"
        );
        $toWarn->execute([$warnCutoff]);

        $warned = 0;
        foreach ($toWarn->fetchAll() as $customer) {
            $deletionDate = I18n::formatLocalDate(date('Y-m-d', strtotime('+3 months')), false, $customer['language'] ?? 'en');
            if (\Mailer::sendAccountDeletionWarning($customer, $deletionDate)) {
                $this->pdo->prepare('UPDATE customers SET deletion_warning_sent_at = NOW() WHERE id = ?')->execute([$customer['id']]);
                $warned++;
            }
        }

        $deleteCutoff = date('Y-m-d H:i:s', strtotime('-3 months'));
        $toDelete = $this->pdo->prepare(
            "SELECT id FROM customers WHERE is_test_account = 0 AND deletion_warning_sent_at IS NOT NULL AND deletion_warning_sent_at < ?"
        );
        $toDelete->execute([$deleteCutoff]);

        $deleted = 0;
        foreach ($toDelete->fetchAll() as $row) {
            $this->deleteCustomer((int)$row['id']);
            $deleted++;
        }

        return ['warned' => $warned, 'deleted' => $deleted, 'checked_at' => date('c')];
    }
}
