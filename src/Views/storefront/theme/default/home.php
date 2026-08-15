<?php
/**
 * Default product-listing content (home page, a category, and search-
 * within-category views all share this one route/template, via
 * Controllers\Storefront\CatalogController). This is the "home.php"
 * template slot a theme package (src/Views/storefront/theme/<key>/home.php)
 * can override for a structurally different layout - see
 * Core\ThemeManager::resolve(). Every variable used below ($categoryPath,
 * $categoryId, $subcategories, $products, etc.) is set by CatalogController
 * before this file is required.
 *
 * All three "modes" (plain home page, a category page, a search) go
 * through the exact same query/render code in CatalogController::listing()
 * - what differs is just which of these variables end up non-empty:
 * $categoryId/$categoryPath/$subcategories are empty on the home page and
 * on a search, $search is '' outside of a search. The markup below reads
 * those combinations rather than being told outright "which mode" it's in.
 *
 * @var array  $categoryPath     Breadcrumb trail (root-to-current) when
 *                                viewing a category; empty on the home
 *                                page or a search.
 * @var int|null $categoryId     The category being viewed, or null on the
 *                                home page/a search.
 * @var array  $subcategories    Direct child categories of $categoryId (for
 *                                the row of category "chip" links); empty
 *                                when not on a category page, or the
 *                                category has no children.
 * @var array  $products         The current page of matching products,
 *                                already translated into the current
 *                                language (Services\TranslationOverlay) and
 *                                each carrying primary_image/
 *                                primary_cropped_image/tax_rate_percent/
 *                                effective_price alongside its normal columns.
 * @var string $search           The current ?q= search term, '' if none -
 *                                also redisplayed in the filter box so a
 *                                visitor can refine their search in place.
 * @var string $sort             Current sort key ('newest'/'price_asc'/
 *                                'price_desc'/'name_asc'/'name_desc').
 * @var string $perPage          Current ?per_page= value as configured
 *                                (a number as a string, or 'all').
 * @var int    $totalProducts    Total matching products across all pages
 *                                (before pagination), for the "N products"
 *                                summary line.
 * @var int    $totalPages       Total number of pages at the current $perPage.
 * @var int    $page             Current page number (1-based).
 * @var array  $paginationParams Extra query-string params (q/sort/per_page,
 *                                only when non-default) that renderPagination()
 *                                needs to preserve on its page-N links.
 * @var string $pageTitle        Heading/<title> text - the current category's
 *                                name, or a generic "All products" label on
 *                                the home page. (Search results use $search
 *                                directly instead of this for the on-page
 *                                heading - see below.)
 */
?>
<?php // Breadcrumb trail is only shown when browsing into a category - the
      // home page and search results have no category context to trace. ?>
<?php if ($categoryPath): ?>
  <nav aria-label="breadcrumb">
    <ol class="breadcrumb">
      <li class="breadcrumb-item"><a href="<?= rtrim(SITE_URL, '/') ?>/"><?= e(__('shop.all_products')) ?></a></li>
      <?php // Every ancestor is a link except the last one (the category
            // we're actually on), which is shown as plain, non-clickable
            // "active" breadcrumb text instead. ?>
      <?php foreach ($categoryPath as $i => $crumb): ?>
        <?php if ($i === array_key_last($categoryPath)): ?>
          <li class="breadcrumb-item active" aria-current="page"><?= e($crumb['name']) ?></li>
        <?php else: ?>
          <li class="breadcrumb-item"><a href="<?= e(getCategoryUrl($crumb)) ?>"><?= e($crumb['name']) ?></a></li>
        <?php endif; ?>
      <?php endforeach; ?>
    </ol>
  </nav>
<?php endif; ?>

<?php // getCategoryIntroText() looks up this category's per-language intro
      // blurb (falls back to the shop's default language, then null if
      // there isn't one at all in either) - assigned inline in the `if` so
      // it's only fetched when we're actually on a category page. ?>
