<?php
/**
 * Admin -> Pages: CMS page list + rich-text (Quill.js) editor, one page
 * per (slug, language) row - unlike products, a CMS page is NOT a
 * default-language row plus translation overlays; each language is a
 * fully separate, independently-editable row, so a page can simply not
 * exist yet in some language (see $missingTranslations below and
 * CLAUDE.md's "Product/option translation" section, which calls out pages
 * as using a third, different translation pattern). Page content is
 * rendered as trusted, unescaped HTML on the storefront by design (same
 * model as WordPress page content, see CLAUDE.md's Security posture
 * section) - anyone who can reach this screen can inject markup/scripts
 * into the storefront, which is why Pages is a capability, not open to
 * every admin.
 *
 * @var array      $errors               Validation error messages to show above the page.
 * @var array      $availableLangs       Enabled languages as [code => label], for the language tab bar.
 * @var string     $lang                 Which language's pages are currently being listed/edited.
 * @var array|null $editPage             The page (in $lang) being edited, or null when the form is in "create new" mode.
 * @var array      $pages                Every page that exists IN $lang specifically (not all pages overall - a page missing from this language just won't appear here, see $missingTranslations).
 * @var array      $missingTranslations  Pages that exist in another language but have no row yet for $lang - surfaced as a banner with quick "go create it" links, not silently hidden.
 */
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">

<div class="page-header"><h1><?= e(__('admin.pages')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (count($availableLangs) > 1): ?>
<div class="toolbar">
  <?php foreach ($availableLangs as $code => $label): ?>
    <a class="btn <?= $code === $lang ? '' : 'btn-secondary' ?>" href="<?= rtrim(SITE_URL, '/') ?>/admin/pages?lang=<?= e($code) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php /* Since each language's pages are wholly separate rows (see this file's top docblock), a page can exist in English but simply not have a German row at all yet - this banner calls that out explicitly so it's not mistaken for a bug/missing content, with direct links to go create the missing translation. */ ?>
<?php if (!empty($missingTranslations)): ?>
  <div class="flash flash-info">
    <?= e(__('admin.pages.missing_in', ['lang' => $availableLangs[$lang]])) ?>
    <?php foreach ($missingTranslations as $s): ?>
      <a href="<?= rtrim(SITE_URL, '/') ?>/admin/pages?lang=<?= e($lang) ?>&amp;slug=<?= urlencode($s['slug']) ?>"><?= e($s['title']) ?></a>&nbsp;
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editPage['id']) ? e(__('admin.pages.edit_title')) : e(__('admin.pages.add_title')) ?> (<?= e($availableLangs[$lang]) ?>)</h2>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/pages" id="pageForm">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= e($editPage['id'] ?? '') ?>">
    <input type="hidden" name="language" value="<?= e($lang) ?>">
    <div class="form-grid">
      <div class="form-group"><label for="title"><?= e(__('admin.pages.title_label')) ?></label><input type="text" id="title" name="title" required value="<?= e($editPage['title'] ?? '') ?>"></div>
      <div class="form-group">
        <label for="slug"><?= e(__('admin.pages.slug_label')) ?></label>
        <?php /* A "system" page (e.g. the homepage, or another page the app depends on finding by a fixed slug) can't have its slug changed - readonly here is the UI-level guard; the controller is expected to also reject a slug change server-side, this alone wouldn't stop a crafted request. */ ?>
        <input type="text" id="slug" name="slug" value="<?= e($editPage['slug'] ?? '') ?>" <?= !empty($editPage['is_system']) ? 'readonly' : '' ?>>
        <?php if (!empty($editPage['is_system'])): ?><small style="color:var(--color-muted);"><?= e(__('admin.pages.system_page_hint')) ?></small><?php endif; ?>
      </div>
    </div>
    <?php /* Same trusted-rich-text pattern as Email Templates' Quill editor: $editPage['content'] is intentionally NOT escaped (it's real HTML meant to be rendered, both here to re-populate the editor and later on the live storefront page) - see this file's top docblock about pages being a deliberate trusted-HTML exception. The hidden textarea is what actually submits, synced from Quill right before submit (see the script at the bottom). */ ?>
    <div class="form-group">
      <label><?= e(__('admin.pages.content')) ?></label>
      <div id="quillEditor" style="background:#fff;min-height:280px;"><?= $editPage['content'] ?? '' ?></div>
      <textarea name="content" id="contentField" style="display:none;"></textarea>
    </div>
    <button class="btn" type="submit"><?= e(__('admin.pages.save')) ?></button>
    <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/pages?lang=<?= e($lang) ?>"><?= e(__('common.cancel')) ?></a>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.pages.title_label')) ?></th><th><?= e(__('admin.categories.slug')) ?></th><th><?= e(__('admin.pages.type')) ?></th><th><?= e(__('admin.pages.updated')) ?></th><th></th></tr></thead>
  <tbody>
  <?php /* System pages can be edited but never deleted (no delete form rendered for them at all - see the is_system check below) since the app relies on them existing at a fixed slug. */ ?>
  <?php foreach ($pages as $p): ?>
    <tr>
      <td><?= e($p['title']) ?></td>
      <td><code>/page/<?= e($p['slug']) ?></code></td>
      <td><?= $p['is_system'] ? '<span class="badge badge-processing">' . e(__('admin.pages.system')) . '</span>' : '<span class="badge badge-completed">' . e(__('admin.pages.custom')) . '</span>' ?></td>
      <td><?= e(formatLocalDate($p['updated_at'], true)) ?></td>
      <td>
        <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/pages?edit=<?= (int)$p['id'] ?>"><?= e(__('common.edit')) ?></a>
        <?php if (!$p['is_system']): ?>
          <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/pages" style="display:inline;" data-confirm="<?= e(__('admin.pages.confirm_delete', ['title' => $p['title']])) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($pages)): ?><tr><td colspan="5"><?= e(__('admin.pages.none_for_lang')) ?></td></tr><?php endif; ?>
  </tbody>
</table>

<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
  // Same pattern as Email Templates' editor (see email_templates/index.php):
  // Quill's content lives in an editable <div>, not a form field, so it's
  // copied into the real hidden "content" textarea right before the form
  // actually submits.
  var quill = new Quill('#quillEditor', { theme: 'snow' });
  document.getElementById('pageForm').addEventListener('submit', function () {
    document.getElementById('contentField').value = quill.root.innerHTML;
  });
</script>
