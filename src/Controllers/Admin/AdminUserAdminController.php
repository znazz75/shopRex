<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/** Direct port of admin/admins.php. */
final class AdminUserAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function index(Request $request): Response
    {
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

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $me = $this->admin;

        if ($request->post('delete_id') !== null) {
            $deleteId = (int)$request->post('delete_id');

            if ($deleteId === (int)$me['id']) {
                $this->flash('error', __('admin.admins.cannot_delete_self'));
            } else {
                $stmt = $this->pdo->prepare('SELECT * FROM admin_users WHERE id = ?');
                $stmt->execute([$deleteId]);
                $target = $stmt->fetch();

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
        $id = $request->post('id') !== '' && $request->post('id') !== null ? (int)$request->post('id') : null;
        $username = trim((string)$request->post('username', ''));
        $email = filter_var($request->post('email', ''), FILTER_VALIDATE_EMAIL);
        $role = in_array($request->post('role', ''), array_keys(AdminAuth::ROLES), true) ? $request->post('role') : 'manager';
        $status = in_array($request->post('status', ''), ['active', 'disabled'], true) ? $request->post('status') : 'active';
        $password = (string)$request->post('password', '');

        if ($username === '') {
            $errors[] = __('admin.admins.username_required');
        }
        if (!$email) {
            $errors[] = __('admin.admins.valid_email_required');
        }
        if (!$id && strlen($password) < 8) {
            $errors[] = __('validation.password_min_length');
        }
        if ($password !== '' && strlen($password) < 8) {
            $errors[] = __('validation.password_min_length');
        }

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
                    if ($password !== '') {
                        $stmt = $this->pdo->prepare('UPDATE admin_users SET username=?, email=?, role=?, status=?, password_hash=? WHERE id=?');
                        $stmt->execute([$username, $email, $role, $status, password_hash($password, PASSWORD_DEFAULT), $id]);
                    } else {
                        $stmt = $this->pdo->prepare('UPDATE admin_users SET username=?, email=?, role=?, status=? WHERE id=?');
                        $stmt->execute([$username, $email, $role, $status, $id]);
                    }
                    $this->flash('success', __('admin.admins.flash_updated'));
                } else {
                    $stmt = $this->pdo->prepare('INSERT INTO admin_users (username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $status]);
                    $this->flash('success', __('admin.admins.flash_created'));
                }
                return $this->redirect('/admin/admins');
            } catch (\PDOException $e) {
                $errors[] = str_contains($e->getMessage(), 'Duplicate')
                    ? __('admin.admins.duplicate_username_email')
                    : __('admin.admins.save_error', ['message' => $e->getMessage()]);
            }
        }

        $editAdmin = ['id' => $id, 'username' => $username, 'email' => $email, 'role' => $role, 'status' => $status];
        $admins = $this->pdo->query('SELECT * FROM admin_users ORDER BY created_at')->fetchAll();
        return $this->render('admins/index', [...compact('me', 'errors', 'editAdmin', 'admins'), 'pageTitle' => __('admin.admin_accounts')]);
    }

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
