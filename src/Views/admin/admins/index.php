<?php
/**
 * Admin -> Admin Accounts: one combined list+edit-form page for managing
 * other admin logins. Only reachable by the 'super_admin' role (the
 * 'admins' capability in Core\Auth\AdminAuth::CAPABILITIES belongs to
 * super_admin only - see CLAUDE.md's "two roles" section) - a manager
 * never sees this nav link at all. Same create-vs-edit-share-one-form
 * pattern as Categories/Tax Rates.
 *
 * @var array      $me        The currently logged-in admin's own row - used to stop an admin from deleting their own account (see the delete-button check below) and to label their own row "(you)" in the table.
 * @var array      $errors    Validation error messages to show above the form.
 * @var array|null $editAdmin The admin account being edited, or null when the form is in "create new" mode.
 * @var array      $admins    Every admin account, for the table below.
 */
?>
<div class="page-header"><h1><?= e(__('admin.admin_accounts')) ?></h1></div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= $editAdmin && !empty($editAdmin['id']) ? e(__('admin.admins.edit_title')) : e(__('admin.admins.add_title')) ?></h2>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/admins">
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
          <?php /* \ShopRex\Core\Auth\AdminAuth::ROLES is the single source of truth for what roles exist ('super_admin', 'manager') - looping it here means adding a new role there automatically shows up in this dropdown too. Defaults to 'manager' (the less-privileged role) when creating a new admin and no role has been picked yet. */ ?>
          <?php foreach (\ShopRex\Core\Auth\AdminAuth::ROLES as $roleKey => $roleLabel): ?>
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
      <?php /* Password hint text ("leave blank to keep current password") only appears while editing an existing account - a brand new account has no existing password to keep. */ ?>
      <label for="password"><?= e(__('common.password')) ?> <?= !empty($editAdmin['id']) ? e(__('admin.admins.password_hint')) : '' ?></label>
      <?php /* Required only when creating (no $editAdmin id yet); optional when editing, so submitting the edit form without touching this field doesn't wipe out the existing password. */ ?>
      <input type="password" id="password" name="password" minlength="8" <?= empty($editAdmin['id']) ? 'required' : '' ?>>
    </div>
    <button class="btn" type="submit"><?= e(__('admin.admins.save')) ?></button>
    <?php if (!empty($editAdmin['id'])): ?><a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/admins"><?= e(__('common.cancel')) ?></a><?php endif; ?>
  </form>
</div>

<table class="data-table">
  <thead><tr><th><?= e(__('admin.username')) ?></th><th><?= e(__('common.email')) ?></th><th><?= e(__('admin.admins.role')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('admin.admins.last_login')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($admins as $a): ?>
    <tr>
      <?php /* Label the logged-in admin's own row so it's obvious at a glance which one is "me" in a list of several admins. */ ?>
      <td><?= e($a['username']) ?><?= (int)$a['id'] === (int)$me['id'] ? ' <small style="color:var(--color-muted);">(' . e(__('admin.admins.you')) . ')</small>' : '' ?></td>
      <td><?= e($a['email']) ?></td>
      <td><?= e(adminRoleLabel($a['role'])) ?></td>
      <td><span class="badge badge-<?= $a['status'] === 'active' ? 'completed' : 'cancelled' ?>"><?= e($a['status']) ?></span></td>
      <td><?= $a['last_login'] ? e(formatLocalDate($a['last_login'], true)) : e(__('admin.admins.never')) ?></td>
      <td>
        <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/admins?edit=<?= (int)$a['id'] ?>"><?= e(__('common.edit')) ?></a>
        <?php /* No delete button on your own row - stops an admin from accidentally locking themselves out by deleting the account they're currently signed in as. */ ?>
        <?php if ((int)$a['id'] !== (int)$me['id']): ?>
          <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/admins" style="display:inline;" data-confirm="<?= e(__('admin.admins.confirm_delete', ['username' => $a['username']])) ?>">
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
