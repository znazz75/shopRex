<?php
/**
 * Admin -> Orders: the order list/search page (linked from the dashboard's
 * "recent orders" and from Finance's test-orders banner too, both via the
 * ?status=/?type= query params this page's own filter form also writes).
 *
 * @var array  $orders       The (already filtered) order rows to display - customer_email, status, payment_status, total, is_test_order, etc.
 * @var array  $statuses     Every possible order status value (e.g. 'pending', 'processing', 'completed'), for the status filter dropdown - looked up via 'admin.orders.status_'.$s translation keys.
 * @var string $statusFilter Which status is currently filtered to ('' = all statuses).
 * @var string $typeFilter   Which order "type" is currently filtered to: 'all', 'real' (excludes test orders), or 'test' (test orders only) - see CLAUDE.md's "Test accounts" section for what a test order is.
 */
?>
<div class="page-header"><h1><?= e(__('admin.orders')) ?></h1></div>

<?php /* Both dropdowns auto-submit the form on change (onchange="this.form.submit()") - there's no separate "Apply" button, picking a filter value immediately reloads the page with the new query string. */ ?>
<form class="toolbar" method="get">
  <select name="status" onchange="this.form.submit()">
    <option value=""><?= e(__('admin.orders.all_statuses')) ?></option>
    <?php foreach ($statuses as $s): ?>
      <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(__('admin.orders.status_' . $s)) ?></option>
    <?php endforeach; ?>
  </select>
  <select name="type" onchange="this.form.submit()">
    <option value="all" <?= $typeFilter === 'all' ? 'selected' : '' ?>><?= e(__('admin.orders.all_orders')) ?></option>
    <option value="real" <?= $typeFilter === 'real' ? 'selected' : '' ?>><?= e(__('admin.orders.real_only')) ?></option>
    <option value="test" <?= $typeFilter === 'test' ? 'selected' : '' ?>><?= e(__('admin.orders.test_only')) ?></option>
  </select>
</form>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.dashboard.order_number')) ?></th><th><?= e(__('admin.dashboard.customer')) ?></th><th><?= e(__('admin.orders.payment_method')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('order.payment')) ?></th><th><?= e(__('common.total')) ?></th><th><?= e(__('common.date')) ?></th></tr></thead>
  <tbody>
  <?php foreach ($orders as $order): ?>
    <tr>
      <td>
        <a href="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>"><?= e($order['order_number']) ?></a>
        <?php /* Visual flag for test orders, same badge as the dashboard's recent-orders table - only relevant when $typeFilter is 'all' or 'test' (real-only filtering means this will never actually trigger). */ ?>
        <?php if ($order['is_test_order']): ?> <span class="badge badge-pending"><?= e(__('admin.dashboard.test_badge')) ?></span><?php endif; ?>
      </td>
      <td><?= e($order['customer_email']) ?></td>
      <?php /* Same raw-code-to-friendly-label formatting as Finance's payment-method table: "bank_transfer" -> "Bank Transfer". */ ?>
      <td><?= e(ucwords(str_replace('_', ' ', $order['payment_method']))) ?></td>
      <td><span class="badge badge-<?= e($order['status']) ?>"><?= e($order['status']) ?></span></td>
      <td><span class="badge badge-<?= e($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></td>
      <td><?= formatPrice((float)$order['total']) ?></td>
      <td><?= e(formatLocalDate($order['created_at'], true)) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php /* Empty-state row when no orders match the current filters (or there are simply no orders yet). */ ?>
  <?php if (empty($orders)): ?><tr><td colspan="7"><?= e(__('admin.orders.none_found')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
