<?php
/**
 * Back-office role-based access control.
 *
 *   super_admin - full access, including managing other admin accounts
 *   manager     - product/category/inventory ("article") management only
 *
 * Add a role by giving it a label below and listing which capabilities it
 * grants in ADMIN_CAPABILITIES.
 */

const ADMIN_ROLES = [
    'super_admin' => 'Super Admin',
    'manager'     => 'Manager',
];

const ADMIN_CAPABILITIES = [
    'dashboard'  => ['super_admin', 'manager'],
    'products'   => ['super_admin', 'manager'],
    'categories' => ['super_admin', 'manager'],
    'inventory'  => ['super_admin', 'manager'],
    'pages'      => ['super_admin', 'manager'],
    'menus'      => ['super_admin', 'manager'],
    'orders'     => ['super_admin'],
    'finance'    => ['super_admin'],
    'customers'  => ['super_admin'],
    'admins'     => ['super_admin'],
    'settings'   => ['super_admin'],
    // Shipping cost configuration is financial/checkout-affecting, same
    // trust level as 'settings' - Super Admin only.
    'shipping'   => ['super_admin'],
];

function adminRoleLabel(string $role): string
{
    $key = 'admin.role.' . $role;
    $label = __($key);
    return $label !== $key ? $label : (ADMIN_ROLES[$role] ?? $role);
}

function adminCan(?array $admin, string $capability): bool
{
    if (!$admin) {
        return false;
    }
    $allowedRoles = ADMIN_CAPABILITIES[$capability] ?? [];
    return in_array($admin['role'], $allowedRoles, true);
}

/**
 * Require both a logged-in admin AND that their role grants $capability.
 * Redirects to login (not authenticated) or the dashboard (authenticated
 * but not permitted) with an explanatory flash message.
 */
function requireAdminPermission(string $capability): void
{
    requireAdminLogin();
    if (!adminCan(currentAdmin(), $capability)) {
        setFlash('error', __('admin.no_access'));
        redirect(rtrim(SITE_URL, '/') . '/admin/index.php');
    }
}
