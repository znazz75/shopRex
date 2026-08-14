<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('menus');

$errors = [];

// ---------------------------------------------------------------
// Delete
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    requireCsrf();
    // Children cascade-delete via the FK - warn about that in the confirm prompt.
    db()->prepare('DELETE FROM menu_items WHERE id = ?')->execute([(int)$_POST['delete_id']]);
    setFlash('success', __('admin.menus.flash_deleted'));
    redirect(rtrim(SITE_URL, '/') . '/admin/menus.php');
}

// ---------------------------------------------------------------
// Create / update
// ---------------------------------------------------------------
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editItem = null;
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM menu_items WHERE id = ?');
    $stmt->execute([$editId]);
    $editItem = $stmt->fetch();
    if (!$editItem) {
        setFlash('error', __('admin.menus.not_found'));
        redirect(rtrim(SITE_URL, '/') . '/admin/menus.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id'])) {
    requireCsrf();

    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $location = in_array($_POST['location'] ?? '', ['main', 'footer'], true) ? $_POST['location'] : 'main';
    $label = trim($_POST['label'] ?? '');
    $linkType = in_array($_POST['link_type'] ?? '', ['custom', 'category', 'page'], true) ? $_POST['link_type'] : 'custom';
    $linkValue = trim($_POST['link_value'] ?? '');
    $parentId = $_POST['parent_id'] !== '' ? (int)$_POST['parent_id'] : null;
    $openNewTab = !empty($_POST['open_new_tab']) ? 1 : 0;
    $isActive = !empty($_POST['is_active']) ? 1 : 0;

    if ($label === '') $errors[] = __('admin.menus.label_required');
    if ($linkValue === '') $errors[] = __('admin.menus.link_target_required');
    if ($id && $parentId === $id) $errors[] = __('admin.menus.cannot_be_own_parent');
    if ($id && $parentId && isMenuItemOrDescendant($id, $parentId)) $errors[] = __('admin.menus.cannot_move_under_own_subitem');
    if (!$errors && $parentId) {
        $parentStmt = db()->prepare('SELECT location FROM menu_items WHERE id = ?');
        $parentStmt->execute([$parentId]);
        $parentLocation = $parentStmt->fetchColumn();
        if ($parentLocation === false || $parentLocation !== $location) {
            $errors[] = __('admin.menus.parent_same_menu');
        }
    }

    if (!$errors) {
        if ($id) {
            $stmt = db()->prepare('UPDATE menu_items SET location=?, parent_id=?, label=?, link_type=?, link_value=?, open_new_tab=?, is_active=? WHERE id=?');
            $stmt->execute([$location, $parentId, $label, $linkType, $linkValue, $openNewTab, $isActive, $id]);
            setFlash('success', __('admin.menus.flash_updated'));
        } else {
            $maxSortStmt = db()->prepare('SELECT COALESCE(MAX(sort_order),0) FROM menu_items WHERE location = ?');
            $maxSortStmt->execute([$location]);
            $maxSort = (int)$maxSortStmt->fetchColumn();
            $stmt = db()->prepare('INSERT INTO menu_items (location, parent_id, label, link_type, link_value, open_new_tab, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?)');
            $stmt->execute([$location, $parentId, $label, $linkType, $linkValue, $openNewTab, $isActive, $maxSort + 1]);
            setFlash('success', __('admin.menus.flash_added'));
        }
        redirect(rtrim(SITE_URL, '/') . '/admin/menus.php?location=' . $location);
    }
    $editItem = [
        'id' => $id, 'location' => $location, 'parent_id' => $parentId, 'label' => $label,
        'link_type' => $linkType, 'link_value' => $linkValue, 'open_new_tab' => $openNewTab, 'is_active' => $isActive,
    ];
}

$activeLocation = $_GET['location'] ?? ($editItem['location'] ?? 'main');
$activeLocation = in_array($activeLocation, ['main', 'footer'], true) ? $activeLocation : 'main';

$categories = flattenCategoryTree(getCategoryTree());
$pages = db()->query('SELECT slug, title FROM pages ORDER BY title')->fetchAll();

function fetchMenuTreeAll(string $location): array
{
    $stmt = db()->prepare('SELECT * FROM menu_items WHERE location = ? ORDER BY sort_order, id');
    $stmt->execute([$location]);
    return buildMenuTree($stmt->fetchAll());
}

/**
 * Each nesting level gets its OWN jQuery UI Sortable list (scoped to that
 * parent's children only), so dragging can only reorder siblings - it can
 * never accidentally re-parent an item.
 */
