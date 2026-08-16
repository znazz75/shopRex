<?php
/**
 * Admin -> Audit Log: read-only, newest-first list of every mutating admin
 * action (see Services\AuditLogService/sql/schema.sql's admin_action_log
 * table docblock for what gets recorded and why - method + path +
 * capability + status code, deliberately no POST-body snapshot). Reachable
 * only by Super Admin (the 'audit_log' capability - see Core\Auth\AdminAuth).
 *
 * @var array  $entries          This page's rows (admin_id, username, role, method, path, capability, status_code, created_at), newest first.
 * @var array  $adminUsernames   Every distinct username that has ever appeared in the log, for the filter dropdown - includes usernames of since-deleted admin accounts.
 * @var string $adminFilter      The currently active ?admin= filter value, or '' for "all admins".
 * @var int    $page             Current page number.
 * @var int    $totalPages       Total number of pages.
 * @var array  $paginationParams Extra query params (the admin filter, when set) to preserve across pagination links - see renderPagination().
 */
?>
<div class="page-header"><h1><?= e(__('admin.audit_log')) ?></h1></div>

<div class="toolbar">
  <form method="get" action="<?= rtrim(SITE_URL, '/') ?>/admin/audit-log" style="display:flex;gap:10px;align-items:flex-end;">
    <div class="form-group" style="margin-bottom:0;">
      <label for="admin_filter"><?= e(__('admin.audit_log.filter_by_admin')) ?></label>
      <select id="admin_filter" name="admin" onchange="this.form.submit()">
        <option value=""><?= e(__('admin.audit_log.all_admins')) ?></option>
        <?php foreach ($adminUsernames as $username): ?>
          <option value="<?= e($username) ?>" <?= $adminFilter === $username ? 'selected' : '' ?>><?= e($username) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>
</div>

<table class="data-table">
  <thead>
    <tr>
      <th><?= e(__('common.date')) ?></th>
      <th><?= e(__('admin.audit_log.admin')) ?></th>
      <th><?= e(__('admin.audit_log.role')) ?></th>
      <th><?= e(__('admin.audit_log.action')) ?></th>
      <th><?= e(__('admin.audit_log.status')) ?></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($entries as $entry): ?>
    <tr>
      <td><?= e(formatLocalDate($entry['created_at'], true)) ?></td>
      <td><?= e($entry['username']) ?></td>
      <td><?= e($entry['role']) ?></td>
      <?php /* "POST /admin/orders/123/cancel" - this app's clean-URL routing already says exactly what happened, no separate free-text summary needed. */ ?>
      <td><code><?= e($entry['method']) ?> <?= e($entry['path']) ?></code></td>
      <?php /* Color-codes the status the same way the Finance ledger color-codes amounts - a quick visual cue for "this action was denied/failed" (4xx/5xx) vs succeeded. */ ?>
      <td style="color: <?= (int)$entry['status_code'] >= 400 ? 'var(--color-error)' : 'var(--color-success)' ?>;"><?= (int)$entry['status_code'] ?></td>
    </tr>
  <?php endforeach; ?>
  <?php if (empty($entries)): ?><tr><td colspan="5"><?= e(__('admin.audit_log.none')) ?></td></tr><?php endif; ?>
  </tbody>
</table>

<?php renderPagination($page, $totalPages, $paginationParams); ?>
