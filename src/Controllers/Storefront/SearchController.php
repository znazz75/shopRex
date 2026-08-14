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

/** Direct port of search.php. */
final class SearchController extends Controller
{
    private readonly \PDO $pdo;
    private readonly TranslationOverlay $translations;
    private readonly PerPageResolver $perPage;
    private readonly SettingsRepository $settings;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->translations = $container->make(TranslationOverlay::class);
        $this->perPage = $container->make(PerPageResolver::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

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
            $like = "%$query%";

            $catStmt = $this->pdo->prepare('SELECT * FROM categories WHERE name LIKE ? OR description LIKE ? ORDER BY name');
            $catStmt->execute([$like, $like]);
            $categories = $catStmt->fetchAll();

            $currentLang = I18n::current();
            $defaultLang = $this->settings->get('default_language', 'en');
            $translateJoin = $currentLang !== $defaultLang ? 'LEFT JOIN product_translations pt ON pt.product_id = p.id AND pt.language = ?' : '';
            $joinParams = $translateJoin ? [$currentLang] : [];
            $nameSortSql = $translateJoin ? 'COALESCE(pt.name, p.name)' : 'p.name';

            $prodWhere = "p.status = 'active' AND " . \ShopRex\Models\Product::availabilityWindowSql() . " AND (p.name LIKE ? OR p.short_description LIKE ? OR p.description LIKE ? OR p.sku LIKE ?"
                . ($translateJoin ? ' OR pt.name LIKE ? OR pt.short_description LIKE ? OR pt.description LIKE ?' : '') . ')';
            $prodParams = [$like, $like, $like, $like];
            if ($translateJoin) {
                $prodParams[] = $like;
                $prodParams[] = $like;
                $prodParams[] = $like;
            }
            $allProdParams = array_merge($joinParams, $prodParams);

            $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM products p $translateJoin WHERE $prodWhere");
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
            $prodStmt = $this->pdo->prepare($prodSql);
            $prodStmt->execute($allProdParams);
            $products = $prodStmt->fetchAll();
            foreach ($products as &$product) {
                $product = $this->translations->applyToProduct($product, $currentLang);
            }
            unset($product);
        }

        $pageTitle = __('search.title');
        return $this->render('search/index', compact('query', 'page', 'categories', 'products', 'totalProducts', 'totalPages', 'pageTitle'));
    }
}
