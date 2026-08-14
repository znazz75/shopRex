<?php
/**
 * @var string $query
 * @var int $page
 * @var array $categories
 * @var array $products
 * @var int $totalProducts
 * @var int $totalPages
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

<?php if ($query === ''): ?>
  <p class="text-secondary"><?= e(__('search.prompt')) ?></p>
<?php elseif (empty($categories) && empty($products)): ?>
  <div class="alert alert-info"><?= e(__('search.no_results', ['query' => $query])) ?></div>
<?php else: ?>

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
      <?php renderPagination($page, $totalPages, ['q' => $query]); ?>
    </div>
  <?php elseif (!empty($categories)): ?>
    <p class="text-secondary"><?= e(__('search.no_products_hint')) ?></p>
  <?php endif; ?>

<?php endif; ?>