function renderMenuAdminTree(array $nodes, string $location): void
{
    if (empty($nodes)) {
        echo '<p style="color:var(--color-muted);">' . e(__('admin.menus.no_items_yet')) . '</p>';
        return;
    }
    echo '<ul class="menu-sortable" data-location="' . e($location) . '">';
    foreach ($nodes as $node) {
        $children = $node['children'] ?? [];
        echo '<li data-id="' . (int)$node['id'] . '">';
        echo '<div class="menu-item-row">';
        echo '<span class="drag-handle">&#10021;</span>';
        echo '<span class="label">' . e($node['label']);
        echo ' <small style="color:var(--color-muted);">(' . e($node['link_type']) . ')</small>';
        if (!$node['is_active']) {
            echo ' <span class="badge badge-cancelled">' . e(__('admin.menus.inactive')) . '</span>';
        }
        echo '</span>';
        echo '<a class="btn btn-sm btn-secondary" href="menus.php?edit=' . (int)$node['id'] . '">' . e(__('common.edit')) . '</a>';
        echo '<form method="post" style="display:inline;" data-confirm="' . e(__('admin.menus.confirm_delete', ['label' => $node['label']])) . '">';
        echo csrfField();
        echo '<input type="hidden" name="delete_id" value="' . (int)$node['id'] . '">';
        echo '<button class="btn btn-sm btn-danger" type="submit">' . e(__('common.delete')) . '</button>';
        echo '</form>';
        echo '</div>';
        if ($children) {
            renderMenuAdminTree($children, $location);
        }
        echo '</li>';
    }
    echo '</ul>';
}

