<?php
/**
 * "Sidebar Filters" theme package's override of the home.php template slot
 * (see .../theme/default/home.php for the default this replaces, and
 * Core\ThemeManager::resolve() for how it's selected). Structurally
 * different from the default: a persistent category tree in a left
 * sidebar instead of a top breadcrumb + subcategory-chip row. Every
 * variable used below is set by Controllers\Storefront\CatalogController
 * before this file is required.
 */

use ShopRex\Support\StorefrontMenuRenderer;
?>
<div class="row g-4">
  <aside class="col-md-3 sidebar-categories">
    <h2 class="h6 text-uppercase text-secondary mb-3"><?= e(__('shop.all_products')) ?></h2>
    <ul class="sidebar-category-tree">
      <li><a href="<?= rtrim(SITE_URL, '/') ?>/" class="<?= !$categoryId ? 'active' : '' ?>"><?= e(__('shop.all_products')) ?></a></li>
    </ul>
    <?php
    $activeChainIds = array_map(fn ($c) => (int)$c['id'], $categoryPath);
    StorefrontMenuRenderer::renderSidebarCategoryTree(getCategoryTree(), $categoryId, $activeChainIds);
    ?>
  </aside>

  <div class="col-md-9">
    <?php if ($categoryId && ($categoryIntroText = getCategoryIntroText($categoryId, getCurrentLanguage()))): ?>
      <div class="category-intro-text mb-3"><?= nl2br(e($categoryIntroText)) ?></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3 mb-3">
      <h1 class="h3 mb-0"><?= $search !== '' ? e(__('shop.search_results', ['query' => $search])) : e($pageTitle) ?></h1>
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
          <?php foreach (PER_PAGE_OPTIONS as $opt): ?>
            <option value="<?= e($opt) ?>" <?= $perPage === $opt ? 'selected' : '' ?>><?= $opt === 'all' ? e(__('shop.show_all')) : e(__('shop.per_page', ['n' => $opt])) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-primary" type="submit"><?= e(__('common.apply')) ?></button>
      </form>
    </div>

    <?php if (empty($products)): ?>
      <p class="text-secondary"><?= e(__('shop.no_products')) ?></p>
    <?php else: ?>
      <p class="text-secondary small">
        <?= $totalProducts === 1 ? e(__('shop.product_count_one')) : e(__('shop.product_count', ['n' => $totalProducts])) ?>
        <?= $totalPages > 1 ? ' &middot; ' . e(__('shop.page_of', ['current' => $page, 'total' => $totalPages])) : '' ?>
        <?= vatIsEnabled() ? ' &middot; ' . e(__('shop.prices_incl_vat')) : '' ?>
      </p>
      <div class="row row-cols-1 row-cols-sm-2 row-cols-lg-3 g-4">
        <?php foreach ($products as $product): ?>
          <?php
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
                  <?php if ($discount): ?>
                    <span class="price-old"><?= formatPrice($grossRegular) ?></span><span class="price-current"><?= formatPrice($grossCurrent) ?></span>
                    <span class="badge bg-danger ms-1"><?= e($discount['label']) ?></span>
                  <?php else: ?>
                    <span class="price-current"><?= formatPrice($grossCurrent) ?></span>
                  <?php endif; ?>
                </div>
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
        <?php renderPagination($page, $totalPages, $paginationParams); ?>
      </div>
    <?php endif; ?>
  </div>
</div>
