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
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

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

    public function save(Request $request): Response
    {
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
        $status = in_array($request->post('status', ''), $statuses, true) ? $request->post('status') : $message['status'];
        $adminNotes = trim((string)$request->post('admin_notes', ''));

        $this->pdo->prepare('UPDATE contact_messages SET status = ?, admin_notes = ? WHERE id = ?')
            ->execute([$status, $adminNotes !== '' ? $adminNotes : null, $id]);

        $this->flash('success', __('admin.contact_message_view.flash_updated'));
        return $this->redirect('/admin/contact-messages/' . $id);
    }

    private function fetchMessage(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM contact_messages WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
