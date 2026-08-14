<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('admins');

$me = currentAdmin();
$errors = [];

function countActiveSuperAdmins(?int $excludingId = null): int
{
    $sql = "SELECT COUNT(*) FROM admin_users WHERE role = 'super_admin' AND status = 'active'";
    $params = [];
    if ($excludingId) {
        $sql .= ' AND id != ?';
        $params[] = $excludingId;
    }
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

// ---------------------------------------------------------------
// Delete
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    requireCsrf();
    $deleteId = (int)$_POST['delete_id'];

    if ($deleteId === (int)$me['id']) {
        setFlash('error', __('admin.admins.cannot_delete_self'));
    } else {
        $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
        $stmt->execute([$deleteId]);
        $target = $stmt->fetch();

        if ($target && $target['role'] === 'super_admin' && countActiveSuperAdmins($deleteId) < 1) {
            setFlash('error', __('admin.admins.cannot_delete_last_super_admin'));
        } elseif ($target) {
            db()->prepare('DELETE FROM admin_users WHERE id = ?')->execute([$deleteId]);
            setFlash('success', __('admin.admins.flash_deleted'));
        }
    }
    redirect(rtrim(SITE_URL, '/') . '/admin/admins.php');
}

// ---------------------------------------------------------------
// Create / update
// ---------------------------------------------------------------
$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editAdmin = null;
if ($editId) {
    $stmt = db()->prepare('SELECT * FROM admin_users WHERE id = ?');
    $stmt->execute([$editId]);
    $editAdmin = $stmt->fetch();
    if (!$editAdmin) {
        setFlash('error', __('admin.admins.not_found'));
        redirect(rtrim(SITE_URL, '/') . '/admin/admins.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['delete_id'])) {
    requireCsrf();

    $id = isset($_POST['id']) && $_POST['id'] !== '' ? (int)$_POST['id'] : null;
    $username = trim($_POST['username'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $role = in_array($_POST['role'] ?? '', array_keys(ADMIN_ROLES), true) ? $_POST['role'] : 'manager';
    $status = in_array($_POST['status'] ?? '', ['active', 'disabled'], true) ? $_POST['status'] : 'active';
    $password = $_POST['password'] ?? '';

    if ($username === '') $errors[] = __('admin.admins.username_required');
    if (!$email) $errors[] = __('admin.admins.valid_email_required');
    if (!$id && strlen($password) < 8) $errors[] = __('validation.password_min_length');
    if ($password !== '' && strlen($password) < 8) $errors[] = __('validation.password_min_length');

    // Prevent demoting/disabling the last active super admin.
    if ($id && ($role !== 'super_admin' || $status !== 'active') && countActiveSuperAdmins($id) < 1) {
        $stmt = db()->prepare('SELECT role, status FROM admin_users WHERE id = ?');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if ($existing && $existing['role'] === 'super_admin' && $existing['status'] === 'active') {
            $errors[] = __('admin.admins.cannot_demote_last_super_admin');
        }
    }

    if (!$errors) {
        $pdo = db();
        try {
            if ($id) {
                if ($password !== '') {
                    $stmt = $pdo->prepare('UPDATE admin_users SET username=?, email=?, role=?, status=?, password_hash=? WHERE id=?');
                    $stmt->execute([$username, $email, $role, $status, password_hash($password, PASSWORD_DEFAULT), $id]);
                } else {
                    $stmt = $pdo->prepare('UPDATE admin_users SET username=?, email=?, role=?, status=? WHERE id=?');
                    $stmt->execute([$username, $email, $role, $status, $id]);
                }
                setFlash('success', __('admin.admins.flash_updated'));
            } else {
                $stmt = $pdo->prepare('INSERT INTO admin_users (username, email, password_hash, role, status) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $role, $status]);
                setFlash('success', __('admin.admins.flash_created'));
            }
            redirect(rtrim(SITE_URL, '/') . '/admin/admins.php');
        } catch (PDOException $e) {
            $errors[] = str_contains($e->getMessage(), 'Duplicate')
                ? __('admin.admins.duplicate_username_email')
                : __('admin.admins.save_error', ['message' => $e->getMessage()]);
        }
    }

    // Keep submitted values on screen if validation failed.
    $editAdmin = ['id' => $id, 'username' => $username, 'email' => $email, 'role' => $role, 'status' => $status];
}

$admins = db()->query('SELECT * FROM admin_users ORDER BY created_at')->fetchAll();

$pageTitle = __('admin.admin_accounts');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header"><h1><?= e(__('admin.admin_accounts')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= $editAdmin && !empty($editAdmin['id']) ? e(__('admin.admins.edit_title')) : e(__('admin.admins.add_title')) ?></h2>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="id" value="<?= e($editAdmin['id'] ?? '') ?>">
    <div class="form-grid">
      <div class="form-group"><label for="username"><?= e(__('admin.username')) ?></label><input type="text" id="username" name="username" required value="<?= e($editAdmin['username'] ?? '') ?>"></div>
      <div class="form-group"><label for="email"><?= e(__('common.email')) ?></label><input type="email" id="email" name="email" required value="<?= e($editAdmin['email'] ?? '') ?>"></div>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label for="role"><?= e(__('admin.admins.role')) ?></label>
        <select id="role" name="role">
          <?php foreach (ADMIN_ROLES as $roleKey => $roleLabel): ?>
            <option value="<?= e($roleKey) ?>" <?= (($editAdmin['role'] ?? 'manager') === $roleKey) ? 'selected' : '' ?>><?= e(adminRoleLabel($roleKey)) ?></option>
          <?php endforeach; ?>
        </select>
        <small style="color:var(--color-muted);"><?= e(__('admin.admins.role_hint')) ?></small>
      </div>
      <div class="form-group">
        <label for="status"><?= e(__('common.status')) ?></label>
        <select id="status" name="status">
          <option value="active" <?= (($editAdmin['status'] ?? 'active') === 'active') ? 'selected' : '' ?>><?= e(__('common.active')) ?></option>
          <option value="disabled" <?= (($editAdmin['status'] ?? '') === 'disabled') ? 'selected' : '' ?>><?= e(__('admin.admins.disabled')) ?></option>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label for="password"><?= e(__('common.password')) ?> <?= !empty($editAdmin['id']) ? e(__('admin.admins.password_hint')) : '' ?></label>
      <input type="password" id="password" name="password" minlength="8" <?= empty($editAdmin['id']) ? 'required' : '' ?>>
    </div>
    <button class="btn" type="submit"><?= e(__('admin.admins.save')) ?></button>
    <?php if (!empty($editAdmin['id'])): ?><a class="btn btn-secondary" href="admins.php"><?= e(__('common.cancel')) ?></a><?php endif; ?>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.username')) ?></th><th><?= e(__('common.email')) ?></th><th><?= e(__('admin.admins.role')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('admin.admins.last_login')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($admins as $a): ?>
    <tr>
      <td><?= e($a['username']) ?><?= (int)$a['id'] === (int)$me['id'] ? ' <small style="color:var(--color-muted);">(' . e(__('admin.admins.you')) . ')</small>' : '' ?></td>
      <td><?= e($a['email']) ?></td>
      <td><?= e(adminRoleLabel($a['role'])) ?></td>
      <td><span class="badge badge-<?= $a['status'] === 'active' ? 'completed' : 'cancelled' ?>"><?= e($a['status']) ?></span></td>
      <td><?= $a['last_login'] ? e(formatLocalDate($a['last_login'], true)) : e(__('admin.admins.never')) ?></td>
      <td>
        <a class="btn btn-sm btn-secondary" href="admins.php?edit=<?= (int)$a['id'] ?>"><?= e(__('common.edit')) ?></a>
        <?php if ((int)$a['id'] !== (int)$me['id']): ?>
          <form method="post" style="display:inline;" data-confirm="<?= e(__('admin.admins.confirm_delete', ['username' => $a['username']])) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="delete_id" value="<?= (int)$a['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
          </form>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<?php require __DIR__ . '/includes/footer.php'; ?>
