<?php
require_once __DIR__ . '/includes/bootstrap.php';

$categoryId = isset($_GET['category']) ? (int)$_GET['category'] : null;
$search = trim($_GET['q'] ?? '');
$sort = $_GET['sort'] ?? 'newest';
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = getPerPage();
$perPageInt = getPerPageInt();

// A translated product name (Admin -> Products -> edit) should also be
// what's sorted/searched against on a storefront browsed in that
// language - not just what's displayed. LEFT JOIN product_translations
// only when the visitor's language differs from the site default (the
// common case), so the query is identical to before this feature existed
// whenever no translation is even possible.
$currentLang = getCurrentLanguage();
$defaultLang = getSetting('default_language', 'en');
$translateJoin = $currentLang !== $defaultLang ? 'LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.language = ?' : '';
$joinParams = $translateJoin ? [$currentLang] : [];
$nameSortSql = $translateJoin ? 'COALESCE(pt.name, p.name)' : 'p.name';

$sortMap = [
    'newest'     => 'p.created_at DESC',
    'price_asc'  => 'effective_price ASC',
    'price_desc' => 'effective_price DESC',
    'name_asc'   => "$nameSortSql ASC",
    'name_desc'  => "$nameSortSql DESC",
];
$orderBy = $sortMap[$sort] ?? $sortMap['newest'];

// Active discount, computed in SQL so sorting by price reflects what the
// customer would actually pay right now (see includes/functions.php
// getActiveDiscount() for the PHP-side equivalent used for display).
$effectivePriceSql = "CASE
    WHEN p.discount_type = 'percent' AND (p.discount_starts_at IS NULL OR p.discount_starts_at <= NOW()) AND (p.discount_ends_at IS NULL OR p.discount_ends_at >= NOW())
        THEN p.price * (1 - LEAST(p.discount_value, 100) / 100)
    WHEN p.discount_type = 'fixed' AND (p.discount_starts_at IS NULL OR p.discount_starts_at <= NOW()) AND (p.discount_ends_at IS NULL OR p.discount_ends_at >= NOW())
        THEN GREATEST(p.price - p.discount_value, 0)
    ELSE p.price
END";

$where = ["p.status = 'active'", availabilityWindowSql()];
$params = [];

$categoryPath = [];
$subcategories = [];
if ($categoryId) {
    // Selecting a category also includes products in every subcategory
    // beneath it, at any depth.
    $descendantIds = getCategoryDescendantIds($categoryId);
    $where[] = 'p.category_id IN (' . implode(',', array_fill(0, count($descendantIds), '?')) . ')';
    $params = array_merge($params, $descendantIds);

    $categoryPath = getCategoryPath($categoryId);
    $selectedNode = findCategoryNode(getCategoryTree(), $categoryId);
    $subcategories = $selectedNode['children'] ?? [];
}
if ($search !== '') {
    if ($translateJoin) {
        $where[] = '(p.name LIKE ? OR p.short_description LIKE ? OR pt.name LIKE ? OR pt.short_description LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    } else {
        $where[] = '(p.name LIKE ? OR p.short_description LIKE ?)';
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
}
$whereSql = implode(' AND ', $where);
// $joinParams (the JOIN's own ON ? for language) always comes first -
// it's textually before WHERE in every query below, and PDO positional
// params must be bound in the same left-to-right order as their `?`.
$allParams = array_merge($joinParams, $params);

$countStmt = db()->prepare("SELECT COUNT(*) FROM products p $translateJoin WHERE $whereSql");
$countStmt->execute($allParams);
$totalProducts = (int)$countStmt->fetchColumn();
$totalPages = $perPageInt ? max(1, (int)ceil($totalProducts / $perPageInt)) : 1;
$page = min($page, $totalPages);

$sql = "SELECT p.*,
               (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_image,
               (SELECT cropped_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_cropped_image,
               (SELECT rate FROM tax_rates WHERE id = p.tax_rate_id) AS tax_rate_percent,
               $effectivePriceSql AS effective_price
        FROM products p
        $translateJoin
        WHERE $whereSql
        ORDER BY $orderBy";
if ($perPageInt) {
    // $perPageInt/$offset are always ints derived from a whitelist/max(), safe to interpolate.
    $offset = ($page - 1) * $perPageInt;
    $sql .= " LIMIT $perPageInt OFFSET $offset";
}

$stmt = db()->prepare($sql);
$stmt->execute($allParams);
$products = $stmt->fetchAll();
foreach ($products as &$product) {
    $product = applyProductTranslation($product, $currentLang);
}
unset($product);

$paginationParams = array_filter([
    'category' => $categoryId,
    'q'        => $search !== '' ? $search : null,
    'sort'     => $sort !== 'newest' ? $sort : null,
    'per_page' => $perPage !== getSetting('items_per_page_default', '20') ? $perPage : null,
], fn($v) => $v !== null && $v !== '');

$pageTitle = $categoryPath ? end($categoryPath)['name'] : __('shop.all_products');
require themeTemplatePath('header.php');
require themeTemplatePath('home.php');
require themeTemplatePath('footer.php');
