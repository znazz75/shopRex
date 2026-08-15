<?php
/**
 * Storefront single-product page - photo gallery, price (with any active
 * discount), option pickers (Size/Color/...), add-to-cart form, and
 * description. Rendered by Controllers\Storefront\ProductController::show()
 * at /product/{slug}. Just the body; Core\Renderer::render() wraps it with
 * the theme's header.php/footer.php. Unlike the home/category/search
 * listing page, this page's body is NOT a swappable theme-package slot in
 * the original app - only header/footer/home.php are - so it's a plain
 * view rather than going through Renderer::slot().
 *
 * A product outside its configured available_from/available_until window
 * is treated by the controller exactly like it doesn't exist at all (404,
 * same as a bad slug) - by the time this file runs, $product is guaranteed
 * to be a real, currently-available product.
 *
 * @var \ShopRex\Models\Product $product     The product being shown - already
 *                                             translation-overlaid for the
 *                                             current language (name/
 *                                             shortDescription/description
 *                                             reflect that, not necessarily
 *                                             the DB's default-language row).
 * @var array $images                        This product's photos, in
 *                                             display order (admin-chosen
 *                                             "primary" image first).
 * @var array $options                       Option groups (e.g. "Size",
 *                                             "Color"), each with a
 *                                             'values' sub-array of
 *                                             selectable option_value rows
 *                                             (id/value/price_modifier).
 *                                             Empty for a product with no
 *                                             variants.
 * @var array $variantStockByValueIds        Map of "id-id-id" (sorted,
 *                                             hyphen-joined option-value
 *                                             IDs - one per option group,
 *                                             matching Models\Cart::key()'s
 *                                             own key format) => remaining
 *                                             stock for that EXACT
 *                                             combination. Only meaningful
 *                                             when $options is non-empty;
 *                                             read by the inline <script>
 *                                             below to live-update
 *                                             availability as the visitor
 *                                             picks options - the server
 *                                             re-validates stock again on
 *                                             add-to-cart regardless.
 * @var array|null $discount                 The product's currently-active
 *                                             discount record (percent/
 *                                             fixed, within its date
 *                                             window), or null if none applies.
 * @var array $categoryPath                  Breadcrumb trail from the
 *                                             product's category up to the
 *                                             root; empty if the product
 *                                             has no category.
 * @var float $taxRatePct                    This product's VAT percentage
 *                                             (0 if VAT is off or no rate
 *                                             assigned) - used here only to
 *                                             compute the struck-through
 *                                             "was" price next to a discount.
 * @var float $grossCurrent                  The actual price to charge now,
 *                                             tax included, discount already
 *                                             applied.
 * @var bool $vatEnabled                     Whether the shop displays VAT-
 *                                             inclusive pricing at all
 *                                             (Admin -> Settings) - controls
 *                                             the "prices incl. VAT" note.
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
    <?php // No uploaded photos - fall back to a generic placeholder image
          // instead of an empty gallery. ?>
    <?php if (empty($images)): ?>
      <img class="w-100 rounded" src="<?= rtrim(SITE_URL, '/') ?>/assets/img/placeholder.svg" alt="<?= e($product->name) ?>">
    <?php else: ?>
      <div id="productGallery" class="carousel slide product-gallery" data-bs-ride="false">
        <div class="carousel-inner">
          <?php // First image is the admin-chosen "primary" one (see
                // ProductController's ORDER BY) and starts as the active
                // Bootstrap carousel slide. ?>
          <?php foreach ($images as $i => $img): ?>
            <div class="carousel-item <?= $i === 0 ? 'active' : '' ?>">
              <img src="<?= e(\ShopRex\Models\Product::imageUrl($img)) ?>" alt="<?= e($img['description'] ?: $product->name) ?>">
            </div>
          <?php endforeach; ?>
        </div>
        <?php // Prev/Next arrows only make sense with more than one photo. ?>
        <?php if (count($images) > 1): ?>
          <button class="carousel-control-prev" type="button" data-bs-target="#productGallery" data-bs-slide="prev">
            <span class="carousel-control-prev-icon" aria-hidden="true"></span>
          </button>
          <button class="carousel-control-next" type="button" data-bs-target="#productGallery" data-bs-slide="next">
            <span class="carousel-control-next-icon" aria-hidden="true"></span>
          </button>
        <?php endif; ?>
      </div>

      <?php // Caption paragraph starts matching the first (active) image's
            // description; the inline <script> further below swaps this
            // text whenever the carousel slides to a different image. ?>
      <?php $activeCaption = $images[0]['description'] ?? ''; ?>
      <?php if ($activeCaption): ?>
        <p id="galleryCaption" class="carousel-caption-static"><?= e($activeCaption) ?></p>
      <?php else: ?>
        <p id="galleryCaption" class="carousel-caption-static d-none"></p>
      <?php endif; ?>

      <?php // Clickable thumbnail strip, only shown with multiple photos -
            // each thumb carries its slide index + caption text as data
            // attributes for assets/js/main.js to jump the carousel and
            // swap the caption when clicked. ?>
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
    <?php // What the undiscounted price would look like with tax added -
          // only used to render the struck-through "was" price next to an
          // active discount (mirrors the same computation in home.php's
          // product cards). ?>
    <?php $grossRegular = $taxRatePct > 0 ? round($product->price * (1 + $taxRatePct / 100), 2) : $product->price; ?>
    <div class="fs-3 mb-1">
      <?php // Struck-through old price + discounted price + a badge naming
            // the discount, when one applies; otherwise just the plain
            // current price. ?>
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
    <?php // Human-readable discount date range (e.g. "Until Aug 20"), shown
          // only when there's an active discount with a bounded end date
          // (open-ended discounts return null); when there's nothing to
          // show, an empty spacer div keeps the vertical layout consistent
          // instead of the page shifting up. ?>
    <?php if ($discount && ($dateRange = formatDiscountDateRange($discount))): ?>
      <p class="text-secondary small mb-3"><i class="bi bi-clock me-1"></i><?= e($dateRange) ?></p>
    <?php else: ?>
      <div class="mb-3"></div>
    <?php endif; ?>

    <?php // Out-of-stock badge only for simple (option-less) products - a
          // product WITH options can't have a single "in stock or not"
          // answer (each combination has its own stock), so that case is
          // instead handled per-selection by the variant-stock <script>
          // further below. ?>
    <?php if ($product->stockQuantity <= 0 && empty($options)): ?>
      <p><span class="badge bg-secondary"><?= e(__('shop.out_of_stock')) ?></span></p>
    <?php endif; ?>

    <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/cart/add">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="product_id" value="<?= (int)$product->id ?>">

      <?php // One <select> per option group (e.g. "Size", "Color") - the
            // "variant-option-select" class is what the stock-checking
            // <script> further below listens to for changes. A blank
            // "choose an option" placeholder is required so add-to-cart
            // can't be submitted with a group left unselected (browser-
            // enforced via `required`; the server re-validates too). Each
            // value's price modifier (extra cost for that choice, e.g.
            // "+€2.00" for a larger size) is shown inline when positive. ?>
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

      <?php // Empty placeholder paragraph, only present when there are
            // options to pick - the variant-stock <script> below fills this
            // in with an out-of-stock / "only N left" message once every
            // group has a value chosen. ?>
      <?php if ($options): ?>
        <p id="variantStockNote" class="small mb-3"></p>
      <?php endif; ?>

      <?php
      // How high the quantity field's max="" can go: capped at 99
      // regardless, further capped by the product's configured
      // max-order-quantity if set, and (only for option-less products,
      // where stock is a single number rather than per-combination) also
      // capped by actual remaining stock. A product WITH options gets its
      // cap corrected client-side once a combination is chosen (see the
      // variant-stock <script> below) since stock isn't known until then.
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

      <?php // Disabled only for an option-less product that's already out
            // of stock - a product WITH options starts enabled (nothing's
            // been chosen yet to be "out of stock" about) and gets
            // disabled/re-enabled dynamically as the visitor picks options
            // (see the variant-stock <script> below). Either way, the
            // server re-checks real stock again when the form is actually
            // submitted. ?>
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

<?php // Always present (doesn't depend on $options) since it only concerns
      // the image gallery, which every product has. ?>
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

<?php // Only needed for a product that actually has option groups - an
      // option-less product's stock is the single $product->stockQuantity
      // number already reflected server-side above, nothing to react to. ?>
<?php if ($options): ?>
<script>
  // Exact-combination stock (see product_variants in sql/schema.sql) - as
  // soon as every option group has a value chosen, look up that precise
  // combination's stock and reflect it here, instead of the old
  // per-single-option-value approximation.
  // These are PHP-computed values baked straight into the page as JSON
  // (json_encode) so this script has everything it needs without a
  // network round-trip - variantStockByValueIds mirrors this same file's
  // $variantStockByValueIds PHP variable (see the docblock at the top).
  var variantStockByValueIds = <?= json_encode($variantStockByValueIds, JSON_FORCE_OBJECT) ?>;
  var optionGroupCount = <?= count($options) ?>;
  var productMaxOrderQty = <?= json_encode($product->maxOrderQuantity ? (int)$product->maxOrderQuantity : null) ?>;
  var outOfStockLabel = <?= json_encode(__('shop.out_of_stock')) ?>;
  var onlyLeftTpl = <?= json_encode(__('cart.only_left', ['n' => '%n%'])) ?>;

  function updateVariantAvailability() {
    // Read whichever value is currently selected in each option <select>;
    // if any group is still on the blank placeholder, we don't yet have a
    // complete combination to look up.
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
    // Sort numerically before joining into the lookup key - matches
    // PHP's sort($valueIds) in ProductController::show() and
    // Models\Cart::key(), so the same combination produces the same key
    // regardless of which order the visitor picked the option groups in.
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
