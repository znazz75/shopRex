<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('dashboard');

$pdo = db();
$admin = currentAdmin();
$isSuperAdmin = adminCan($admin, 'finance');

$productCount = (int)$pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
$lowStockCount = (int)$pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity <= stock_threshold')->fetchColumn();
$lowStock = $pdo->query(
    'SELECT id, name, sku, stock_quantity, stock_threshold FROM products WHERE stock_quantity <= stock_threshold ORDER BY stock_quantity ASC LIMIT 8'
)->fetchAll();

if ($isSuperAdmin) {
    // Test orders/accounts (Admin -> Customers -> Test Users) are excluded
    // from every figure here, same as admin/finance.php.
    $revenueToday = (float)$pdo->query(
        "SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0 AND DATE(created_at) = CURDATE()"
    )->fetchColumn();
    $revenueMonth = (float)$pdo->query(
        "SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0 AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())"
    )->fetchColumn();
    $orderCount = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE is_test_order = 0')->fetchColumn();
    $pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND is_test_order = 0")->fetchColumn();
    $customerCount = (int)$pdo->query('SELECT COUNT(*) FROM customers WHERE is_test_account = 0')->fetchColumn();
    $testOrderCount = (int)$pdo->query('SELECT COUNT(*) FROM orders WHERE is_test_order = 1')->fetchColumn();
    $recentOrders = $pdo->query(
        "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
         FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
         ORDER BY o.created_at DESC LIMIT 8"
    )->fetchAll();
}

$pageTitle = __('admin.dashboard');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e(__('admin.dashboard')) ?></h1></div>

<div class="stat-grid">
  <?php if ($isSuperAdmin): ?>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.revenue_today')) ?></div><div class="value"><?= formatPrice($revenueToday) ?></div></div>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.revenue_month')) ?></div><div class="value"><?= formatPrice($revenueMonth) ?></div></div>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.total_orders')) ?></div><div class="value"><?= $orderCount ?></div></div>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.pending_orders')) ?></div><div class="value"><?= $pendingOrders ?></div></div>
    <div class="stat-card"><div class="label"><?= e(__('admin.customers')) ?></div><div class="value"><?= $customerCount ?></div></div>
  <?php endif; ?>
  <div class="stat-card"><div class="label"><?= e(__('admin.products')) ?></div><div class="value"><?= $productCount ?></div></div>
  <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.low_stock_items')) ?></div><div class="value"><?= $lowStockCount ?></div></div>
  <?php if ($isSuperAdmin && $testOrderCount > 0): ?>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.test_orders')) ?></div><div class="value"><?= $testOrderCount ?></div></div>
  <?php endif; ?>
</div>

<?php if ($isSuperAdmin): ?>
<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.dashboard.recent_orders')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.dashboard.order_number')) ?></th><th><?= e(__('admin.dashboard.customer')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('order.payment')) ?></th><th><?= e(__('common.total')) ?></th><th><?= e(__('common.date')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($recentOrders as $order): ?>
      <tr>
        <td>
          <a href="order_view.php?id=<?= (int)$order['id'] ?>"><?= e($order['order_number']) ?></a>
          <?php if ($order['is_test_order']): ?> <span class="badge badge-pending"><?= e(__('admin.dashboard.test_badge')) ?></span><?php endif; ?>
        </td>
        <td><?= e($order['customer_email']) ?></td>
        <td><span class="badge badge-<?= e($order['status']) ?>"><?= e($order['status']) ?></span></td>
        <td><span class="badge badge-<?= e($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></td>
        <td><?= formatPrice((float)$order['total']) ?></td>
        <td><?= e(formatLocalDate($order['created_at'], true)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($recentOrders)): ?><tr><td colspan="6"><?= e(__('admin.dashboard.no_orders_yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.dashboard.low_stock_alerts')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.products')) ?></th><th><?= e(__('admin.dashboard.sku')) ?></th><th><?= e(__('admin.dashboard.stock')) ?></th><th><?= e(__('admin.dashboard.threshold')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($lowStock as $p): ?>
      <tr>
        <td><a href="product_edit.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a></td>
        <td><?= e($p['sku']) ?></td>
        <td><span class="badge badge-low"><?= (int)$p['stock_quantity'] ?></span></td>
        <td><?= (int)$p['stock_threshold'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($lowStock)): ?><tr><td colspan="4"><?= e(__('admin.dashboard.well_stocked')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
