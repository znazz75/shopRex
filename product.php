<?php
require_once __DIR__ . '/includes/bootstrap.php';

$slug = $_GET['slug'] ?? '';
$stmt = db()->prepare(
    "SELECT p.*, (SELECT rate FROM tax_rates WHERE id = p.tax_rate_id) AS tax_rate_percent
     FROM products p WHERE p.slug = ? AND p.status = 'active'"
);
$stmt->execute([$slug]);
$product = $stmt->fetch();

// Outside its available_from/available_until window, a product is treated
// exactly like it doesn't exist.
if ($product && !isProductCurrentlyAvailable($product)) {
    $product = false;
}

if (!$product) {
    http_response_code(404);
    $pageTitle = __('product.not_found_title');
    require themeTemplatePath('header.php');
    echo '<div class="alert alert-warning">' . e(__('product.not_found_text')) . '</div>';
    require themeTemplatePath('footer.php');
    exit;
}

// Overlay the visitor's language onto name/short_description/description
// (falls back to the base/default-language text per field when no
// translation exists) - every use of $product below is unchanged, it just
// reads whichever text ended up in these same array keys.
$product = applyProductTranslation($product);

// Gallery images (each with its own description/caption)
$imgStmt = db()->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC');
$imgStmt->execute([$product['id']]);
$images = $imgStmt->fetchAll();

// Options + values
$optStmt = db()->prepare('SELECT * FROM product_options WHERE product_id = ? ORDER BY sort_order');
$optStmt->execute([$product['id']]);
$options = $optStmt->fetchAll();
foreach ($options as &$opt) {
    $valStmt = db()->prepare('SELECT * FROM product_option_values WHERE product_option_id = ? ORDER BY sort_order');
    $valStmt->execute([$opt['id']]);
    $opt['values'] = $valStmt->fetchAll();
}
unset($opt);
$options = applyOptionTranslations($options);

// Exact per-combination stock (see product_variants in sql/schema.sql) -
// embedded for the JS below, which matches the customer's current
// Size+Color(+...) selection against these to show real availability
// instead of the old "stock per single option value" approximation.
$variantStockByValueIds = [];
if ($options) {
    $variantStmt = db()->prepare('SELECT id, stock_quantity FROM product_variants WHERE product_id = ?');
    $variantStmt->execute([$product['id']]);
    foreach ($variantStmt->fetchAll() as $variant) {
        $vvStmt = db()->prepare('SELECT product_option_value_id FROM product_variant_values WHERE product_variant_id = ?');
        $vvStmt->execute([$variant['id']]);
        $valueIds = array_map('intval', array_column($vvStmt->fetchAll(), 'product_option_value_id'));
        sort($valueIds);
        $variantStockByValueIds[implode('-', $valueIds)] = (int)$variant['stock_quantity'];
    }
}

$discount = getActiveDiscount($product);
$categoryPath = getCategoryPath($product['category_id'] ? (int)$product['category_id'] : null);
$pageTitle = $product['name'];

// Remembered by cart.php's "Continue Shopping" button.
$_SESSION['last_product_url'] = rtrim(SITE_URL, '/') . '/product.php?slug=' . urlencode($product['slug']);

require themeTemplatePath('header.php');
?>

<?php if ($categoryPath): ?>
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= rtrim(SITE_URL, '/') ?>/index.php"><?= e(__('shop.all_products')) ?></a></li>
      <?php foreach ($categoryPath as $crumb): ?>
        <li class="breadcrumb-item"><a href="<?= rtrim(SITE_URL, '/') ?>/index.php?category=<?= (int)$crumb['id'] ?>"><?= e($crumb['name']) ?></a></li>
      <?php endforeach; ?>
      <li class="breadcrumb-item active" aria-current="page"><?= e($product['name']) ?></li>
    </ol>
  </nav>
<?php endif; ?>

