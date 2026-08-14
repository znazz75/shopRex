<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/** Direct port of admin/index.php. */
final class DashboardController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function index(Request $request): Response
    {
        $isSuperAdmin = AdminAuth::can('finance');

        $productCount = (int)$this->pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
        $lowStockCount = (int)$this->pdo->query('SELECT COUNT(*) FROM products WHERE stock_quantity <= stock_threshold')->fetchColumn();
        $lowStock = $this->pdo->query(
            'SELECT id, name, sku, stock_quantity, stock_threshold FROM products WHERE stock_quantity <= stock_threshold ORDER BY stock_quantity ASC LIMIT 8'
        )->fetchAll();

        $revenueToday = 0.0;
        $revenueMonth = 0.0;
        $orderCount = 0;
        $pendingOrders = 0;
        $customerCount = 0;
        $testOrderCount = 0;
        $recentOrders = [];

        if ($isSuperAdmin) {
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
