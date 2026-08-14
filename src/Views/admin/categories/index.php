<?php
/**
 * @var array $errors
 * @var array $availableLangs
 * @var string $lang
 * @var array|null $editCategory
 * @var string $introText
 * @var array $flatTree
 * @var array $counts
 * Direct port of admin/categories.php's body.
 */
?>
<div class="page-header"><h1><?= e(__('admin.categories')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (count($availableLangs) > 1): ?>
  <div class="toolbar">
    <?php foreach ($availableLangs as $code => $label): ?>
      <a class="btn <?= $code === $lang ? '' : 'btn-secondary' ?>" href="<?= rtrim(SITE_URL, '/') ?>/admin/categories?lang=<?= e($code) ?><?= !empty($editCategory['id']) ? '&edit=' . (int)$editCategory['id'] : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editCategory['id']) ? e(__('admin.categories.edit_title')) : e(__('admin.categories.add_title')) ?></h2>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/categories">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= e($editCategory['id'] ?? '') ?>">
    <input type="hidden" name="language" value="<?= e($lang) ?>">
    <div class="form-grid">
      <div class="form-group"><label for="name"><?= e(__('admin.products.name')) ?></label><input type="text" id="name" name="name" required value="<?= e($editCategory['name'] ?? '') ?>"></div>
      <div class="form-group">
        <label for="parent_id"><?= e(__('admin.categories.parent_category')) ?></label>
        <select id="parent_id" name="parent_id">
          <option value="">-- <?= e(__('admin.categories.none_top_level')) ?> --</option>
          <?php foreach ($flatTree as $cat): ?>
            <?php if (!empty($editCategory['id']) && ((int)$cat['id'] === (int)$editCategory['id'])) continue; ?>
            <option value="<?= (int)$cat['id'] ?>" <?= (($editCategory['parent_id'] ?? null) == $cat['id']) ? 'selected' : '' ?>>
              <?= str_repeat('&mdash; ', $cat['depth']) ?><?= e($cat['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group"><label for="description"><?= e(__('admin.categories.description')) ?></label><input type="text" id="description" name="description" value="<?= e($editCategory['description'] ?? '') ?>"></div>
    <div class="form-group">
      <label for="intro_text"><?= e(__('admin.categories.intro_text_label', ['lang' => $availableLangs[$lang]])) ?></label>
      <textarea id="intro_text" name="intro_text" rows="3"><?= e($introText) ?></textarea>
      <small style="color:var(--color-muted);"><?= e(__('admin.categories.intro_text_hint')) ?></small>
    </div>
    <button class="btn" type="submit"><?= e(__('admin.categories.save')) ?></button>
    <?php if (!empty($editCategory['id'])): ?><a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/categories"><?= e(__('common.cancel')) ?></a><?php endif; ?>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('admin.categories.slug')) ?></th><th><?= e(__('admin.categories.products_direct')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($flatTree as $cat): ?>
    <tr>
      <td><?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $cat['depth']) ?><?= $cat['depth'] > 0 ? '&#8627; ' : '' ?><?= e($cat['name']) ?></td>
      <td><?= e($cat['slug']) ?></td>
      <td><?= $counts[(int)$cat['id']] ?? 0 ?></td>
      <td>
        <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/categories?edit=<?= (int)$cat['id'] ?>"><?= e(__('common.edit')) ?></a>
        <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/categories" style="display:inline;" data-confirm="<?= e(__('admin.categories.confirm_delete', ['name' => $cat['name']])) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$cat['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($flatTree)): ?><tr><td colspan="4"><?= e(__('admin.categories.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
