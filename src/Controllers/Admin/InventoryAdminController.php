<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Direct port of admin/inventory.php. Read-only stock overview (products
 * with no variants, and product variants separately, both sorted lowest-
 * stock-first) plus a manual stock adjustment form and the recent
 * inventory_log entries. Kept separate from ProductAdminController
 * because this page cuts across every product/variant at once (a stock
 * "dashboard"), rather than editing one product's own fields.
 */
final class InventoryAdminController extends AdminCrudController
{
    /** Raw database handle for this controller's queries against `products`/`product_variants`/`inventory_log`. */
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** GET /admin/inventory - the stock dashboard: simple (non-variant) products, product variants, and the most recent manual/automatic stock changes. */
    public function index(Request $request): Response
    {
        // Products with NO variants (a plain product tracks its own
        // stock_quantity directly) - the "id NOT IN (subquery)" excludes
        // any product that has at least one row in product_variants,
        // since those products' stock is tracked per-variant instead (see
        // the $variants query below and CLAUDE.md's Cart section on why
        // stock is per-exact-combination, not per option value).
        $products = $this->pdo->query(
            "SELECT id, name, sku, stock_quantity, stock_threshold FROM products
             WHERE id NOT IN (SELECT DISTINCT product_id FROM product_variants)
             ORDER BY stock_quantity ASC, name ASC"
        )->fetchAll();

        // One row per variant (e.g. "Size: L, Color: Red") - the
        // GROUP_CONCAT subquery builds a human-readable label like
        // "Size: L, Color: Red" out of the variant's individual option
        // value rows, so the template doesn't need its own N+1 lookups.
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

        // Same variant-label trick as above, applied to the audit log -
        // shows the last 20 stock changes (manual adjustments, sales,
        // etc.) across every product/variant, newest first.
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

    /**
     * Manually adjusts one product's (or one variant's) stock quantity by a
     * +/- amount and logs it to inventory_log - the only place besides an
     * actual sale/order that stock levels change. GREATEST(0, ...) in the
     * UPDATEs stops a large negative adjustment from ever driving stock
     * below zero.
     */
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

        // Nothing to do if no product was picked or the change amount is
        // zero (a zero-quantity "adjustment" wouldn't be a real change).
        if ($productId && $changeQty !== 0) {
            // Transaction: the stock update and its audit-log entry must
            // both land or neither should - otherwise a failure between
            // the two would leave stock changed with no record of why.
            $this->pdo->beginTransaction();
            try {
                // Two different tables get the stock update depending on
                // whether this product has variants (see index()'s
                // comment on the same split) - a variant ID means adjust
                // that specific combination's stock, otherwise adjust the
                // product's own stock_quantity column.
                if ($variantId) {
                    // "AND product_id = ?" makes sure the variant being
                    // adjusted actually belongs to the product ID that was
                    // posted, not just any variant ID - stops a crafted
                    // variant_id from touching another product's stock.
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
