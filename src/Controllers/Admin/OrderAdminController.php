<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/** Direct port of admin/orders.php + admin/order_view.php. */
final class OrderAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function index(Request $request): Response
    {
        $statusFilter = (string)$request->get('status', '');
        $typeFilter = in_array($request->get('type', ''), ['all', 'real', 'test'], true) ? $request->get('type') : 'all';

        $where = [];
        $params = [];
        if ($statusFilter !== '') {
            $where[] = 'o.status = ?';
            $params[] = $statusFilter;
        }
        if ($typeFilter === 'real') {
            $where[] = 'o.is_test_order = 0';
        } elseif ($typeFilter === 'test') {
            $where[] = 'o.is_test_order = 1';
        }

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

        $pageTitle = __('admin.order_view.title', ['number' => $order['order_number']]);
        return $this->render('orders/show', compact('order', 'statuses', 'paymentStatuses', 'items', 'payments', 'invoice', 'pageTitle'));
    }

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
        if ($newPaymentStatus === 'paid' && $order['payment_status'] !== 'paid') {
            $this->pdo->prepare('UPDATE payments SET status = "completed" WHERE order_id = ?')->execute([$id]);
            if (empty($order['is_test_order'])) {
                $this->pdo->prepare('INSERT INTO transactions (order_id, type, amount, note, created_by) VALUES (?, "sale", ?, "Marked paid by admin", ?)')
                    ->execute([$id, $order['total'], $adminId]);
            }
        }
        if ($newPaymentStatus === 'refunded' && $order['payment_status'] !== 'refunded' && empty($order['is_test_order'])) {
            $this->pdo->prepare('INSERT INTO transactions (order_id, type, amount, note, created_by) VALUES (?, "refund", ?, "Refund recorded by admin", ?)')
                ->execute([$id, -$order['total'], $adminId]);
        }

        $order['status'] = $newStatus;
        $order['payment_status'] = $newPaymentStatus;
        $order['admin_notes'] = $adminNotes;

        if ($request->post('notify_customer')) {
            \Mailer::sendOrderStatusUpdate($order);
        }

        $this->flash('success', __('admin.order_view.flash_updated'));
        return $this->redirect('/admin/orders/' . $id);
    }

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

    private function fetchAll(string $sql, array $params): array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
