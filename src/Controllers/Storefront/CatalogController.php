<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\I18n;
use ShopRex\Services\PerPageResolver;
use ShopRex\Services\SettingsRepository;
use ShopRex\Services\TranslationOverlay;

/**
 * Product listing - home page, a category (with its subcategories, via
 * /category/{slug}), and (via SearchController) the search results page.
 * Renders through Renderer::renderSlot('home', ...) so the 'home.php'
 * theme-package slot (src/Views/storefront/theme/<key>/home.php, falling
 * back to .../theme/default/home.php) stays swappable per theme package -
 * see Core\ThemeManager's docblock.
 */
final class CatalogController extends Controller
{
    private readonly \PDO $pdo;
    private readonly CategoryTreeService $categories;
    private readonly TranslationOverlay $translations;
    private readonly PerPageResolver $perPage;
    private readonly SettingsRepository $settings;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->categories = $container->make(CategoryTreeService::class);
        $this->translations = $container->make(TranslationOverlay::class);
        $this->perPage = $container->make(PerPageResolver::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    /** Ported from index.php. */
    public function home(Request $request): Response
    {
        return $this->listing($request, null);
    }

    /** /category/{slug} - clean-URL replacement for the old ?category=<id> query param. */
    public function category(Request $request): Response
    {
        $slug = (string)$request->routeParam('slug', '');
        $category = $this->categories->findBySlug($slug);
        if (!$category) {
            $html = $this->view->render('category/not_found', ['pageTitle' => __('page.not_found_title')]);
            return Response::html($html, 404);
        }
        return $this->listing($request, (int)$category['id']);
    }

    private function listing(Request $request, ?int $categoryId): Response
    {
        $search = trim((string)$request->get('q', ''));
        $sort = $request->get('sort', 'newest');
        $page = max(1, (int)$request->get('page', 1));
        $perPage = $this->perPage->current();
        $perPageInt = $this->perPage->currentInt();

        // A translated product name (Admin -> Products -> edit) should also
        // be what's sorted/searched against on a storefront browsed in that
        // language, not just what's displayed - LEFT JOIN only when the
        // visitor's language differs from the site default.
        $currentLang = I18n::current();
        $defaultLang = $this->settings->get('default_language', 'en');
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

        $effectivePriceSql = "CASE
            WHEN p.discount_type = 'percent' AND (p.discount_starts_at IS NULL OR p.discount_starts_at <= NOW()) AND (p.discount_ends_at IS NULL OR p.discount_ends_at >= NOW())
                THEN p.price * (1 - LEAST(p.discount_value, 100) / 100)
            WHEN p.discount_type = 'fixed' AND (p.discount_starts_at IS NULL OR p.discount_starts_at <= NOW()) AND (p.discount_ends_at IS NULL OR p.discount_ends_at >= NOW())
                THEN GREATEST(p.price - p.discount_value, 0)
            ELSE p.price
        END";

        $where = ["p.status = 'active'", \ShopRex\Models\Product::availabilityWindowSql()];
        $params = [];

        $categoryPath = [];
        $subcategories = [];
        if ($categoryId) {
            $descendantIds = $this->categories->descendantIds($categoryId);
            $where[] = 'p.category_id IN (' . implode(',', array_fill(0, count($descendantIds), '?')) . ')';
            $params = array_merge($params, $descendantIds);

            $categoryPath = $this->categories->path($categoryId);
            $selectedNode = $this->categories->findNode($this->categories->tree(), $categoryId);
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
        $allParams = array_merge($joinParams, $params);

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM products p $translateJoin WHERE $whereSql");
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
            $offset = ($page - 1) * $perPageInt;
            $sql .= " LIMIT $perPageInt OFFSET $offset";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($allParams);
        $products = $stmt->fetchAll();
        foreach ($products as &$product) {
            $product = $this->translations->applyToProduct($product, $currentLang);
        }
        unset($product);

        // No 'category' key - it's now part of the path (/category/{slug}),
        // not a query param, so Support\Pagination's relative "?page=N"
        // links resolve against whichever URL (/ or /category/{slug}) the
        // visitor is actually on.
        $paginationParams = array_filter([
            'q'        => $search !== '' ? $search : null,
            'sort'     => $sort !== 'newest' ? $sort : null,
            'per_page' => $perPage !== $this->settings->get('items_per_page_default', '20') ? $perPage : null,
        ], fn ($v) => $v !== null && $v !== '');

        $pageTitle = $categoryPath ? end($categoryPath)['name'] : __('shop.all_products');

        $html = $this->view->renderSlot('home', compact(
            'categoryPath', 'categoryId', 'subcategories', 'products', 'search',
            'sort', 'perPage', 'totalProducts', 'totalPages', 'page',
            'paginationParams', 'pageTitle'
        ));
        return Response::html($html);
    }
}
