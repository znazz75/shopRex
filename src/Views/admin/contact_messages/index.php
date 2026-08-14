<?php
/**
 * @var array $messages
 * @var array $statuses
 * @var string $statusFilter
 */
$base = rtrim(SITE_URL, '/') . '/admin/contact-messages';
?>
<div class="page-header"><h1><?= e(__('admin.contact_messages')) ?></h1></div>

<form class="toolbar" method="get">
  <select name="status" onchange="this.form.submit()">
    <option value=""><?= e(__('admin.contact_messages.all_statuses')) ?></option>
    <?php foreach ($statuses as $s): ?>
      <option value="<?= e($s) ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
    <?php endforeach; ?>
  </select>
</form>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.contact_messages.from')) ?></th><th><?= e(__('admin.contact_messages.subject')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('common.date')) ?></th><th></th></tr></thead>
  <tbody>
    <?php foreach ($messages as $m): ?>
      <tr>
        <td><a href="<?= e($base) ?>/<?= (int)$m['id'] ?>"><?= e($m['name']) ?></a><br><span style="color:var(--color-muted);font-size:12px;"><?= e($m['email']) ?></span></td>
        <td><?= e($m['subject'] ?: __('admin.contact_messages.no_subject')) ?></td>
        <td><span class="badge badge-<?= $m['status'] === 'new' ? 'pending' : ($m['status'] === 'closed' ? 'cancelled' : 'completed') ?>"><?= e(ucfirst($m['status'])) ?></span></td>
        <td><?= e(formatLocalDate($m['created_at'], true)) ?></td>
        <td><a class="btn btn-sm btn-secondary" href="<?= e($base) ?>/<?= (int)$m['id'] ?>"><?= e(__('admin.customers.view')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($messages)): ?><tr><td colspan="5"><?= e(__('admin.contact_messages.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
