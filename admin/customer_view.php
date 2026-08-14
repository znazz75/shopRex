<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('customers');

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_test_account'])) {
    requireCsrf();
    db()->prepare('DELETE FROM customers WHERE id = ? AND is_test_account = 1')->execute([$id]);
    setFlash('success', __('admin.customer_view.test_user_deleted'));
    redirect(rtrim(SITE_URL, '/') . '/admin/customers.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['gdpr_delete'])) {
    requireCsrf();
    GdprTools::deleteCustomer($id);
    setFlash('success', __('admin.customer_view.gdpr_deleted'));
    redirect(rtrim(SITE_URL, '/') . '/admin/customers.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_payment'])) {
    requireCsrf();
    $canPayOnInvoice = !empty($_POST['can_pay_on_invoice']) ? 1 : 0;
    db()->prepare('UPDATE customers SET can_pay_on_invoice = ? WHERE id = ?')->execute([$canPayOnInvoice, $id]);
    setFlash('success', __('admin.customer_view.flash_updated'));
    redirect(rtrim(SITE_URL, '/') . '/admin/customer_view.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $status = in_array($_POST['status'] ?? '', ['active', 'blocked'], true) ? $_POST['status'] : 'active';
    db()->prepare('UPDATE customers SET status = ? WHERE id = ?')->execute([$status, $id]);
    setFlash('success', __('admin.customer_view.flash_updated'));
    redirect(rtrim(SITE_URL, '/') . '/admin/customer_view.php?id=' . $id);
}

$stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
$stmt->execute([$id]);
$customer = $stmt->fetch();

if (!$customer) {
    setFlash('error', __('admin.customer_view.not_found'));
    redirect(rtrim(SITE_URL, '/') . '/admin/customers.php');
}

$orderStmt = db()->prepare('SELECT * FROM orders WHERE customer_id = ? ORDER BY created_at DESC');
$orderStmt->execute([$id]);
$orders = $orderStmt->fetchAll();

$pageTitle = $customer['first_name'] . ' ' . $customer['last_name'];
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><?= e($customer['first_name'] . ' ' . $customer['last_name']) ?> <?php if ($customer['is_test_account']): ?><span class="badge badge-processing"><?= e(__('admin.customers.test_user_badge')) ?></span><?php endif; ?></h1>
</div>

<?php if ($customer['is_test_account']): ?>
  <div class="flash flash-info">
    <?= e(__('admin.customer_view.test_account_notice')) ?>
  </div>
<?php endif; ?>

<div class="card">
  <p><strong><?= e(__('common.email')) ?>:</strong> <?= e($customer['email']) ?></p>
  <p><strong><?= e(__('admin.customers.phone')) ?>:</strong> <?= e($customer['phone'] ?? '-') ?></p>
  <p><strong><?= e(__('admin.customers.joined')) ?>:</strong> <?= e(formatLocalDate($customer['created_at'])) ?></p>
  <form method="post" style="display:flex;gap:10px;align-items:center;margin-top:10px;">
    <?= csrfField() ?>
    <label for="status"><?= e(__('admin.customer_view.account_status')) ?></label>
    <select id="status" name="status">
      <option value="active" <?= $customer['status'] === 'active' ? 'selected' : '' ?>><?= e(__('common.active')) ?></option>
      <option value="blocked" <?= $customer['status'] === 'blocked' ? 'selected' : '' ?>><?= e(__('admin.customer_view.blocked')) ?></option>
    </select>
    <button class="btn btn-sm" type="submit"><?= e(__('common.save')) ?></button>
  </form>
  <?php if ($customer['is_test_account']): ?>
    <form method="post" data-confirm="<?= e(__('admin.customer_view.confirm_delete_test_user')) ?>" style="margin-top:10px;">
      <?= csrfField() ?>
      <input type="hidden" name="delete_test_account" value="1">
      <button class="btn btn-sm btn-danger" type="submit"><?= e(__('admin.customer_view.delete_test_user')) ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.customer_view.payment_heading')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= e(__('admin.customer_view.payment_hint')) ?>
  </p>
  <form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="save_payment" value="1">
    <div class="form-group mb-0">
      <label><input type="checkbox" name="can_pay_on_invoice" value="1" style="width:auto;" <?= !empty($customer['can_pay_on_invoice']) ? 'checked' : '' ?>> <?= e(__('admin.customer_view.can_pay_on_invoice')) ?></label>
    </div>
    <button class="btn btn-sm" type="submit" style="margin-top:10px;"><?= e(__('common.save')) ?></button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.customer_view.data_protection')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= e(__('admin.customer_view.data_protection_hint')) ?>
  </p>
  <a class="btn btn-sm btn-secondary" href="customer_export.php?id=<?= (int)$id ?>"><i class="bi bi-download"></i> <?= e(__('admin.customer_view.export_data')) ?></a>
  <form method="post" style="display:inline;" data-confirm="<?= e(__('admin.customer_view.confirm_gdpr_delete')) ?>">
    <?= csrfField() ?>
    <input type="hidden" name="gdpr_delete" value="1">
    <button class="btn btn-sm btn-danger" type="submit"><i class="bi bi-trash"></i> <?= e(__('admin.customer_view.delete_account_gdpr')) ?></button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.orders')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.dashboard.order_number')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('order.payment')) ?></th><th><?= e(__('common.total')) ?></th><th><?= e(__('common.date')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($orders as $order): ?>
      <tr>
        <td>
          <a href="order_view.php?id=<?= (int)$order['id'] ?>"><?= e($order['order_number']) ?></a>
          <?php if ($order['is_test_order']): ?> <span class="badge badge-pending"><?= e(__('admin.dashboard.test_badge')) ?></span><?php endif; ?>
        </td>
        <td><span class="badge badge-<?= e($order['status']) ?>"><?= e($order['status']) ?></span></td>
        <td><span class="badge badge-<?= e($order['payment_status']) ?>"><?= e($order['payment_status']) ?></span></td>
        <td><?= formatPrice((float)$order['total']) ?></td>
        <td><?= e(formatLocalDate($order['created_at'], true)) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (empty($orders)): ?><tr><td colspan="5"><?= e(__('admin.dashboard.no_orders_yet')) ?></td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
