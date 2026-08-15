<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Direct port of admin/finance.php - read-only reporting page, no forms.
 * Gives a super admin/manager a bird's-eye view of the shop's money:
 * total revenue, refunds, pending payments, average order value, a
 * 6-month revenue trend, the recent transaction ledger, and a breakdown
 * by payment method. Exists as its own controller because it's pure
 * aggregation across `orders`/`transactions` - nothing here is a single
 * entity being created/edited, so it doesn't fit any CRUD shape.
 */
final class FinanceAdminController extends AdminCrudController
{
    /** Raw database handle for this controller's read-only aggregate queries against `orders`/`transactions`. */
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** GET /admin/finance - computes every figure/table on the finance dashboard in one request; every query below excludes `is_test_account`/`is_test_order` data (see CLAUDE.md's "Test accounts" section) so demo/test orders never inflate real revenue figures. */
    public function index(Request $request): Response
    {
        // Only orders that actually got paid, and never a test order -
        // COALESCE(...,0) turns "no matching rows" (SUM() would return
        // NULL) into a clean 0.00 instead of null arithmetic downstream.
        $totalRevenue = (float)$this->pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0")->fetchColumn();
        // Refund amounts in `transactions` are stored negative (see
        // OrderAdminController::save()), so SUM(-amount) flips the sign
        // back to a positive "total refunded" figure for display.
        $totalRefunded = (float)$this->pdo->query("SELECT COALESCE(SUM(-amount),0) FROM transactions WHERE type = 'refund'")->fetchColumn();
        $pendingPayments = (float)$this->pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status = 'pending' AND is_test_order = 0")->fetchColumn();
        $avgOrderValue = (float)$this->pdo->query("SELECT COALESCE(AVG(total),0) FROM orders WHERE payment_status = 'paid' AND is_test_order = 0")->fetchColumn();
        // Shown separately (not folded into the real-money figures above)
        // just so an admin can see how much test traffic exists, without
        // it affecting any dollar amount.
        $testOrderCount = (int)$this->pdo->query("SELECT COUNT(*) FROM orders WHERE is_test_order = 1")->fetchColumn();

        // Revenue/order-count grouped by calendar month - DATE_FORMAT(...,
        // '%Y-%m') collapses every order's timestamp down to just its
        // year-month, so GROUP BY produces one row per month. Newest 6
        // months only (LIMIT 6 after ORDER BY ym DESC).
        $monthly = $this->pdo->query(
            "SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, SUM(total) AS revenue, COUNT(*) AS orders
             FROM orders WHERE payment_status = 'paid' AND is_test_order = 0
             GROUP BY ym ORDER BY ym DESC LIMIT 6"
        )->fetchAll();

        // Raw ledger of every sale/refund/manual entry, newest first,
        // capped at the most recent 30 - LEFT JOINs (not INNER) because a
        // transaction might not have an associated order or a recorded
        // admin (e.g. a system-generated entry).
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
