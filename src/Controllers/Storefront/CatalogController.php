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
    private readonly \PDO $pdo; // Raw DB handle for the hand-written listing/search query below.
    private readonly CategoryTreeService $categories; // Resolves category slugs, breadcrumb paths, and descendant category ids for "show this category and everything under it".
    private readonly TranslationOverlay $translations; // Overlays per-language product name/description onto each listed product.
    private readonly PerPageResolver $perPage; // Resolves how many products to show per page (visitor override vs. site default).
    private readonly SettingsRepository $settings; // Used here for the default language (translation-join decision) and the default items-per-page value.

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->categories = $container->make(CategoryTreeService::class);
        $this->translations = $container->make(TranslationOverlay::class);
        $this->perPage = $container->make(PerPageResolver::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    /** The storefront home page - just the product listing with no category filter. Ported from index.php. */
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

    /**
     * Shared implementation behind both home() and category(): builds the
     * filtered/sorted/paginated product list. $categoryId of null means
     * "no category filter" (the home page); otherwise the listing includes
     * that category's own products plus every descendant subcategory's
     * products, so browsing a parent category shows everything beneath it.
     */
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

        // Whitelist map from the "sort" query param to actual SQL ORDER BY
        // clauses - the query string value is never used directly in SQL,
        // only as a lookup key, so an unrecognized/tampered value can't
        // inject anything and just falls back to 'newest' via the ?? below.
        $sortMap = [
            'newest'     => 'p.created_at DESC',
            'price_asc'  => 'effective_price ASC',
            'price_desc' => 'effective_price DESC',
            'name_asc'   => "$nameSortSql ASC",
            'name_desc'  => "$nameSortSql DESC",
        ];
        $orderBy = $sortMap[$sort] ?? $sortMap['newest'];

        // Computes each product's actual selling price after any active
        // discount, in SQL, so "sort by price" reflects what the customer
        // would really pay - percent discounts are capped at 100% (LEAST)
        // and fixed discounts can't drive the price below zero (GREATEST),
        // and either type only applies if today falls within its optional
        // start/end window.
        $effectivePriceSql = "CASE
            WHEN p.discount_type = 'percent' AND (p.discount_starts_at IS NULL OR p.discount_starts_at <= NOW()) AND (p.discount_ends_at IS NULL OR p.discount_ends_at >= NOW())
                THEN p.price * (1 - LEAST(p.discount_value, 100) / 100)
            WHEN p.discount_type = 'fixed' AND (p.discount_starts_at IS NULL OR p.discount_starts_at <= NOW()) AND (p.discount_ends_at IS NULL OR p.discount_ends_at >= NOW())
                THEN GREATEST(p.price - p.discount_value, 0)
            ELSE p.price
        END";

        // Base filter: only active products, and only ones currently
        // inside their optional available_from/available_until window (see
        // Product::availabilityWindowSql() and CatalogController's sibling
        // ProductController, which applies the equivalent PHP-side check
        // for a single product page).
        $where = ["p.status = 'active'", \ShopRex\Models\Product::availabilityWindowSql()];
        $params = [];

        $categoryPath = [];
        $subcategories = [];
        if ($categoryId) {
            // descendantIds() includes the category itself plus every
            // subcategory beneath it, so browsing a parent category shows
            // products filed directly under any of its children too, not
            // just ones filed directly on the parent.
            $descendantIds = $this->categories->descendantIds($categoryId);
            // Builds a "?,?,?..." placeholder list matching the number of
            // descendant ids, so IN (...) can be a prepared statement
            // rather than interpolating the ids directly.
            $where[] = 'p.category_id IN (' . implode(',', array_fill(0, count($descendantIds), '?')) . ')';
            $params = array_merge($params, $descendantIds);

            $categoryPath = $this->categories->path($categoryId);
            // translatedTree(), not tree() - this is a storefront display
            // (the current category's subcategory chips), so the visitor's
            // language should be reflected, unlike an admin picker.
            $selectedNode = $this->categories->findNode($this->categories->translatedTree(), $categoryId);
            $subcategories = $selectedNode['children'] ?? [];
        }
        if ($search !== '') {
            // Same "only join translations when the visitor's language
            // differs from the default" logic as SearchController - search
            // against the translated name/short_description too when
            // that's what's actually displayed.
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
        // Join params (the language code for the LEFT JOIN's ON clause)
        // must come before the WHERE params, matching placeholder order in
        // the SQL string built below.
        $allParams = array_merge($joinParams, $params);

        // Count first so pagination (totalPages) can be computed before
        // fetching the actual page of rows below.
        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM products p $translateJoin WHERE $whereSql");
        $countStmt->execute($allParams);
        $totalProducts = (int)$countStmt->fetchColumn();
        $totalPages = $perPageInt ? max(1, (int)ceil($totalProducts / $perPageInt)) : 1;
        // Clamp the requested page into range - a page number past the
        // last page (e.g. a stale bookmark, or after a product was
        // deleted) falls back to the last page instead of an empty result.
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
            // $perPageInt/$offset are computed ints, never raw user input,
            // so interpolating them directly into LIMIT/OFFSET is safe.
            $offset = ($page - 1) * $perPageInt;
            $sql .= " LIMIT $perPageInt OFFSET $offset";
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($allParams);
        $products = $stmt->fetchAll();
        // Re-run each row through the translation overlay so the displayed
        // name/description matches the visitor's language, not just what
        // was matched/sorted against in SQL above.
        foreach ($products as &$product) {
            $product = $this->translations->applyToProduct($product, $currentLang);
        }
        unset($product);

        // No 'category' key - it's now part of the path (/category/{slug}),
        // not a query param, so Support\Pagination's relative "?page=N"
        // links resolve against whichever URL (/ or /category/{slug}) the
        // visitor is actually on.
        // array_filter(..., fn($v) => ...) drops any param that's at its
        // default value (empty search, default sort, default per_page) so
        // pagination links stay as short/clean as possible instead of
        // always carrying every parameter.
        $paginationParams = array_filter([
            'q'        => $search !== '' ? $search : null,
            'sort'     => $sort !== 'newest' ? $sort : null,
            'per_page' => $perPage !== $this->settings->get('items_per_page_default', '20') ? $perPage : null,
        ], fn ($v) => $v !== null && $v !== '');

        // end($categoryPath) is the deepest/current category in the
        // breadcrumb trail (path() returns root-to-leaf order) - its name
        // becomes the page title; with no category, fall back to a generic
        // "All products" title for the home page.
        $pageTitle = $categoryPath ? end($categoryPath)['name'] : __('shop.all_products');

        // renderSlot() (not render()) because the product grid is a
        // theme-package-swappable slot ('home.php') - see this class's
        // docblock and Core\ThemeManager.
        $html = $this->view->renderSlot('home', compact(
            'categoryPath', 'categoryId', 'subcategories', 'products', 'search',
            'sort', 'perPage', 'totalProducts', 'totalPages', 'page',
            'paginationParams', 'pageTitle'
        ));
        return Response::html($html);
    }
}
