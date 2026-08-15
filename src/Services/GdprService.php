<?php

namespace ShopRex\Services;

/**
 * Customer-data export/erasure (GDPR "right to access"/"right to
 * erasure") plus the inactivity-cleanup sweep that automatically erases
 * long-dormant accounts. Independent, from-scratch reimplementation of
 * what were originally two separate procedural files - not a thin
 * wrapper around anything else.
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

    /** Builds a "data export" package for one customer (their profile, saved addresses, and full order history with line items) - the customer's self-service "download my data" GDPR right, and also usable by an admin on the customer's behalf. */
    public function exportData(int $customerId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, first_name, last_name, email, phone, language, status, created_at FROM customers WHERE id = ?');
        $stmt->execute([$customerId]);
        $customer = $stmt->fetch();
        // No such customer - nothing to export.
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

        // Attach each order's line items individually (one query per order,
        // since order counts per customer are small) and drop the internal
        // numeric 'id' from the exported order - it's a database
        // implementation detail the customer has no use for, unlike the
        // human-facing order_number which is kept.
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

    /** Permanently erases a customer's personal data - the GDPR "right to erasure" / "right to be forgotten" path. Deliberately does NOT delete the orders themselves (see class docblock: totals/line items must be kept for accounting/tax law), it only scrubs the personally-identifying fields on them before hard-deleting the customer row itself. */
    public function deleteCustomer(int $customerId): void
    {
        // Wrapped in a transaction so a failure partway through (e.g. the
        // orders UPDATE succeeds but the customers DELETE fails) can't leave
        // the data in a half-scrubbed state - either both steps happen or
        // neither does.
        $this->pdo->beginTransaction();
        try {
            // Scrubs personally-identifying fields on the customer's past
            // orders (name, address, notes, guest email) while deliberately
            // leaving totals/line items/status intact - see class docblock
            // for the GDPR Art. 17(3)(b) reasoning.
            $this->pdo->prepare(
                "UPDATE orders SET shipping_name = 'Deleted Customer', shipping_address1 = '', shipping_address2 = NULL,
                 shipping_city = '', shipping_postal_code = '', shipping_state = NULL, customer_notes = NULL, guest_email = NULL
                 WHERE customer_id = ?"
            )->execute([$customerId]);

            // customer_addresses cascade-deletes via its FK.
            $this->pdo->prepare('DELETE FROM customers WHERE id = ?')->execute([$customerId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            // Undo the partial UPDATE above if the DELETE (or anything else)
            // throws, then re-throw so the caller still finds out it failed.
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
        // Admin-configurable "how many months of inactivity before we start
        // the deletion process" - floored at 4 months since the process
        // itself takes 3 months from warning to deletion (see below), so
        // anything less wouldn't leave any inactivity window before warning.
        $months = max(4, (int)$this->settings->get('gdpr_inactivity_months', '24'));

        // Customers are warned 3 months before the full inactivity period is
        // up, so the cutoff for "who gets warned now" is $months minus that
        // 3-month notice period.
        $warnCutoff = date('Y-m-d H:i:s', strtotime('-' . ($months - 3) . ' months'));
        // Finds active, non-test customers who haven't already been warned
        // and whose most recent activity - GREATEST of their last login,
        // their signup date, and their most recent order - is older than the
        // cutoff. Using GREATEST() of multiple activity signals (rather than
        // just last_login_at) means a customer who orders as a guest, or
        // never logs back in but still has recent orders, isn't wrongly
        // flagged as inactive.
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
            // The warning email tells the customer the exact future date
            // their account will be deleted if they don't come back.
            $deletionDate = I18n::formatLocalDate(date('Y-m-d', strtotime('+3 months')), false, $customer['language'] ?? 'en');
            // Only marks the customer as warned if the email actually sent -
            // if delivery fails, they'll be picked up again on the next run
            // instead of silently being marked warned with no email received.
            if (Mailer::sendAccountDeletionWarning($customer, $deletionDate)) {
                $this->pdo->prepare('UPDATE customers SET deletion_warning_sent_at = NOW() WHERE id = ?')->execute([$customer['id']]);
                $warned++;
            }
        }

        // Anyone warned more than 3 months ago and still inactive/hasn't
        // logged back in (which would have reset deletion_warning_sent_at
        // elsewhere) gets actually deleted now.
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
