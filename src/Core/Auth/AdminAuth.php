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
 */
final class AdminAuth
{
    public const ROLES = [
        'super_admin' => 'Super Admin',
        'manager'     => 'Manager',
    ];

    public const CAPABILITIES = [
        'dashboard'        => ['super_admin', 'manager'],
        'products'         => ['super_admin', 'manager'],
        'categories'       => ['super_admin', 'manager'],
        'inventory'        => ['super_admin', 'manager'],
        'pages'            => ['super_admin', 'manager'],
        'menus'            => ['super_admin', 'manager'],
        'orders'           => ['super_admin'],
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
    ];

    private static ?array $cachedAdmin = null;
    private static bool $lookedUp = false;

    public static function current(): ?array
    {
        if (empty($_SESSION['admin_id'])) {
            return null;
        }
        if (!self::$lookedUp) {
            $stmt = \Database::getConnection()->prepare("SELECT * FROM admin_users WHERE id = ? AND status = 'active'");
            $stmt->execute([$_SESSION['admin_id']]);
            self::$cachedAdmin = $stmt->fetch() ?: null;
            self::$lookedUp = true;
        }
        return self::$cachedAdmin;
    }

    public static function roleLabel(string $role): string
    {
        $key = 'admin.role.' . $role;
        $label = __($key);
        return $label !== $key ? $label : (self::ROLES[$role] ?? $role);
    }

    public static function can(string $capability): bool
    {
        $admin = self::current();
        if (!$admin) {
            return false;
        }
        $allowedRoles = self::CAPABILITIES[$capability] ?? [];
        return in_array($admin['role'], $allowedRoles, true);
    }

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
            return Response::redirect(rtrim(SITE_URL, '/') . '/admin/login');
        }
        $_SESSION['flash'][] = ['type' => 'error', 'message' => __('admin.no_access')];
        return Response::redirect(rtrim(SITE_URL, '/') . '/admin');
    }

    /** Reset the per-request memoized lookup - only relevant right after login/logout. */
    public static function forget(): void
    {
        self::$cachedAdmin = null;
        self::$lookedUp = false;
    }
}
