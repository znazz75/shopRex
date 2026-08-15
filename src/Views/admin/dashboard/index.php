<?php
/**
 * Admin -> Dashboard (the landing page after login). Shows a grid of
 * summary stat cards plus two small tables (recent orders, low-stock
 * products). Financial/order figures below are only ever computed for
 * real (non-test) orders - see CLAUDE.md's "Test accounts" section: any
 * order placed by an `is_test_account` customer is tagged
 * `is_test_order = 1` and excluded from every financial figure, including
 * these dashboard numbers.
 *
 * @var bool  $isSuperAdmin   True for the 'super_admin' role, false for
 *                             'manager'. Gates the money/order stats and the
 *                             recent-orders table below, since 'finance' is
 *                             a capability managers don't have (see
 *                             CLAUDE.md's Core\Auth\AdminAuth section) -
 *                             a manager only ever sees the
 *                             products/low-stock cards.
 * @var int   $productCount   Total number of products in the catalog.
 * @var int   $lowStockCount  Count of products at/below their stock threshold.
 * @var array $lowStock       The actual low-stock product rows (id, name, sku, stock_quantity, stock_threshold), for the table below.
 * @var float $revenueToday   Sum of today's real (non-test) order totals.
 * @var float $revenueMonth   Sum of this calendar month's real order totals.
 * @var int   $orderCount     Total count of real orders.
 * @var int   $pendingOrders  Count of real orders still awaiting fulfillment.
 * @var int   $customerCount  Total registered customers.
 * @var int   $testOrderCount How many test orders exist (only surfaced as an FYI card - never mixed into the revenue/order figures above).
 * @var array $recentOrders   The most recent handful of real orders, for the table below.
 */
?>
<div class="page-header"><h1><?= e(__('admin.dashboard')) ?></h1></div>

<div class="stat-grid">
  <?php /* Money and order-volume figures are hidden from 'manager' accounts entirely - they only see product/stock cards below. */ ?>
  <?php if ($isSuperAdmin): ?>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.revenue_today')) ?></div><div class="value"><?= formatPrice($revenueToday) ?></div></div>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.revenue_month')) ?></div><div class="value"><?= formatPrice($revenueMonth) ?></div></div>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.total_orders')) ?></div><div class="value"><?= $orderCount ?></div></div>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.pending_orders')) ?></div><div class="value"><?= $pendingOrders ?></div></div>
    <div class="stat-card"><div class="label"><?= e(__('admin.customers')) ?></div><div class="value"><?= $customerCount ?></div></div>
  <?php endif; ?>
  <div class="stat-card"><div class="label"><?= e(__('admin.products')) ?></div><div class="value"><?= $productCount ?></div></div>
  <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.low_stock_items')) ?></div><div class="value"><?= $lowStockCount ?></div></div>
  <?php /* Only shown when there actually are test orders, and only to super admins - it's just an informational count, not a figure meant to be acted on. */ ?>
  <?php if ($isSuperAdmin && $testOrderCount > 0): ?>
    <div class="stat-card"><div class="label"><?= e(__('admin.dashboard.test_orders')) ?></div><div class="value"><?= $testOrderCount ?></div></div>
  <?php endif; ?>
</div>

<?php /* Recent-orders table: super admin only, same reasoning as the stat cards above. */ ?>
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
          <?php /* Belt-and-suspenders label: $recentOrders is expected to already exclude test orders, but this badge would still flag one if it ever slipped through. */ ?>
          <?php if ($order['is_test_order']): ?> <span class="badge badge-pending"><?= e(__('admin.dashboard.test_badge')) ?></span><?php endif; ?>
        </td>
        <td><?= e($order['customer_email']) ?></td>
        <td><span class="badge badge-<?= e($order['status']) ?>"><?= e($order['status']) ?></span></td>
        <td><span class="badge badge-<?= e($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></td>
        <td><?= formatPrice((float)$order['total']) ?></td>
        <td><?= e(formatLocalDate($order['created_at'], true)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php /* Friendly empty-state row instead of a blank table when there are no orders yet. */ ?>
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
    <?php /* Empty-state row shown when nothing is low on stock. */ ?>
    <?php if (empty($lowStock)): ?><tr><td colspan="4"><?= e(__('admin.dashboard.well_stocked')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>
