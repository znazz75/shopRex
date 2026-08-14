<?php
/**
 * @var int|null $id
 * @var array|null $product
 * @var array $options
 * @var array $variantStockByCombo
 * @var array $categories
 * @var array $availableLangs
 * @var string $defaultLang
 * @var array $otherLanguages
 * @var array $productTranslations
 * @var array $optionTranslationsForForm
 * @var array $taxRates
 * @var bool $vatEnabled
 * @var array $errors
 * Direct port of admin/product_edit.php's body.
 */
$actionUrl = rtrim(SITE_URL, '/') . '/admin/products' . ($id ? '/' . $id . '/edit' : '/create');
?>
<div class="page-header"><h1><?= e($pageTitle) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<form method="post" action="<?= e($actionUrl) ?>">
  <?= csrfField() ?>
  <div class="card">
    <div class="form-group"><label for="sku"><?= e(__('admin.dashboard.sku')) ?></label><input type="text" id="sku" name="sku" required value="<?= e($product['sku'] ?? '') ?>"></div>

    <?php if (count($availableLangs) > 1): ?>
      <div class="lang-tabs" role="tablist">
        <?php foreach ($availableLangs as $code => $label): ?>
          <button type="button" class="btn btn-sm lang-tab-btn <?= $code === $defaultLang ? '' : 'btn-secondary' ?>" data-lang-tab="<?= e($code) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php foreach ($availableLangs as $code => $label): ?>
      <div data-lang-panel="<?= e($code) ?>" <?= $code === $defaultLang ? '' : 'style="display:none;"' ?>>
        <?php if ($code === $defaultLang): ?>
          <div class="form-group"><label for="name"><?= e(__('admin.products.name')) ?></label><input type="text" id="name" name="name" required value="<?= e($product['name'] ?? '') ?>"></div>
          <div class="form-group"><label for="short_description"><?= e(__('admin.product_edit.short_description')) ?></label><input type="text" id="short_description" name="short_description" value="<?= e($product['short_description'] ?? '') ?>"></div>
          <div class="form-group"><label for="description"><?= e(__('product.description')) ?></label><textarea id="description" name="description" rows="4"><?= e($product['description'] ?? '') ?></textarea></div>
        <?php else: ?>
          <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.product_edit.translation_hint', ['lang' => $availableLangs[$defaultLang]])) ?></p>
          <div class="form-group">
            <label for="name_translations_<?= e($code) ?>"><?= e(__('admin.products.name')) ?></label>
            <input type="text" id="name_translations_<?= e($code) ?>" name="name_translations[<?= e($code) ?>]" value="<?= e($productTranslations[$code]['name'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="short_description_translations_<?= e($code) ?>"><?= e(__('admin.product_edit.short_description')) ?></label>
            <input type="text" id="short_description_translations_<?= e($code) ?>" name="short_description_translations[<?= e($code) ?>]" value="<?= e($productTranslations[$code]['short_description'] ?? '') ?>">
          </div>
          <div class="form-group">
            <label for="description_translations_<?= e($code) ?>"><?= e(__('product.description')) ?></label>
            <textarea id="description_translations_<?= e($code) ?>" name="description_translations[<?= e($code) ?>]" rows="4"><?= e($productTranslations[$code]['description'] ?? '') ?></textarea>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="form-grid">
      <div class="form-group">
        <label for="category_id"><?= e(__('admin.products.category')) ?></label>
        <select id="category_id" name="category_id">
          <option value="">-- <?= e(__('common.none')) ?> --</option>
          <?php foreach ($categories as $cat): ?>
            <option value="<?= (int)$cat['id'] ?>" <?= (($product['category_id'] ?? null) == $cat['id']) ? 'selected' : '' ?>>
              <?= str_repeat('&mdash; ', $cat['depth']) ?><?= e($cat['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="status"><?= e(__('common.status')) ?></label>
        <select id="status" name="status">
          <?php foreach (['active', 'draft', 'archived'] as $s): ?>
            <option value="<?= $s ?>" <?= (($product['status'] ?? 'active') === $s) ? 'selected' : '' ?>><?= e(__('admin.product_edit.status_' . $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php
    $currentTaxRateId = $product['tax_rate_id'] ?? null;
    if ($currentTaxRateId === null) {
        foreach ($taxRates as $tr) {
            if ($tr['is_default']) { $currentTaxRateId = $tr['id']; break; }
        }
    }
    $currentMode = $product['price_entry_mode'] ?? 'net';
    $currentRatePercent = 0.0;
    foreach ($taxRates as $tr) {
        if ((int)$tr['id'] === (int)$currentTaxRateId) $currentRatePercent = (float)$tr['rate'];
    }
    $prefillPrice = '';
    if (isset($product['price'])) {
        $prefillPrice = $currentMode === 'gross' && $currentRatePercent > 0
            ? round((float)$product['price'] * (1 + $currentRatePercent / 100), 2)
            : (float)$product['price'];
    }
    ?>
    <fieldset style="margin:0 0 16px;">
      <legend style="font-size:15px;"><?= e(__('common.price')) ?><?= $vatEnabled ? ' &amp; ' . e(__('common.tax')) : '' ?></legend>
      <?php if ($vatEnabled): ?>
        <div class="form-group">
          <label><?= e(__('admin.product_edit.enter_price_as')) ?></label>
          <label style="font-weight:normal;margin-right:16px;"><input type="radio" name="price_entry_mode" value="net" <?= $currentMode !== 'gross' ? 'checked' : '' ?> style="width:auto;" onchange="syncPriceMode()"> <?= e(__('admin.product_edit.price_net')) ?></label>
          <label style="font-weight:normal;"><input type="radio" name="price_entry_mode" value="gross" <?= $currentMode === 'gross' ? 'checked' : '' ?> style="width:auto;" onchange="syncPriceMode()"> <?= e(__('admin.product_edit.price_gross')) ?></label>
        </div>
      <?php else: ?>
        <input type="hidden" name="price_entry_mode" value="net">
      <?php endif; ?>
      <div class="form-grid">
        <div class="form-group">
          <label for="price_input"><?= e(__('common.price')) ?></label>
          <input type="number" step="0.01" id="price_input" name="price_input" required value="<?= e($prefillPrice) ?>" oninput="syncPriceMode()">
          <small id="priceConversionHint" style="color:var(--color-muted);"></small>
        </div>
        <?php if ($vatEnabled): ?>
          <div class="form-group">
            <label for="tax_rate_id"><?= e(__('admin.product_edit.tax_rate')) ?></label>
            <select id="tax_rate_id" name="tax_rate_id" onchange="syncPriceMode()">
              <?php foreach ($taxRates as $tr): ?>
                <option value="<?= (int)$tr['id'] ?>" data-rate="<?= e($tr['rate']) ?>" <?= (int)$currentTaxRateId === (int)$tr['id'] ? 'selected' : '' ?>>
                  <?= e($tr['name']) ?> (<?= e($tr['rate']) ?>%)<?= $tr['is_default'] ? ' - ' . e(__('admin.product_edit.default')) : '' ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>
      </div>
    </fieldset>
    <div class="form-grid">
      <div class="form-group"><label for="stock_quantity"><?= e(__('admin.product_edit.stock_quantity')) ?></label><input type="number" id="stock_quantity" name="stock_quantity" value="<?= e($product['stock_quantity'] ?? '0') ?>"></div>
      <div class="form-group"><label for="stock_threshold"><?= e(__('admin.product_edit.stock_threshold')) ?></label><input type="number" id="stock_threshold" name="stock_threshold" value="<?= e($product['stock_threshold'] ?? '5') ?>"></div>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label for="max_order_quantity"><?= e(__('admin.product_edit.max_order_quantity')) ?></label>
        <input type="number" id="max_order_quantity" name="max_order_quantity" min="1" value="<?= e($product['max_order_quantity'] ?? '') ?>" placeholder="<?= e(__('admin.product_edit.max_order_quantity_placeholder')) ?>">
        <small style="color:var(--color-muted);"><?= e(__('admin.product_edit.max_order_quantity_hint')) ?></small>
      </div>
      <div class="form-group"><label for="weight_kg"><?= e(__('admin.product_edit.weight_kg')) ?></label><input type="number" step="0.01" id="weight_kg" name="weight_kg" value="<?= e($product['weight_kg'] ?? '') ?>"></div>
    </div>
  </div>

  <script>
    var grossSuffixTpl = <?= json_encode(__('admin.product_edit.gross_incl_vat')) ?>;
    var netSuffix = <?= json_encode(__('admin.product_edit.net')) ?>;
    function syncPriceMode() {
      var priceInput = document.getElementById('price_input');
      var hint = document.getElementById('priceConversionHint');
      var modeInput = document.querySelector('input[name="price_entry_mode"]:checked');
      var mode = modeInput ? modeInput.value : 'net';
      var rateSelect = document.getElementById('tax_rate_id');
      var rate = rateSelect ? parseFloat(rateSelect.options[rateSelect.selectedIndex].dataset.rate || '0') : 0;
      var amount = parseFloat(priceInput.value || '0');
      if (!rate || !amount) { hint.textContent = ''; return; }
      if (mode === 'net') {
        hint.textContent = '= ' + (amount * (1 + rate / 100)).toFixed(2) + ' ' + grossSuffixTpl.replace('{rate}', rate);
      } else {
        hint.textContent = '= ' + (amount / (1 + rate / 100)).toFixed(2) + ' ' + netSuffix;
      }
    }
    document.addEventListener('DOMContentLoaded', syncPriceMode);
  </script>

  <script>
    document.querySelectorAll('.lang-tab-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        var lang = btn.dataset.langTab;
        document.querySelectorAll('.lang-tab-btn').forEach(function (b) {
          b.classList.toggle('btn-secondary', b.dataset.langTab !== lang);
        });
        document.querySelectorAll('[data-lang-panel]').forEach(function (panel) {
          panel.style.display = (panel.dataset.langPanel === lang) ? '' : 'none';
        });
      });
    });
  </script>

  <fieldset>
    <legend><?= e(__('admin.product_edit.discount')) ?></legend>
    <p style="color:var(--color-muted);font-size:13px;margin-top:0;">
      <?= e(__('admin.product_edit.discount_hint')) ?>
    </p>
    <div class="form-grid">
      <div class="form-group">
        <label for="discount_type"><?= e(__('admin.product_edit.discount_type')) ?></label>
        <select id="discount_type" name="discount_type">
          <option value="none" <?= (($product['discount_type'] ?? 'none') === 'none') ? 'selected' : '' ?>><?= e(__('common.none')) ?></option>
          <option value="percent" <?= (($product['discount_type'] ?? '') === 'percent') ? 'selected' : '' ?>><?= e(__('admin.product_edit.discount_percent')) ?></option>
          <option value="fixed" <?= (($product['discount_type'] ?? '') === 'fixed') ? 'selected' : '' ?>><?= e(__('admin.product_edit.discount_fixed')) ?></option>
        </select>
      </div>
      <div class="form-group"><label for="discount_value"><?= e(__('admin.product_edit.discount_value')) ?></label><input type="number" step="0.01" id="discount_value" name="discount_value" value="<?= e($product['discount_value'] ?? '') ?>"></div>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label for="discount_starts_at"><?= e(__('admin.product_edit.discount_starts')) ?></label>
        <input type="datetime-local" id="discount_starts_at" name="discount_starts_at" value="<?= e(isset($product['discount_starts_at']) ? str_replace(' ', 'T', substr((string)$product['discount_starts_at'], 0, 16)) : '') ?>">
      </div>
      <div class="form-group">
        <label for="discount_ends_at"><?= e(__('admin.product_edit.discount_ends')) ?></label>
        <input type="datetime-local" id="discount_ends_at" name="discount_ends_at" value="<?= e(isset($product['discount_ends_at']) ? str_replace(' ', 'T', substr((string)$product['discount_ends_at'], 0, 16)) : '') ?>">
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend><?= e(__('admin.product_edit.availability_window')) ?></legend>
    <p style="color:var(--color-muted);font-size:13px;margin-top:0;">
      <?= e(__('admin.product_edit.availability_window_hint')) ?>
    </p>
    <div class="form-grid">
      <div class="form-group">
        <label for="available_from"><?= e(__('admin.product_edit.available_from')) ?></label>
        <input type="datetime-local" id="available_from" name="available_from" value="<?= e(isset($product['available_from']) ? str_replace(' ', 'T', substr((string)$product['available_from'], 0, 16)) : '') ?>">
      </div>
      <div class="form-group">
        <label for="available_until"><?= e(__('admin.product_edit.available_until')) ?></label>
        <input type="datetime-local" id="available_until" name="available_until" value="<?= e(isset($product['available_until']) ? str_replace(' ', 'T', substr((string)$product['available_until'], 0, 16)) : '') ?>">
      </div>
    </div>
  </fieldset>

  <fieldset>
    <legend><?= e(__('admin.product_edit.legal_legend')) ?></legend>
    <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.product_edit.legal_hint')) ?></p>
    <div class="form-grid">
      <div class="form-group">
        <label for="statutory_warranty_months"><?= e(__('admin.product_edit.statutory_warranty_months')) ?></label>
        <input type="number" min="0" id="statutory_warranty_months" name="statutory_warranty_months" value="<?= e($product['statutory_warranty_months'] ?? '24') ?>">
      </div>
      <div class="form-group">
        <label for="manufacturer_warranty_months"><?= e(__('admin.product_edit.manufacturer_warranty_months')) ?></label>
        <input type="number" min="0" id="manufacturer_warranty_months" name="manufacturer_warranty_months" value="<?= e($product['manufacturer_warranty_months'] ?? '') ?>" placeholder="<?= e(__('admin.product_edit.manufacturer_warranty_placeholder')) ?>">
      </div>
    </div>
    <div class="form-group">
      <label for="manufacturer_warranty_notes"><?= e(__('admin.product_edit.manufacturer_warranty_notes')) ?></label>
      <input type="text" id="manufacturer_warranty_notes" name="manufacturer_warranty_notes" value="<?= e($product['manufacturer_warranty_notes'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="contains_battery" value="1" style="width:auto;" <?= !empty($product['contains_battery']) ? 'checked' : '' ?>> <?= e(__('admin.product_edit.contains_battery')) ?></label>
    </div>
    <div class="form-group mb-0">
      <label><input type="checkbox" name="is_hygiene_product" value="1" style="width:auto;" <?= !empty($product['is_hygiene_product']) ? 'checked' : '' ?>> <?= e(__('admin.product_edit.is_hygiene_product')) ?></label>
      <small style="color:var(--color-muted);display:block;"><?= e(__('admin.product_edit.is_hygiene_product_hint')) ?></small>
    </div>
  </fieldset>

  <div class="card">
    <div class="form-group mb-0">
      <label><?= e(__('admin.product_edit.images')) ?></label>
      <?php if ($id): ?>
        <div>
          <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/products/<?= (int)$id ?>/images"><?= e(__('admin.product_edit.manage_images')) ?></a>
          <span style="color:var(--color-muted);font-size:13px;margin-left:8px;">
            <?= e(__('admin.product_edit.manage_images_hint')) ?>
          </span>
        </div>
      <?php else: ?>
        <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.product_edit.save_first_for_images')) ?></p>
      <?php endif; ?>
    </div>
  </div>

  <fieldset>
    <legend><?= e(__('admin.product_edit.options_legend')) ?></legend>
    <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.product_edit.options_hint')) ?></p>
    <?php for ($i = 0; $i < 2; $i++): $existing = $options[$i] ?? null; ?>
      <div class="form-grid" data-option-row>
        <div class="form-group">
          <?php foreach ($availableLangs as $code => $label): ?>
            <div data-lang-panel="<?= e($code) ?>" <?= $code === $defaultLang ? '' : 'style="display:none;"' ?>>
              <label><?= e(__('admin.product_edit.option_name', ['n' => $i + 1])) ?><?= $code === $defaultLang ? '' : ' (' . e($label) . ')' ?></label>
              <?php if ($code === $defaultLang): ?>
                <input type="text" name="option_name[]" placeholder="<?= e(__('admin.product_edit.option_name_placeholder')) ?>" value="<?= e($existing['name'] ?? '') ?>" oninput="scheduleVariantRegen()">
              <?php else: ?>
                <input type="text" name="option_name_translations[<?= e($code) ?>][<?= $i ?>]" placeholder="<?= e(__('admin.product_edit.option_name_placeholder')) ?>" value="<?= e($optionTranslationsForForm['names'][$i][$code] ?? '') ?>">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="form-group">
          <?php foreach ($availableLangs as $code => $label): ?>
            <div data-lang-panel="<?= e($code) ?>" <?= $code === $defaultLang ? '' : 'style="display:none;"' ?>>
              <label><?= e(__('admin.product_edit.option_values')) ?><?= $code === $defaultLang ? '' : ' (' . e($label) . ')' ?></label>
              <?php if ($code === $defaultLang): ?>
                <input type="text" name="option_values[]" placeholder="<?= e(__('admin.product_edit.option_values_placeholder')) ?>"
                       value="<?= $existing ? e(implode(', ', array_column($existing['values'], 'value'))) : '' ?>" oninput="scheduleVariantRegen()">
              <?php else: ?>
                <input type="text" name="option_values_translations[<?= e($code) ?>][<?= $i ?>]" placeholder="<?= e(__('admin.product_edit.option_values_placeholder')) ?>" value="<?= e($optionTranslationsForForm['values'][$i][$code] ?? '') ?>">
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endfor; ?>
  </fieldset>

  <fieldset>
    <legend><?= e(__('admin.product_edit.variants_legend')) ?></legend>
    <p style="color:var(--color-muted);font-size:13px;margin-top:0;"><?= e(__('admin.product_edit.variants_hint')) ?></p>
    <div id="variantRows"></div>
    <button type="button" class="btn btn-sm btn-secondary" onclick="regenerateVariants(readVariantStockFromDom())"><?= e(__('admin.product_edit.regenerate_variants')) ?></button>
  </fieldset>

  <button class="btn" type="submit"><?= e(__('admin.product_edit.save')) ?></button>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/products"><?= e(__('common.cancel')) ?></a>
</form>

<script>
  var initialVariantStock = <?= json_encode($variantStockByCombo, JSON_FORCE_OBJECT) ?>;
  var noOptionsMsg = <?= json_encode(__('admin.product_edit.no_options_yet')) ?>;
  var variantRowLabelSep = <?= json_encode(': ') ?>;

  function escapeHtml(s) {
    var div = document.createElement('div');
    div.textContent = s;
    return div.innerHTML;
  }

  function readVariantStockFromDom() {
    var map = {};
    document.querySelectorAll('#variantRows .variant-row').forEach(function (row) {
      map[row.dataset.combo] = row.querySelector('[name="variant_stock[]"]').value;
    });
    return map;
  }

  function regenerateVariants(preserveStock) {
    var groups = [];
    document.querySelectorAll('[data-option-row]').forEach(function (row) {
      var name = row.querySelector('[name="option_name[]"]').value.trim();
      var valuesRaw = row.querySelector('[name="option_values[]"]').value.trim();
      if (!name || !valuesRaw) return;
      var values = valuesRaw.split(',').map(function (v) { return v.trim(); }).filter(function (v) { return v; });
      if (values.length) groups.push({ name: name, values: values });
    });

    var container = document.getElementById('variantRows');
    container.innerHTML = '';
    if (!groups.length) {
      container.innerHTML = '<p style="color:var(--color-muted);font-size:13px;">' + escapeHtml(noOptionsMsg) + '</p>';
      return;
    }

    var combos = [[]];
    groups.forEach(function (group) {
      var next = [];
      combos.forEach(function (prefix) {
        group.values.forEach(function (v) {
          next.push(prefix.concat([v]));
        });
      });
      combos = next;
    });

    combos.forEach(function (combo, i) {
      var comboKey = combo.join('||');
      var label = combo.map(function (v, gi) { return groups[gi].name + variantRowLabelSep + v; }).join(', ');
      var stockVal = Object.prototype.hasOwnProperty.call(preserveStock, comboKey) ? preserveStock[comboKey] : '0';
      var row = document.createElement('div');
      row.className = 'form-grid variant-row';
      row.dataset.combo = comboKey;
      row.style.alignItems = 'center';
      row.innerHTML =
        '<div class="form-group" style="margin-bottom:0;">' + escapeHtml(label) + '</div>' +
        '<div class="form-group" style="margin-bottom:0;">' +
        '<input type="number" min="0" name="variant_stock[]" value="' + escapeHtml(String(stockVal)) + '">' +
        '<input type="hidden" name="variant_combo[]" value="' + escapeHtml(comboKey) + '">' +
        '</div>';
      container.appendChild(row);
    });
  }

  var variantRegenTimer = null;
  function scheduleVariantRegen() {
    clearTimeout(variantRegenTimer);
    variantRegenTimer = setTimeout(function () { regenerateVariants(readVariantStockFromDom()); }, 600);
  }

  document.addEventListener('DOMContentLoaded', function () { regenerateVariants(initialVariantStock); });
</script>
