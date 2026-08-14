<?php
/**
 * @var array $errors
 * @var array|null $editMethod
 * @var array $editTiers
 * @var array $methods
 */
?>
<div class="page-header"><h1><?= e(__('admin.shipping')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= !empty($editMethod['id']) ? e(__('admin.shipping.edit_title')) : e(__('admin.shipping.add_title')) ?></h2>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/shipping" id="shippingForm">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= e($editMethod['id'] ?? '') ?>">
    <div class="form-grid">
      <div class="form-group"><label for="name"><?= e(__('admin.shipping.method_name')) ?></label><input type="text" id="name" name="name" required placeholder="<?= e(__('admin.shipping.method_name_placeholder')) ?>" value="<?= e($editMethod['name'] ?? '') ?>"></div>
      <div class="form-group"><label for="sort_order"><?= e(__('admin.shipping.sort_order')) ?></label><input type="number" id="sort_order" name="sort_order" value="<?= e($editMethod['sort_order'] ?? '0') ?>"></div>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="is_active" value="1" style="width:auto;" <?= ($editMethod['is_active'] ?? 1) ? 'checked' : '' ?>> <?= e(__('common.active')) ?></label>
    </div>

    <fieldset>
      <legend><?= e(__('admin.shipping.weight_tiers')) ?></legend>
      <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.shipping.weight_tiers_hint')) ?></p>
      <div id="tierRows">
        <?php foreach ($editTiers as $tier): ?>
          <div class="form-grid tier-row" style="align-items:end;">
            <div class="form-group"><label><?= e(__('admin.shipping.up_to_weight')) ?></label><input type="number" step="0.01" min="0" name="tier_up_to[]" value="<?= e($tier['up_to_weight_kg']) ?>"></div>
            <div class="form-group"><label><?= e(__('admin.shipping.tier_price')) ?></label><input type="number" step="0.01" min="0" name="tier_price[]" value="<?= e($tier['price']) ?>"></div>
            <div class="form-group"><button type="button" class="btn btn-sm btn-secondary" onclick="removeTierRow(this)"><?= e(__('common.delete')) ?></button></div>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="button" class="btn btn-sm btn-secondary" onclick="addTierRow()"><?= e(__('admin.shipping.add_tier')) ?></button>
    </fieldset>

    <fieldset>
      <legend><?= e(__('admin.shipping.extra_step_legend')) ?></legend>
      <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.shipping.extra_step_hint')) ?></p>
      <div class="form-grid">
        <div class="form-group"><label for="extra_weight_step_kg"><?= e(__('admin.shipping.extra_step_kg')) ?></label><input type="number" step="0.01" min="0" id="extra_weight_step_kg" name="extra_weight_step_kg" value="<?= e($editMethod['extra_weight_step_kg'] ?? '') ?>"></div>
        <div class="form-group"><label for="extra_weight_step_price"><?= e(__('admin.shipping.extra_step_price')) ?></label><input type="number" step="0.01" min="0" id="extra_weight_step_price" name="extra_weight_step_price" value="<?= e($editMethod['extra_weight_step_price'] ?? '') ?>"></div>
      </div>
    </fieldset>

    <fieldset>
      <legend><?= e(__('admin.shipping.free_shipping_legend')) ?></legend>
      <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.shipping.free_shipping_hint')) ?></p>
      <div class="form-grid">
        <div class="form-group"><label for="free_shipping_min_order_value"><?= e(__('admin.shipping.free_min_value')) ?></label><input type="number" step="0.01" min="0" id="free_shipping_min_order_value" name="free_shipping_min_order_value" value="<?= e($editMethod['free_shipping_min_order_value'] ?? '') ?>"></div>
        <div class="form-group"><label for="free_shipping_min_quantity"><?= e(__('admin.shipping.free_min_qty')) ?></label><input type="number" min="1" id="free_shipping_min_quantity" name="free_shipping_min_quantity" value="<?= e($editMethod['free_shipping_min_quantity'] ?? '') ?>"></div>
      </div>
    </fieldset>

    <button class="btn" type="submit"><?= e(__('admin.shipping.save')) ?></button>
    <?php if (!empty($editMethod['id'])): ?><a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/shipping"><?= e(__('common.cancel')) ?></a><?php endif; ?>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.shipping.method_name')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('admin.shipping.weight_tiers')) ?></th><th><?= e(__('admin.shipping.free_shipping_legend')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($methods as $m): ?>
    <tr>
      <td><?= e($m['name']) ?></td>
      <td><span class="badge badge-<?= $m['is_active'] ? 'completed' : 'cancelled' ?>"><?= $m['is_active'] ? e(__('common.active')) : e(__('admin.admins.disabled')) ?></span></td>
      <td><?= (int)$m['tier_count'] ?></td>
      <td>
        <?php if ($m['free_shipping_min_order_value'] !== null): ?>
          <?= e(__('admin.shipping.free_summary_value', ['value' => formatPrice((float)$m['free_shipping_min_order_value'])])) ?><br>
        <?php endif; ?>
        <?php if ($m['free_shipping_min_quantity'] !== null): ?>
          <?= e(__('admin.shipping.free_summary_qty', ['n' => $m['free_shipping_min_quantity']])) ?>
        <?php endif; ?>
        <?php if ($m['free_shipping_min_order_value'] === null && $m['free_shipping_min_quantity'] === null): ?>&mdash;<?php endif; ?>
      </td>
      <td>
        <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/shipping?edit=<?= (int)$m['id'] ?>"><?= e(__('common.edit')) ?></a>
        <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/shipping" style="display:inline;" data-confirm="<?= e(__('admin.shipping.confirm_delete', ['name' => $m['name']])) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$m['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($methods)): ?><tr><td colspan="5"><?= e(__('admin.shipping.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>

<script>
  function addTierRow() {
    var container = document.getElementById('tierRows');
    var row = document.createElement('div');
    row.className = 'form-grid tier-row';
    row.style.alignItems = 'end';
    row.innerHTML =
      '<div class="form-group"><label><?= e(__('admin.shipping.up_to_weight')) ?></label><input type="number" step="0.01" min="0" name="tier_up_to[]"></div>' +
      '<div class="form-group"><label><?= e(__('admin.shipping.tier_price')) ?></label><input type="number" step="0.01" min="0" name="tier_price[]"></div>' +
      '<div class="form-group"><button type="button" class="btn btn-sm btn-secondary" onclick="removeTierRow(this)"><?= e(__('common.delete')) ?></button></div>';
    container.appendChild(row);
  }
  function removeTierRow(btn) {
    var rows = document.querySelectorAll('.tier-row');
    if (rows.length <= 1) {
      btn.closest('.tier-row').querySelectorAll('input').forEach(function (i) { i.value = ''; });
      return;
    }
    btn.closest('.tier-row').remove();
  }
</script>
