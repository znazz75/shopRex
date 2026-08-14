<?php
/**
 * @var bool $isSuperAdmin
 * @var int $productCount
 * @var int $lowStockCount
 * @var array $lowStock
 * @var float $revenueToday
 * @var float $revenueMonth
 * @var int $orderCount
 * @var int $pendingOrders
 * @var int $customerCount
 * @var int $testOrderCount
 * @var array $recentOrders
 */
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
          <a href="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>"><?= e($order['order_number']) ?></a>
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
        <td><a href="<?= rtrim(SITE_URL, '/') ?>/admin/products/<?= (int)$p['id'] ?>/edit"><?= e($p['name']) ?></a></td>
        <td><?= e($p['sku']) ?></td>
        <td><span class="badge badge-low"><?= (int)$p['stock_quantity'] ?></span></td>
        <td><?= (int)$p['stock_threshold'] ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($lowStock)): ?><tr><td colspan="4"><?= e(__('admin.dashboard.well_stocked')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
