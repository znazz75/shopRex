<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('pages');

$errors = [];
$availableLangs = getAvailableLanguages();
$lang = $_GET['lang'] ?? getSetting('default_language', 'en');
if (!array_key_exists($lang, $availableLangs)) {
    $lang = 'en';
}

// ---------------------------------------------------------------
// Delete (custom pages only - system pages are protected)
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    requireCsrf();
    $deleteId = (int)$_POST['delete_id'];
    $stmt = db()->prepare('SELECT is_system FROM pages WHERE id = ?');
    $stmt->execute([$deleteId]);
    $isSystem = $stmt->fetchColumn();

    if ($isSystem === false) {
        setFlash('error', __('admin.pages.not_found'));
    } elseif ((int)$isSystem === 1) {
        setFlash('error', __('admin.pages.system_page_protected'));
    } else {
        db()->prepare('DELETE FROM pages WHERE id = ?')->execute([$deleteId]);
        setFlash('success', __('admin.pages.flash_deleted'));
    }
    redirect(rtrim(SITE_URL, '/') . '/admin/pages.php?lang=' . urlencode($lang));
}

// ---------------------------------------------------------------
// Create / update
// ---------------------------------------------------------------
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editPage = null;
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM pages WHERE id = ?');
    $stmt->execute([$editId]);
    $editPage = $stmt->fetch();
    if (!$editPage) {
        setFlash('error', __('admin.pages.not_found'));
        redirect(rtrim(SITE_URL, '/') . '/admin/pages.php');
    }
    $lang = $editPage['language'];
} elseif (!empty($_GET['slug'])) {
    // "Add translation" link for a system slug missing this language.
    $prefillSlug = $_GET['slug'];
    $sysCheck = db()->prepare('SELECT title FROM pages WHERE slug = ? AND is_system = 1 LIMIT 1');
    $sysCheck->execute([$prefillSlug]);
    $sysTitle = $sysCheck->fetchColumn();
    if ($sysTitle !== false) {
        $editPage = ['id' => null, 'slug' => $prefillSlug, 'title' => $sysTitle, 'content' => '', 'is_system' => 1, 'language' => $lang];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id'])) {
    requireCsrf();

    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $postLang = array_key_exists($_POST['language'] ?? '', $availableLangs) ? $_POST['language'] : 'en';
    $title = trim($_POST['title'] ?? '');
    $content = $_POST['content'] ?? '';
    $isSystem = $id && $editPage && (int)$editPage['is_system'] === 1;

    // System pages (any language) keep the slug that ties them together;
    // a brand new page picks its own. If the submitted slug matches an
    // existing system slug, the new row inherits system status too, so
    // adding a missing translation for a system page works the same way
    // as adding any other page.
    if ($isSystem) {
        $slug = $editPage['slug'];
    } else {
        $slug = slugify(trim($_POST['slug'] ?? $title));
        $sysCheck = db()->prepare('SELECT COUNT(*) FROM pages WHERE slug = ? AND is_system = 1');
        $sysCheck->execute([$slug]);
        if ((int)$sysCheck->fetchColumn() > 0) {
            $isSystem = true;
        }
    }

    if ($title === '') {
        $errors[] = __('admin.pages.title_required');
    }

    if (!$errors) {
        try {
            if ($id) {
                $stmt = db()->prepare('UPDATE pages SET title=?, slug=?, content=? WHERE id=?');
                $stmt->execute([$title, $slug, $content, $id]);
                setFlash('success', __('admin.pages.flash_updated'));
            } else {
                $stmt = db()->prepare('INSERT INTO pages (title, slug, language, content, is_system) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$title, $slug, $postLang, $content, $isSystem ? 1 : 0]);
                setFlash('success', __('admin.pages.flash_created'));
            }
            redirect(rtrim(SITE_URL, '/') . '/admin/pages.php?lang=' . urlencode($postLang));
        } catch (PDOException $e) {
            $errors[] = str_contains($e->getMessage(), 'Duplicate')
                ? __('admin.pages.duplicate_slug')
                : __('admin.pages.save_error', ['message' => $e->getMessage()]);
        }
    }
    $lang = $postLang;
    $editPage = ['id' => $id, 'title' => $title, 'slug' => $slug, 'content' => $content, 'is_system' => $isSystem ? 1 : 0, 'language' => $postLang];
}

$stmt = db()->prepare('SELECT * FROM pages WHERE language = ? ORDER BY is_system DESC, title');
$stmt->execute([$lang]);
$pages = $stmt->fetchAll();

// System slugs (from any language) that don't yet have a row for $lang.
$allSystemSlugs = db()->query("SELECT DISTINCT slug, title FROM pages WHERE is_system = 1 ORDER BY title")->fetchAll();
$presentSlugs = array_column($pages, 'slug');
$missingTranslations = array_filter($allSystemSlugs, fn($s) => !in_array($s['slug'], $presentSlugs, true));

$pageTitle = __('admin.pages');
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css">

<div class="page-header"><h1><?= e(__('admin.pages')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="toolbar">
  <?php foreach ($availableLangs as $code => $label): ?>
    <a class="btn <?= $code === $lang ? '' : 'btn-secondary' ?>" href="pages.php?lang=<?= e($code) ?>"><?= e($label) ?></a>
  <?php endforeach; ?>
</div>

<?php if (!empty($missingTranslations)): ?>
  <div class="flash flash-info">
    <?= e(__('admin.pages.missing_in', ['lang' => $availableLangs[$lang]])) ?>
    <?php foreach ($missingTranslations as $s): ?>
      <a href="pages.php?lang=<?= e($lang) ?>&amp;slug=<?= urlencode($s['slug']) ?>"><?= e($s['title']) ?></a>&nbsp;
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editPage['id']) ? e(__('admin.pages.edit_title')) : e(__('admin.pages.add_title')) ?> (<?= e($availableLangs[$lang]) ?>)</h2>
  <form method="post" id="pageForm">
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
    <a class="btn btn-secondary" href="pages.php?lang=<?= e($lang) ?>"><?= e(__('common.cancel')) ?></a>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.pages.title_label')) ?></th><th><?= e(__('admin.categories.slug')) ?></th><th><?= e(__('admin.pages.type')) ?></th><th><?= e(__('admin.pages.updated')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($pages as $p): ?>
    <tr>
      <td><?= e($p['title']) ?></td>
      <td><code>/page.php?slug=<?= e($p['slug']) ?></code></td>
      <td><?= $p['is_system'] ? '<span class="badge badge-processing">' . e(__('admin.pages.system')) . '</span>' : '<span class="badge badge-completed">' . e(__('admin.pages.custom')) . '</span>' ?></td>
      <td><?= e(formatLocalDate($p['updated_at'], true)) ?></td>
      <td>
        <a class="btn btn-sm btn-secondary" href="pages.php?edit=<?= (int)$p['id'] ?>"><?= e(__('common.edit')) ?></a>
        <?php if (!$p['is_system']): ?>
          <form method="post" style="display:inline;" data-confirm="<?= e(__('admin.pages.confirm_delete', ['title' => $p['title']])) ?>">
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

<?php require __DIR__ . '/includes/footer.php'; ?>
