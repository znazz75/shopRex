<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('products');

$id = isset($_GET['id']) ? (int)$_GET['id'] : null;
$product = null;
$options = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
    $stmt->execute([$id]);
    $product = $stmt->fetch();
    if (!$product) {
        setFlash('error', __('admin.product_edit.not_found'));
        redirect(rtrim(SITE_URL, '/') . '/admin/products.php');
    }
    $optStmt = db()->prepare('SELECT * FROM product_options WHERE product_id = ? ORDER BY sort_order');
    $optStmt->execute([$id]);
    $options = $optStmt->fetchAll();
    foreach ($options as &$opt) {
        $valStmt = db()->prepare('SELECT * FROM product_option_values WHERE product_option_id = ? ORDER BY sort_order');
        $valStmt->execute([$opt['id']]);
        $opt['values'] = $valStmt->fetchAll();
    }
    unset($opt);
}

// Existing variant stock, keyed by a "value text per group, joined with ||"
// combo signature (e.g. "M||Red") so the JS matrix below can carry stock
// forward across an edit even though every product_option_values row (and
// therefore every id) gets recreated on save (see the save handler further
// down) - matching is done by what the admin actually TYPED, not by id.
$variantStockByCombo = [];
if ($id) {
    $valueGroupAndText = [];
    foreach ($options as $groupIndex => $opt) {
        foreach ($opt['values'] as $val) {
            $valueGroupAndText[$val['id']] = ['group' => $groupIndex, 'text' => $val['value']];
        }
    }
    $variantStmt = db()->prepare('SELECT id, stock_quantity FROM product_variants WHERE product_id = ?');
    $variantStmt->execute([$id]);
    foreach ($variantStmt->fetchAll() as $variant) {
        $vvStmt = db()->prepare('SELECT product_option_value_id FROM product_variant_values WHERE product_variant_id = ?');
        $vvStmt->execute([$variant['id']]);
        $comboParts = [];
        foreach ($vvStmt->fetchAll() as $vv) {
            $info = $valueGroupAndText[$vv['product_option_value_id']] ?? null;
            if ($info) {
                $comboParts[$info['group']] = $info['text'];
            }
        }
        if ($comboParts) {
            ksort($comboParts);
            $variantStockByCombo[implode('||', $comboParts)] = (int)$variant['stock_quantity'];
        }
    }
}

