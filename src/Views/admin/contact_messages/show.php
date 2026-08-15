<?php
/**
 * Admin -> Contact Messages -> single message detail: full message body
 * plus a small form to change its status and add internal admin notes.
 *
 * @var array $message  The contact message row - name, email, subject, message body, order_number (optional - the customer can optionally tie their message to an order), status, admin_notes, created_at.
 * @var array $statuses Every possible message status, for the status dropdown.
 */
$base = rtrim(SITE_URL, '/') . '/admin/contact-messages/' . (int)$message['id'];
?>
<div class="page-header">
  <h1><?= e(__('admin.contact_message_view.title', ['name' => $message['name']])) ?></h1>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/contact-messages">&larr; <?= e(__('admin.contact_messages')) ?></a>
</div>

<div class="card">
  <p><strong><?= e(__('admin.contact_messages.from')) ?>:</strong> <?= e($message['name']) ?> &lt;<a href="mailto:<?= e($message['email']) ?>"><?= e($message['email']) ?></a>&gt;</p>
  <?php /* order_number is optional - the storefront contact form lets a customer optionally reference an order they're asking about, so only shown when they actually provided one. */ ?>
  <?php if ($message['order_number']): ?><p><strong><?= e(__('admin.contact_messages.related_order')) ?>:</strong> <?= e($message['order_number']) ?></p><?php endif; ?>
  <p><strong><?= e(__('admin.contact_messages.subject')) ?>:</strong> <?= e($message['subject'] ?: __('admin.contact_messages.no_subject')) ?></p>
  <p><strong><?= e(__('common.date')) ?>:</strong> <?= e(formatLocalDate($message['created_at'], true)) ?></p>
  <hr>
  <?php /* white-space:pre-wrap preserves the customer's original blank lines/spacing, and nl2br(e(...)) escapes the text first before turning single line breaks into visible <br> tags - same safe pattern as the order's customer-notes field. */ ?>
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
