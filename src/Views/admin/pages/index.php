<?php
/**
 * @var array $errors
 * @var array $availableLangs
 * @var string $lang
 * @var array|null $editPage
 * @var array $pages
 * @var array $missingTranslations
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
        <input type="text" id="slug" name="slug" value="<?= e($editPage['slug'] ?? '') ?>" <?= !empty($editPage['is_system']) ? 'readonly' : '' ?>>
        <?php if (!empty($editPage['is_system'])): ?><small style="color:var(--color-muted);"><?= e(__('admin.pages.system_page_hint')) ?></small><?php endif; ?>
      </div>
    </div>
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
  var quill = new Quill('#quillEditor', { theme: 'snow' });
  document.getElementById('pageForm').addEventListener('submit', function () {
    document.getElementById('contentField').value = quill.root.innerHTML;
  });
</script>
