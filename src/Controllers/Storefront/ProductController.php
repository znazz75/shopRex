<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Product;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\DiscountCalculator;
use ShopRex\Services\TaxCalculator;
use ShopRex\Services\TranslationOverlay;

/**
 * Single product page - direct port of product.php. Unlike the listing
 * page (CatalogController), this page's body is NOT a theme-package slot
 * in the original app (only header/footer/home.php are), so it's
 * rewritten as a real view (src/Views/storefront/product/show.php)
 * instead of going through Renderer::slot().
 */
final class ProductController extends Controller
{
    private readonly \PDO $pdo; // Raw DB handle for the images/options/variant-stock queries below.
    private readonly TranslationOverlay $translations; // Overlays per-language name/description/option text onto the default-language rows.
    private readonly DiscountCalculator $discounts; // Computes the product's currently-active discount (if any) for display.
    private readonly TaxCalculator $tax; // Computes the displayed tax rate/gross price for this product.
    private readonly CategoryTreeService $categories; // Resolves the breadcrumb path from the product's category up to the root.

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->translations = $container->make(TranslationOverlay::class);
        $this->discounts = $container->make(DiscountCalculator::class);
        $this->tax = $container->make(TaxCalculator::class);
        $this->categories = $container->make(CategoryTreeService::class);
    }

    /**
     * Renders a single product's page: its details, images, option groups
     * (with per-combination stock for client-side JS), active discount,
     * and category breadcrumb. A product outside its availability window
     * or that doesn't exist both render the same 404 "not found" page, so
     * a visitor can't tell the difference between "never existed" and
     * "not on sale right now" from the response alone.
     */
    public function show(Request $request): Response
    {
        $slug = (string)$request->routeParam('slug', '');
        $product = Product::findBySlug($slug);

        // Outside its available_from/available_until window, a product is
        // treated exactly like it doesn't exist.
        if ($product && !$product->isCurrentlyAvailable()) {
            $product = null;
        }

        if (!$product) {
            $html = $this->view->render('product/not_found', ['pageTitle' => __('product.not_found_title')]);
            return Response::html($html, 404);
        }

        $productArray = $this->translations->applyToProduct($product->toArray());
        // Re-fill so every typed property (name/short_description/
        // description) reflects the overlay too - toArray()/fill() are
        // exact inverses (see Model docblock), so this round-trip is safe.
        $product->fill($productArray);

        // ORDER BY is_primary DESC, sort_order ASC puts the admin-chosen
        // "primary" image first (for the main product photo), then the
        // rest in their configured display order.
        $imgStmt = $this->pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY is_primary DESC, sort_order ASC');
        $imgStmt->execute([$product->id]);
        $images = $imgStmt->fetchAll();

        // An "option" is a choice group (e.g. "Size", "Color"); each has
        // its own set of selectable values, fetched in a second query per
        // group and attached under $opt['values'] - N+1 queries here, but
        // a product has only a handful of option groups so this stays
        // simple rather than a single multi-join query.
        $optStmt = $this->pdo->prepare('SELECT * FROM product_options WHERE product_id = ? ORDER BY sort_order');
        $optStmt->execute([$product->id]);
        $options = $optStmt->fetchAll();
        foreach ($options as &$opt) {
            $valStmt = $this->pdo->prepare('SELECT * FROM product_option_values WHERE product_option_id = ? ORDER BY sort_order');
            $valStmt->execute([$opt['id']]);
            $opt['values'] = $valStmt->fetchAll();
        }
        unset($opt);
        $options = $this->translations->applyToOptions($options);

        // Exact per-combination stock (product_variants) - embedded for
        // client-side JS, matching the customer's option selection against
        // real combination stock instead of a per-value approximation.
        $variantStockByValueIds = [];
        if ($options) {
            $variantStmt = $this->pdo->prepare('SELECT id, stock_quantity FROM product_variants WHERE product_id = ?');
            $variantStmt->execute([$product->id]);
            foreach ($variantStmt->fetchAll() as $variant) {
                // Every option-value combination that makes up this
                // variant (e.g. "Size: M" + "Color: Blue").
                $vvStmt = $this->pdo->prepare('SELECT product_option_value_id FROM product_variant_values WHERE product_variant_id = ?');
                $vvStmt->execute([$variant['id']]);
                $valueIds = array_map('intval', array_column($vvStmt->fetchAll(), 'product_option_value_id'));
                // sort() makes the combination order-independent - matches
                // Models\Cart::key()'s own sort() so the same combination
                // always produces the same lookup key regardless of the
                // order the customer clicked the options in.
                sort($valueIds);
                $variantStockByValueIds[implode('-', $valueIds)] = (int)$variant['stock_quantity'];
            }
        }

        $discount = $product->activeDiscount($this->discounts);
        $categoryPath = $this->categories->path($product->categoryId ? (int)$product->categoryId : null);
        $pageTitle = $product->name;

        // Remembered by the cart page's "Continue Shopping" button.
        $this->request->session()->set('last_product_url', rtrim(SITE_URL, '/') . '/product/' . urlencode($product->slug));

        $html = $this->view->render('product/show', [
            'product'                 => $product,
            'images'                  => $images,
            'options'                 => $options,
            'variantStockByValueIds'  => $variantStockByValueIds,
            'discount'                => $discount,
            'categoryPath'            => $categoryPath,
            'pageTitle'               => $pageTitle,
            'taxRatePct'              => $product->taxRatePercentValue($this->tax),
            'grossCurrent'            => $product->grossPrice($this->tax),
            'vatEnabled'              => $this->tax->vatEnabled(),
        ]);
        return Response::html($html);
    }
}
