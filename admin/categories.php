<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('categories');

$errors = [];
$availableLangs = getAvailableLanguages();
$lang = $_GET['lang'] ?? getSetting('default_language', 'en');
if (!array_key_exists($lang, $availableLangs)) {
    $lang = 'en';
}

// ---------------------------------------------------------------
// Delete
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    requireCsrf();
    // Children fall back to the deleted category's own parent (FK ON DELETE
    // SET NULL) - re-parent them first so subcategories aren't orphaned to root.
    $deleteId = (int)$_POST['delete_id'];
    $stmt = db()->prepare('SELECT parent_id FROM categories WHERE id = ?');
    $stmt->execute([$deleteId]);
    $parentId = $stmt->fetchColumn();
    db()->prepare('UPDATE categories SET parent_id = ? WHERE parent_id = ?')->execute([$parentId ?: null, $deleteId]);
    db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$deleteId]);
    setFlash('success', __('admin.categories.flash_deleted'));
    redirect(rtrim(SITE_URL, '/') . '/admin/categories.php');
}

// ---------------------------------------------------------------
// Create / update
// ---------------------------------------------------------------
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editCategory = null;
$introText = '';
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM categories WHERE id = ?');
    $stmt->execute([$editId]);
    $editCategory = $stmt->fetch();
    if (!$editCategory) {
        setFlash('error', __('admin.categories.not_found'));
        redirect(rtrim(SITE_URL, '/') . '/admin/categories.php');
    }
    // Exact row for this language only (no default-language fallback here -
    // editing should never silently show/overwrite another language's text).
    $introStmt = db()->prepare('SELECT intro_text FROM category_translations WHERE category_id = ? AND language = ?');
    $introStmt->execute([$editId, $lang]);
    $introText = (string)($introStmt->fetchColumn() ?: '');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id'])) {
    requireCsrf();

    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $parentId = $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
    $postLang = array_key_exists($_POST['language'] ?? '', $availableLangs) ? $_POST['language'] : 'en';
    $introText = trim($_POST['intro_text'] ?? '');

    if ($name === '') {
        $errors[] = __('admin.categories.name_required');
    }
    if ($id && $parentId === $id) {
        $errors[] = __('admin.categories.cannot_be_own_parent');
    }
    if ($id && $parentId && isCategoryOrDescendant($id, $parentId)) {
        $errors[] = __('admin.categories.cannot_move_under_own_subcategory');
    }

    if (!$errors) {
        $slug = slugify($name);
        try {
            $pdo = db();
            if ($id) {
                $stmt = $pdo->prepare('UPDATE categories SET name=?, slug=?, description=?, parent_id=? WHERE id=?');
                $stmt->execute([$name, $slug, $description, $parentId, $id]);
                setFlash('success', __('admin.categories.flash_updated'));
            } else {
                $stmt = $pdo->prepare('INSERT INTO categories (name, slug, description, parent_id) VALUES (?, ?, ?, ?)');
                $stmt->execute([$name, $slug, $description, $parentId]);
                $id = (int)$pdo->lastInsertId();
                setFlash('success', __('admin.categories.flash_added'));
            }
            // Listing intro text is per-language (category_translations) -
            // upsert this language's row independent of name/slug/description.
            $introStmt = $pdo->prepare(
                'INSERT INTO category_translations (category_id, language, intro_text) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE intro_text = VALUES(intro_text)'
            );
            $introStmt->execute([$id, $postLang, $introText !== '' ? $introText : null]);
            redirect(rtrim(SITE_URL, '/') . '/admin/categories.php?lang=' . urlencode($postLang));
        } catch (PDOException $e) {
            $errors[] = str_contains($e->getMessage(), 'Duplicate')
                ? __('admin.categories.duplicate_name')
                : __('admin.categories.save_error', ['message' => $e->getMessage()]);
        }
    }
    $lang = $postLang;
    $editCategory = ['id' => $id, 'name' => $name, 'description' => $description, 'parent_id' => $parentId];
}

$flatTree = flattenCategoryTree(getCategoryTree());

// Product counts (direct only, not including subcategory products)
$counts = [];
foreach (db()->query('SELECT category_id, COUNT(*) AS cnt FROM products GROUP BY category_id')->fetchAll() as $row) {
    if ($row['category_id'] !== null) {
        $counts[(int)$row['category_id']] = (int)$row['cnt'];
    }
}

$pageTitle = __('admin.categories');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e(__('admin.categories')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<?php if (count($availableLangs) > 1): ?>
  <div class="toolbar">
    <?php foreach ($availableLangs as $code => $label): ?>
      <a class="btn <?= $code === $lang ? '' : 'btn-secondary' ?>" href="categories.php?lang=<?= e($code) ?><?= !empty($editCategory['id']) ? '&edit=' . (int)$editCategory['id'] : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editCategory['id']) ? e(__('admin.categories.edit_title')) : e(__('admin.categories.add_title')) ?></h2>
  <form method="post">
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
    <?php if (!empty($editCategory['id'])): ?><a class="btn btn-secondary" href="categories.php"><?= e(__('common.cancel')) ?></a><?php endif; ?>
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
        <a class="btn btn-sm btn-secondary" href="categories.php?edit=<?= (int)$cat['id'] ?>"><?= e(__('common.edit')) ?></a>
        <form method="post" style="display:inline;" data-confirm="<?= e(__('admin.categories.confirm_delete', ['name' => $cat['name']])) ?>">
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

<?php require __DIR__ . '/includes/footer.php'; ?>
