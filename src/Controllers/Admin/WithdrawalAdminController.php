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
    /** Every status a withdrawal request can be in, in the order it's typically progressed through - used both to whitelist a posted status value and to populate the status dropdown in the views. */
    private const STATUSES = ['submitted', 'under_review', 'approved', 'rejected', 'refunded', 'cancelled'];

    /** Raw database handle - passed straight into WithdrawalRequest::transitionTo() as well as used for this controller's own order lookups. */
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** GET /admin/withdrawals - lists withdrawal requests, optionally filtered to one status. */
    public function index(Request $request): Response
    {
        $statusFilter = (string)$request->get('status', '');
        // JOIN orders to show the order number, LEFT JOIN customers +
        // COALESCE for the email since the underlying order may be a guest
        // checkout with no linked customer row (same pattern as
        // OrderAdminController's own order lookup).
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

    /** GET /admin/withdrawals/{id} - the single-request detail page: which order items the customer wants to return/cancel, and the status-change form. */
    public function show(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        // Models\WithdrawalRequest::find() - a real hydrated model here
        // (unlike most admin CRUD pages, which work with plain fetchAll()
        // arrays), since a withdrawal request has real behavior of its own
        // (transitionTo(), items()) rather than being pure data.
        $withdrawal = WithdrawalRequest::find($id);
        if (!$withdrawal) {
            $this->flash('error', __('admin.withdrawal_view.not_found'));
            return $this->redirect('/admin/withdrawals');
        }

        $order = $this->fetchOrder((int)$withdrawal->orderId);
        // Which specific order line items this request covers (a customer
        // can withdraw from just part of an order, not necessarily all of it).
        $items = $withdrawal->items();

        $pageTitle = __('admin.withdrawal_view.title', ['number' => $order['order_number'] ?? '']);
        return $this->render('withdrawals/show', [
            'withdrawal' => $withdrawal, 'order' => $order, 'items' => $items,
            'statuses' => self::STATUSES, 'pageTitle' => $pageTitle,
        ]);
    }

    /** Changes a withdrawal request's status (and optional admin note), then optionally emails the customer if it was just approved or rejected. */
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

        // Whitelisted against the known status list - falls back to the
        // request's current (unchanged) status rather than an unvalidated
        // POST value if something unexpected arrives.
        $newStatus = in_array($request->post('status', ''), self::STATUSES, true) ? $request->post('status') : $withdrawal->status;
        $adminNotes = trim((string)$request->post('admin_notes', ''));

        // transitionTo() is the shared base-class behavior (CustomerRequest)
        // that both WithdrawalRequest and RmaTicket use - it writes the new
        // status/notes and records who made the change and when.
        $withdrawal->transitionTo($newStatus, $this->pdo, $this->admin['id'], $adminNotes !== '' ? $adminNotes : null);

        // Only emails on the two "final decision" statuses, and only if the
        // admin explicitly checked the notify box - a move to e.g.
        // "under_review" doesn't trigger a customer email.
        if (($newStatus === 'approved' || $newStatus === 'rejected') && $request->post('notify_customer')) {
            $this->notifyCustomer($withdrawal, $newStatus, $adminNotes);
        }

        $this->flash('success', __('admin.withdrawal_view.flash_updated'));
        return $this->redirect('/admin/withdrawals/' . $id);
    }

    /** Sends the customer an approved/rejected email using the matching Mailer template, in the language the order was originally placed in (falling back to the current request's language if the order has none recorded). */
    private function notifyCustomer(WithdrawalRequest $withdrawal, string $status, string $adminNotes): void
    {
        $order = $this->fetchOrder((int)$withdrawal->orderId);
        if (!$order) {
            return;
        }
        $lang = $order['language'] ?: I18n::current();
        $templateKey = $status === 'approved' ? 'withdrawal_request_approved' : 'withdrawal_request_rejected';
        // e() escapes each value for safe HTML output, nl2br() turns the
        // admin's typed line breaks into <br> tags - both needed since
        // these values get interpolated into an HTML email template.
        $rendered = \Mailer::render($templateKey, $lang, [
            'customer_name' => e($order['customer_first_name'] ?? ''),
            'order_number'  => e($order['order_number']),
            'admin_notes'   => nl2br(e($adminNotes)),
        ]);
        \Mailer::send($order['customer_email'], $rendered['subject'], $rendered['html'], $templateKey, (int)$order['id']);
    }

    /** Looks up one order (with customer/guest email + name) by ID - used both to build the show() page and to address the notification email. */
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
