<?php
/**
 * @var \ShopRex\Models\Product $product
 * @var array $images
 * @var array $options
 * @var array $variantStockByValueIds
 * @var array|null $discount
 * @var array $categoryPath
 * @var float $taxRatePct
 * @var float $grossCurrent
 * @var bool $vatEnabled
 *
 * Direct port of product.php's body (lines 76-298) - see
 * Controllers\Storefront\ProductController::show() for the data prep.
 */
?>

<?php if ($categoryPath): ?>
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= rtrim(SITE_URL, '/') ?>/"><?= e(__('shop.all_products')) ?></a></li>
      <?php foreach ($categoryPath as $crumb): ?>
        <li class="breadcrumb-item"><a href="<?= e(getCategoryUrl($crumb)) ?>"><?= e($crumb['name']) ?></a></li>
      <?php endforeach; ?>
      <li class="breadcrumb-item active" aria-current="page"><?= e($product->name) ?></li>
    </ol>
  </nav>
<?php endif; ?>

<div class="row g-5">
  <div class="col-lg-6">
    <?php if (empty($images)): ?>
      <img class="w-100 rounded" src="<?= rtrim(SITE_URL, '/') ?>/assets/img/placeholder.svg" alt="<?= e($product->name) ?>">
    <?php else: ?>
      <div id="productGallery" class="carousel slide product-gallery" data-bs-ride="false">
        <div class="carousel-inner">
          <?php foreach ($images as $i => $img): ?>
            <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
              <img src="<?= e(\ShopRex\Models\Product::imageUrl($img)) ?>" alt="<?= e($img['description'] ?: $product->name) ?>">
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

      <?php $activeCaption = $images[0]['description'] ?? ''; ?>
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
                 src="<?= e(\ShopRex\Models\Product::imageUrl($img)) ?>" alt="">
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <div class="col-lg-6">
    <h1 class="h3"><?= e($product->name) ?></h1>
    <p class="text-secondary"><?= e($product->shortDescription) ?></p>
    <?php $grossRegular = $taxRatePct > 0 ? round($product->price * (1 + $taxRatePct / 100), 2) : $product->price; ?>
    <div class="fs-3 mb-1">
      <?php if ($discount): ?>
        <span class="price-old"><?= formatPrice($grossRegular) ?></span><span class="price-current"><?= formatPrice($grossCurrent) ?></span>
        <span class="badge bg-danger align-middle"><?= e($discount['label']) ?></span>
      <?php else: ?>
        <span class="price-current"><?= formatPrice($grossCurrent) ?></span>
      <?php endif; ?>
    </div>
    <?php if ($vatEnabled): ?>
      <p class="text-secondary small mb-1"><?= e(__('shop.prices_incl_vat')) ?></p>
    <?php endif; ?>
    <?php if ($discount && ($dateRange = formatDiscountDateRange($discount))): ?>
      <p class="text-secondary small mb-3"><i class="bi bi-clock me-1"></i><?= e($dateRange) ?></p>
    <?php else: ?>
      <div class="mb-3"></div>
    <?php endif; ?>

    <?php if ($product->stockQuantity <= 0 && empty($options)): ?>
      <p><span class="badge bg-secondary"><?= e(__('shop.out_of_stock')) ?></span></p>
    <?php endif; ?>

    <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/cart/add">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="product_id" value="<?= (int)$product->id ?>">

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
      $qtyMax = 99;
      if (!empty($product->maxOrderQuantity)) {
          $qtyMax = min($qtyMax, (int)$product->maxOrderQuantity);
      }
      if (empty($options)) {
          $qtyMax = min($qtyMax, max(0, $product->stockQuantity));
      }
      ?>
      <div class="mb-3" style="max-width:140px;">
        <label class="form-label" for="quantity"><?= e(__('common.quantity')) ?></label>
        <input class="form-control" type="number" id="quantity" name="quantity" value="1" min="1" max="<?= max(1, $qtyMax) ?>">
        <?php if (!empty($product->maxOrderQuantity)): ?>
          <small class="text-secondary"><?= e(__('product.max_order_quantity_note', ['n' => $product->maxOrderQuantity])) ?></small>
        <?php endif; ?>
      </div>

      <button class="btn btn-primary btn-lg" type="submit" <?= ($product->stockQuantity <= 0 && empty($options)) ? 'disabled' : '' ?>>
        <i class="bi bi-cart-plus me-1"></i> <?= e(__('product.add_to_cart')) ?>
      </button>
    </form>

    <!-- Battery/warranty disclosure UI lands in Phase 6, together with the
         schema columns, translations, and the rest of the legal/compliance
         feature - see the approved architecture plan. -->

    <h2 class="h5 mt-5"><?= e(__('product.description')) ?></h2>
    <p><?= nl2br(e($product->description)) ?></p>
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
  var productMaxOrderQty = <?= json_encode($product->maxOrderQuantity ? (int)$product->maxOrderQuantity : null) ?>;
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
    var submitButton = document.querySelector('form[action$="cart/add"] button[type="submit"]');
    var note = document.getElementById('variantStockNote');

    if (!allChosen || ids.length !== optionGroupCount) {
      if (note) { note.textContent = ''; }
      return;
    }
    ids.sort(function (a, b) { return a - b; });
    var key = ids.join('-');
    if (!Object.prototype.hasOwnProperty.call(variantStockByValueIds, key)) {
      // No variant matrix for this product - nothing more we can say
      // client-side; server-side checks in CartController still apply.
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
