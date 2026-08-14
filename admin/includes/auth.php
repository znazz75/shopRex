<?php
/**
 * Back-office authentication helpers.
 */

function currentAdmin(): ?array
{
    if (empty($_SESSION['admin_id'])) {
        return null;
    }
    static $admin = null;
    if ($admin === null) {
        $stmt = db()->prepare("SELECT * FROM admin_users WHERE id = ? AND status = 'active'");
        $stmt->execute([$_SESSION['admin_id']]);
        $admin = $stmt->fetch() ?: null;
    }
    return $admin;
}

function requireAdminLogin(): void
{
    if (!currentAdmin()) {
        redirect(rtrim(SITE_URL, '/') . '/admin/login.php');
    }
}
