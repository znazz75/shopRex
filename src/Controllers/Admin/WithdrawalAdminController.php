<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\WithdrawalRequest;
use ShopRex\Services\I18n;

/**
 * New in v2.00 - admin review queue for Controllers\Storefront\WithdrawalController's
 * self-service requests. No legacy procedural equivalent exists (the whole
 * flow is new), so this follows the same index()+show()+save() shape the
 * rest of Phase 8 uses.
 */
final class WithdrawalAdminController extends AdminCrudController
{
    private const STATUSES = ['submitted', 'under_review', 'approved', 'rejected', 'refunded', 'cancelled'];

    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function index(Request $request): Response
    {
        $statusFilter = (string)$request->get('status', '');
        $sql = "SELECT wr.*, o.order_number, COALESCE(c.email, o.guest_email) AS customer_email
                FROM withdrawal_requests wr
                JOIN orders o ON o.id = wr.order_id
                LEFT JOIN customers c ON c.id = wr.customer_id";
        $params = [];
        if ($statusFilter !== '') {
            $sql .= ' WHERE wr.status = ?';
            $params[] = $statusFilter;
        }
        $sql .= ' ORDER BY wr.requested_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $requests = $stmt->fetchAll();

        return $this->render('withdrawals/index', ['requests' => $requests, 'statuses' => self::STATUSES, 'statusFilter' => $statusFilter, 'pageTitle' => __('admin.withdrawals')]);
    }

    public function show(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        $withdrawal = WithdrawalRequest::find($id);
        if (!$withdrawal) {
            $this->flash('error', __('admin.withdrawal_view.not_found'));
            return $this->redirect('/admin/withdrawals');
        }

        $order = $this->fetchOrder((int)$withdrawal->orderId);
        $items = $withdrawal->items();

        $pageTitle = __('admin.withdrawal_view.title', ['number' => $order['order_number'] ?? '']);
        return $this->render('withdrawals/show', [
            'withdrawal' => $withdrawal, 'order' => $order, 'items' => $items,
            'statuses' => self::STATUSES, 'pageTitle' => $pageTitle,
        ]);
    }

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $withdrawal = WithdrawalRequest::find($id);
        if (!$withdrawal) {
            $this->flash('error', __('admin.withdrawal_view.not_found'));
            return $this->redirect('/admin/withdrawals');
        }

        $newStatus = in_array($request->post('status', ''), self::STATUSES, true) ? $request->post('status') : $withdrawal->status;
        $adminNotes = trim((string)$request->post('admin_notes', ''));

        $withdrawal->transitionTo($newStatus, $this->pdo, $this->admin['id'], $adminNotes !== '' ? $adminNotes : null);

        if (($newStatus === 'approved' || $newStatus === 'rejected') && $request->post('notify_customer')) {
            $this->notifyCustomer($withdrawal, $newStatus, $adminNotes);
        }

        $this->flash('success', __('admin.withdrawal_view.flash_updated'));
        return $this->redirect('/admin/withdrawals/' . $id);
    }

    private function notifyCustomer(WithdrawalRequest $withdrawal, string $status, string $adminNotes): void
    {
        $order = $this->fetchOrder((int)$withdrawal->orderId);
        if (!$order) {
            return;
        }
        $lang = $order['language'] ?: I18n::current();
        $templateKey = $status === 'approved' ? 'withdrawal_request_approved' : 'withdrawal_request_rejected';
        $rendered = \Mailer::render($templateKey, $lang, [
            'customer_name' => e($order['customer_first_name'] ?? ''),
            'order_number'  => e($order['order_number']),
            'admin_notes'   => nl2br(e($adminNotes)),
        ]);
        \Mailer::send($order['customer_email'], $rendered['subject'], $rendered['html'], $templateKey, (int)$order['id']);
    }

    private function fetchOrder(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email, c.first_name AS customer_first_name, c.last_name AS customer_last_name
             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.id = ?"
        );
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
