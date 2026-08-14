<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('settings');

// Template keys the UI knows about, with a human label and the tokens each
// one's body/subject can use (shown as a hint while editing). A function
// (not a const) because the labels go through __() - constant expressions
// can't call functions.
function getEmailTemplateKeysMeta(): array
{
    return [
        '_header'                  => ['label' => __('admin.email_templates.key_header'), 'tokens' => ['shop_name'], 'has_subject' => false],
        '_footer'                  => ['label' => __('admin.email_templates.key_footer'), 'tokens' => ['shop_name'], 'has_subject' => false],
        'order_confirmation'       => ['label' => __('admin.email_templates.key_order_confirmation'), 'tokens' => ['shop_name', 'customer_name', 'order_number', 'order_items_table', 'bank_transfer_details'], 'has_subject' => true],
        'order_status_update'      => ['label' => __('admin.email_templates.key_order_status_update'), 'tokens' => ['shop_name', 'order_number', 'status', 'admin_notes'], 'has_subject' => true],
        'registration_welcome'     => ['label' => __('admin.email_templates.key_registration_welcome'), 'tokens' => ['shop_name', 'customer_name', 'account_url'], 'has_subject' => true],
        'password_reset'           => ['label' => __('admin.email_templates.key_password_reset'), 'tokens' => ['shop_name', 'customer_name', 'reset_link'], 'has_subject' => true],
        'account_deletion_warning' => ['label' => __('admin.email_templates.key_account_deletion_warning'), 'tokens' => ['shop_name', 'customer_name', 'deletion_date', 'login_url'], 'has_subject' => true],
    ];
}

$lang = $_GET['lang'] ?? getSetting('default_language', 'en');
$availableLangs = getEnabledLanguages();
if (!array_key_exists($lang, $availableLangs)) {
    $lang = 'en';
}

$emailTemplateKeys = getEmailTemplateKeysMeta();

$editKey = $_GET['key'] ?? null;
if ($editKey && !array_key_exists($editKey, $emailTemplateKeys)) {
    setFlash('error', __('admin.email_templates.unknown_template'));
    redirect(rtrim(SITE_URL, '/') . '/admin/email_templates.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $key = $_POST['template_key'] ?? '';
    $postLang = $_POST['language'] ?? 'en';
    if (!array_key_exists($key, $emailTemplateKeys) || !array_key_exists($postLang, $availableLangs)) {
        $errors[] = __('admin.email_templates.invalid_template_or_lang');
    } else {
        $subject = trim($_POST['subject'] ?? '');
        $body = $_POST['body_html'] ?? '';
        $stmt = db()->prepare(
            'INSERT INTO email_templates (template_key, language, subject, body_html) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html)'
        );
        $stmt->execute([$key, $postLang, $subject, $body]);
        setFlash('success', __('admin.email_templates.flash_saved'));
        redirect(rtrim(SITE_URL, '/') . '/admin/email_templates.php?lang=' . urlencode($postLang));
    }
}

$current = null;
if ($editKey) {
    $stmt = db()->prepare('SELECT * FROM email_templates WHERE template_key = ? AND language = ?');
    $stmt->execute([$editKey, $lang]);
    $current = $stmt->fetch() ?: ['template_key' => $editKey, 'language' => $lang, 'subject' => '', 'body_html' => ''];
}

// Which languages already have a saved override for each key, for the list view.
$existing = [];
foreach (db()->query('SELECT template_key, language FROM email_templates')->fetchAll() as $row) {
    $existing[$row['template_key']][$row['language']] = true;
}

$pageTitle = __('admin.email_templates');
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">

<div class="page-header"><h1><?= e(__('admin.email_templates')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (count($availableLangs) > 1): ?>
<div class="toolbar">
  <?php foreach ($availableLangs as $code => $label): ?>
    <a class="btn <?= $code === $lang ? '' : 'btn-secondary' ?>" href="email_templates.php?lang=<?= e($code) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($editKey && $current): ?>
  <div class="card">
    <h2 style="margin-top:0;"><?= e($emailTemplateKeys[$editKey]['label']) ?> (<?= e($availableLangs[$lang]) ?>)</h2>
    <p style="color:var(--color-muted);font-size:13px;">
      <?= e(__('admin.email_templates.available_tokens')) ?> <?php foreach ($emailTemplateKeys[$editKey]['tokens'] as $t): ?><code>{{<?= e($t) ?>}}</code> <?php endforeach; ?>
    </p>
    <form method="post" id="templateForm">
      <?= csrfField() ?>
      <input type="hidden" name="template_key" value="<?= e($editKey) ?>">
      <input type="hidden" name="language" value="<?= e($lang) ?>">
      <?php if ($emailTemplateKeys[$editKey]['has_subject']): ?>
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
      <a class="btn btn-secondary" href="email_templates.php?lang=<?= e($lang) ?>"><?= e(__('common.cancel')) ?></a>
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
    <?php foreach ($emailTemplateKeys as $key => $meta): ?>
      <tr>
        <td><?= e($meta['label']) ?></td>
        <td><?= isset($existing[$key][$lang]) ? '<span class="badge badge-completed">' . e(__('admin.email_templates.customized')) . '</span>' : '<span class="badge badge-pending">' . e(__('admin.email_templates.using_default')) . '</span>' ?></td>
        <td><a class="btn btn-sm btn-secondary" href="email_templates.php?key=<?= urlencode($key) ?>&amp;lang=<?= e($lang) ?>"><?= e(__('common.edit')) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
