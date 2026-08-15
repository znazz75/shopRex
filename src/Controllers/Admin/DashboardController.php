<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Renders the admin back-office home page (the first screen after login) -
 * a mix of inventory health (product/low-stock counts, always shown) and
 * financial figures (revenue, order counts, recent orders, only shown to
 * Super Admins). Direct port of admin/index.php. It's its own controller
 * rather than folded into another one because it's the one page every
 * admin lands on regardless of role, so it needs its own capability-gated
 * route ('dashboard', open to both roles) with extra internal gating for
 * the finance-flavored parts.
 */
final class DashboardController extends AdminCrudController
{
    // Shared PDO connection, pulled from the container - used directly here
    // (rather than through a Model) because this page is just read-only
    // aggregate counts, not entity CRUD.
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** Gathers the stats shown on the dashboard and renders the page - product/stock counts for everyone, revenue and order figures only for Super Admins. */
    public function index(Request $request): Response
    {
        // 'finance' capability is Super Admin-only (see AdminAuth::CAPABILITIES) -
        // reused here as a stand-in for "is this admin a Super Admin", since a
        // Manager shouldn't see revenue/order figures even though both roles can
        // view this page at all.
        $isSuperAdmin = AdminAuth::can('finance');

        $productCount = (int)$this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        // "Low stock" = current stock at or below the per-product reorder threshold.
        $lowStockCount = (int)$this->pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity <= stock_threshold')->fetchColumn();
        $lowStock = $this->pdo->query(
            'SELECT id, name, sku, stock_quantity, stock_threshold FROM products WHERE stock_quantity <= stock_threshold ORDER BY stock_quantity ASC LIMIT 8'
        )->fetchAll();

        // Defaults used when the viewer isn't a Super Admin - keeps the view
        // template simple (it can always reference these keys) without leaking
        // real figures to a Manager.
        $revenueToday = 0.0;
        $revenueMonth = 0.0;
        $orderCount = 0;
        $pendingOrders = 0;
        $customerCount = 0;
        $testOrderCount = 0;
        $recentOrders = [];

        if ($isSuperAdmin) {
            // is_test_order = 0 / is_test_account = 0 excludes orders/customers created
            // via "is_test_account" test logins (see CLAUDE.md's Test accounts section) -
            // those are sandbox activity, not real sales, and must never appear in
            // financial totals.
            $revenueToday = (float)$this->pdo->query(
                "SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0 AND DATE(created_at) = CURDATE()"
            )->fetchColumn();
            $revenueMonth = (float)$this->pdo->query(
                "SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0 AND YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE())"
            )->fetchColumn();
            $orderCount = (int)$this->pdo->query('SELECT COUNT(*) FROM orders WHERE is_test_order = 0')->fetchColumn();
            $pendingOrders = (int)$this->pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'pending' AND is_test_order = 0")->fetchColumn();
            $customerCount = (int)$this->pdo->query('SELECT COUNT(*) FROM customers WHERE is_test_account = 0')->fetchColumn();
            $testOrderCount = (int)$this->pdo->query('SELECT COUNT(*) FROM orders WHERE is_test_order = 1')->fetchColumn();
            // LEFT JOIN + COALESCE so a guest checkout (no customers row, customer_id
            // is null) still shows an email address by falling back to the order's own
            // guest_email column.
            $recentOrders = $this->pdo->query(
                "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
                 FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
                 ORDER BY o.created_at DESC LIMIT 8"
            )->fetchAll();
        }

        return $this->render('dashboard/index', [
            'isSuperAdmin' => $isSuperAdmin, 'productCount' => $productCount, 'lowStockCount' => $lowStockCount,
            'lowStock' => $lowStock, 'revenueToday' => $revenueToday, 'revenueMonth' => $revenueMonth,
            'orderCount' => $orderCount, 'pendingOrders' => $pendingOrders, 'customerCount' => $customerCount,
            'testOrderCount' => $testOrderCount, 'recentOrders' => $recentOrders, 'pageTitle' => __('admin.dashboard'),
        ]);
    }
}
