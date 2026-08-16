<?php
/**
 * Admin -> Finance: read-only revenue/payments dashboard. Reachable only
 * by admins with the 'finance' capability (super_admin only - a manager
 * never sees this in the nav, see CLAUDE.md's Core\Auth\AdminAuth
 * section). Every figure on this page (revenue, refunds, transactions,
 * etc.) is computed from real orders only - test orders (placed by
 * `is_test_account` customers, see CLAUDE.md's "Test accounts" section)
 * are deliberately excluded everywhere here, with just a small
 * informational banner noting how many were left out.
 *
 * @var float $totalRevenue           Sum of all completed/paid real orders.
 * @var float $totalRefunded          Sum of all refund amounts issued against real orders.
 * @var float $pendingPayments        Sum of real orders still awaiting payment.
 * @var float $avgOrderValue          Average order total across real orders.
 * @var int   $testOrderCount         How many test orders exist (shown only as an informational banner - never included in the figures above).
 * @var array $monthly                Revenue/order-count grouped by calendar month ('ym', 'orders', 'revenue'), for the "Revenue by Month" table.
 * @var array $transactions           The raw payment/refund ledger rows (one row per money-moving event), for the "Transaction Ledger" table at the bottom.
 * @var array $paymentMethodBreakdown Revenue/order-count grouped by payment method ('payment_method', 'cnt', 'revenue'), for the "Revenue by Payment Method" table.
 * @var array $reportYears            Every year with at least one paid, non-test order (newest first), for the Annual Report card's year picker.
 * @var array $overdueUnpaidOrders    v3.10 - orders past the payment-reminder days threshold, still unpaid, bank_transfer/invoice only (id, order_number, created_at, total, payment_method, payment_reminder_sent_at, customer_email) - visibility only, the actual "send" action lives on each order's own detail page.
 * @var int   $reminderDays           The configured payment-reminder days threshold (Admin -> Settings -> Payment Reminders), shown in this card's heading/hint.
 */
?>
<div class="page-header"><h1><?= e(__('admin.finance')) ?></h1></div>
<?php /* Informational-only banner (never affects any figure below) - links off to the orders list filtered to test orders, in case the admin wants to actually look at them. Uses a singular vs. plural i18n key depending on count. */ ?>
<?php if ($testOrderCount > 0): ?>
  <div class="flash flash-info">
    <?= e(__($testOrderCount === 1 ? 'admin.finance.test_orders_excluded_one' : 'admin.finance.test_orders_excluded', ['n' => $testOrderCount])) ?>
    <a href="<?= rtrim(SITE_URL, '/') ?>/admin/orders?type=test"><?= e(__('admin.finance.view_test_orders')) ?></a>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat-card"><div class="label"><?= e(__('admin.finance.total_revenue')) ?></div><div class="value"><?= formatPrice($totalRevenue) ?></div></div>
  <div class="stat-card"><div class="label"><?= e(__('admin.finance.pending_payments')) ?></div><div class="value"><?= formatPrice($pendingPayments) ?></div></div>
  <div class="stat-card"><div class="label"><?= e(__('admin.finance.total_refunded')) ?></div><div class="value"><?= formatPrice($totalRefunded) ?></div></div>
  <div class="stat-card"><div class="label"><?= e(__('admin.finance.avg_order_value')) ?></div><div class="value"><?= formatPrice($avgOrderValue) ?></div></div>
</div>

