<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\I18n;
use ShopRex\Services\PerPageResolver;
use ShopRex\Services\SettingsRepository;
use ShopRex\Services\TranslationOverlay;

/**
 * Storefront search results page ("q=..." in the URL) - looks across both
 * categories and products for a text match. Kept separate from
 * CatalogController (which also supports an in-category "q" filter)
 * because this page has no category context at all: it searches every
 * product regardless of category and shows matching categories too.
 * Direct port of search.php.
 */
final class SearchController extends Controller
{
    private readonly \PDO $pdo; // Raw DB handle for the hand-written search queries below.
    private readonly TranslationOverlay $translations; // Overlays per-language product name/description onto the default-language row (see CLAUDE.md's "Product/option translation").
    private readonly PerPageResolver $perPage; // Resolves how many results to show per page (visitor override vs. site default).
    private readonly SettingsRepository $settings; // Used here to read the site's default language for the translation-join decision below.

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->translations = $container->make(TranslationOverlay::class);
        $this->perPage = $container->make(PerPageResolver::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    /**
     * Renders the search results page for whatever "q" is in the query
     * string - an empty query just shows the page with no results rather
     * than erroring, since that's also what a visitor sees before typing
     * anything.
     */
    public function index(Request $request): Response
    {
        $query = trim((string)$request->get('q', ''));
        $page = max(1, (int)$request->get('page', 1));
        $perPageInt = $this->perPage->currentInt();
        $categories = [];
        $products = [];
        $totalProducts = 0;
        $totalPages = 1;

        if ($query !== '') {
            // Prepared LIKE pattern - $query is still bound as a parameter
            // below, never concatenated into the SQL string, so a crafted
            // search term can't alter the query itself (it can only ever
            // match/not match as literal text).
            $like = "%$query%";

            $catStmt = $this->pdo->prepare('SELECT * FROM categories WHERE name LIKE ? OR description LIKE ? ORDER BY name');
            $catStmt->execute([$like, $like]);
            $categories = $catStmt->fetchAll();

            // Only join the per-language translations table when the
            // visitor's language differs from the shop's default - the
            // default language's own text already lives on `products`, so
            // there'd be nothing extra to search/sort by otherwise.
            $currentLang = I18n::current();
            $defaultLang = $this->settings->get('default_language', 'en');
            $translateJoin = $currentLang !== $defaultLang ? 'LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.language = ?' : '';
            $joinParams = $translateJoin ? [$currentLang] : [];
            $nameSortSql = $translateJoin ? 'COALESCE(pt.name, p.name)' : 'p.name';

            // Search across the default-language fields, plus (when a
            // translation join is active) the translated name/short
            // description/description too - so a search matches whichever
            // language a product's text was actually entered in.
            $prodWhere = "p.status = 'active' AND " . \ShopRex\Models\Product::availabilityWindowSql() . " AND (p.name LIKE ? OR p.short_description LIKE ? OR p.description LIKE ? OR p.sku LIKE ?"
                . ($translateJoin ? ' OR pt.name LIKE ? OR pt.short_description LIKE ? OR pt.description LIKE ?' : '') . ')';
            $prodParams = [$like, $like, $like, $like];
            if ($translateJoin) {
                $prodParams[] = $like;
                $prodParams[] = $like;
                $prodParams[] = $like;
            }
            // Join params (the language code for the LEFT JOIN's ON clause)
            // must come before the WHERE params in the final bound list,
            // matching the order placeholders appear in the SQL string below.
            $allProdParams = array_merge($joinParams, $prodParams);

            // Count first so pagination (totalPages) can be computed before
            // fetching the actual page of rows below.
            $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM products p $translateJoin WHERE $prodWhere");
            $countStmt->execute($allProdParams);
            $totalProducts = (int)$countStmt->fetchColumn();
            $totalPages = $perPageInt ? max(1, (int)ceil($totalProducts / $perPageInt)) : 1;
            // Clamp the requested page into range - a page number past the
            // last page (e.g. from a stale bookmark) falls back to the last
            // page instead of returning an empty/error result.
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
                // $perPageInt/$offset are computed ints, never raw user
                // input, so interpolating them directly into LIMIT/OFFSET
                // is safe (PDO can't bind LIMIT/OFFSET as parameters on
                // every MySQL version anyway).
                $offset = ($page - 1) * $perPageInt;
                $prodSql .= " LIMIT $perPageInt OFFSET $offset";
            }
            $prodStmt = $this->pdo->prepare($prodSql);
            $prodStmt->execute($allProdParams);
            $products = $prodStmt->fetchAll();
            // Re-run each row through the translation overlay so the
            // displayed name/description matches the visitor's language,
            // not just what was matched against in SQL above.
            foreach ($products as &$product) {
                $product = $this->translations->applyToProduct($product, $currentLang);
            }
            unset($product);
        }

        $pageTitle = __('search.title');
        return $this->render('search/index', compact('query', 'page', 'categories', 'products', 'totalProducts', 'totalPages', 'pageTitle'));
    }
}
