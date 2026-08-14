<?php
require_once __DIR__ . '/includes/bootstrap.php';

$query = trim($_GET['q'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPageInt = getPerPageInt();
$categories = [];
$products = [];
$totalProducts = 0;
$totalPages = 1;

if ($query !== '') {
    $like = "%$query%";

    $catStmt = db()->prepare(
        'SELECT * FROM categories WHERE name LIKE ? OR description LIKE ? ORDER BY name'
    );
    $catStmt->execute([$like, $like]);
    $categories = $catStmt->fetchAll();

    // Search a translated name/short_description/description too (Admin ->
    // Products -> edit) when browsing in a non-default language - not just
    // the base/default-language columns - same reasoning as index.php's
    // product listing search.
    $currentLang = getCurrentLanguage();
    $defaultLang = getSetting('default_language', 'en');
    $translateJoin = $currentLang !== $defaultLang ? 'LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.language = ?' : '';
    $joinParams = $translateJoin ? [$currentLang] : [];
    $nameSortSql = $translateJoin ? 'COALESCE(pt.name, p.name)' : 'p.name';

    $prodWhere = "p.status = 'active' AND " . availabilityWindowSql() . " AND (p.name LIKE ? OR p.short_description LIKE ? OR p.description LIKE ? OR p.sku LIKE ?"
        . ($translateJoin ? ' OR pt.name LIKE ? OR pt.short_description LIKE ? OR pt.description LIKE ?' : '') . ')';
    $prodParams = [$like, $like, $like, $like];
    if ($translateJoin) {
        $prodParams[] = $like;
        $prodParams[] = $like;
        $prodParams[] = $like;
    }
    // $joinParams (the JOIN's own ON ? for language) always comes first -
    // it's textually before WHERE in every query below.
    $allProdParams = array_merge($joinParams, $prodParams);

    $countStmt = db()->prepare("SELECT COUNT(*) FROM products p $translateJoin WHERE $prodWhere");
    $countStmt->execute($allProdParams);
    $totalProducts = (int)$countStmt->fetchColumn();
    $totalPages = $perPageInt ? max(1, (int)ceil($totalProducts / $perPageInt)) : 1;
    $page = min($page, $totalPages);

    $prodSql = "SELECT p.*,
                (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_image,
                (SELECT cropped_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_cropped_image,
                (SELECT rate FROM tax_rates WHERE id = p.tax_rate_id) AS tax_rate_percent
         FROM products p
         $translateJoin
         WHERE $prodWhere
         ORDER BY $nameSortSql";
    if ($perPageInt) {
        $offset = ($page - 1) * $perPageInt;
        $prodSql .= " LIMIT $perPageInt OFFSET $offset";
    }
    $prodStmt = db()->prepare($prodSql);
    $prodStmt->execute($allProdParams);
    $products = $prodStmt->fetchAll();
    foreach ($products as &$product) {
        $product = applyProductTranslation($product, $currentLang);
    }
    unset($product);
}

$pageTitle = __('search.title');
require themeTemplatePath('header.php');
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
        <a class="btn btn-outline-secondary" href="<?= rtrim(SITE_URL, '/') ?>/index.php?category=<?= (int)$cat['id'] ?>">
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
          <a class="card product-card text-decoration-none text-body" href="<?= rtrim(SITE_URL, '/') ?>/product.php?slug=<?= e($product['slug']) ?>">
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

<?php require themeTemplatePath('footer.php'); ?>