<?php /* v3.10 - Services\PaymentReminderService. Visibility only - no send button here, the one "send a reminder" action lives on each order's own detail page (Controllers\Admin\OrderAdminController::sendPaymentReminderNow()), so there's exactly one code path that can trigger it. */ ?>
<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.finance.overdue_orders.heading', ['days' => $reminderDays])) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.finance.overdue_orders.hint')) ?></p>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.finance.order')) ?></th><th><?= e(__('common.date')) ?></th><th><?= e(__('admin.order_view.method')) ?></th><th><?= e(__('admin.order_view.amount')) ?></th><th><?= e(__('admin.orders.payment_reminder.submit')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($overdueUnpaidOrders as $o): ?>
      <tr>
        <td><a href="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$o['id'] ?>"><?= e($o['order_number']) ?></a></td>
        <td><?= e(formatLocalDate($o['created_at'])) ?></td>
        <td><?= e(ucwords(str_replace('_', ' ', $o['payment_method']))) ?></td>
        <td><?= formatPrice((float)$o['total']) ?></td>
        <td><?= !empty($o['payment_reminder_sent_at']) ? e(formatLocalDate($o['payment_reminder_sent_at'])) : '-' ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($overdueUnpaidOrders)): ?><tr><td colspan="5"><?= e(__('admin.finance.overdue_orders.none')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.finance.annual_report.heading')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.finance.annual_report.hint')) ?></p>
  <?php if (empty($reportYears)): ?>
    <p style="color:var(--color-muted);"><?= e(__('admin.finance.annual_report.no_years')) ?></p>
  <?php else: ?>
    <form method="get" action="<?= rtrim(SITE_URL, '/') ?>/admin/finance/annual-report" target="_blank" style="display:flex;gap:10px;align-items:flex-end;">
      <div class="form-group" style="margin-bottom:0;">
        <label for="report_year"><?= e(__('admin.finance.annual_report.year')) ?></label>
        <select id="report_year" name="year">
          <?php foreach ($reportYears as $y): ?>
            <option value="<?= (int)$y ?>"><?= (int)$y ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-sm" type="submit"><?= e(__('admin.finance.annual_report.submit')) ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.finance.revenue_by_month')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.finance.month')) ?></th><th><?= e(__('admin.orders')) ?></th><th><?= e(__('admin.finance.revenue')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($monthly as $m): ?>
      <tr><td><?= e($m['ym']) ?></td><td><?= (int)$m['orders'] ?></td><td><?= formatPrice((float)$m['revenue']) ?></td></tr>
    <?php endforeach; ?>
    <?php /* Empty-state row for a shop with no paid orders yet. */ ?>
    <?php if (empty($monthly)): ?><tr><td colspan="3"><?= e(__('admin.finance.no_paid_orders')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.finance.revenue_by_payment_method')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.order_view.method')) ?></th><th><?= e(__('admin.orders')) ?></th><th><?= e(__('admin.finance.revenue')) ?></th></tr></thead>
    <tbody>
    <?php /* payment_method is a raw internal code like "bank_transfer" or "credit_card" - ucwords(str_replace(...)) turns it into a human-friendly "Bank Transfer" for display only, the underlying value is untouched. */ ?>
    <?php foreach ($paymentMethodBreakdown as $m): ?>
      <tr><td><?= e(ucwords(str_replace('_', ' ', $m['payment_method']))) ?></td><td><?= (int)$m['cnt'] ?></td><td><?= formatPrice((float)$m['revenue']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($paymentMethodBreakdown)): ?><tr><td colspan="3"><?= e(__('admin.finance.no_paid_orders')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.finance.transaction_ledger')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('common.date')) ?></th><th><?= e(__('admin.finance.order')) ?></th><th><?= e(__('admin.finance.type')) ?></th><th><?= e(__('admin.order_view.amount')) ?></th><th><?= e(__('admin.finance.note')) ?></th><th><?= e(__('admin.finance.by')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($transactions as $t): ?>
      <tr>
        <td><?= e(formatLocalDate($t['created_at'], true)) ?></td>
        <?php /* Only render a link when the transaction is actually tied to an order (order_number present) - some ledger entries may not be, hence the fallback dash. */ ?>
        <td><?= $t['order_number'] ? '<a href="' . rtrim(SITE_URL, '/') . '/admin/orders/' . (int)$t['order_id'] . '">' . e($t['order_number']) . '</a>' : '-' ?></td>
        <td><?= e(ucfirst($t['type'])) ?></td>
        <?php /* Color-codes the amount: negative (refunds/deductions) in the error color, positive (payments) in the success color - a quick visual cue on top of the sign itself. */ ?>
        <td style="color: <?= $t['amount'] < 0 ? 'var(--color-error)' : 'var(--color-success)' ?>;"><?= formatPrice((float)$t['amount']) ?></td>
        <td><?= e($t['note']) ?></td>
        <?php /* Some ledger entries are system-generated (e.g. an automatic gateway callback) rather than performed by a named admin, hence the fallback label. */ ?>
        <td><?= e($t['created_by_name'] ?? __('admin.finance.system')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($transactions)): ?><tr><td colspan="6"><?= e(__('admin.finance.no_transactions')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
