<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * New in v2.00 - admin side of Controllers\Storefront\ContactController.
 * No legacy procedural equivalent to port (the contact form itself is new),
 * so this follows the same index()+show()+save() shape the rest of Phase 8
 * uses rather than porting anything line-for-line.
 */
final class ContactAdminController extends AdminCrudController
{
    // Shared PDO connection - this controller talks to the contact_messages
    // table directly with hand-written SQL rather than through a Model class.
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** Lists submitted contact-form messages, optionally filtered to just one status (new/read/replied/closed), newest first. */
    public function index(Request $request): Response
    {
        $statusFilter = (string)$request->get('status', '');
        $sql = 'SELECT * FROM contact_messages';
        $params = [];
        if ($statusFilter !== '') {
            $sql .= ' WHERE status = ?';
            $params[] = $statusFilter;
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $messages = $stmt->fetchAll();

        $statuses = ['new', 'read', 'replied', 'closed'];

        return $this->render('contact_messages/index', compact('messages', 'statuses', 'statusFilter') + ['pageTitle' => __('admin.contact_messages')]);
    }

    /** Shows one contact message in full, auto-marking it as read the first time an admin opens it. */
    public function show(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        $message = $this->fetchMessage($id);
        if (!$message) {
            $this->flash('error', __('admin.contact_messages.not_found'));
            return $this->redirect('/admin/contact-messages');
        }

        // Opening a new message marks it read - same "viewing implies
        // acknowledged" convention as a normal inbox; a further explicit
        // status change (replied/closed) still needs the form below.
        if ($message['status'] === 'new') {
            $this->pdo->prepare('UPDATE contact_messages SET status = ? WHERE id = ?')->execute(['read', $id]);
            $message['status'] = 'read';
        }

        $statuses = ['new', 'read', 'replied', 'closed'];
        $pageTitle = __('admin.contact_message_view.title', ['name' => $message['name']]);
        return $this->render('contact_messages/show', compact('message', 'statuses', 'pageTitle'));
    }

    /** Updates a contact message's status and/or internal admin notes from the review form. */
    public function save(Request $request): Response
    {
        // Blocks a forged status-change submission (CSRF) - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->routeParam('id', 0);
        $message = $this->fetchMessage($id);
        if (!$message) {
            $this->flash('error', __('admin.contact_messages.not_found'));
            return $this->redirect('/admin/contact-messages');
        }

        $statuses = ['new', 'read', 'replied', 'closed'];
        // Whitelist check: only accept the submitted status if it's one of the
        // four known values, otherwise silently keep the message's current
        // status - stops a tampered/unexpected form value from writing garbage
        // into the status column.
        $status = in_array($request->post('status', ''), $statuses, true) ? $request->post('status') : $message['status'];
        $adminNotes = trim((string)$request->post('admin_notes', ''));

        // Empty notes are stored as NULL rather than an empty string, so "no
        // notes" is represented one consistent way in the database.
        $this->pdo->prepare('UPDATE contact_messages SET status = ?, admin_notes = ? WHERE id = ?')
            ->execute([$status, $adminNotes !== '' ? $adminNotes : null, $id]);

        $this->flash('success', __('admin.contact_message_view.flash_updated'));
        return $this->redirect('/admin/contact-messages/' . $id);
    }

    /** Looks up one contact message by id, or null if it doesn't exist - shared by show() and save() so both agree on what "not found" means. */
    private function fetchMessage(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contact_messages WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
