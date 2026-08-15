<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Handles the product list screen (search + delete) in the admin back
 * office. Direct port of admin/products.php (list + delete only -
 * product_edit.php is ProductEditController). Kept separate from
 * ProductEditController because the list/search/delete flow and the
 * create/edit form are two very different concerns - this file stays a
 * short, simple "browse and remove" page while all the per-language
 * translation/option/variant complexity lives in the edit controller.
 */
final class ProductAdminController extends AdminCrudController
{
    // Shared PDO connection used for the raw list/search/delete queries below.
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** Lists every product (optionally filtered by a name/SKU search term), each row annotated with its category, tax rate, and thumbnail image for the admin product table. */
    public function index(Request $request): Response
    {
        $search = trim((string)$request->get('q', ''));
        // Correlated subqueries pick a single "primary" image per product (the
        // one flagged is_primary, falling back to lowest sort_order if none is
        // flagged) - both the original and the cropped version, so the table can
        // show whichever one the theme prefers without a second round trip.
        $sql = "SELECT p.*, c.name AS category_name, tr.name AS tax_rate_name, tr.rate AS tax_rate_percent,
                       (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_image,
                       (SELECT cropped_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_cropped_image
                FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN tax_rates tr ON tr.id = p.tax_rate_id";
        $params = [];
        if ($search !== '') {
            // LIKE with the search term wrapped in wildcards - still a prepared
            // statement/bound parameter, so the search text itself can't break out
            // of the query even though it's a substring match.
            $sql .= ' WHERE p.name LIKE ? OR p.sku LIKE ?';
            $params = ["%$search%", "%$search%"];
        }
        $sql .= ' ORDER BY p.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        return $this->render('products/index', compact('search', 'products') + ['pageTitle' => __('admin.products')]);
    }

    /** Permanently deletes one product by id, after checking the submitted form carries a valid CSRF token. */
    public function delete(Request $request): Response
    {
        // Blocks a cross-site request forgery attempt (a malicious page tricking
        // a logged-in admin's browser into submitting this delete form) - see
        // Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $this->pdo->prepare('DELETE FROM products WHERE id = ?')->execute([(int)$request->post('delete_id')]);
        $this->flash('success', __('admin.products.flash_deleted'));
        return $this->redirect('/admin/products');
    }
}
