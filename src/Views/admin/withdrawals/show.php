<?php
/**
 * Admin -> Withdrawals -> single request detail: which items are covered,
 * the deadline, the customer's stated reason, and a form to approve/
 * reject/otherwise transition the request.
 *
 * Note: unlike most admin list/detail pages (which pass plain arrays from
 * fetchAll()), $withdrawal here is a real hydrated Models\WithdrawalRequest
 * object (property access like $withdrawal->status, not
 * $withdrawal['status']) - see CLAUDE.md's Core\Model section: this is one
 * of the few places "a real object's behavior earns its keep" (deadline
 * calculation, approve()/reject()/transitionTo() state transitions).
 *
 * @var \ShopRex\Models\WithdrawalRequest $withdrawal The request being viewed - id, status, deadlineAt, reason, adminNotes, plus behavior like isPastDeadline().
 * @var array|null                        $order      The order this withdrawal is against (customer_email, order_number, id), or null if it couldn't be loaded.
 * @var array                             $items      The specific order line items this withdrawal request covers (not necessarily every item on the order - a withdrawal can be partial, and hygiene items may be excluded per-item, see CLAUDE.md).
 * @var array                             $statuses   Every possible request status, for the status dropdown.
 */
$base = rtrim(SITE_URL, '/') . '/admin/withdrawals/' . (int)$withdrawal->id;
?>
<div class="page-header">
  <h1><?= e(__('admin.withdrawal_view.title', ['number' => $order['order_number'] ?? ''])) ?></h1>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/withdrawals">&larr; <?= e(__('admin.withdrawals')) ?></a>
</div>

<div class="card">
  <p><strong><?= e(__('admin.numbering.type_withdrawal_request')) ?>:</strong> <?= e($withdrawal->withdrawalNumber ?? '-') ?></p>
  <p><strong><?= e(__('common.email')) ?>:</strong> <?= e($order['customer_email'] ?? '') ?></p>
  <?php /* isPastDeadline() compares deadlineAt (computed at request-creation time from Models\WithdrawalRequest::calculateDeadline() - the fixed legal cooling-off window) against now - purely informational here, it doesn't stop the admin from still approving/rejecting a late request below. */ ?>
  <p><strong><?= e(__('admin.withdrawals.deadline')) ?>:</strong> <?= e(formatLocalDate($withdrawal->deadlineAt)) ?>
    <?= $withdrawal->isPastDeadline() ? '<span class="badge badge-cancelled">' . e(__('admin.withdrawals.past_deadline')) . '</span>' : '' ?>
  </p>
  <?php /* Reason is optional (a customer isn't legally required to justify a withdrawal) so only shown when they provided one. */ ?>
  <?php if ($withdrawal->reason): ?><p><strong><?= e(__('admin.withdrawal_view.reason')) ?>:</strong></p><p style="white-space:pre-wrap;"><?= nl2br(e($withdrawal->reason)) ?></p><?php endif; ?>
  <?php /* $order can be null (e.g. the order it referenced was since deleted) - guards against linking to a non-existent order page. */ ?>
  <?php if ($order): ?>
    <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>"><?= e(__('admin.withdrawal_view.view_order')) ?></a>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.withdrawal_view.covered_items')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('admin.order_view.qty')) ?></th><th><?= e(__('common.total')) ?></th></tr></thead>
    <tbody>
      <?php /* Only the specific items THIS withdrawal request covers - not necessarily the order's full item list, since a customer can withdraw from just part of an order (and hygiene-flagged items may be excluded, see this file's top docblock). */ ?>
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
    <?php /* Unchecked by default, same "silent unless opted in" pattern as the order status-update form - saving a status change doesn't email the customer unless explicitly requested. */ ?>
    <div class="form-group">
      <label><input type="checkbox" name="notify_customer" value="1" style="width:auto;"> <?= e(__('admin.withdrawal_view.notify_customer')) ?></label>
      <small style="color:var(--color-muted);display:block;"><?= e(__('admin.withdrawal_view.notify_customer_hint')) ?></small>
    </div>
    <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
  </form>
</div>
