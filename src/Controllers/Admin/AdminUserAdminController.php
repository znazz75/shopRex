<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Manages the admin_users table itself - creating/editing/deleting the
 * back-office accounts that log into /admin (as opposed to shop customers).
 * Direct port of admin/admins.php. Gated behind the 'admins' capability
 * (Super Admin only, see AdminAuth::CAPABILITIES) since letting anyone
 * else manage admin accounts would be a privilege-escalation hole. Its
 * central concern - never letting the last active Super Admin account be
 * deleted or demoted, which would permanently lock everyone out of the
 * 'admins'/'settings'/etc. capabilities - is why countActiveSuperAdmins()
 * exists and is checked before every delete/save.
 */
final class AdminUserAdminController extends AdminCrudController
{
    // Shared PDO connection - admin_users rows are read/written directly here
    // with hand-written SQL rather than through a Model class.
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** Lists every admin account, and - if ?edit={id} is present - loads that one account's details into the edit form. */
    public function index(Request $request): Response
    {
        // The currently logged-in admin (used by the view to, e.g., disable the
        // "delete" button on your own row).
        $me = $this->admin;
        $errors = [];

        $editId = $request->get('edit') !== null ? (int)$request->get('edit') : null;
        $editAdmin = null;
        if ($editId) {
            $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
            $stmt->execute([$editId]);
            $editAdmin = $stmt->fetch();
            if (!$editAdmin) {
                $this->flash('error', __('admin.admins.not_found'));
                return $this->redirect('/admin/admins');
            }
        }

        $admins = $this->pdo->query('SELECT * FROM admin_users ORDER BY created_at')->fetchAll();
        return $this->render('admins/index', [...compact('me', 'errors', 'editAdmin', 'admins'), 'pageTitle' => __('admin.admin_accounts')]);
    }

