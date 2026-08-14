<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/** Direct port of admin/products.php (list + delete only - product_edit.php is ProductEditController). */
final class ProductAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function index(Request $request): Response
    {
        $search = trim((string)$request->get('q', ''));
        $sql = "SELECT p.*, c.name AS category_name, tr.name AS tax_rate_name, tr.rate AS tax_rate_percent,
                       (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_image,
                       (SELECT cropped_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_cropped_image
                FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN tax_rates tr ON tr.id = p.tax_rate_id";
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE p.name LIKE ? OR p.sku LIKE ?';
            $params = ["%$search%", "%$search%"];
        }
        $sql .= ' ORDER BY p.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $products = $stmt->fetchAll();

        return $this->render('products/index', compact('search', 'products') + ['pageTitle' => __('admin.products')]);
    }

    public function delete(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $this->pdo->prepare('DELETE FROM products WHERE id = ?')->execute([(int)$request->post('delete_id')]);
        $this->flash('success', __('admin.products.flash_deleted'));
        return $this->redirect('/admin/products');
    }
}
