<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\AnnualReportGenerator;

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
    private readonly AnnualReportGenerator $annualReportGenerator;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->annualReportGenerator = $container->make(AnnualReportGenerator::class);
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

        // Every year that has at least one paid, non-test order - populates
        // the Annual Report card's year picker. Newest first.
        $reportYears = array_column(
            $this->pdo->query(
                "SELECT DISTINCT YEAR(created_at) AS y FROM orders WHERE payment_status = 'paid' AND is_test_order = 0 ORDER BY y DESC"
            )->fetchAll(),
            'y'
        );

        return $this->render('finance/index', compact(
            'totalRevenue', 'totalRefunded', 'pendingPayments', 'avgOrderValue', 'testOrderCount',
            'monthly', 'transactions', 'paymentMethodBreakdown', 'reportYears'
        ) + ['pageTitle' => __('admin.finance')]);
    }

    /**
     * GET /admin/finance/annual-report?year=YYYY - streams a generated PDF
     * (Services\AnnualReportGenerator) listing every paid, non-test order
     * in that year, inline (same Content-Disposition pattern
     * Controllers\Storefront\OrderController::downloadInvoice() already
     * uses) so it opens straight in the browser, printable immediately.
     */
    public function annualReport(Request $request): Response
    {
        $year = (int)$request->get('year', date('Y'));
        // A sane range check, not a real validation need (the query below
        // is fully parameterized) - just avoids generating a technically-valid
        // but meaningless report for e.g. year 0 or year 99999 from a
        // tampered/typo'd query string.
        if ($year < 2000 || $year > (int)date('Y') + 1) {
            $year = (int)date('Y');
        }

        $orders = $this->pdo->prepare(
            "SELECT o.order_number, o.created_at, COALESCE(c.email, o.guest_email) AS customer_email,
                    o.subtotal, o.shipping_cost, o.tax_total, o.total
             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.payment_status = 'paid' AND o.is_test_order = 0 AND YEAR(o.created_at) = ?
             ORDER BY o.created_at ASC"
        );
        $orders->execute([$year]);
        $orders = $orders->fetchAll();

        $pdfBytes = $this->annualReportGenerator->generate($year, $orders);

        return Response::html($pdfBytes)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="annual-report-' . $year . '.pdf"')
            ->withHeader('Content-Length', (string)strlen($pdfBytes))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
