<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Admin -> Audit Log: read-only, Super-Admin-only view of
 * `admin_action_log` (see Services\AuditLogService for how rows get
 * written - one row per mutating admin request, from a single choke point
 * in Core\Router::dispatch(), not a hand-written call in this or any
 * other controller). No create/edit/delete here - this table is a record
 * of the past, not something an admin manages.
 */
final class AuditLogAdminController extends AdminCrudController
{
    private const PER_PAGE = 50;

    /** Raw database handle for this controller's read-only queries against `admin_action_log`. */
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** GET /admin/audit-log - newest-first, paginated, optionally filtered to one admin's username via ?admin=. */
    public function index(Request $request): Response
    {
        $adminFilter = trim((string)$request->get('admin', ''));
        $page = max(1, (int)$request->get('page', 1));

        $where = '';
        $params = [];
        if ($adminFilter !== '') {
            $where = 'WHERE username = ?';
            $params[] = $adminFilter;
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM admin_action_log $where");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($total / self::PER_PAGE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PER_PAGE;

        // $offset/self::PER_PAGE are computed ints, never raw user input,
        // so interpolating them directly into LIMIT/OFFSET is safe (same
        // reasoning as every other hand-paginated admin/storefront query
        // in this app).
        $stmt = $this->pdo->prepare(
            "SELECT * FROM admin_action_log $where ORDER BY created_at DESC LIMIT " . self::PER_PAGE . " OFFSET $offset"
        );
        $stmt->execute($params);
        $entries = $stmt->fetchAll();

        // For the "filter by admin" dropdown - every username that has
        // ever appeared in the log (not just admin_users' current roster,
        // since a since-deleted admin's past entries still show a
        // username via the denormalized column).
        $adminUsernames = array_column(
            $this->pdo->query('SELECT DISTINCT username FROM admin_action_log ORDER BY username')->fetchAll(),
            'username'
        );

        $paginationParams = array_filter(['admin' => $adminFilter !== '' ? $adminFilter : null], fn ($v) => $v !== null && $v !== '');

        return $this->render('audit_log/index', [
            'entries' => $entries, 'adminUsernames' => $adminUsernames, 'adminFilter' => $adminFilter,
            'page' => $page, 'totalPages' => $totalPages, 'paginationParams' => $paginationParams,
            'pageTitle' => __('admin.audit_log'),
        ]);
    }
}
