<?php

namespace ShopRex\Services;

/**
 * Sends a "your order is still unpaid" reminder email a configurable
 * number of days after an order is placed (Admin -> Settings -> Payment
 * Reminders: days threshold + an automatic/manual on-off toggle), for the
 * two payment methods with no gateway auto-capture - bank_transfer/
 * invoice. PayPal/credit-card orders settle via a gateway callback
 * (Payment\CapturableGateway), so a reminder wouldn't make sense there.
 * One reminder per order (not recurring) - orders.payment_reminder_sent_at
 * is stamped on every send, manual or automatic, and both entry points
 * below funnel through the same sendReminder() so they can never drift
 * into two different implementations. Same dual-entry-point shape as
 * Services\GdprService::runInactivityCleanup() (daily cron +
 * admin-triggered "run now" both call one shared method).
 */
final class PaymentReminderService
{
    public function __construct(
        private readonly \PDO $pdo,
        private readonly SettingsRepository $settings,
    ) {
    }

    /**
     * Sends the reminder email for one order and stamps
     * payment_reminder_sent_at regardless of whether the send itself
     * succeeded (a permanently-failing mailbox shouldn't make the
     * automatic job retry the same order forever - the order page still
     * shows the attempt happened, and a manager can always trigger
     * another manual send).
     */
    public function sendReminder(array $order): bool
    {
        // Whole days elapsed since the order was placed - shown in the
        // email itself (the {{days_since_order}} token).
        $daysSinceOrder = (int)floor((time() - strtotime((string)$order['created_at'])) / 86400);
        $sent = Mailer::sendPaymentReminder($order, $daysSinceOrder);
        $this->pdo->prepare('UPDATE orders SET payment_reminder_sent_at = NOW() WHERE id = ?')->execute([$order['id']]);
        return $sent;
    }

    /**
     * Cron entry point (admin/cron/payment_reminders.php) - no-ops
     * immediately unless an admin has explicitly opted into automatic
     * sending, so the crontab line never needs editing when that setting
     * is later toggled on/off; the cron can just run daily regardless.
     */
    public function runAutomaticReminders(): array
    {
        $checkedAt = date('c');
        if ($this->settings->get('payment_reminder_auto_send', '0') !== '1') {
            return ['checked_at' => $checkedAt, 'enabled' => false, 'checked' => 0, 'sent' => 0];
        }

        // Clamped to a 1-day minimum, same defensive reasoning as
        // GdprService's inactivity-months floor.
        $days = max(1, (int)$this->settings->get('payment_reminder_days', '7'));

        // Same COALESCE(c.email, o.guest_email) join every other order
        // query in this app uses (see Models\Order::findByNumber()/
        // Controllers\Admin\OrderAdminController) - an order's customer_id
        // can be null for a guest checkout.
        $stmt = $this->pdo->prepare(
            "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.payment_status = 'pending'
               AND o.payment_method IN ('bank_transfer', 'invoice')
               AND o.is_test_order = 0
               AND o.payment_reminder_sent_at IS NULL
               AND o.created_at <= NOW() - INTERVAL ? DAY"
        );
        $stmt->execute([$days]);
        $orders = $stmt->fetchAll();

        $sent = 0;
        foreach ($orders as $order) {
            if ($this->sendReminder($order)) {
                $sent++;
            }
        }

        return ['checked_at' => $checkedAt, 'enabled' => true, 'checked' => count($orders), 'sent' => $sent];
    }
}
