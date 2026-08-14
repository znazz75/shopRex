<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/** Direct port of admin/finance.php - read-only reporting page, no forms. */
final class FinanceAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function index(Request $request): Response
    {
        $totalRevenue = (float)$this->pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0")->fetchColumn();
        $totalRefunded = (float)$this->pdo->query("SELECT COALESCE(SUM(-amount),0) FROM transactions WHERE type = 'refund'")->fetchColumn();
        $pendingPayments = (float)$this->pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'pending' AND is_test_order = 0")->fetchColumn();
        $avgOrderValue = (float)$this->pdo->query("SELECT COALESCE(AVG(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0")->fetchColumn();
        $testOrderCount = (int)$this->pdo->query("SELECT COUNT(*) FROM orders WHERE is_test_order = 1")->fetchColumn();

        $monthly = $this->pdo->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total) AS revenue, COUNT(*) AS orders
             FROM orders WHERE payment_status = 'paid' AND is_test_order = 0
             GROUP BY ym ORDER BY ym DESC LIMIT 6"
        )->fetchAll();

        $transactions = $this->pdo->query(
            "SELECT t.*, o.order_number, a.username AS created_by_name
             FROM transactions t
             LEFT JOIN orders o ON o.id = t.order_id
             LEFT JOIN admin_users a ON a.id = t.created_by
             ORDER BY t.created_at DESC LIMIT 30"
        )->fetchAll();

        $paymentMethodBreakdown = $this->pdo->query(
            "SELECT payment_method, COUNT(*) AS cnt, COALESCE(SUM(total),0) AS revenue
             FROM orders WHERE payment_status = 'paid' AND is_test_order = 0 GROUP BY payment_method"
        )->fetchAll();

        return $this->render('finance/index', compact(
            'totalRevenue', 'totalRefunded', 'pendingPayments', 'avgOrderValue', 'testOrderCount',
            'monthly', 'transactions', 'paymentMethodBreakdown'
        ) + ['pageTitle' => __('admin.finance')]);
    }
}
