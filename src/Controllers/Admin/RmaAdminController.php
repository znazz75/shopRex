<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\RmaTicket;
use ShopRex\Services\I18n;
use ShopRex\Services\Mailer;

/**
 * New in v2.00 - admin review queue for Controllers\Storefront\RmaController's
 * defect/warranty tickets. No legacy procedural equivalent exists, so this
 * follows the same index()+show()+save() shape the rest of Phase 8 uses.
 */
final class RmaAdminController extends AdminCrudController
{
    // Every valid lifecycle state an RMA ticket can be in, in roughly the
    // order a ticket progresses through them - used both to validate incoming
    // status values and to populate the status dropdown in the views.
    private const STATUSES = ['submitted', 'under_review', 'awaiting_return', 'approved', 'rejected', 'repaired', 'replaced', 'refunded', 'closed'];

    // Shared PDO connection - used for the hand-written list/lookup queries;
    // the ticket itself is still loaded/mutated through the RmaTicket model.
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** Lists RMA (return/warranty) tickets, optionally filtered to one status, each row annotated with its order number, product, and customer email. */
    public function index(Request $request): Response
    {
        $statusFilter = (string)$request->get('status', '');
        // Inner JOINs to orders/order_items are safe (every ticket must
        // reference a real order and order line), but customers is a LEFT JOIN
        // + COALESCE because a guest checkout has no customers row - falls back
        // to the order's own guest_email in that case.
        $sql = "SELECT rt.*, o.order_number, oi.product_name, COALESCE(c.email, o.guest_email) AS customer_email
                FROM rma_tickets rt
                JOIN orders o ON o.id = rt.order_id
                JOIN order_items oi ON oi.id = rt.order_item_id
                LEFT JOIN customers c ON c.id = rt.customer_id";
        $params = [];
        if ($statusFilter !== '') {
            $sql .= ' WHERE rt.status = ?';
            $params[] = $statusFilter;
        }
        $sql .= ' ORDER BY rt.requested_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $tickets = $stmt->fetchAll();

        return $this->render('rma_tickets/index', ['tickets' => $tickets, 'statuses' => self::STATUSES, 'statusFilter' => $statusFilter, 'pageTitle' => __('admin.rma_tickets')]);
    }

    /** Shows one RMA ticket's full detail (order, the specific order line it's about, and any photo attachments the customer uploaded) for review. */
    public function show(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        $ticket = RmaTicket::find($id);
        if (!$ticket) {
            $this->flash('error', __('admin.rma_view.not_found'));
            return $this->redirect('/admin/rma-tickets');
        }

        $order = $this->fetchOrder((int)$ticket->orderId);
        $item = $this->fetchOrderItem((int)$ticket->orderItemId);
        // Up to 5 customer-uploaded photos documenting the defect - see
        // Models\RmaTicket::attachments() / CLAUDE.md's legal/compliance domain notes.
        $attachments = $ticket->attachments();

        $pageTitle = __('admin.rma_view.title', ['number' => $order['order_number'] ?? '']);
        return $this->render('rma_tickets/show', [
            'ticket' => $ticket, 'order' => $order, 'item' => $item, 'attachments' => $attachments,
            'statuses' => self::STATUSES, 'pageTitle' => $pageTitle,
        ]);
    }

    /** Applies a status change and/or resolution notes to a ticket from the review form, optionally emailing the customer about the update. */
    public function save(Request $request): Response
    {
        // Blocks a forged status-change submission (CSRF) - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $ticket = RmaTicket::find($id);
        if (!$ticket) {
            $this->flash('error', __('admin.rma_view.not_found'));
            return $this->redirect('/admin/rma-tickets');
        }

        // Whitelist check: fall back to the ticket's existing status if the
        // submitted value isn't one of the known STATUSES, so a tampered form
        // value can't set an invalid/unexpected status.
        $newStatus = in_array($request->post('status', ''), self::STATUSES, true) ? $request->post('status') : $ticket->status;
        $adminNotes = trim((string)$request->post('admin_notes', ''));
        $resolutionNotes = trim((string)$request->post('resolution_notes', ''));

        // transitionTo() is the shared CustomerRequest behavior (also used by
        // WithdrawalRequest) that records the status change plus which admin
        // made it and any notes - see CLAUDE.md's legal/compliance domain
        // notes. Wrapped together with the resolution_notes UPDATE in one
        // transaction so a failure between the two can't leave the ticket's
        // status changed without its resolution notes actually saved.
        $this->pdo->beginTransaction();
        try {
            $ticket->transitionTo($newStatus, $this->pdo, $this->admin['id'], $adminNotes !== '' ? $adminNotes : null);
            $this->pdo->prepare('UPDATE rma_tickets SET resolution_notes = ? WHERE id = ?')
                ->execute([$resolutionNotes !== '' ? $resolutionNotes : null, $id]);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->flash('error', __('admin.rma_view.flash_updated_error', ['message' => $e->getMessage()]));
            return $this->redirect('/admin/rma-tickets/' . $id);
        }

        // Emailing the customer is opt-in per save (a checkbox on the form) -
        // an admin can update internal notes/status without necessarily
        // notifying the customer every single time.
        if ($request->post('notify_customer')) {
            $this->notifyCustomer($ticket, $newStatus, $resolutionNotes);
        }

        $this->flash('success', __('admin.rma_view.flash_updated'));
        return $this->redirect('/admin/rma-tickets/' . $id);
    }

    /** Sends the customer an email about their RMA ticket's status update, in the order's own language (not the admin's). */
    private function notifyCustomer(RmaTicket $ticket, string $status, string $resolutionNotes): void
    {
        $order = $this->fetchOrder((int)$ticket->orderId);
        if (!$order) {
            return;
        }
        // Prefer the language the order was originally placed in; fall back to
        // whatever the current request's language is if the order never
        // recorded one - keeps the notification readable to the customer even
        // for old orders predating this column.
        $lang = $order['language'] ?: I18n::current();
        $rendered = Mailer::render('rma_ticket_status_update', $lang, [
            'order_number'      => e($order['order_number']),
            'status'            => e($status),
            // e() escapes the notes for HTML, nl2br() then turns the admin's
            // typed line breaks into <br> tags so the email keeps their
            // paragraph structure.
            'resolution_notes'  => $resolutionNotes !== '' ? '<p>' . nl2br(e($resolutionNotes)) . '</p>' : '',
        ]);
        Mailer::send($order['customer_email'], $rendered['subject'], $rendered['html'], 'rma_ticket_status_update', (int)$order['id']);
    }

    /** Looks up one order by id, with its customer's (or guest's) email/name attached - shared by show() and notifyCustomer(). */
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

    /** Looks up the single order line item an RMA ticket is about. */
    private function fetchOrderItem(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM order_items WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
