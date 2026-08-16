<?php

namespace ShopRex\Core\Auth;

use ShopRex\Core\Response;

/**
 * Back-office authentication + RBAC. Direct port of
 * admin/includes/auth.php (currentAdmin()/requireAdminLogin()) and
 * admin/includes/roles.php (ADMIN_ROLES/ADMIN_CAPABILITIES/adminCan()/
 * requireAdminPermission()) - static, like the functions it replaces,
 * since $_SESSION['admin_id'] and the DB connection are already ambient
 * singletons throughout this codebase (see Database::getConnection()).
 *
 * The Router checks can() via a route's ->capability() before dispatch,
 * so admin controllers no longer need "requireAdminPermission() is line 2
 * of every page" - but AdminController's constructor still calls
 * requireLogin() itself as defense-in-depth, in case a route is ever
 * registered without ->capability() by mistake.
 *
 * In plain terms: this class answers two questions for the admin back
 * office - "who (if anyone) is logged in right now?" and "is that person
 * allowed to do this particular thing?" (RBAC = Role-Based Access Control -
 * permissions are granted to a *role*, like 'manager', not to individual
 * admins). It's a separate class from CustomerAuth because admins and
 * customers are completely different concepts with different session keys,
 * different DB tables, and (crucially) admins have roles/capabilities while
 * customers don't.
 */
final class AdminAuth
{
    /** Every admin role that exists, mapped to its human-readable display label - used for e.g. populating a role dropdown in the admin UI. */
    public const ROLES = [
        'super_admin' => 'Super Admin',
        'manager'     => 'Manager',
    ];

    /**
     * The permission table: each key is a "capability" string (a named
     * action/area of the admin, e.g. 'products' or 'settings'), and its
     * value is the list of roles allowed to use it. This is the single
     * source of truth Router::dispatch() consults (via can()) before
     * letting a request reach a capability-gated route - adding a new
     * admin feature's permission is just adding/editing one line here.
     */
    public const CAPABILITIES = [
        'dashboard'        => ['super_admin', 'manager'],
        'products'         => ['super_admin', 'manager'],
        'categories'       => ['super_admin', 'manager'],
        'inventory'        => ['super_admin', 'manager'],
        'pages'            => ['super_admin', 'manager'],
        'menus'            => ['super_admin', 'manager'],
        // v3.06 - managers can view/create/edit orders (including line
        // items); cancelling one (the one irreversible action - stock
        // restore, order marked cancelled) is gated separately below,
        // Super Admin only. See CLAUDE.md's order create/edit/cancel notes.
        'orders'           => ['super_admin', 'manager'],
        'orders_delete'    => ['super_admin'],
        'finance'          => ['super_admin'],
        'customers'        => ['super_admin'],
        'admins'           => ['super_admin'],
        'settings'         => ['super_admin'],
        // Shipping cost configuration is financial/checkout-affecting, same
        // trust level as 'settings' - Super Admin only.
        'shipping'         => ['super_admin'],
        // New in v2.00 - all conservatively Super Admin only, matching the
        // existing default for anything order/customer/finance-adjacent.
        'withdrawals'      => ['super_admin'],
        'rma_tickets'      => ['super_admin'],
        'contact_messages' => ['super_admin'],
        'legal_documents'  => ['super_admin'],
        // v3.10 - Services\AuditLogService's log of every admin action.
        // Super Admin only, per the same "who watches the watchers"
        // reasoning as 'admins' just above - a manager shouldn't be able
        // to see (or infer anything about) every action every admin has
        // taken, including other managers'.
        'audit_log'        => ['super_admin'],
    ];

    /** Memoized result of the currently-logged-in admin's DB row lookup (see $lookedUp) - null both before the lookup has run and when there simply is no logged-in admin. */
    private static ?array $cachedAdmin = null;

    /** Whether current() has already queried the database this request - prevents re-querying admin_users on every single can()/current() call within the same request. */
    private static bool $lookedUp = false;

    /**
     * Returns the logged-in admin's full DB row (as an array), or null if
     * nobody is logged in / the session's admin id no longer maps to an
     * active account. The DB lookup only happens once per request thanks to
     * the $lookedUp memoization above - every other call this request just
     * returns the cached result instantly.
     */
    public static function current(): ?array
    {
        if (empty($_SESSION['admin_id'])) {
            return null;
        }
        if (!self::$lookedUp) {
            // status = 'active' means a deactivated admin account is
            // instantly logged out on their very next request, even if
            // their session cookie is still valid.
            $stmt = \Database::getConnection()->prepare("SELECT * FROM admin_users WHERE id = ? AND status = 'active'");
            $stmt->execute([$_SESSION['admin_id']]);
            self::$cachedAdmin = $stmt->fetch() ?: null;
            self::$lookedUp = true;
        }
        return self::$cachedAdmin;
    }

    /** Returns the display label for a role, preferring a translated string (admin.role.<role>) if one exists for the current language, falling back to the plain English label in ROLES. */
    public static function roleLabel(string $role): string
    {
        $key = 'admin.role.' . $role;
        $label = __($key);
        // __() returns the key itself unchanged when no translation exists
        // for it - so comparing the result back against $key is how this
        // detects "no translation found" and falls back to the ROLES array.
        return $label !== $key ? $label : (self::ROLES[$role] ?? $role);
    }

    /**
     * The actual permission check: is the currently logged-in admin allowed
     * to use $capability? Returns false outright if nobody is logged in.
     * This is what both Router::dispatch() (before invoking a controller)
     * and AdminController (defense-in-depth) call.
     */
    public static function can(string $capability): bool
    {
        $admin = self::current();
        if (!$admin) {
            return false;
        }
        // Unknown capability strings default to an empty allow-list, i.e.
        // "nobody" - so a typo'd ->capability('prodcuts') fails closed
        // (denies everyone) rather than failing open.
        $allowedRoles = self::CAPABILITIES[$capability] ?? [];
        return in_array($admin['role'], $allowedRoles, true);
    }

    /** Redirects to the admin login page and halts execution immediately if nobody is logged in - the classic "guard clause" used at the top of legacy admin pages before the Router's capability gate existed. */
    public static function requireLogin(): void
    {
        if (!self::current()) {
            header('Location: ' . rtrim(SITE_URL, '/') . '/admin/login');
            exit;
        }
    }

    /** Redirect used by the Router when a route's capability check fails. */
    public static function denyResponse(): Response
    {
        if (!self::current()) {
            // Not logged in at all -> send them to log in first.
            return Response::redirect(rtrim(SITE_URL, '/') . '/admin/login');
        }
        // Logged in, but their role isn't on this capability's allow-list ->
        // send them back to the dashboard with an explanatory flash message
        // rather than a bare 403 page.
        $_SESSION['flash'][] = ['type' => 'error', 'message' => __('admin.no_access')];
        return Response::redirect(rtrim(SITE_URL, '/') . '/admin');
    }

    /** Reset the per-request memoized lookup - only relevant right after login/logout. */
    public static function forget(): void
    {
        // Called right after a login/logout/impersonation-style change so
        // the very next current() call re-queries the database instead of
        // returning a stale cached admin (or stale "nobody logged in")
        // from earlier in the same request.
        self::$cachedAdmin = null;
        self::$lookedUp = false;
    }
}