    /** Handles both "delete an admin" and "create/update an admin" submissions from the same form, enforcing that at least one active Super Admin always remains. */
    public function save(Request $request): Response
    {
        // Blocks a forged admin-account-management submission (CSRF) - this is
        // one of the most sensitive forms in the app, since it can create new
        // privileged accounts - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $me = $this->admin;

        // This one save() action doubles as the delete handler too (the delete
        // button posts delete_id instead of the full edit form) - checked first
        // so the rest of the save logic below doesn't run for a delete request.
        if ($request->post('delete_id') !== null) {
            $deleteId = (int)$request->post('delete_id');

            // Safety check: an admin can't delete their own account (would
            // instantly lock them out of the session they're using right now).
            if ($deleteId === (int)$me['id']) {
                $this->flash('error', __('admin.admins.cannot_delete_self'));
            } else {
                $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
                $stmt->execute([$deleteId]);
                $target = $stmt->fetch();

                // Safety check: if the target is a super_admin, refuse the delete
                // unless at least one *other* active super_admin would still
                // exist afterwards - countActiveSuperAdmins($deleteId) excludes
                // the account being deleted from its own count, so this is
                // "how many super admins are left besides this one".
                if ($target && $target['role'] === 'super_admin' && $this->countActiveSuperAdmins($deleteId) < 1) {
                    $this->flash('error', __('admin.admins.cannot_delete_last_super_admin'));
                } elseif ($target) {
                    $this->pdo->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$deleteId]);
                    $this->flash('success', __('admin.admins.flash_deleted'));
                }
            }
            return $this->redirect('/admin/admins');
        }

        $errors = [];
        // Empty string vs null distinguishes "no id field submitted" (create)
        // from "id present but blank" - both mean "creating a new admin", but
        // written this way to be explicit about the two cases the form can send.
        $id = $request->post('id') !== '' && $request->post('id') !== null ? (int)$request->post('id') : null;
        $username = trim((string)$request->post('username', ''));
        // filter_var(..., FILTER_VALIDATE_EMAIL) returns the email string if
        // valid, or false if not - used directly as a truthy/falsy check below.
        $email = filter_var($request->post('email', ''), FILTER_VALIDATE_EMAIL);
        // Whitelist checks: fall back to a safe default ('manager' / 'active')
        // if the submitted role/status isn't one of the known valid values -
        // stops a tampered form from creating an account with a bogus role.
        $role = in_array($request->post('role', ''), array_keys(AdminAuth::ROLES), true) ? $request->post('role') : 'manager';
        $status = in_array($request->post('status', ''), ['active', 'disabled'], true) ? $request->post('status') : 'active';
        $password = (string)$request->post('password', '');

        if ($username === '') {
            $errors[] = __('admin.admins.username_required');
        }
        if (!$email) {
            $errors[] = __('admin.admins.valid_email_required');
        }
        // Password is required (and must meet the length minimum) when creating
        // a brand new account (!$id); when editing an existing account, an
        // empty password field means "leave the current password unchanged" -
        // it's only validated for length if the admin actually typed one.
        if (!$id && strlen($password) < 8) {
            $errors[] = __('validation.password_min_length');
        }
        if ($password !== '' && strlen($password) < 8) {
            $errors[] = __('validation.password_min_length');
        }

        // Safety check for edits: if this save would change an existing
        // account away from "super_admin + active" (demote the role, or
        // disable it), and no *other* active super admin would remain, block
        // it - same "never lose the last super admin" invariant as the delete
        // path above, just triggered by a role/status edit instead of a
        // deletion. The inner query re-confirms the account being edited was
        // actually a super_admin before erroring, since the outer condition
        // alone doesn't check that.
        if ($id && ($role !== 'super_admin' || $status !== 'active') && $this->countActiveSuperAdmins($id) < 1) {
            $stmt = $this->pdo->prepare('SELECT role, status FROM admin_users WHERE id = ?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if ($existing && $existing['role'] === 'super_admin' && $existing['status'] === 'active') {
                $errors[] = __('admin.admins.cannot_demote_last_super_admin');
            }
        }

        if (!$errors) {
            try {
                if ($id) {
                    // Only touch password_hash if a new password was actually
                    // typed - otherwise UPDATE every other column but leave the
                    // existing hash alone.
                    if ($password !== '') {
                        $stmt = $this->pdo->prepare('UPDATE admin_users SET username=?, email=?, role=?, status=?, password_hash=? WHERE id=?');
                        $stmt->execute([$username, $email, $role, $status, password_hash($password, PASSWORD_DEFAULT), $id]);
                    } else {
                        $stmt = $this->pdo->prepare('UPDATE admin_users SET username=?, email=?, role=?, status=? WHERE id=?');
                        $stmt->execute([$username, $email, $role, $status, $id]);
                    }
                    $this->flash('success', __('admin.admins.flash_updated'));
                } else {
                    // password_hash() with PASSWORD_DEFAULT - bcrypt (or whatever
                    // PHP currently defaults to), never stores the plain password.
                    $stmt = $this->pdo->prepare('INSERT INTO admin_users (username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $status]);
                    $this->flash('success', __('admin.admins.flash_created'));
                }
                return $this->redirect('/admin/admins');
            } catch (\PDOException $e) {
                // The username/email columns are presumably UNIQUE in the schema -
                // a duplicate-key error is translated into a friendly message
                // instead of leaking the raw database error to the admin.
                $errors[] = str_contains($e->getMessage(), 'Duplicate')
                    ? __('admin.admins.duplicate_username_email')
                    : __('admin.admins.save_error', ['message' => $e->getMessage()]);
            }
        }

        // Validation (or a DB error) failed - re-render the form with what was
        // submitted so the admin doesn't have to retype everything.
        $editAdmin = ['id' => $id, 'username' => $username, 'email' => $email, 'role' => $role, 'status' => $status];
        $admins = $this->pdo->query('SELECT * FROM admin_users ORDER BY created_at')->fetchAll();
        return $this->render('admins/index', [...compact('me', 'errors', 'editAdmin', 'admins'), 'pageTitle' => __('admin.admin_accounts')]);
    }

    /** Counts how many admin accounts are currently super_admin AND active, optionally excluding one account id from the count - the core check behind "never allow the last super admin to be removed/demoted". */
    private function countActiveSuperAdmins(?int $excludingId = null): int
    {
        $sql = "SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin' AND status = 'active'";
        $params = [];
        if ($excludingId) {
            $sql .= ' AND id != ?';
            $params[] = $excludingId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
