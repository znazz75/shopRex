<?php
/**
 * Admin -> Menus: one combined list+edit-form page for the two menu
 * "locations" the storefront renders (main nav and footer nav - see
 * Services\MenuTreeService / Support\StorefrontMenuRenderer for how these
 * get rendered on the storefront side). Same create-vs-edit-share-one-form
 * pattern as Categories/Tax Rates (decided by whether $editItem is set). A
 * menu item can point at a custom URL, a category, or a CMS page - which
 * one is picked drives which of the three "link value" inputs further
 * down is actually used (see toggleLinkValueField() in the <script> at
 * the bottom). Item ordering is drag-and-drop (jQuery UI "sortable"),
 * saved via a background AJAX POST to /admin/menus/reorder rather than a
 * form submit - see the `$('.menu-sortable').sortable(...)` block below.
 *
 * @var array       $errors         Validation error messages to show above the form.
 * @var array       $availableLangs Enabled languages as [code => label], for the label language tabs.
 * @var string      $lang           Which language's label is currently being edited/shown (a query-string driven tab, not the admin UI language).
 * @var string      $defaultLang    The shop's default language - the Label field is required and drives menu_items.label only on this tab; any other tab's label is an optional translation.
 * @var array|null  $editItem       The menu item being edited, or null when the form is in "create new" mode.
 * @var string      $labelForLang   The Label field's value for $lang specifically - menu_items.label on the default-language tab, that language's (possibly blank) translation otherwise.
 * @var string      $activeLocation Which menu is currently being managed: 'main' or 'footer'.
 * @var array       $categories     Every category, flattened with a 'depth' key, for the "link to a category" dropdown.
 * @var array       $pages          Every CMS page (slug + title), for the "link to a page" dropdown.
 * @var array       $menuTree       $activeLocation's menu items as a nested parent/child tree, for the drag-and-drop list rendered by Support\MenuAdminTreeRenderer::render() below.
 * @var array       $menuTreeFlat   The same items as $menuTree but flattened with a 'depth' key, for the "parent item" dropdown in the edit form (a flat list is what a <select> needs; the nested tree is only useful for the sortable list UI).
 */
