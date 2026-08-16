<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Order;
use ShopRex\Services\Mailer;
use ShopRex\Services\OrderEditingService;
use ShopRex\Services\PaymentReminderService;

/**
 * Direct port of admin/orders.php + admin/order_view.php. The admin's
 * order management screen: a filterable list plus a per-order detail page
 * where staff change status/payment status, add internal notes, and
 * optionally notify the customer. Not built on AdminCrudController's
 * generic list+edit shape because an order isn't really "edited" like a
 * category/tax rate - it's transitioned through a status workflow, with
 * side effects (transaction ledger entries, shipped_at stamping) that a
 * generic save() wouldn't accommodate.
 */
final class OrderAdminController extends AdminCrudController
{
    /** Raw database handle for this controller's queries against `orders` and its related tables (`order_items`, `payments`, `invoices`, `transactions`). */
    private readonly \PDO $pdo;
    // The actual create/edit-items/cancel logic (Cart::priceLine()-based
    // server-side pricing, stock deltas, ledger reconciliation, invoice
    // regeneration) - see its class docblock. Kept out of this controller
    // so those rules live in one place a non-admin caller could reuse too.
    private readonly OrderEditingService $orderEditing;
    /** Sends a "your order is still unpaid" reminder for one order (see sendPaymentReminderNow() below) - the same service the daily cron job (admin/cron/payment_reminders.php) uses for automatic sends. */
    private readonly PaymentReminderService $paymentReminders;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->orderEditing = $container->make(OrderEditingService::class);
        $this->paymentReminders = $container->make(PaymentReminderService::class);
    }

    /** GET /admin/orders - lists orders, optionally filtered by status and/or real-vs-test-order. */
    public function index(Request $request): Response
    {
        $statusFilter = (string)$request->get('status', '');
        $typeFilter = in_array($request->get('type', ''), ['all', 'real', 'test'], true) ? $request->get('type') : 'all';

        // Built up as an array of SQL fragments + matching placeholders
        // (rather than string-concatenating filter values into the query),
        // so every value that ends up in the SQL is still bound through a
        // prepared statement below - filters are optional, so the WHERE
        // clause has to be assembled conditionally.
        $where = [];
        $params = [];
        if ($statusFilter !== '') {
            $where[] = 'o.status = ?';
            $params[] = $statusFilter;
        }
        // is_test_order isn't a bound parameter here since it only ever
        // takes one of two hardcoded literal values (0 or 1) chosen by
        // this PHP code, never taken from user input directly.
        if ($typeFilter === 'real') {
            $where[] = 'o.is_test_order = 0';
        } elseif ($typeFilter === 'test') {
            $where[] = 'o.is_test_order = 1';
        }

        // LEFT JOIN + COALESCE(c.email, o.guest_email): an order's
        // customer_id can be null (guest checkout), so this falls back to
        // the guest_email column stored on the order itself whenever
        // there's no linked customer row.
        $sql = "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
                FROM orders o LEFT JOIN customers c ON c.id = o.customer_id";
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY o.created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $orders = $stmt->fetchAll();

        $statuses = ['pending', 'processing', 'paid', 'shipped', 'completed', 'cancelled', 'refunded'];

        return $this->render('orders/index', compact('orders', 'statuses', 'statusFilter', 'typeFilter') + ['pageTitle' => __('admin.orders')]);
    }

    /** GET /admin/orders/{id} - the single-order detail page: line items, payments, generated invoice (if any), and the status-change form. */
    public function show(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        $order = $this->fetchOrder($id);
        if (!$order) {
            $this->flash('error', __('admin.order_view.not_found'));
            return $this->redirect('/admin/orders');
        }

        $statuses = ['pending', 'processing', 'paid', 'shipped', 'completed', 'cancelled', 'refunded'];
        $paymentStatuses = ['pending', 'paid', 'failed', 'refunded'];

        $items = $this->fetchAll('SELECT * FROM order_items WHERE order_id = ?', [$id]);
        $payments = $this->fetchAll('SELECT * FROM payments WHERE order_id = ?', [$id]);
        $invStmt = $this->pdo->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1');
        $invStmt->execute([$id]);
        $invoice = $invStmt->fetch();

        // Everything the "Edit Line Items" card's product/shipping dropdowns
        // need, plus this order's own edit history and whether the current
        // admin is even allowed to cancel it (Super Admin only - see
        // AdminAuth::CAPABILITIES['orders_delete']).
        $products = $this->pdo->query('SELECT id, name, sku, price FROM products WHERE status = "active" ORDER BY name')->fetchAll();
        $shippingMethods = $this->pdo->query('SELECT id, name FROM shipping_methods WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
        $editLog = $this->fetchAll('SELECT oel.*, au.username FROM order_edit_log oel LEFT JOIN admin_users au ON au.id = oel.admin_id WHERE oel.order_id = ? ORDER BY oel.created_at DESC', [$id]);
        $canDelete = AdminAuth::can('orders_delete');

        $pageTitle = __('admin.order_view.title', ['number' => $order['order_number']]);
        return $this->render('orders/show', compact('order', 'statuses', 'paymentStatuses', 'items', 'payments', 'invoice', 'products', 'shippingMethods', 'editLog', 'canDelete', 'pageTitle'));
    }

    /**
     * Applies a status/payment-status change (and optional admin note) from
     * the order detail page. Beyond the plain UPDATE, this also keeps two
     * things in sync: the shipped_at timestamp (used elsewhere to compute
     * the withdrawal/cancellation deadline) and the `transactions` ledger
     * that Admin -> Finance reads from - a status change here is the only
     * way those side effects get triggered, since nothing else writes to
     * `transactions` for a manually-marked-paid/refunded order.
     */
    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $order = $this->fetchOrder($id);
        if (!$order) {
            $this->flash('error', __('admin.order_view.not_found'));
            return $this->redirect('/admin/orders');
        }

        $statuses = ['pending', 'processing', 'paid', 'shipped', 'completed', 'cancelled', 'refunded'];
        $paymentStatuses = ['pending', 'paid', 'failed', 'refunded'];

        // Whitelisted server-side, not just constrained by the <select> in
        // the form - a POST is just text, and status/payment_status feed
        // straight into the transaction-ledger logic below.
        $newStatus = in_array($request->post('status', ''), $statuses, true) ? $request->post('status') : $order['status'];
        $newPaymentStatus = in_array($request->post('payment_status', ''), $paymentStatuses, true) ? $request->post('payment_status') : $order['payment_status'];
        $adminNotes = trim((string)$request->post('admin_notes', ''));

        // The order row itself, its payments row, and a transactions ledger
        // entry all need to agree with each other - run as one transaction
        // so a mid-way failure (e.g. the ledger INSERT) can't leave the
        // order already marked paid/refunded with no matching ledger entry,
        // or vice versa.
        $this->pdo->beginTransaction();
        try {
            // v2.00 - stamp shipped_at the first time an order transitions to
            // 'shipped', for Models\WithdrawalRequest::calculateDeadline().
            // Never overwritten on a later save (a re-save while already
            // 'shipped' leaves the original ship date intact).
            if ($newStatus === 'shipped' && $order['status'] !== 'shipped') {
                $this->pdo->prepare('UPDATE orders SET status = ?, payment_status = ?, admin_notes = ?, shipped_at = NOW() WHERE id = ?')
                    ->execute([$newStatus, $newPaymentStatus, $adminNotes, $id]);
            } else {
                $this->pdo->prepare('UPDATE orders SET status = ?, payment_status = ?, admin_notes = ? WHERE id = ?')
                    ->execute([$newStatus, $newPaymentStatus, $adminNotes, $id]);
            }

            $adminId = $this->admin['id'];
            // Only log a ledger entry on the actual transition INTO "paid"
            // (not every save while it's already paid) - otherwise re-saving
            // the same order repeatedly would double/triple-count its revenue
            // in Admin -> Finance.
            if ($newPaymentStatus === 'paid' && $order['payment_status'] !== 'paid') {
                $this->pdo->prepare('UPDATE payments SET status = "completed" WHERE order_id = ?')->execute([$id]);
                // Test orders (is_test_account customers) are excluded from
                // every financial figure by design (see CLAUDE.md's "Test
                // accounts" section) - so no ledger row is written for them.
                if (empty($order['is_test_order'])) {
                    $this->pdo->prepare('INSERT INTO transactions (order_id, type, amount, note, created_by) VALUES (?, "sale", ?, "Marked paid by admin", ?)')
                        ->execute([$id, $order['total'], $adminId]);
                }
            }
            // Same "only on the actual transition" + "skip test orders" logic
            // as above, but for refunds - amount is stored negative so
            // FinanceAdminController's SUM() naturally nets refunds against sales.
            if ($newPaymentStatus === 'refunded' && $order['payment_status'] !== 'refunded' && empty($order['is_test_order'])) {
                $this->pdo->prepare('INSERT INTO transactions (order_id, type, amount, note, created_by) VALUES (?, "refund", ?, "Refund recorded by admin", ?)')
                    ->execute([$id, -$order['total'], $adminId]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->flash('error', __('admin.order_view.flash_updated_error', ['message' => $e->getMessage()]));
            return $this->redirect('/admin/orders/' . $id);
        }

        // Reflect the just-saved values back into the in-memory $order
        // array so the notify-customer email below (and anything after it)
        // sees the new status, not the stale one that was loaded at the
        // top of this method.
        $order['status'] = $newStatus;
        $order['payment_status'] = $newPaymentStatus;
        $order['admin_notes'] = $adminNotes;

        // Optional checkbox on the form - only emails the customer if the
        // admin explicitly asked to, so routine/internal-only status
        // tweaks don't spam the customer.
        if ($request->post('notify_customer')) {
            Mailer::sendOrderStatusUpdate($order);
        }

        $this->flash('success', __('admin.order_view.flash_updated'));
        return $this->redirect('/admin/orders/' . $id);
    }

    /** GET /admin/orders/create - the manual order-entry form (e.g. a phone order). Available to managers and Super Admin (the 'orders' capability - see AdminAuth::CAPABILITIES). */
    public function create(Request $request): Response
    {
        return $this->render('orders/create', $this->createFormData([]));
    }

    /** POST /admin/orders/create - validates and creates a manually-entered order via Services\OrderEditingService::createManualOrder(). */
    public function store(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $customer = null;
        $customerId = (int)$request->post('customer_id', 0);
        if ($customerId) {
            $stmt = $this->pdo->prepare('SELECT * FROM customers WHERE id = ?');
            $stmt->execute([$customerId]);
            $customer = $stmt->fetch() ?: null;
        }

        try {
            $order = $this->orderEditing->createManualOrder($request->all(), $customer, $this->admin['id']);
        } catch (\RuntimeException $e) {
            return $this->render('orders/create', $this->createFormData([$e->getMessage()]));
        }

        $this->flash('success', __('admin.orders.create.flash_created', ['number' => $order->orderNumber]));
        return $this->redirect('/admin/orders/' . $order->id);
    }

    /** POST /admin/orders/{id}/items - applies a line-item add/remove/quantity change to an existing order via Services\OrderEditingService::applyLineItemChanges(). */
    public function saveItems(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $order = Order::find($id);
        if (!$order) {
            $this->flash('error', __('admin.order_view.not_found'));
            return $this->redirect('/admin/orders');
        }

        try {
            $this->orderEditing->applyLineItemChanges($order, $request->all(), $this->admin['id']);
            $this->flash('success', __('admin.orders.edit.flash_saved'));
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        return $this->redirect('/admin/orders/' . $id);
    }

    /**
     * POST /admin/orders/{id}/resend-invoice - re-sends the order's
     * already-generated invoice PDF to the customer (Services\Mailer::sendInvoiceEmail()).
     * Available to managers too (->capability('orders'), same as
     * create/edit) - unlike cancel, sending an email has no financial or
     * stock effect. Only ever shown/reachable when the order actually has
     * an invoice yet (same guard the "Download Invoice" link already uses).
     */
    public function resendInvoice(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $order = $this->fetchOrder($id);
        if (!$order) {
            $this->flash('error', __('admin.order_view.not_found'));
            return $this->redirect('/admin/orders');
        }

        $invStmt = $this->pdo->prepare('SELECT * FROM invoices WHERE order_id = ? LIMIT 1');
        $invStmt->execute([$id]);
        $invoice = $invStmt->fetch();
        if (!$invoice || !is_file($invoice['pdf_path'])) {
            $this->flash('error', __('admin.orders.resend.no_invoice'));
            return $this->redirect('/admin/orders/' . $id);
        }

        if (Mailer::sendInvoiceEmail($order, $invoice)) {
            $this->flash('success', __('admin.orders.resend.flash_sent', ['email' => $order['customer_email']]));
        } else {
            $this->flash('error', __('admin.orders.resend.flash_failed'));
        }

        return $this->redirect('/admin/orders/' . $id);
    }

    /**
     * POST /admin/orders/{id}/send-payment-reminder - manually sends a
     * payment-reminder email for this order right now, regardless of the
     * Admin -> Settings -> Payment Reminders automatic-send toggle (that
     * setting only controls the daily cron sweep, not this button). Same
     * 'orders' capability as resendInvoice() - sending an email has no
     * financial/stock effect. Available whenever the order is still
     * unpaid, even if a reminder was already sent before (a manager may
     * reasonably want to send a second one).
     */
    public function sendPaymentReminderNow(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $order = $this->fetchOrder($id);
        if (!$order) {
            $this->flash('error', __('admin.order_view.not_found'));
            return $this->redirect('/admin/orders');
        }
        if ($order['payment_status'] !== 'pending') {
            $this->flash('error', __('admin.orders.payment_reminder.not_unpaid'));
            return $this->redirect('/admin/orders/' . $id);
        }

        if ($this->paymentReminders->sendReminder($order)) {
            $this->flash('success', __('admin.orders.payment_reminder.flash_sent', ['email' => $order['customer_email']]));
        } else {
            $this->flash('error', __('admin.orders.payment_reminder.flash_failed'));
        }

        return $this->redirect('/admin/orders/' . $id);
    }

    /**
     * POST /admin/orders/{id}/cancel - "delete" an order (never a real SQL
     * DELETE - see Services\OrderEditingService::cancelAndRestock()'s
     * docblock and sql/schema.sql's order_edit_log comment). Gated
     * separately from the rest of this controller
     * (->capability('orders_delete') in src/routes/admin.php) - Super
     * Admin only, the one irreversible action in this feature.
     */
    public function cancel(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $order = Order::find($id);
        if (!$order) {
            $this->flash('error', __('admin.order_view.not_found'));
            return $this->redirect('/admin/orders');
        }

        $this->orderEditing->cancelAndRestock($order, $this->admin['id']);
        $this->flash('success', __('admin.orders.cancel.flash_cancelled'));
        return $this->redirect('/admin/orders/' . $id);
    }

    /** Shared view-data builder for create()/a failed store() - every active product/shipping method/customer the form's dropdowns need, plus whatever validation errors (if any) should be shown above it. */
    private function createFormData(array $errors): array
    {
        $products = $this->pdo->query('SELECT id, name, sku, price FROM products WHERE status = "active" ORDER BY name')->fetchAll();
        $shippingMethods = $this->pdo->query('SELECT id, name FROM shipping_methods WHERE is_active = 1 ORDER BY sort_order, id')->fetchAll();
        $customers = $this->pdo->query('SELECT id, first_name, last_name, email FROM customers WHERE status = "active" ORDER BY first_name, last_name')->fetchAll();
        return compact('errors', 'products', 'shippingMethods', 'customers') + ['pageTitle' => __('admin.orders.create.title')];
    }

    /** Looks up one order by numeric ID, joined with its customer/guest email - shared by show() and save() so both always see the exact same shape of order row. */
    private function fetchOrder(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Small prepare+execute+fetchAll shortcut - used for the order's line items and payments, both simple "everything for this order_id" queries. */
    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
