<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('finance');

$pdo = db();

// Test orders (placed with an Admin -> Customers -> Test Users account) are
// excluded from every figure on this page - see checkout_process.php and
// admin/order_view.php, which never write a transactions row for them, and
// the "is_test_order = 0" filters below on the orders-table queries.
$totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0")->fetchColumn();
$totalRefunded = (float)$pdo->query("SELECT COALESCE(SUM(-amount),0) FROM transactions WHERE type = 'refund'")->fetchColumn();
$pendingPayments = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'pending' AND is_test_order = 0")->fetchColumn();
$avgOrderValue = (float)$pdo->query("SELECT COALESCE(AVG(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0")->fetchColumn();
$testOrderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE is_test_order = 1")->fetchColumn();

$monthly = $pdo->query(
    "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total) AS revenue, COUNT(*) AS orders
     FROM orders WHERE payment_status = 'paid' AND is_test_order = 0
     GROUP BY ym ORDER BY ym DESC LIMIT 6"
)->fetchAll();

$transactions = $pdo->query(
    "SELECT t.*, o.order_number, a.username AS created_by_name
     FROM transactions t
     LEFT JOIN orders o ON o.id = t.order_id
     LEFT JOIN admin_users a ON a.id = t.created_by
     ORDER BY t.created_at DESC LIMIT 30"
)->fetchAll();

$paymentMethodBreakdown = $pdo->query(
    "SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS revenue
     FROM orders WHERE payment_status = 'paid' AND is_test_order = 0 GROUP BY payment_method"
)->fetchAll();

$pageTitle = __('admin.finance');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e(__('admin.finance')) ?></h1></div>
<?php if ($testOrderCount > 0): ?>
  <div class="flash flash-info">
    <?= e(__($testOrderCount === 1 ? 'admin.finance.test_orders_excluded_one' : 'admin.finance.test_orders_excluded', ['n' => $testOrderCount])) ?>
    <a href="orders.php?type=test"><?= e(__('admin.finance.view_test_orders')) ?></a>
  </div>
<?php endif; ?>

<div class="stat-grid">
  <div class="stat-card"><div class="label"><?= e(__('admin.finance.total_revenue')) ?></div><div class="value"><?= formatPrice($totalRevenue) ?></div></div>
  <div class="stat-card"><div class="label"><?= e(__('admin.finance.pending_payments')) ?></div><div class="value"><?= formatPrice($pendingPayments) ?></div></div>
  <div class="stat-card"><div class="label"><?= e(__('admin.finance.total_refunded')) ?></div><div class="value"><?= formatPrice($totalRefunded) ?></div></div>
  <div class="stat-card"><div class="label"><?= e(__('admin.finance.avg_order_value')) ?></div><div class="value"><?= formatPrice($avgOrderValue) ?></div></div>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.finance.revenue_by_month')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.finance.month')) ?></th><th><?= e(__('admin.orders')) ?></th><th><?= e(__('admin.finance.revenue')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($monthly as $m): ?>
      <tr><td><?= e($m['ym']) ?></td><td><?= (int)$m['orders'] ?></td><td><?= formatPrice((float)$m['revenue']) ?></td></tr>
    <?php endforeach; ?>
    <?php if (empty($monthly)): ?><tr><td colspan="3"><?= e(__('admin.finance.no_paid_orders')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.finance.revenue_by_payment_method')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.order_view.method')) ?></th><th><?= e(__('admin.orders')) ?></th><th><?= e(__('admin.finance.revenue')) ?></th></tr></thead>
    <tbody>
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
        <td><?= $t['order_number'] ? '<a href="order_view.php?id=' . (int)$t['order_id'] . '">' . e($t['order_number']) . '</a>' : '-' ?></td>
        <td><?= e(ucfirst($t['type'])) ?></td>
        <td style="color: <?= $t['amount'] < 0 ? 'var(--color-error)' : 'var(--color-success)' ?>;"><?= formatPrice((float)$t['amount']) ?></td>
        <td><?= e($t['note']) ?></td>
        <td><?= e($t['created_by_name'] ?? __('admin.finance.system')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($transactions)): ?><tr><td colspan="6"><?= e(__('admin.finance.no_transactions')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
