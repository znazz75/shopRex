<?php
/**
 * @var \ShopRex\Models\RmaTicket $ticket
 * @var array|null $order
 * @var array|null $item
 * @var array $attachments
 * @var array $statuses
 */
$base = rtrim(SITE_URL, '/') . '/admin/rma-tickets/' . (int)$ticket->id;
?>
<div class="page-header">
  <h1><?= e(__('admin.rma_view.title', ['number' => $order['order_number'] ?? ''])) ?></h1>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/rma-tickets">&larr; <?= e(__('admin.rma_tickets')) ?></a>
</div>

<div class="card">
  <p><strong><?= e(__('common.email')) ?>:</strong> <?= e($order['customer_email'] ?? '') ?></p>
  <p><strong><?= e(__('admin.products.name')) ?>:</strong> <?= e($item['product_name'] ?? '') ?></p>
  <p><strong><?= e(__('admin.rma_tickets.claim_type')) ?>:</strong> <?= e(ucfirst($ticket->warrantyClaimType)) ?></p>
  <p><strong><?= e(__('admin.rma_view.defect_description')) ?>:</strong></p>
  <p style="white-space:pre-wrap;"><?= nl2br(e($ticket->defectDescription)) ?></p>
  <?php if ($order): ?>
    <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>"><?= e(__('admin.withdrawal_view.view_order')) ?></a>
  <?php endif; ?>
</div>

<?php if ($attachments): ?>
<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.rma_view.attachments')) ?></h2>
  <div style="display:flex;gap:10px;flex-wrap:wrap;">
    <?php foreach ($attachments as $a): ?>
      <a href="<?= rtrim(SITE_URL, '/') ?>/uploads/<?= e($a['file_path']) ?>" target="_blank">
        <img src="<?= rtrim(SITE_URL, '/') ?>/uploads/<?= e($a['file_path']) ?>" alt="" style="width:120px;height:120px;object-fit:cover;border-radius:6px;border:1px solid var(--color-border);">
      </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.rma_view.manage')) ?></h2>
  <form method="post" action="<?= e($base) ?>">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-group">
        <label for="status"><?= e(__('common.status')) ?></label>
        <select id="status" name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $ticket->status === $s ? 'selected' : '' ?>><?= e(ucwords(str_replace('_', ' ', $s))) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label for="resolution_notes"><?= e(__('admin.rma_view.resolution_notes')) ?></label>
      <textarea id="resolution_notes" name="resolution_notes" rows="3"><?= e($ticket->resolutionNotes ?? '') ?></textarea>
      <small style="color:var(--color-muted);"><?= e(__('admin.rma_view.resolution_notes_hint')) ?></small>
    </div>
    <div class="form-group">
      <label for="admin_notes"><?= e(__('admin.order_view.admin_notes')) ?></label>
      <textarea id="admin_notes" name="admin_notes" rows="3"><?= e($ticket->adminNotes ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="notify_customer" value="1" style="width:auto;"> <?= e(__('admin.withdrawal_view.notify_customer')) ?></label>
      <small style="color:var(--color-muted);display:block;"><?= e(__('admin.rma_view.notify_customer_hint')) ?></small>
    </div>
    <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
  </form>
</div>