<?php if ($categoryId && ($categoryIntroText = getCategoryIntroText($categoryId, getCurrentLanguage()))): ?>
  <div class="category-intro-text mb-3"><?= nl2br(e($categoryIntroText)) ?></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
  <?php // Heading differs by mode: a search shows "Search results for
        // <query>", otherwise it's whatever $pageTitle the controller
        // picked (the category name, or "All products" on the home page). ?>
  <h1 class="h3 mb-0"><?= $search !== '' ? e(__('shop.search_results', ['query' => $search])) : e($pageTitle) ?></h1>
  <?php // Filter/sort/per-page controls - a plain GET form so the resulting
        // URL (with ?q=&sort=&per_page=) is bookmarkable/shareable; the sort
        // and per-page <select>s auto-submit on change via onchange, the
        // text filter needs the explicit Apply button since typing shouldn't
        // reload the page on every keystroke. ?>
  <form class="d-flex gap-2 flex-wrap" method="get">
    <input type="text" class="form-control form-control-sm" name="q" placeholder="<?= e(__('shop.filter_placeholder')) ?>" value="<?= e($search) ?>" style="max-width:220px;">
    <select name="sort" class="form-select form-select-sm" style="max-width:200px;" onchange="this.form.submit()">
      <option value="newest" <?= $sort === 'newest' ? 'selected' : '' ?>><?= e(__('shop.sort_newest')) ?></option>
      <option value="price_asc" <?= $sort === 'price_asc' ? 'selected' : '' ?>><?= e(__('shop.sort_price_asc')) ?></option>
      <option value="price_desc" <?= $sort === 'price_desc' ? 'selected' : '' ?>><?= e(__('shop.sort_price_desc')) ?></option>
      <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>><?= e(__('shop.sort_name_asc')) ?></option>
      <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>><?= e(__('shop.sort_name_desc')) ?></option>
    </select>
    <select name="per_page" class="form-select form-select-sm" style="max-width:130px;" onchange="this.form.submit()">
      <?php // PER_PAGE_OPTIONS (Services\PerPageResolver::OPTIONS) is the
            // fixed list of allowed page sizes, e.g. ['12','24','48','all'] -
            // 'all' disables pagination entirely for that request. ?>
      <?php foreach (PER_PAGE_OPTIONS as $opt): ?>
        <option value="<?= e($opt) ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt === 'all' ? e(__('shop.show_all')) : e(__('shop.per_page', ['n' => $opt])) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-sm btn-primary" type="submit"><?= e(__('common.apply')) ?></button>
  </form>
</div>

<?php // Row of quick-link "chips" to this category's direct children, when
      // any exist - lets a visitor drill down without going through a menu. ?>
<?php if (!empty($subcategories)): ?>
  <div class="subcategory-chips d-flex flex-wrap gap-2 mb-4">
    <?php foreach ($subcategories as $sub): ?>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(getCategoryUrl($sub)) ?>"><?= e($sub['name']) ?></a>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (empty($products)): ?>
  <p class="text-secondary"><?= e(__('shop.no_products')) ?></p>
<?php else: ?>
  <p class="text-secondary small">
    <?php // Singular vs. plural product-count wording, then (only if there's
          // more than one page) a "page X of Y" note, then (only if VAT
          // display is turned on in Settings) a "prices incl. VAT" note -
          // each piece is independently optional, joined with a middot. ?>
    <?= $totalProducts === 1 ? e(__('shop.product_count_one')) : e(__('shop.product_count', ['n' => $totalProducts])) ?>
    <?= $totalPages > 1 ? ' &middot; ' . e(__('shop.page_of', ['current' => $page, 'total' => $totalPages])) : '' ?>
    <?= vatIsEnabled() ? ' &middot; ' . e(__('shop.prices_incl_vat')) : '' ?>
  </p>
  <div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 g-4">
    <?php foreach ($products as $product): ?>
      <?php
      // Per-product pricing, computed fresh for each card:
      //  - $discount: the active discount record for this product right
      //    now (percent/fixed, within its date window), or null if none.
      //  - $taxRatePct: this product's VAT percentage (0 if VAT is off or
      //    the product has no rate assigned).
      //  - $grossRegular: what the *undiscounted* price would look like
      //    with tax added - only used to render the struck-through "was"
      //    price next to a discount.
      //  - $grossCurrent: the actual price to charge right now, tax
      //    included, discount already applied (TaxCalculator::grossPrice()).
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
            <div class="mb-1">
              <?php // Struck-through old price + discounted price + a badge
                    // naming the discount, when one applies; otherwise just
                    // the plain current price. ?>
              <?php if ($discount): ?>
                <span class="price-old"><?= formatPrice($grossRegular) ?></span><span class="price-current"><?= formatPrice($grossCurrent) ?></span>
                <span class="badge bg-danger ms-1"><?= e($discount['label']) ?></span>
              <?php else: ?>
                <span class="price-current"><?= formatPrice($grossCurrent) ?></span>
              <?php endif; ?>
            </div>
            <?php // formatDiscountDateRange() turns the discount's start/end
                  // dates into a human sentence (e.g. "Until Aug 20") - only
                  // shown when there's an active discount AND it actually has
                  // a bounded date range (an open-ended discount returns null). ?>
            <?php if ($discount && ($dateRange = formatDiscountDateRange($discount))): ?>
              <div class="small text-secondary mb-1"><?= e($dateRange) ?></div>
            <?php endif; ?>
            <?php if ((int)$product['stock_quantity'] <= 0): ?>
              <span class="badge bg-secondary align-self-start"><?= e(__('shop.out_of_stock')) ?></span>
            <?php endif; ?>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-4">
    <?php // Renders the Prev/1/2/3/Next page-link row, preserving the
          // current q/sort/per_page via $paginationParams so switching pages
          // doesn't lose the current filter/sort - see Support\Pagination. ?>
    <?php renderPagination($page, $totalPages, $paginationParams); ?>
  </div>
<?php endif; ?>