$categories = flattenCategoryTree(getCategoryTree());
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    $name = trim($_POST['name'] ?? '');
    $sku = trim($_POST['sku'] ?? '');
    $categoryId = $_POST['category_id'] !== '' ? (int)$_POST['category_id'] : null;
    $shortDescription = trim($_POST['short_description'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $stockQuantity = (int)($_POST['stock_quantity'] ?? 0);
    $stockThreshold = (int)($_POST['stock_threshold'] ?? 5);
    $maxOrderQuantity = ($_POST['max_order_quantity'] ?? '') !== '' ? max(1, (int)$_POST['max_order_quantity']) : null;
    $weight = $_POST['weight_kg'] !== '' ? (float)$_POST['weight_kg'] : null;
    $status = in_array($_POST['status'] ?? '', ['active', 'draft', 'archived'], true) ? $_POST['status'] : 'draft';

    // Price entry: admin can type either the net or the gross (tax-included)
    // amount - price is always STORED net, converted here server-side
    // (never trust a client-computed conversion) using the chosen tax rate.
    $priceEntryMode = $_POST['price_entry_mode'] === 'gross' ? 'gross' : 'net';
    $enteredPrice = (float)($_POST['price_input'] ?? 0);
    $taxRateId = vatIsEnabled() && $_POST['tax_rate_id'] !== '' ? (int)$_POST['tax_rate_id'] : null;
    $taxRatePercent = 0.0;
    if ($taxRateId) {
        $rateStmt = db()->prepare('SELECT rate FROM tax_rates WHERE id = ?');
        $rateStmt->execute([$taxRateId]);
        $taxRatePercent = (float)($rateStmt->fetchColumn() ?: 0);
    }
    $price = ($priceEntryMode === 'gross' && $taxRatePercent > 0)
        ? round($enteredPrice / (1 + $taxRatePercent / 100), 2)
        : $enteredPrice;

    $discountType = in_array($_POST['discount_type'] ?? '', ['none', 'percent', 'fixed'], true) ? $_POST['discount_type'] : 'none';
    $discountValue = $_POST['discount_value'] !== '' ? (float)$_POST['discount_value'] : null;
    $toDatetime = fn(string $key): ?string => $_POST[$key] !== '' ? str_replace('T', ' ', $_POST[$key]) . ':00' : null;
    $discountStartsAt = $toDatetime('discount_starts_at');
    $discountEndsAt = $toDatetime('discount_ends_at');
    $availableFrom = $toDatetime('available_from');
    $availableUntil = $toDatetime('available_until');

    if ($name === '') $errors[] = __('admin.product_edit.name_required');
    if ($sku === '') $errors[] = __('admin.product_edit.sku_required');
    if ($enteredPrice <= 0) $errors[] = __('admin.product_edit.price_required');
    if ($discountType !== 'none' && !$discountValue) $errors[] = __('admin.product_edit.discount_value_required');
    if ($discountStartsAt && $discountEndsAt && $discountStartsAt > $discountEndsAt) $errors[] = __('admin.product_edit.discount_date_order');
    if ($availableFrom && $availableUntil && $availableFrom > $availableUntil) $errors[] = __('admin.product_edit.availability_date_order');
    if ($discountType === 'none') {
        $discountValue = null;
        $discountStartsAt = null;
        $discountEndsAt = null;
    }

    $slug = slugify($name);

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $stmt = $pdo->prepare(
                    'UPDATE products SET category_id=?, sku=?, name=?, slug=?, short_description=?, description=?,
                     price=?, price_entry_mode=?, tax_rate_id=?, discount_type=?, discount_value=?, discount_starts_at=?, discount_ends_at=?,
                     available_from=?, available_until=?, stock_quantity=?, stock_threshold=?, max_order_quantity=?, weight_kg=?, status=?
                     WHERE id=?'
                );
                $stmt->execute([
                    $categoryId, $sku, $name, $slug, $shortDescription, $description,
                    $price, $priceEntryMode, $taxRateId, $discountType, $discountValue, $discountStartsAt, $discountEndsAt,
                    $availableFrom, $availableUntil, $stockQuantity, $stockThreshold, $maxOrderQuantity, $weight, $status, $id,
                ]);
                $productId = $id;
                // Reset options - simplest consistent approach for a basic admin UI.
                $pdo->prepare('DELETE FROM product_options WHERE product_id = ?')->execute([$productId]);
                // product_variants isn't cascade-deleted by the above (it's
                // tied to products, not product_options) - clear it
                // explicitly so it's rebuilt from scratch below alongside
                // the freshly-recreated option values.
                $pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$productId]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO products (category_id, sku, name, slug, short_description, description, price,
                     price_entry_mode, tax_rate_id, discount_type, discount_value, discount_starts_at, discount_ends_at, available_from, available_until,
                     stock_quantity, stock_threshold, max_order_quantity, weight_kg, status)
                     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                );
                $stmt->execute([
                    $categoryId, $sku, $name, $slug, $shortDescription, $description, $price,
                    $priceEntryMode, $taxRateId, $discountType, $discountValue, $discountStartsAt, $discountEndsAt, $availableFrom, $availableUntil,
                    $stockQuantity, $stockThreshold, $maxOrderQuantity, $weight, $status,
                ]);
                $productId = (int)$pdo->lastInsertId();
            }

            // Option groups: option_name[] / option_values[] (comma-separated).
            // $groupIndex is a fresh sequential counter over only the
            // non-empty groups (skipping a blank row entirely) - this has
            // to match how the variant-matrix JS numbers groups (also
            // sequential over non-empty rows only), since variant_combo[]
            // below is keyed by that same group order, not by the original
            // option_name[]/option_values[] array index.
            $optionNames = $_POST['option_name'] ?? [];
            $optionValues = $_POST['option_values'] ?? [];
            $newValueIdByGroupText = [];
            $groupIndex = 0;
            foreach ($optionNames as $i => $optName) {
                $optName = trim($optName);
                $valuesRaw = trim($optionValues[$i] ?? '');
                if ($optName === '' || $valuesRaw === '') continue;

                $optStmt = $pdo->prepare('INSERT INTO product_options (product_id, name, sort_order) VALUES (?, ?, ?)');
                $optStmt->execute([$productId, $optName, $groupIndex]);
                $optionId = (int)$pdo->lastInsertId();

                $valStmt = $pdo->prepare('INSERT INTO product_option_values (product_option_id, value, stock_quantity, sort_order) VALUES (?, ?, ?, ?)');
                foreach (explode(',', $valuesRaw) as $j => $val) {
                    $val = trim($val);
                    if ($val === '') continue;
                    $valStmt->execute([$optionId, $val, $stockQuantity, $j]);
                    $newValueIdByGroupText[$groupIndex][$val] = (int)$pdo->lastInsertId();
                }
                $groupIndex++;
            }

            // Variant matrix (one row per option combination, built client-side
            // in the "Generate combinations" JS below and posted as parallel
            // variant_combo[]/variant_stock[] arrays) - only meaningful once
            // at least one option group was actually saved above.
            if ($newValueIdByGroupText) {
                $variantStmt = $pdo->prepare('INSERT INTO product_variants (product_id, stock_quantity, sort_order) VALUES (?, ?, ?)');
                $variantValueStmt = $pdo->prepare('INSERT INTO product_variant_values (product_variant_id, product_option_value_id) VALUES (?, ?)');
                $variantCombos = $_POST['variant_combo'] ?? [];
                $variantStocks = $_POST['variant_stock'] ?? [];
                $expectedGroups = count($newValueIdByGroupText);
                foreach ($variantCombos as $vi => $combo) {
                    $parts = explode('||', (string)$combo);
                    $valueIds = [];
                    foreach ($parts as $gIdx => $text) {
                        $text = trim($text);
                        if ($text === '' || !isset($newValueIdByGroupText[$gIdx][$text])) {
                            $valueIds = null;
                            break;
                        }
                        $valueIds[] = $newValueIdByGroupText[$gIdx][$text];
                    }
                    // Skip a row that doesn't resolve to exactly one value
                    // per saved group (stale combo referencing a renamed/
                    // removed option value - just don't create that variant).
                    if ($valueIds === null || count($valueIds) !== $expectedGroups) {
                        continue;
                    }
                    $stock = max(0, (int)($variantStocks[$vi] ?? 0));
                    $variantStmt->execute([$productId, $stock, $vi]);
                    $newVariantId = (int)$pdo->lastInsertId();
                    foreach ($valueIds as $valueId) {
                        $variantValueStmt->execute([$newVariantId, $valueId]);
                    }
                }
            }

            $pdo->commit();
            setFlash('success', __('admin.product_edit.flash_saved'));
            redirect(rtrim(SITE_URL, '/') . '/admin/product_edit.php?id=' . $productId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors[] = __('admin.product_edit.save_error', ['message' => $e->getMessage()]);
        }
    }
}

