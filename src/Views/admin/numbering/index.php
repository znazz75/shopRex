<?php
/**
 * Admin -> Numbering: one card per sequence type (customer/invoice/RMA
 * ticket/withdrawal request - see NumberingAdminController::TYPES), all
 * saved together via one POST, same multi-card-single-form shape as
 * Admin -> Settings. Order numbers are intentionally not configurable
 * here - see sql/schema.sql's comment on the number_sequences table.
 *
 * @var array $errors    Validation error messages to show above the form.
 * @var array $sequences Every sequence type's current (or just-submitted, on a failed save) row, keyed by type.
 * @var array $types     NumberingAdminController::TYPES, in display order.
 */
$base = rtrim(SITE_URL, '/') . '/admin/numbering';
?>
<div class="page-header"><h1><?= e(__('admin.numbering')) ?></h1></div>
<p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.numbering.intro')) ?></p>

<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<form method="post" action="<?= e($base) ?>">
  <?= csrfField() ?>

  <?php foreach ($types as $type): ?>
    <?php $seq = $sequences[$type] ?? []; ?>
    <div class="card">
      <h2 style="margin-top:0;"><?= e(__('admin.numbering.type_' . $type)) ?></h2>
      <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.numbering.type_' . $type . '_hint')) ?></p>
      <div class="form-grid">
        <div class="form-group">
          <label for="prefix_<?= e($type) ?>"><?= e(__('admin.numbering.prefix')) ?></label>
          <input type="text" id="prefix_<?= e($type) ?>" name="prefix[<?= e($type) ?>]" maxlength="20" value="<?= e($seq['prefix'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="suffix_<?= e($type) ?>"><?= e(__('admin.numbering.suffix')) ?></label>
          <input type="text" id="suffix_<?= e($type) ?>" name="suffix[<?= e($type) ?>]" maxlength="20" value="<?= e($seq['suffix'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="date_format_<?= e($type) ?>"><?= e(__('admin.numbering.date_format')) ?></label>
          <input type="text" id="date_format_<?= e($type) ?>" name="date_format[<?= e($type) ?>]" maxlength="20" placeholder="<?= e(__('admin.numbering.date_format_placeholder')) ?>" value="<?= e($seq['date_format'] ?? '') ?>">
          <p style="color:var(--color-muted);font-size:12px;margin:4px 0 0;"><?= e(__('admin.numbering.date_format_hint')) ?></p>
        </div>
        <div class="form-group">
          <label for="padding_<?= e($type) ?>"><?= e(__('admin.numbering.padding')) ?></label>
          <input type="number" id="padding_<?= e($type) ?>" name="padding[<?= e($type) ?>]" min="1" max="10" value="<?= (int)($seq['padding'] ?? 6) ?>">
        </div>
        <div class="form-group">
          <label for="start_value_<?= e($type) ?>"><?= e(__('admin.numbering.start_value')) ?></label>
          <input type="number" id="start_value_<?= e($type) ?>" name="start_value[<?= e($type) ?>]" min="0" value="<?= (int)($seq['start_value'] ?? 1) ?>">
        </div>
        <div class="form-group">
          <label for="increment_<?= e($type) ?>"><?= e(__('admin.numbering.increment')) ?></label>
          <input type="number" id="increment_<?= e($type) ?>" name="increment[<?= e($type) ?>]" min="1" value="<?= (int)($seq['increment'] ?? 1) ?>">
        </div>
        <div class="form-group">
          <label for="next_value_<?= e($type) ?>"><?= e(__('admin.numbering.next_value')) ?></label>
          <input type="number" id="next_value_<?= e($type) ?>" name="next_value[<?= e($type) ?>]" min="0" value="<?= (int)($seq['next_value'] ?? 1) ?>">
          <p style="color:var(--color-muted);font-size:12px;margin:4px 0 0;"><?= e(__('admin.numbering.next_value_hint')) ?></p>
        </div>
        <div class="form-group" style="align-self:end;">
          <label><input type="checkbox" name="reset_on_date_change[<?= e($type) ?>]" value="1" style="width:auto;" <?= !empty($seq['reset_on_date_change']) ? 'checked' : '' ?>> <?= e(__('admin.numbering.reset_on_date_change')) ?></label>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <button class="btn" type="submit"><?= e(__('common.save')) ?></button>
</form>
