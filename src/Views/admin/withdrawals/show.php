<?php
/**
 * @var \ShopRex\Models\WithdrawalRequest $withdrawal
 * @var array|null $order
 * @var array $items
 * @var array $statuses
 */
$base = rtrim(SITE_URL, '/') . '/admin/withdrawals/' . (int)$withdrawal->id;
?>
<div class="page-header">
  <h1><?= e(__('admin.withdrawal_view.title', ['number' => $order['order_number'] ?? ''])) ?></h1>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/withdrawals">&larr; <?= e(__('admin.withdrawals')) ?></a>
</div>

<div class="card">
  <p><strong><?= e(__('common.email')) ?>:</strong> <?= e($order['customer_email'] ?? '') ?></p>
  <p><strong><?= e(__('admin.withdrawals.deadline')) ?>:</strong> <?= e(formatLocalDate($withdrawal->deadlineAt)) ?>
    <?= $withdrawal->isPastDeadline() ? '<span class="badge badge-cancelled">' . e(__('admin.withdrawals.past_deadline')) . '</span>' : '' ?>
  </p>
  <?php if ($withdrawal->reason): ?><p><strong><?= e(__('admin.withdrawal_view.reason')) ?>:</strong></p><p style="white-space:pre-wrap;"><?= nl2br(e($withdrawal->reason)) ?></p><?php endif; ?>
  <?php if ($order): ?>
    <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>"><?= e(__('admin.withdrawal_view.view_order')) ?></a>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.withdrawal_view.covered_items')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('admin.order_view.qty')) ?></th><th><?= e(__('common.total')) ?></th></tr></thead>
    <tbody>
      <?php foreach ($items as $item): ?>
        <tr>
          <td><?= e($item['product_name']) ?><?php if ($item['option_summary']): ?><br><small style="color:var(--color-muted);"><?= e($item['option_summary']) ?></small><?php endif; ?></td>
          <td><?= (int)$item['quantity'] ?></td>
          <td><?= formatPrice((float)$item['total_price']) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.withdrawal_view.manage')) ?></h2>
  <form method="post" action="<?= e($base) ?>">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-group">
        <label for="status"><?= e(__('common.status')) ?></label>
        <select id="status" name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $withdrawal->status === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label for="admin_notes"><?= e(__('admin.order_view.admin_notes')) ?></label>
      <textarea id="admin_notes" name="admin_notes" rows="3"><?= e($withdrawal->adminNotes ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="notify_customer" value="1" style="width:auto;"> <?= e(__('admin.withdrawal_view.notify_customer')) ?></label>
      <small style="color:var(--color-muted);display:block;"><?= e(__('admin.withdrawal_view.notify_customer_hint')) ?></small>
    </div>
    <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
  </form>
</div>