$pageTitle = $id ? __('admin.product_edit.edit_title') : __('admin.product_edit.add_title');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e($pageTitle) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<form method="post">
  <?= csrfField() ?>
  <div class="card">
    <div class="form-grid">
      <div class="form-group"><label for="name"><?= e(__('admin.products.name')) ?></label><input type="text" id="name" name="name" required value="<?= e($product['name'] ?? '') ?>"></div>
      <div class="form-group"><label for="sku"><?= e(__('admin.dashboard.sku')) ?></label><input type="text" id="sku" name="sku" required value="<?= e($product['sku'] ?? '') ?>"></div>
    </div>
    <div class="form-group"><label for="short_description"><?= e(__('admin.product_edit.short_description')) ?></label><input type="text" id="short_description" name="short_description" value="<?= e($product['short_description'] ?? '') ?>"></div>
    <div class="form-group"><label for="description"><?= e(__('product.description')) ?></label><textarea id="description" name="description" rows="4"><?= e($product['description'] ?? '') ?></textarea></div>
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
    $taxRates = getAllTaxRates();
    $currentTaxRateId = $product['tax_rate_id'] ?? getDefaultTaxRate()['id'];
    $currentMode = $product['price_entry_mode'] ?? 'net';
    $currentRatePercent = 0.0;
    foreach ($taxRates as $tr) {
        if ((int)$tr['id'] === (int)$currentTaxRateId) $currentRatePercent = (float)$tr['rate'];
    }
    // Prefill the amount field with whichever price the admin last entered in.
    $prefillPrice = '';
    if (isset($product['price'])) {
        $prefillPrice = $currentMode === 'gross' && $currentRatePercent > 0
            ? round((float)$product['price'] * (1 + $currentRatePercent / 100), 2)
            : (float)$product['price'];
    }
    ?>
    <fieldset style="margin:0 0 16px;">
      <legend style="font-size:15px;"><?= e(__('common.price')) ?><?= vatIsEnabled() ? ' &amp; ' . e(__('common.tax')) : '' ?></legend>
      <?php if (vatIsEnabled()): ?>
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
        <?php if (vatIsEnabled()): ?>
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
    // Translated templates, JS-embedded via json_encode() (not e()/htmlspecialchars,
    // which would corrupt this - it's raw <script> text, not an HTML attribute).
    var grossSuffixTpl = <?= json_encode(__('admin.product_edit.gross_incl_vat')) ?>; // "gross (incl. {rate}% VAT)"
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
        <input type="datetime-local" id="discount_starts_at" name="discount_starts_at" value="<?= e(isset($product['discount_starts_at']) ? str_replace(' ', 'T', substr($product['discount_starts_at'], 0, 16)) : '') ?>">
      </div>
      <div class="form-group">
        <label for="discount_ends_at"><?= e(__('admin.product_edit.discount_ends')) ?></label>
        <input type="datetime-local" id="discount_ends_at" name="discount_ends_at" value="<?= e(isset($product['discount_ends_at']) ? str_replace(' ', 'T', substr($product['discount_ends_at'], 0, 16)) : '') ?>">
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
        <input type="datetime-local" id="available_from" name="available_from" value="<?= e(isset($product['available_from']) ? str_replace(' ', 'T', substr($product['available_from'], 0, 16)) : '') ?>">
      </div>
      <div class="form-group">
        <label for="available_until"><?= e(__('admin.product_edit.available_until')) ?></label>
        <input type="datetime-local" id="available_until" name="available_until" value="<?= e(isset($product['available_until']) ? str_replace(' ', 'T', substr($product['available_until'], 0, 16)) : '') ?>">
      </div>
    </div>
  </fieldset>

  <div class="card">
    <div class="form-group mb-0">
      <label><?= e(__('admin.product_edit.images')) ?></label>
      <?php if ($id): ?>
        <div>
          <a class="btn btn-secondary" href="product_images.php?product_id=<?= (int)$id ?>"><?= e(__('admin.product_edit.manage_images')) ?></a>
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
          <label><?= e(__('admin.product_edit.option_name', ['n' => $i + 1])) ?></label>
          <input type="text" name="option_name[]" placeholder="<?= e(__('admin.product_edit.option_name_placeholder')) ?>" value="<?= e($existing['name'] ?? '') ?>" oninput="scheduleVariantRegen()">
        </div>
        <div class="form-group">
          <label><?= e(__('admin.product_edit.option_values')) ?></label>
          <input type="text" name="option_values[]" placeholder="<?= e(__('admin.product_edit.option_values_placeholder')) ?>"
                 value="<?= $existing ? e(implode(', ', array_column($existing['values'], 'value'))) : '' ?>" oninput="scheduleVariantRegen()">
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
  <a class="btn btn-secondary" href="products.php"><?= e(__('common.cancel')) ?></a>
</form>

<script>
  // Combination ("variant") matrix: built entirely client-side from the two
  // option-name/values fields above, so an admin sees a stock field per
  // exact combination (e.g. "Size: M, Color: Red") without a page reload -
  // see the PHP save handler above for how variant_combo[]/variant_stock[]
  // get turned back into product_variants/product_variant_values rows.
  var initialVariantStock = <?= json_encode($variantStockByCombo, JSON_FORCE_OBJECT) ?>; // {"M||Red": 12, ...} from the DB, keyed the same way
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
    // Debounced so it doesn't rebuild (and lose focus) on every keystroke -
    // preserves whatever stock numbers are already in the DOM.
    clearTimeout(variantRegenTimer);
    variantRegenTimer = setTimeout(function () { regenerateVariants(readVariantStockFromDom()); }, 600);
  }

  document.addEventListener('DOMContentLoaded', function () { regenerateVariants(initialVariantStock); });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
