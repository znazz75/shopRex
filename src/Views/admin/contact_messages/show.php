<?php
/**
 * @var array $message
 * @var array $statuses
 */
$base = rtrim(SITE_URL, '/') . '/admin/contact-messages/' . (int)$message['id'];
?>
<div class="page-header">
  <h1><?= e(__('admin.contact_message_view.title', ['name' => $message['name']])) ?></h1>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/contact-messages">&larr; <?= e(__('admin.contact_messages')) ?></a>
</div>

<div class="card">
  <p><strong><?= e(__('admin.contact_messages.from')) ?>:</strong> <?= e($message['name']) ?> &lt;<a href="mailto:<?= e($message['email']) ?>"><?= e($message['email']) ?></a>&gt;</p>
  <?php if ($message['order_number']): ?><p><strong><?= e(__('admin.contact_messages.related_order')) ?>:</strong> <?= e($message['order_number']) ?></p><?php endif; ?>
  <p><strong><?= e(__('admin.contact_messages.subject')) ?>:</strong> <?= e($message['subject'] ?: __('admin.contact_messages.no_subject')) ?></p>
  <p><strong><?= e(__('common.date')) ?>:</strong> <?= e(formatLocalDate($message['created_at'], true)) ?></p>
  <hr>
  <p style="white-space:pre-wrap;"><?= nl2br(e($message['message'])) ?></p>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.contact_message_view.manage')) ?></h2>
  <form method="post" action="<?= e($base) ?>">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-group">
        <label for="status"><?= e(__('common.status')) ?></label>
        <select id="status" name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= e($s) ?>" <?= $message['status'] === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label for="admin_notes"><?= e(__('admin.order_view.admin_notes')) ?></label>
      <textarea id="admin_notes" name="admin_notes" rows="3"><?= e($message['admin_notes'] ?? '') ?></textarea>
    </div>
    <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
  </form>
</div>
