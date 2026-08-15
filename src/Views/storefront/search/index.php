<?php
/**
 * Storefront global search results page - unlike CatalogController's
 * in-category "q" filter (used on home.php/category pages), this searches
 * across EVERY product regardless of category, and also surfaces matching
 * categories by name/description. Rendered by
 * Controllers\Storefront\SearchController::index() at /search?q=....
 * Just the body; Core\Renderer::render() wraps it with the theme's
 * header.php/footer.php.
 *
 * @var string $query         The current ?q= search term, redisplayed in
 *                             the search box; '' means no search has been
 *                             performed yet (shown as a plain prompt, not
 *                             an error or "no results").
 * @var int    $page          Current page number of the product results
 *                             (categories are never paginated - there's
 *                             usually only a handful of matches).
 * @var array  $categories    Categories whose name or description matched
 *                             $query - shown as a row of quick-link chips.
 * @var array  $products      The current page of matching products,
 *                             already translated into the current language
 *                             (Services\TranslationOverlay), each carrying
 *                             primary_image/tax_rate_percent alongside its
 *                             normal columns.
 * @var int    $totalProducts Total matching products across all pages.
 * @var int    $totalPages    Total number of result pages.
 * Direct port of search.php's body.
 */
?>

<h1 class="h3 mb-4">
  <?= $query !== '' ? e(__('shop.search_results', ['query' => $query])) : e(__('search.title')) ?>
</h1>

<form class="d-flex gap-2 mb-4" method="get" style="max-width:420px;">
  <input class="form-control" type="search" name="q" placeholder="<?= e(__('nav.search_placeholder')) ?>" value="<?= e($query) ?>">
  <button class="btn btn-primary" type="submit"><i class="bi bi-search"></i></button>
</form>

<?php // Three-way branch: (1) no search performed yet - just a prompt to
      // type something; (2) searched but nothing matched in either
      // categories or products - an explicit "no results" notice; (3) got
      // at least one kind of match - show whichever of categories/products
      // actually has results. ?>
<?php if ($query === ''): ?>
  <p class="text-secondary"><?= e(__('search.prompt')) ?></p>
<?php elseif (empty($categories) && empty($products)): ?>
  <div class="alert alert-info"><?= e(__('search.no_results', ['query' => $query])) ?></div>
<?php else: ?>

  <?php // Matching categories shown first, as quick-link chips (not a full
        // category listing - just enough to route the visitor to the right
        // one) - only rendered when there's at least one match. ?>
  <?php if (!empty($categories)): ?>
    <h2 class="h5"><?= e(__('search.categories_count', ['n' => count($categories)])) ?></h2>
    <div class="d-flex flex-wrap gap-2 mb-4">
      <?php foreach ($categories as $cat): ?>
        <a class="btn btn-outline-secondary" href="<?= e(getCategoryUrl($cat)) ?>">
          <i class="bi bi-tag me-1"></i><?= e($cat['name']) ?>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!empty($products)): ?>
    <h2 class="h5"><?= e(__('search.products_count', ['n' => $totalProducts])) ?></h2>
    <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-4">
      <?php foreach ($products as $product): ?>
        <?php
        // Per-product pricing, computed fresh for each card - same
        // pattern as home.php's product grid: $discount is the active
        // discount (if any), $grossRegular is the undiscounted tax-
        // inclusive price (for the struck-through "was" price),
        // $grossCurrent is the actual price to charge now.
        $discount = getActiveDiscount($product);
        $taxRatePct = getTaxRatePercent($product);
        $grossRegular = $taxRatePct > 0 ? round((float)$product['price'] * (1 + $taxRatePct / 100), 2) : (float)$product['price'];
        $grossCurrent = getGrossPrice($product);
        ?>
        <div class="col">
          <a class="card product-card text-decoration-none text-body" href="<?= rtrim(SITE_URL, '/') ?>/product/<?= e($product['slug']) ?>">
            <img class="card-img-top" src="<?= e(getPrimaryImage($product)) ?>" alt="<?= e($product['name']) ?>">
            <div class="card-body d-flex flex-column">
              <h3 class="h6 mb-1"><?= e($product['name']) ?></h3>
              <p class="small text-secondary mb-2 flex-grow-1"><?= e($product['short_description']) ?></p>
              <div>
                <?php // Struck-through old price + discounted price + a
                      // badge naming the discount, when one applies;
                      // otherwise just the plain current price. ?>
                <?php if ($discount): ?>
                  <span class="price-old"><?= formatPrice($grossRegular) ?></span><span class="price-current"><?= formatPrice($grossCurrent) ?></span>
                  <span class="badge bg-danger ms-1"><?= e($discount['label']) ?></span>
                <?php else: ?>
                  <span class="price-current"><?= formatPrice($grossCurrent) ?></span>
                <?php endif; ?>
              </div>
            </div>
          </a>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="mt-4">
      <?php // Preserves the search query on page-N links so paging doesn't
            // lose what was searched for. ?>
      <?php renderPagination($page, $totalPages, ['q' => $query]); ?>
    </div>
  <?php elseif (!empty($categories)): ?>
    <?php // Categories matched but no products did - a softer hint than the
          // full "no results" alert above, since the search wasn't a total miss. ?>
    <p class="text-secondary"><?= e(__('search.no_products_hint')) ?></p>
  <?php endif; ?>

<?php endif; ?>
