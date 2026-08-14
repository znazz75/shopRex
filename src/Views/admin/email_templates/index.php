<?php
/**
 * @var string $lang
 * @var array $availableLangs
 * @var array $templateKeys
 * @var string|null $editKey
 * @var array|null $current
 * @var array $existing
 * @var array $errors
 */
$base = rtrim(SITE_URL, '/') . '/admin/email-templates';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">

<div class="page-header"><h1><?= e(__('admin.email_templates')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (count($availableLangs) > 1): ?>
<div class="toolbar">
  <?php foreach ($availableLangs as $code => $label): ?>
    <a class="btn <?= $code === $lang ? '' : 'btn-secondary' ?>" href="<?= e($base) ?>?lang=<?= e($code) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($editKey && $current): ?>
  <div class="card">
    <h2 style="margin-top:0;"><?= e($templateKeys[$editKey]['label']) ?> (<?= e($availableLangs[$lang]) ?>)</h2>
    <p style="color:var(--color-muted);font-size:13px;">
      <?= e(__('admin.email_templates.available_tokens')) ?> <?php foreach ($templateKeys[$editKey]['tokens'] as $t): ?><code>{{<?= e($t) ?>}}</code> <?php endforeach; ?>
    </p>
    <form method="post" action="<?= e($base) ?>" id="templateForm">
      <?= csrfField() ?>
      <input type="hidden" name="template_key" value="<?= e($editKey) ?>">
      <input type="hidden" name="language" value="<?= e($lang) ?>">
      <?php if ($templateKeys[$editKey]['has_subject']): ?>
        <div class="form-group">
          <label for="subject"><?= e(__('admin.email_templates.subject')) ?></label>
          <input type="text" id="subject" name="subject" value="<?= e($current['subject'] ?? '') ?>">
        </div>
      <?php endif; ?>
      <div class="form-group">
        <label><?= e(__('admin.email_templates.body')) ?></label>
        <div id="quillEditor" style="background:#fff;min-height:280px;"><?= $current['body_html'] ?? '' ?></div>
        <textarea name="body_html" id="contentField" style="display:none;"></textarea>
      </div>
      <button class="btn" type="submit"><?= e(__('admin.email_templates.save_template')) ?></button>
      <a class="btn btn-secondary" href="<?= e($base) ?>?lang=<?= e($lang) ?>"><?= e(__('common.cancel')) ?></a>
    </form>
  </div>
  <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
  <script>
    var quill = new Quill('#quillEditor', { theme: 'snow' });
    document.getElementById('templateForm').addEventListener('submit', function () {
      document.getElementById('contentField').value = quill.root.innerHTML;
    });
  </script>
<?php else: ?>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.email_templates.template')) ?></th><th><?= e(__('common.status')) ?></th><th></th></tr></thead>
    <tbody>
    <?php foreach ($templateKeys as $key => $meta): ?>
      <tr>
        <td><?= e($meta['label']) ?></td>
        <td><?= isset($existing[$key][$lang]) ? '<span class="badge badge-completed">' . e(__('admin.email_templates.customized')) . '</span>' : '<span class="badge badge-pending">' . e(__('admin.email_templates.using_default')) . '</span>' ?></td>
        <td><a class="btn btn-sm btn-secondary" href="<?= e($base) ?>?key=<?= urlencode($key) ?>&amp;lang=<?= e($lang) ?>"><?= e(__('common.edit')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
