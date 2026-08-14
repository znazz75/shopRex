<?php
/**
 * @var array $errors
 * @var array|null $editItem
 * @var string $activeLocation
 * @var array $categories
 * @var array $pages
 * @var array $menuTree
 */
?>
<div class="page-header"><h1><?= e(__('admin.menus')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="toolbar">
  <a class="btn <?= $activeLocation === 'main' ? '' : 'btn-secondary' ?>" href="<?= rtrim(SITE_URL, '/') ?>/admin/menus?location=main"><?= e(__('admin.menus.main_menu')) ?></a>
  <a class="btn <?= $activeLocation === 'footer' ? '' : 'btn-secondary' ?>" href="<?= rtrim(SITE_URL, '/') ?>/admin/menus?location=footer"><?= e(__('admin.menus.footer_menu')) ?></a>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editItem['id']) ? e(__('admin.menus.edit_title')) : e(__('admin.menus.add_title')) ?></h2>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/menus">
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
          <?php foreach ($menuTreeFlat as $node): ?>
            <?php if (!empty($editItem['id']) && (int)$node['id'] === (int)$editItem['id']) continue; ?>
            <option value="<?= (int)$node['id'] ?>" <?= (($editItem['parent_id'] ?? null) == $node['id']) ? 'selected' : '' ?>>
              <?= str_repeat('&mdash; ', $node['depth']) ?><?= e($node['label']) ?>
            </option>
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
    <?php if (!empty($editItem['id'])): ?><a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/menus?location=<?= e($activeLocation) ?>"><?= e(__('common.cancel')) ?></a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= $activeLocation === 'main' ? e(__('admin.menus.main_menu_items')) : e(__('admin.menus.footer_menu_items')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= str_replace('%icon%', '<i class="bi-arrows-move" style="font-style:normal;">&#10021;</i>', e(__('admin.menus.drag_hint'))) ?>
  </p>
  <?php \ShopRex\Support\MenuAdminTreeRenderer::render($menuTree, $activeLocation); ?>
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
        $.post('<?= rtrim(SITE_URL, '/') ?>/admin/menus/reorder', {
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