$pageTitle = __('admin.menus');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e(__('admin.menus')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="toolbar">
  <a class="btn <?= $activeLocation === 'main' ? '' : 'btn-secondary' ?>" href="menus.php?location=main"><?= e(__('admin.menus.main_menu')) ?></a>
  <a class="btn <?= $activeLocation === 'footer' ? '' : 'btn-secondary' ?>" href="menus.php?location=footer"><?= e(__('admin.menus.footer_menu')) ?></a>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editItem['id']) ? e(__('admin.menus.edit_title')) : e(__('admin.menus.add_title')) ?></h2>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= e($editItem['id'] ?? '') ?>">
    <div class="form-grid">
      <div class="form-group">
        <label for="location"><?= e(__('admin.menus.menu_label')) ?></label>
        <select id="location" name="location">
          <option value="main" <?= $activeLocation === 'main' ? 'selected' : '' ?>><?= e(__('admin.menus.main_menu')) ?></option>
          <option value="footer" <?= $activeLocation === 'footer' ? 'selected' : '' ?>><?= e(__('admin.menus.footer_menu')) ?></option>
        </select>
      </div>
      <div class="form-group"><label for="label"><?= e(__('admin.menus.item_label')) ?></label><input type="text" id="label" name="label" required value="<?= e($editItem['label'] ?? '') ?>"></div>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label for="link_type"><?= e(__('admin.menus.link_type')) ?></label>
        <select id="link_type" name="link_type" onchange="toggleLinkValueField()">
          <option value="custom" <?= ($editItem['link_type'] ?? 'custom') === 'custom' ? 'selected' : '' ?>><?= e(__('admin.menus.custom_url')) ?></option>
          <option value="category" <?= ($editItem['link_type'] ?? '') === 'category' ? 'selected' : '' ?>><?= e(__('admin.products.category')) ?></option>
          <option value="page" <?= ($editItem['link_type'] ?? '') === 'page' ? 'selected' : '' ?>><?= e(__('admin.pages')) ?></option>
        </select>
      </div>
      <div class="form-group">
        <label for="parent_id"><?= e(__('admin.menus.parent_item')) ?></label>
        <select id="parent_id" name="parent_id">
          <option value="">-- <?= e(__('admin.categories.none_top_level')) ?> --</option>
          <?php foreach (fetchMenuTreeAll($activeLocation) as $topNode): ?>
            <?php foreach (flattenCategoryTree([$topNode]) as $node): ?>
              <?php if (!empty($editItem['id']) && (int)$node['id'] === (int)$editItem['id']) continue; ?>
              <option value="<?= (int)$node['id'] ?>" <?= (($editItem['parent_id'] ?? null) == $node['id']) ? 'selected' : '' ?>>
                <?= str_repeat('&mdash; ', $node['depth']) ?><?= e($node['label']) ?>
              </option>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="form-group" id="linkValueCustom">
      <label for="link_value_custom"><?= e(__('admin.menus.url_label')) ?></label>
      <input type="text" id="link_value_custom" data-link-input value="<?= (($editItem['link_type'] ?? 'custom') === 'custom') ? e($editItem['link_value'] ?? '') : '' ?>">
    </div>
    <div class="form-group" id="linkValueCategory" style="display:none;">
      <label for="link_value_category"><?= e(__('admin.products.category')) ?></label>
      <select id="link_value_category" data-link-input>
        <?php foreach ($categories as $cat): ?>
          <option value="<?= (int)$cat['id'] ?>" <?= (($editItem['link_type'] ?? '') === 'category' && ($editItem['link_value'] ?? '') == $cat['id']) ? 'selected' : '' ?>>
            <?= str_repeat('&mdash; ', $cat['depth']) ?><?= e($cat['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="form-group" id="linkValuePage" style="display:none;">
      <label for="link_value_page"><?= e(__('admin.pages')) ?></label>
      <select id="link_value_page" data-link-input>
        <?php foreach ($pages as $p): ?>
          <option value="<?= e($p['slug']) ?>" <?= (($editItem['link_type'] ?? '') === 'page' && ($editItem['link_value'] ?? '') === $p['slug']) ? 'selected' : '' ?>><?= e($p['title']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <input type="hidden" name="link_value" id="link_value" value="<?= e($editItem['link_value'] ?? '') ?>">

    <div class="form-grid">
      <div class="form-group"><label><input type="checkbox" name="open_new_tab" value="1" style="width:auto;" <?= !empty($editItem['open_new_tab']) ? 'checked' : '' ?>> <?= e(__('admin.menus.open_new_tab')) ?></label></div>
      <div class="form-group"><label><input type="checkbox" name="is_active" value="1" style="width:auto;" <?= ($editItem['is_active'] ?? 1) ? 'checked' : '' ?>> <?= e(__('admin.menus.active_visible')) ?></label></div>
    </div>

    <button class="btn" type="submit"><?= e(__('admin.menus.save')) ?></button>
    <?php if (!empty($editItem['id'])): ?><a class="btn btn-secondary" href="menus.php?location=<?= e($activeLocation) ?>"><?= e(__('common.cancel')) ?></a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= $activeLocation === 'main' ? e(__('admin.menus.main_menu_items')) : e(__('admin.menus.footer_menu_items')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= str_replace('%icon%', '<i class="bi-arrows-move" style="font-style:normal;">&#10021;</i>', e(__('admin.menus.drag_hint'))) ?>
  </p>
  <?php renderMenuAdminTree(fetchMenuTreeAll($activeLocation), $activeLocation); ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
  var csrfToken = <?= json_encode(csrfToken()) ?>;

  function toggleLinkValueField() {
    var type = document.getElementById('link_type').value;
    document.getElementById('linkValueCustom').style.display = type === 'custom' ? 'block' : 'none';
    document.getElementById('linkValueCategory').style.display = type === 'category' ? 'block' : 'none';
    document.getElementById('linkValuePage').style.display = type === 'page' ? 'block' : 'none';
  }
  toggleLinkValueField();

  document.querySelector('form').addEventListener('submit', function () {
    var type = document.getElementById('link_type').value;
    var input = type === 'custom' ? document.getElementById('link_value_custom')
      : type === 'category' ? document.getElementById('link_value_category')
      : document.getElementById('link_value_page');
    document.getElementById('link_value').value = input.value;
  });

  $(function () {
    $('.menu-sortable').sortable({
      handle: '.drag-handle',
      items: '> li',
      placeholder: 'menu-sortable-placeholder',
      update: function () {
        var ids = $(this).children('li').map(function () { return $(this).data('id'); }).get();
        $.post('menu_reorder.php', {
          csrf_token: csrfToken,
          location: $(this).data('location'),
          ids: ids
        });
      }
    });
  });
</script>

<style>
  .menu-sortable { list-style: none; margin: 0; padding-left: 0; }
  .menu-sortable .menu-sortable { margin-top: 6px; margin-left: 26px; }
  .menu-item-row { display: flex; align-items: center; gap: 10px; background: #fff; border: 1px solid var(--color-border); border-radius: 6px; padding: 8px 12px; margin-bottom: 6px; }
  .menu-item-row .drag-handle { cursor: grab; color: var(--color-muted); }
  .menu-item-row .label { flex: 1; }
  .menu-sortable-placeholder { border: 2px dashed var(--color-border); border-radius: 6px; margin-bottom: 6px; height: 42px; }
</style>

<?php require __DIR__ . '/includes/footer.php'; ?>
