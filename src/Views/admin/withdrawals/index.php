<?php
/**
 * Admin -> Withdrawals: list of customer right-of-withdrawal requests (an
 * order-level request to return/cancel an order within the legal
 * cooling-off window - see Models\WithdrawalRequest and CLAUDE.md's "New
 * legal/compliance domain" section; the sibling item-level RMA/warranty
 * flow lives under Admin -> RMA Tickets instead).
 *
 * @var array  $requests     The (already status-filtered) withdrawal request rows - order_number, customer_email, status, deadline_at, requested_at.
 * @var array  $statuses     Every possible request status (e.g. 'pending', 'approved', 'rejected', 'cancelled', 'refunded'), for the filter dropdown.
 * @var string $statusFilter Which status is currently filtered to ('' = all).
 */
$base = rtrim(SITE_URL, '/') . '/admin/withdrawals';
// Maps a request status to which badge color to use - grouped by meaning
// (both "approved" and "refunded" read as a successful outcome, both
// "rejected" and "cancelled" as a negative one) rather than one CSS class
// per exact status string, so the badge still makes sense to skim even
// with several different status values in play.
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
  <thead><tr><th><?= e(__('admin.numbering.type_withdrawal_request')) ?></th><th><?= e(__('admin.dashboard.order_number')) ?></th><th><?= e(__('common.email')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('admin.withdrawals.deadline')) ?></th><th><?= e(__('common.date')) ?></th><th></th></tr></thead>
  <tbody>
    <?php /* Raw status codes use underscores (e.g. "in_review") - ucwords(str_replace(...)) turns that into a readable "In Review" for display, same formatting trick used for payment-method names elsewhere in the admin. */ ?>
    <?php foreach ($requests as $r): ?>
      <tr>
        <?php /* withdrawal_number is NULL for any request submitted before Admin -> Numbering existed. */ ?>
        <td><?= e($r['withdrawal_number'] ?? '-') ?></td>
        <td><?= e($r['order_number']) ?></td>
        <td><?= e($r['customer_email'] ?? '') ?></td>
        <td><span class="badge badge-<?= $badgeClass($r['status']) ?>"><?= e(ucwords(str_replace('_', ' ', $r['status']))) ?></span></td>
        <td><?= e(formatLocalDate($r['deadline_at'])) ?></td>
        <td><?= e(formatLocalDate($r['requested_at'], true)) ?></td>
        <td><a class="btn btn-sm btn-secondary" href="<?= e($base) ?>/<?= (int)$r['id'] ?>"><?= e(__('admin.customers.view')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($requests)): ?><tr><td colspan="7"><?= e(__('admin.withdrawals.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
