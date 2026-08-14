<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/** Direct port of admin/inventory.php. */
final class InventoryAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function index(Request $request): Response
    {
        $products = $this->pdo->query(
            "SELECT id, name, sku, stock_quantity, stock_threshold FROM products
             WHERE id NOT IN (SELECT DISTINCT product_id FROM product_variants)
             ORDER BY stock_quantity ASC, name ASC"
        )->fetchAll();

        $variants = $this->pdo->query(
            "SELECT pv.id, pv.product_id, pv.stock_quantity, p.name AS product_name, p.stock_threshold, p.sku,
                    (SELECT GROUP_CONCAT(po.name, ': ', pov.value SEPARATOR ', ')
                     FROM product_variant_values pvv
                     JOIN product_option_values pov ON pov.id = pvv.product_option_value_id
                     JOIN product_options po ON po.id = pov.product_option_id
                     WHERE pvv.product_variant_id = pv.id) AS combo_label
             FROM product_variants pv
             JOIN products p ON p.id = pv.product_id
             ORDER BY pv.stock_quantity ASC, p.name ASC"
        )->fetchAll();

        $recentLog = $this->pdo->query(
            "SELECT il.*, p.name AS product_name,
                    (SELECT GROUP_CONCAT(po.name, ': ', pov.value SEPARATOR ', ')
                     FROM product_variant_values pvv
                     JOIN product_option_values pov ON pov.id = pvv.product_option_value_id
                     JOIN product_options po ON po.id = pov.product_option_id
                     WHERE pvv.product_variant_id = il.product_variant_id) AS variant_label
             FROM inventory_log il
             JOIN products p ON p.id = il.product_id
             ORDER BY il.created_at DESC LIMIT 20"
        )->fetchAll();

        return $this->render('inventory/index', compact('products', 'variants', 'recentLog') + ['pageTitle' => __('admin.inventory')]);
    }

    public function adjust(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $productId = (int)$request->post('product_id', 0);
        $variantId = (int)$request->post('variant_id', 0);
        $changeQty = (int)$request->post('change_qty', 0);
        $reason = in_array($request->post('reason', ''), ['restock', 'adjustment', 'return'], true) ? $request->post('reason') : 'adjustment';
        $adminId = $this->admin['id'];

        if ($productId && $changeQty !== 0) {
            $this->pdo->beginTransaction();
            try {
                if ($variantId) {
                    $this->pdo->prepare('UPDATE product_variants SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id = ? AND product_id = ?')
                        ->execute([$changeQty, $variantId, $productId]);
                    $this->pdo->prepare('INSERT INTO inventory_log (product_id, product_variant_id, change_qty, reason, reference, created_by) VALUES (?, ?, ?, ?, "manual adjustment", ?)')
                        ->execute([$productId, $variantId, $changeQty, $reason, $adminId]);
                } else {
                    $this->pdo->prepare('UPDATE products SET stock_quantity = GREATEST(0, stock_quantity + ?) WHERE id = ?')->execute([$changeQty, $productId]);
                    $this->pdo->prepare('INSERT INTO inventory_log (product_id, change_qty, reason, reference, created_by) VALUES (?, ?, ?, "manual adjustment", ?)')
                        ->execute([$productId, $changeQty, $reason, $adminId]);
                }
                $this->pdo->commit();
                $this->flash('success', __('admin.inventory.flash_updated'));
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                $this->flash('error', __('admin.inventory.update_error', ['message' => $e->getMessage()]));
            }
        }
        return $this->redirect('/admin/inventory');
    }
}