?>
<div class="page-header"><h1><?= e(__('admin.menus')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<?php /* Switches which of the two menu locations (main nav / footer nav) the whole rest of this page is managing - everything below (form + item list) only ever shows $activeLocation's items. Carries the active language tab along too, so switching location doesn't reset it. */ ?>
<div class="toolbar">
  <a class="btn <?= $activeLocation === 'main' ? '' : 'btn-secondary' ?>" href="<?= rtrim(SITE_URL, '/') ?>/admin/menus?location=main&lang=<?= e($lang) ?>"><?= e(__('admin.menus.main_menu')) ?></a>
  <a class="btn <?= $activeLocation === 'footer' ? '' : 'btn-secondary' ?>" href="<?= rtrim(SITE_URL, '/') ?>/admin/menus?location=footer&lang=<?= e($lang) ?>"><?= e(__('admin.menus.footer_menu')) ?></a>
</div>

<?php /* Language tab bar for the item Label field - only shown when there's more than one language to switch between. Switching preserves which item is being edited (?edit=) and which location tab is active, so you don't lose your place. Same mechanism as Admin -> Categories' name/intro-text tabs (v3.09/v3.10). */ ?>
<?php if (count($availableLangs) > 1): ?>
  <div class="toolbar">
    <?php foreach ($availableLangs as $code => $label): ?>
      <a class="btn <?= $code === $lang ? '' : 'btn-secondary' ?>" href="<?= rtrim(SITE_URL, '/') ?>/admin/menus?location=<?= e($activeLocation) ?>&lang=<?= e($code) ?><?= !empty($editItem['id']) ? '&edit=' . (int)$editItem['id'] : '' ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editItem['id']) ? e(__('admin.menus.edit_title')) : e(__('admin.menus.add_title')) ?></h2>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/menus">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= e($editItem['id'] ?? '') ?>">
    <input type="hidden" name="language" value="<?= e($lang) ?>">
    <div class="form-grid">
      <div class="form-group">
        <label for="location"><?= e(__('admin.menus.menu_label')) ?></label>
        <select id="location" name="location">
          <option value="main" <?= $activeLocation === 'main' ? 'selected' : '' ?>><?= e(__('admin.menus.main_menu')) ?></option>
          <option value="footer" <?= $activeLocation === 'footer' ? 'selected' : '' ?>><?= e(__('admin.menus.footer_menu')) ?></option>
        </select>
      </div>
      <div class="form-group">
        <?php /* Required + drives menu_items.label only on the default-language tab; on any other tab it's an optional translation (blank falls back to the default label on the storefront), same treatment as Categories' Name field. */ ?>
        <label for="label"><?= $lang === $defaultLang ? e(__('admin.menus.item_label')) : e(__('admin.menus.label_lang_label', ['lang' => $availableLangs[$lang]])) ?></label>
        <input type="text" id="label" name="label" <?= $lang === $defaultLang ? 'required' : '' ?> value="<?= e($labelForLang) ?>">
      </div>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label for="link_type"><?= e(__('admin.menus.link_type')) ?></label>
        <?php /* Changing this shows/hides the matching one of the three "link value" inputs below (see toggleLinkValueField() in the <script> block at the bottom) - only one of them is ever actually used, based on this choice. */ ?>
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
            <?php /* Same reasoning as Categories' parent dropdown: a menu item can't be nested under itself, so skip it from its own "parent" options while editing. */ ?>
            <?php if (!empty($editItem['id']) && (int)$node['id'] === (int)$editItem['id']) continue; ?>
            <option value="<?= (int)$node['id'] ?>" <?= (($editItem['parent_id'] ?? null) == $node['id']) ? 'selected' : '' ?>>
              <?= str_repeat('&mdash; ', $node['depth']) ?><?= e($node['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <?php /* Three alternative "where does this link go" inputs, one per link_type. Only one is visible at a time (JS-toggled), and only the visible one's value gets copied into the real "link_value" hidden field on submit (see the form's submit listener in the <script> block below) - none of these three inputs has a "name" attribute of its own, which is what stops the two hidden/inactive ones from also being submitted. */ ?>
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
    <?php /* This is the ONE input actually submitted with the form (name="link_value") - its value gets overwritten from whichever of the three inputs above is currently active, right before submit. */ ?>
    <input type="hidden" name="link_value" id="link_value" value="<?= e($editItem['link_value'] ?? '') ?>">

    <div class="form-grid">
      <div class="form-group"><label><input type="checkbox" name="open_new_tab" value="1" style="width:auto;" <?= !empty($editItem['open_new_tab']) ? 'checked' : '' ?>> <?= e(__('admin.menus.open_new_tab')) ?></label></div>
      <div class="form-group"><label><input type="checkbox" name="is_active" value="1" style="width:auto;" <?= ($editItem['is_active'] ?? 1) ? 'checked' : '' ?>> <?= e(__('admin.menus.active_visible')) ?></label></div>
    </div>

    <button class="btn" type="submit"><?= e(__('admin.menus.save')) ?></button>
    <?php if (!empty($editItem['id'])): ?><a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/menus?location=<?= e($activeLocation) ?>&lang=<?= e($lang) ?>"><?= e(__('common.cancel')) ?></a><?php endif; ?>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= $activeLocation === 'main' ? e(__('admin.menus.main_menu_items')) : e(__('admin.menus.footer_menu_items')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= str_replace('%icon%', '<i class="bi-arrows-move" style="font-style:normal;">&#10021;</i>', e(__('admin.menus.drag_hint'))) ?>
  </p>
  <?php /* Renders the nested <ul>/<li> drag-and-drop item list (with the ↕ drag handle, edit/delete links per item, etc.) - this Support class is a presentation-only static renderer, not a service; it just turns $menuTree into the matching HTML. See CLAUDE.md's Support/ section. */ ?>
  <?php \ShopRex\Support\MenuAdminTreeRenderer::render($menuTree, $activeLocation); ?>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
  // csrfToken() (a PHP global helper, not a JS function) returns the same
  // token csrfField() embeds in the form above - reused here so the
  // background reorder request below can pass CSRF verification too.
  var csrfToken = <?= json_encode(csrfToken()) ?>;

  // Shows only the one "link value" input matching the currently selected
  // link_type, hiding the other two (see the three linkValue* blocks above).
  function toggleLinkValueField() {
    var type = document.getElementById('link_type').value;
    document.getElementById('linkValueCustom').style.display = type === 'custom' ? 'block' : 'none';
    document.getElementById('linkValueCategory').style.display = type === 'category' ? 'block' : 'none';
    document.getElementById('linkValuePage').style.display = type === 'page' ? 'block' : 'none';
  }
  // Run once on page load too, so the form starts in the right state for
  // whichever link_type $editItem already has (not just after a change).
  toggleLinkValueField();

  // Right before the form actually submits, copy whichever of the three
  // "link value" inputs is currently visible/active into the real hidden
  // "link_value" field - that's the only one of the four that has a form
  // "name" and gets sent to the server.
  document.querySelector('form').addEventListener('submit', function () {
    var type = document.getElementById('link_type').value;
    var input = type === 'custom' ? document.getElementById('link_value_custom')
      : type === 'category' ? document.getElementById('link_value_category')
      : document.getElementById('link_value_page');
    document.getElementById('link_value').value = input.value;
  });

  // Makes each menu-item list (rendered by MenuAdminTreeRenderer above)
  // drag-and-drop sortable via jQuery UI. Dropping an item into a new
  // position fires this "update" callback, which fires a background POST
  // to /admin/menus/reorder with the new order of ids - no page reload,
  // and no relation to the create/edit form above (this reorders existing
  // items; the form above adds a new one or edits one item's own fields).
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
