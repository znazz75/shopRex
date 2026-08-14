<?php
/**
 * @var array $requests
 * @var array $statuses
 * @var string $statusFilter
 */
$base = rtrim(SITE_URL, '/') . '/admin/withdrawals';
$badgeClass = static fn (string $status): string => match ($status) {
    'approved', 'refunded' => 'completed',
    'rejected', 'cancelled' => 'cancelled',
    default => 'pending',
};
?>
<div class="page-header"><h1><?= e(__('admin.withdrawals')) ?></h1></div>

<form class="toolbar" method="get">
  <select name="status" onchange="this.form.submit()">
    <option value=""><?= e(__('admin.contact_messages.all_statuses')) ?></option>
    <?php foreach ($statuses as $s): ?>
      <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.dashboard.order_number')) ?></th><th><?= e(__('common.email')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('admin.withdrawals.deadline')) ?></th><th><?= e(__('common.date')) ?></th><th></th></tr></thead>
  <tbody>
    <?php foreach ($requests as $r): ?>
      <tr>
        <td><?= e($r['order_number']) ?></td>
        <td><?= e($r['customer_email'] ?? '') ?></td>
        <td><span class="badge badge-<?= $badgeClass($r['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $r['status']))) ?></span></td>
        <td><?= e(formatLocalDate($r['deadline_at'])) ?></td>
        <td><?= e(formatLocalDate($r['requested_at'], true)) ?></td>
        <td><a class="btn btn-sm btn-secondary" href="<?= e($base) ?>/<?= (int)$r['id'] ?>"><?= e(__('admin.customers.view')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($requests)): ?><tr><td colspan="6"><?= e(__('admin.withdrawals.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
