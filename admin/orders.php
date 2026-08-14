<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('orders');

$statusFilter = $_GET['status'] ?? '';
$typeFilter = in_array($_GET['type'] ?? '', ['all', 'real', 'test'], true) ? $_GET['type'] : 'all';

$where = [];
$params = [];
if ($statusFilter !== '') {
    $where[] = 'o.status = ?';
    $params[] = $statusFilter;
}
if ($typeFilter === 'real') {
    $where[] = 'o.is_test_order = 0';
} elseif ($typeFilter === 'test') {
    $where[] = 'o.is_test_order = 1';
}

$sql = "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
        FROM orders o LEFT JOIN customers c ON c.id = o.customer_id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY o.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

$statuses = ['pending', 'processing', 'paid', 'shipped', 'completed', 'cancelled', 'refunded'];

$pageTitle = __('admin.orders');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e(__('admin.orders')) ?></h1></div>

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
        <a href="order_view.php?id=<?= (int)$order['id'] ?>"><?= e($order['order_number']) ?></a>
        <?php if ($order['is_test_order']): ?> <span class="badge badge-pending"><?= e(__('admin.dashboard.test_badge')) ?></span><?php endif; ?>
      </td>
      <td><?= e($order['customer_email']) ?></td>
      <td><?= e(ucwords(str_replace('_', ' ', $order['payment_method']))) ?></td>
      <td><span class="badge badge-<?= e($order['status']) ?>"><?= e($order['status']) ?></span></td>
      <td><span class="badge badge-<?= e($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></td>
      <td><?= formatPrice((float)$order['total']) ?></td>
      <td><?= e(formatLocalDate($order['created_at'], true)) ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($orders)): ?><tr><td colspan="7"><?= e(__('admin.orders.none_found')) ?></td></tr><?php endif; ?>
  </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
