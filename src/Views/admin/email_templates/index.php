<?php
/**
 * Admin -> Email Templates: one page serving two very different views
 * depending on whether a template is currently being edited - a list of
 * every template's customization status (default view), or a rich-text
 * (Quill.js) editor for one specific template+language combination (when
 * $editKey is set). Each template can be customized independently per
 * language; a language that hasn't been customized just falls back to the
 * shop's built-in default wording for that email.
 *
 * @var string      $lang          Which language is currently being viewed/edited.
 * @var array       $availableLangs Enabled languages as [code => label], for the language tab bar.
 * @var array       $templateKeys  Every email template this shop sends, keyed by an internal id, each with a 'label', 'tokens' (the {{placeholder}} names available in that email, e.g. {{customer_name}}), and 'has_subject' (some templates, like a plain notification, might not need a customizable subject line).
 * @var string|null $editKey       Which template is currently being edited (a key into $templateKeys), or null when showing the list view instead.
 * @var array|null  $current       The template+language combination's saved override (subject/body_html), or null/empty when it's still using the built-in default - only meaningful when $editKey is set.
 * @var array       $existing      Map of template key => language code => true, marking every (template, language) pair that HAS a saved customization - used by the list view to show "Customized" vs "Using default" badges.
 * @var array       $errors        Validation error messages to show above the page.
 */
$base = rtrim(SITE_URL, '/') . '/admin/email-templates';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">

<div class="page-header"><h1><?= e(__('admin.email_templates')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<?php /* Same "only show if there's a choice" language tab pattern used elsewhere in the admin (e.g. Categories) - switching languages here reloads the list/editor scoped to that language, since templates are customized per language independently. */ ?>
<?php if (count($availableLangs) > 1): ?>
<div class="toolbar">
  <?php foreach ($availableLangs as $code => $label): ?>
    <a class="btn <?= $code === $lang ? '' : 'btn-secondary' ?>" href="<?= e($base) ?>?lang=<?= e($code) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* Two entirely different bodies for this one page - the rich-text editor when a specific template is being edited, or the summary list otherwise. Only one branch ever renders. */ ?>
<?php if ($editKey && $current): ?>
  <div class="card">
    <h2 style="margin-top:0;"><?= e($templateKeys[$editKey]['label']) ?> (<?= e($availableLangs[$lang]) ?>)</h2>
    <?php /* Shows which {{token}} placeholders this specific email supports (e.g. {{customer_name}}, {{order_number}}) - Mailer::render() substitutes these when actually sending, so this is just a reference list telling the admin what's available to use in the body/subject below. */ ?>
    <p style="color:var(--color-muted);font-size:13px;">
      <?= e(__('admin.email_templates.available_tokens')) ?> <?php foreach ($templateKeys[$editKey]['tokens'] as $t): ?><code>{{<?= e($t) ?>}}</code> <?php endforeach; ?>
    </p>
    <form method="post" action="<?= e($base) ?>" id="templateForm">
      <?= csrfField() ?>
      <input type="hidden" name="template_key" value="<?= e($editKey) ?>">
      <input type="hidden" name="language" value="<?= e($lang) ?>">
      <?php /* Not every template has a customizable subject (e.g. some emails' subject is fixed/computed) - only rendered when this template's metadata says it has one. */ ?>
      <?php if ($templateKeys[$editKey]['has_subject']): ?>
        <div class="form-group">
          <label for="subject"><?= e(__('admin.email_templates.subject')) ?></label>
          <input type="text" id="subject" name="subject" value="<?= e($current['subject'] ?? '') ?>">
        </div>
      <?php endif; ?>
      <?php /* Deliberately UNescaped (no e()) - $current['body_html'] is trusted rich-text HTML an admin authored via the Quill editor, meant to be rendered as real markup both here (to re-populate the editor) and in the actual sent email, the same "trusted content" model CMS pages use (see CLAUDE.md's Security posture section). The hidden textarea below is what actually gets submitted - synced from Quill's live content right before submit, see the script below. */ ?>
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
    // Quill is a rich-text editor widget, not a plain <textarea> - its
    // content lives in an in-memory editable <div>, not a form field, so
    // right before the form submits its current HTML is copied into the
    // real (hidden) "body_html" textarea that actually gets POSTed.
    var quill = new Quill('#quillEditor', { theme: 'snow' });
    document.getElementById('templateForm').addEventListener('submit', function () {
      document.getElementById('contentField').value = quill.root.innerHTML;
    });
  </script>
<?php else: ?>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.email_templates.template')) ?></th><th><?= e(__('common.status')) ?></th><th></th></tr></thead>
    <tbody>
    <?php /* $existing[$key][$lang] being set at all (regardless of its value) means this template+language has a saved override; its absence means the shop's built-in default wording is still being used - the badge just reflects which of those two is true. */ ?>
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
