<?php

namespace ShopRex\Services;

use ShopRex\Core\Auth\AdminAuth;

/**
 * Records "who did what when" for every mutating admin-back-office
 * request, in `admin_action_log` - a single, generic table filled from a
 * single choke point (Core\Router::dispatch(), right after a POST route
 * whose handler is a Controllers\Admin\* controller returns), rather than
 * a hand-written logging call scattered across every admin controller's
 * save()/delete() method the way the older, domain-specific
 * `order_edit_log`/`inventory_log` tables work. Chosen deliberately for
 * *this* log because "all actions... must be logged" is a completeness
 * guarantee that per-call-site logging can't make - a 24th admin
 * controller added next year is automatically covered here, with zero
 * changes to it, whereas a hand-written call is trivially forgotten.
 *
 * Deliberately records only method + path + capability + status code, no
 * POST body snapshot - several admin forms carry plaintext-sensitive
 * fields (a new admin user's password, a payment gateway's secret key on
 * Admin -> Settings) and this app has no field-level redaction mechanism
 * to safely pick out only the "changed" bits. method + path is sufficient
 * for this app's clean-URL routing (e.g. "POST /admin/orders/123/cancel"
 * already says exactly what happened) without ever risking a credential
 * ending up in a log table. See Controllers\Admin\AuditLogAdminController
 * for the (Super-Admin-only) screen that displays this table.
 */
final class AuditLogService
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Called from Router::dispatch() for every POST request dispatched to
     * an admin controller. Silently no-ops when nobody is logged in (e.g.
     * the /admin/login POST itself) - an unauthenticated request isn't
     * "an action performed by an administrator".
     */
    public function record(string $method, string $path, ?string $capability, int $statusCode): void
    {
        $admin = AdminAuth::current();
        if (!$admin) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_action_log (admin_id, username, role, method, path, capability, status_code) VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$admin['id'], $admin['username'], $admin['role'], $method, $path, $capability, $statusCode]);
    }
}