<div class="row g-5">
  <div class="col-lg-6">
    <?php if (empty($images)): ?>
      <img class="w-100 rounded" src="<?= rtrim(SITE_URL, '/') ?>/assets/img/placeholder.svg" alt="<?= e($product['name']) ?>">
    <?php else: ?>
      <div id="productGallery" class="carousel slide product-gallery" data-bs-ride="false">
        <div class="carousel-inner">
          <?php foreach ($images as $i => $img): ?>
            <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
              <img src="<?= e(getImageUrl($img)) ?>" alt="<?= e($img['description'] ?: $product['name']) ?>">
            </div>
          <?php endforeach; ?>
        </div>
        <?php if (count($images) > 1): ?>
          <button class="carousel-control-prev" type="button" data-bs-target="#productGallery" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
          </button>
          <button class="carousel-control-next" type="button" data-bs-target="#productGallery" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
          </button>
        <?php endif; ?>
      </div>

      <?php
      $activeCaption = $images[0]['description'] ?? '';
      ?>
      <?php if ($activeCaption): ?>
        <p id="galleryCaption" class="carousel-caption-static"><?= e($activeCaption) ?></p>
      <?php else: ?>
        <p id="galleryCaption" class="carousel-caption-static d-none"></p>
      <?php endif; ?>

      <?php if (count($images) > 1): ?>
        <div class="d-flex gap-2 flex-wrap mt-2">
          <?php foreach ($images as $i => $img): ?>
            <img class="gallery-thumb <?= $i === 0 ? 'active' : '' ?>" data-slide-index="<?= $i ?>"
                 data-caption="<?= e($img['description'] ?? '') ?>"
                 src="<?= e(getImageUrl($img)) ?>" alt="">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="col-lg-6">
    <h1 class="h3"><?= e($product['name']) ?></h1>
    <p class="text-secondary"><?= e($product['short_description']) ?></p>
    <?php
    $taxRatePct = getTaxRatePercent($product);
    $grossRegular = $taxRatePct > 0 ? round((float)$product['price'] * (1 + $taxRatePct / 100), 2) : (float)$product['price'];
    $grossCurrent = getGrossPrice($product);
    ?>
    <div class="fs-3 mb-1">
      <?php if ($discount): ?>
        <span class="price-old"><?= formatPrice($grossRegular) ?></span><span class="price-current"><?= formatPrice($grossCurrent) ?></span>
        <span class="badge bg-danger align-middle"><?= e($discount['label']) ?></span>
      <?php else: ?>
        <span class="price-current"><?= formatPrice($grossCurrent) ?></span>
      <?php endif; ?>
    </div>
    <?php if (vatIsEnabled()): ?>
      <p class="text-secondary small mb-1"><?= e(__('shop.prices_incl_vat')) ?></p>
    <?php endif; ?>
    <?php if ($discount && ($dateRange = formatDiscountDateRange($discount))): ?>
      <p class="text-secondary small mb-3"><i class="bi bi-clock me-1"></i><?= e($dateRange) ?></p>
    <?php else: ?>
      <div class="mb-3"></div>
    <?php endif; ?>

    <?php if ((int)$product['stock_quantity'] <= 0 && empty($options)): ?>
      <p><span class="badge bg-secondary"><?= e(__('shop.out_of_stock')) ?></span></p>
    <?php endif; ?>

    <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/cart_action.php">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">

      <?php foreach ($options as $opt): ?>
        <div class="mb-3">
          <label class="form-label" for="opt-<?= (int)$opt['id'] ?>"><?= e($opt['name']) ?></label>
          <select class="form-select variant-option-select" id="opt-<?= (int)$opt['id'] ?>" name="options[<?= (int)$opt['id'] ?>]" required>
            <option value=""><?= e(__('product.choose_option', ['option' => $opt['name']])) ?></option>
            <?php foreach ($opt['values'] as $val): ?>
              <option value="<?= (int)$val['id'] ?>">
                <?= e($val['value']) ?>
                <?= $val['price_modifier'] > 0 ? ' (+' . formatPrice((float)$val['price_modifier']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      <?php endforeach; ?>

      <?php if ($options): ?>
        <p id="variantStockNote" class="small mb-3"></p>
      <?php endif; ?>

      <?php
      // Upper bound for the qty field: the general sanity cap (99), further
      // capped by the product's optional max-order-quantity, and - for
      // option-less products only - by stock on hand (products with options
      // are stock-checked server-side in cart_action.php instead, since
      // stock there depends on which option value is picked).
      $qtyMax = 99;
      if (!empty($product['max_order_quantity'])) {
          $qtyMax = min($qtyMax, (int)$product['max_order_quantity']);
      }
      if (empty($options)) {
          $qtyMax = min($qtyMax, max(0, (int)$product['stock_quantity']));
      }
      ?>
      <div class="mb-3" style="max-width:140px;">
        <label class="form-label" for="quantity"><?= e(__('common.quantity')) ?></label>
        <input class="form-control" type="number" id="quantity" name="quantity" value="1" min="1" max="<?= max(1, $qtyMax) ?>">
        <?php if (!empty($product['max_order_quantity'])): ?>
          <small class="text-secondary"><?= e(__('product.max_order_quantity_note', ['n' => $product['max_order_quantity']])) ?></small>
        <?php endif; ?>
      </div>

      <button class="btn btn-primary btn-lg" type="submit" <?= ((int)$product['stock_quantity'] <= 0 && empty($options)) ? 'disabled' : '' ?>>
        <i class="bi bi-cart-plus me-1"></i> <?= e(__('product.add_to_cart')) ?>
      </button>
    </form>

    <h2 class="h5 mt-5"><?= e(__('product.description')) ?></h2>
    <p><?= nl2br(e($product['description'])) ?></p>
  </div>
</div>

<script>
  // Keep the caption under the gallery in sync with the active slide.
  document.addEventListener('DOMContentLoaded', function () {
    var carousel = document.getElementById('productGallery');
    var caption = document.getElementById('galleryCaption');
    if (!carousel || !caption) return;
    var captions = Array.prototype.map.call(document.querySelectorAll('.gallery-thumb'), function (t) { return t.dataset.caption || ''; });
    carousel.addEventListener('slid.bs.carousel', function (e) {
      var text = captions[e.to] || '';
      caption.textContent = text;
      caption.classList.toggle('d-none', text === '');
    });
  });
</script>

<?php if ($options): ?>
<script>
  // Exact-combination stock (see product_variants in sql/schema.sql) - as
  // soon as every option group has a value chosen, look up that precise
  // combination's stock and reflect it here, instead of the old
  // per-single-option-value approximation.
  var variantStockByValueIds = <?= json_encode($variantStockByValueIds, JSON_FORCE_OBJECT) ?>;
  var optionGroupCount = <?= count($options) ?>;
  var productMaxOrderQty = <?= json_encode($product['max_order_quantity'] ? (int)$product['max_order_quantity'] : null) ?>;
  var outOfStockLabel = <?= json_encode(__('shop.out_of_stock')) ?>;
  var onlyLeftTpl = <?= json_encode(__('cart.only_left', ['n' => '%n%'])) ?>;

  function updateVariantAvailability() {
    var selects = document.querySelectorAll('.variant-option-select');
    var ids = [];
    var allChosen = true;
    selects.forEach(function (sel) {
      if (!sel.value) { allChosen = false; return; }
      ids.push(parseInt(sel.value, 10));
    });

    var quantityInput = document.getElementById('quantity');
    var submitButton = document.querySelector('form[action$="cart_action.php"] button[type="submit"]');
    var note = document.getElementById('variantStockNote');

    if (!allChosen || ids.length !== optionGroupCount) {
      if (note) { note.textContent = ''; }
      return;
    }
    ids.sort(function (a, b) { return a - b; });
    var key = ids.join('-');
    if (!Object.prototype.hasOwnProperty.call(variantStockByValueIds, key)) {
      // No variant matrix for this product (legacy data) - nothing more we
      // can say client-side; server-side checks at cart_action.php still apply.
      if (note) { note.textContent = ''; }
      return;
    }

    var stock = variantStockByValueIds[key];
    var cap = Math.min(99, stock, productMaxOrderQty || 99);
    if (quantityInput) {
      quantityInput.max = Math.max(0, cap);
      if (parseInt(quantityInput.value, 10) > cap) { quantityInput.value = Math.max(1, cap); }
    }
    if (submitButton) { submitButton.disabled = stock <= 0; }
    if (note) {
      if (stock <= 0) {
        note.textContent = outOfStockLabel;
        note.className = 'small mb-3 text-danger';
      } else if (stock <= 5) {
        note.textContent = onlyLeftTpl.replace('%n%', stock);
        note.className = 'small mb-3 text-secondary';
      } else {
        note.textContent = '';
      }
    }
  }

  document.querySelectorAll('.variant-option-select').forEach(function (sel) {
    sel.addEventListener('change', updateVariantAvailability);
  });
  document.addEventListener('DOMContentLoaded', updateVariantAvailability);
</script>
<?php endif; ?>

<?php require themeTemplatePath('footer.php'); ?>
