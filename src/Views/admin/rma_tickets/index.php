<?php
/**
 * @var array $tickets
 * @var array $statuses
 * @var string $statusFilter
 */
$base = rtrim(SITE_URL, '/') . '/admin/rma-tickets';
$badgeClass = static fn (string $status): string => match ($status) {
    'approved', 'repaired', 'replaced', 'refunded' => 'completed',
    'rejected', 'closed' => 'cancelled',
    default => 'pending',
};
?>
<div class="page-header"><h1><?= e(__('admin.rma_tickets')) ?></h1></div>

<form class="toolbar" method="get">
  <select name="status" onchange="this.form.submit()">
    <option value=""><?= e(__('admin.contact_messages.all_statuses')) ?></option>
    <?php foreach ($statuses as $s): ?>
      <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.dashboard.order_number')) ?></th><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('admin.rma_tickets.claim_type')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('common.date')) ?></th><th></th></tr></thead>
  <tbody>
    <?php foreach ($tickets as $t): ?>
      <tr>
        <td><?= e($t['order_number']) ?></td>
        <td><?= e($t['product_name']) ?></td>
        <td><?= e(ucfirst($t['warranty_claim_type'])) ?></td>
        <td><span class="badge badge-<?= $badgeClass($t['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $t['status']))) ?></span></td>
        <td><?= e(formatLocalDate($t['requested_at'], true)) ?></td>
        <td><a class="btn btn-sm btn-secondary" href="<?= e($base) ?>/<?= (int)$t['id'] ?>"><?= e(__('admin.customers.view')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($tickets)): ?><tr><td colspan="6"><?= e(__('admin.rma_tickets.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
