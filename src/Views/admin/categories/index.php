<?php
/**
 * Admin -> Categories: one combined list+edit-form page. Editing an
 * existing category and creating a new one share this same form; which
 * mode it's in is decided purely by whether $editCategory is set (see the
 * "id" hidden field and the page-title ternary below). Category *names*
 * are not translated per-language (only free-text `intro_text` is - see
 * CLAUDE.md's "Product/option translation" section) - hence the small
 * per-language toolbar that only affects the intro-text field, not the
 * name field.
 *
 * @var array       $errors       Validation error messages to show above the form (e.g. "Name is required").
 * @var array       $availableLangs Enabled languages as [code => label], for the intro-text language tabs.
 * @var string      $lang         Which language's intro-text is currently being edited/shown (a query-string driven tab, not the admin UI language).
 * @var array|null  $editCategory The category being edited, or null when the form is in "create new" mode.
 * @var string      $introText    The intro_text value for $editCategory in $lang specifically (already resolved for this language, not the raw row).
 * @var array       $flatTree     Every category, flattened from its parent/child tree into a single list with a 'depth' key already computed, so nesting can be shown with indentation instead of a real nested <ul>.
 * @var array       $counts       Map of category id => number of products directly assigned to it (not counting products in child categories).
 * Direct port of admin/categories.php's body.
 */
?>
<div class="page-header"><h1><?= e(__('admin.categories')) ?></h1></div>
<?php /* One flash-style error box per validation message (e.g. duplicate slug). */ ?>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<?php /* Language tab bar for the intro-text field only - only shown when there's more than one language to switch between. Switching preserves which category is being edited (?edit=) so you don't lose your place. */ ?>
<?php if (count($availableLangs) > 1): ?>
  <div class="toolbar">
    <?php foreach ($availableLangs as $code => $label): ?>
      <a class="btn <?= $code === $lang ? '' : 'btn-secondary' ?>" href="<?= rtrim(SITE_URL, '/') ?>/admin/categories?lang=<?= e($code) ?><?= !empty($editCategory['id']) ? '&edit=' . (int)$editCategory['id'] : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <?php /* Same form serves both "add" and "edit" - only the heading text and the hidden "id" field differ based on whether $editCategory is set. */ ?>
  <h2 style="margin-top:0;"><?= !empty($editCategory['id']) ? e(__('admin.categories.edit_title')) : e(__('admin.categories.add_title')) ?></h2>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/categories">
    <?= csrfField() ?>
    <?php /* Empty "id" value means the controller treats this submission as a create; a non-empty one means update. */ ?>
    <input type="hidden" name="id" value="<?= e($editCategory['id'] ?? '') ?>">
    <input type="hidden" name="language" value="<?= e($lang) ?>">
    <div class="form-grid">
      <div class="form-group"><label for="name"><?= e(__('admin.products.name')) ?></label><input type="text" id="name" name="name" required value="<?= e($editCategory['name'] ?? '') ?>"></div>
      <div class="form-group">
        <label for="parent_id"><?= e(__('admin.categories.parent_category')) ?></label>
        <select id="parent_id" name="parent_id">
          <option value="">-- <?= e(__('admin.categories.none_top_level')) ?> --</option>
          <?php foreach ($flatTree as $cat): ?>
            <?php /* A category can't be its own parent, so skip it from its own dropdown while editing. */ ?>
            <?php if (!empty($editCategory['id']) && ((int)$cat['id'] === (int)$editCategory['id'])) continue; ?>
            <option value="<?= (int)$cat['id'] ?>" <?= (($editCategory['parent_id'] ?? null) == $cat['id']) ? 'selected' : '' ?>>
              <?php /* $cat['depth'] (0 = top level) indents nested categories in this flat <select> so the tree structure still reads visually. */ ?>
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
    <?php /* "Cancel" only makes sense (and only appears) while editing - there's nothing to cancel back to when already creating fresh. */ ?>
    <?php if (!empty($editCategory['id'])): ?><a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/categories"><?= e(__('common.cancel')) ?></a><?php endif; ?>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('admin.categories.slug')) ?></th><th><?= e(__('admin.categories.products_direct')) ?></th><th></th></tr></thead>
  <tbody>
  <?php /* Same flattened tree as the parent-category dropdown above, but here every category (including the one being edited) is listed, indented by depth with a small "└" corner mark for anything nested under a parent. */ ?>
  <?php foreach ($flatTree as $cat): ?>
    <tr>
      <td><?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $cat['depth']) ?><?= $cat['depth'] > 0 ? '&#8627; ' : '' ?><?= e($cat['name']) ?></td>
      <td><?= e($cat['slug']) ?></td>
      <?php /* $counts may not have an entry for a category with zero directly-assigned products, hence the ?? 0 fallback. */ ?>
      <td><?= $counts[(int)$cat['id']] ?? 0 ?></td>
      <td>
        <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/categories?edit=<?= (int)$cat['id'] ?>"><?= e(__('common.edit')) ?></a>
        <?php /* data-confirm triggers a JS "are you sure?" prompt (see admin.js) before this delete form actually submits. */ ?>
        <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/categories" style="display:inline;" data-confirm="<?= e(__('admin.categories.confirm_delete', ['name' => $cat['name']])) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$cat['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php /* Empty-state row when no categories exist at all yet. */ ?>
  <?php if (empty($flatTree)): ?><tr><td colspan="4"><?= e(__('admin.categories.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
