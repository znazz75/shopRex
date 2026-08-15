<?php
/**
 * Admin -> Tax Rates: one combined list+edit-form page (linked from
 * Settings, not its own nav entry - see the "back to settings" link
 * below). Same create-vs-edit pattern as Categories: the same form is
 * used for both, decided by whether $editRate is set.
 *
 * @var array      $errors   Validation error messages to show above the form.
 * @var array|null $editRate The tax rate being edited, or null when the form is in "create new" mode.
 * @var array      $rates    Every tax rate row (name, rate, is_default, product_count), for the table below.
 */
?>
<div class="page-header">
  <h1><?= e(__('admin.tax_rates')) ?></h1>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/settings">&larr; <?= e(__('admin.tax_rates.back_to_settings')) ?></a>
</div>
<?php /* vatIsEnabled() reads the 'vat_enabled' setting via Services\TaxCalculator - when the shop owner has turned VAT off entirely, tax rates configured here are unused, so show a hint explaining why nothing happens with prices. */ ?>
<?php if (!vatIsEnabled()): ?>
  <div class="flash flash-info"><?= e(__('admin.tax_rates.vat_disabled_notice')) ?></div>
<?php endif; ?>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <?php /* Heading text switches between "Add" and "Edit" based on which mode the shared form is in. */ ?>
  <h2 style="margin-top:0;"><?= !empty($editRate['id']) ? e(__('admin.tax_rates.edit_title')) : e(__('admin.tax_rates.add_title')) ?></h2>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/tax-rates" class="form-grid">
    <?= csrfField() ?>
    <?php /* Empty "id" = create a new rate; a real id = update the existing one with that id. */ ?>
    <input type="hidden" name="id" value="<?= e($editRate['id'] ?? '') ?>">
    <div class="form-group"><label for="name"><?= e(__('admin.products.name')) ?></label><input type="text" id="name" name="name" required placeholder="<?= e(__('admin.tax_rates.name_placeholder')) ?>" value="<?= e($editRate['name'] ?? '') ?>"></div>
    <div class="form-group"><label for="rate"><?= e(__('admin.tax_rates.rate_percent')) ?></label><input type="number" step="0.01" min="0" max="100" id="rate" name="rate" required value="<?= e($editRate['rate'] ?? '') ?>"></div>
    <div class="form-group" style="align-self:end;">
      <label><input type="checkbox" name="is_default" value="1" style="width:auto;" <?= !empty($editRate['is_default']) ? 'checked' : '' ?>> <?= e(__('admin.tax_rates.default_rate')) ?></label>
    </div>
    <div class="form-group" style="align-self:end;"><button class="btn" type="submit"><?= e(__('common.save')) ?></button></div>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('admin.tax_rates.rate')) ?></th><th><?= e(__('admin.tax_rates.default_col')) ?></th><th><?= e(__('admin.tax_rates.products_using')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($rates as $r): ?>
    <tr>
      <td><?= e($r['name']) ?></td>
      <td><?= e($r['rate']) ?>%</td>
      <?php /* Only one rate at a time is the shop's default (auto-applied to products without an explicit rate); the badge is blank for every other row. */ ?>
      <td><?= $r['is_default'] ? '<span class="badge badge-completed">' . e(__('admin.tax_rates.default_col')) . '</span>' : '' ?></td>
      <td><?= (int)$r['product_count'] ?></td>
      <td>
        <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/tax-rates?edit=<?= (int)$r['id'] ?>"><?= e(__('common.edit')) ?></a>
        <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/tax-rates" style="display:inline;" data-confirm="<?= e(__('admin.tax_rates.confirm_delete', ['name' => $r['name']])) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$r['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php /* Empty-state row when no tax rates have been configured yet. */ ?>
  <?php if (empty($rates)): ?><tr><td colspan="5"><?= e(__('admin.tax_rates.none_yet')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
