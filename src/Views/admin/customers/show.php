<?php
/**
 * @var array $customer
 * @var array $orders
 */
$id = (int)$customer['id'];
$base = rtrim(SITE_URL, '/') . '/admin/customers/' . $id;
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
  <form method="post" action="<?= e($base) ?>/status" style="display:flex;gap:10px;align-items:center;margin-top:10px;">
    <?= csrfField() ?>
    <label for="status"><?= e(__('admin.customer_view.account_status')) ?></label>
    <select id="status" name="status">
      <option value="active" <?= $customer['status'] === 'active' ? 'selected' : '' ?>><?= e(__('common.active')) ?></option>
      <option value="blocked" <?= $customer['status'] === 'blocked' ? 'selected' : '' ?>><?= e(__('admin.customer_view.blocked')) ?></option>
    </select>
    <button class="btn btn-sm" type="submit"><?= e(__('common.save')) ?></button>
  </form>
  <?php if ($customer['is_test_account']): ?>
    <form method="post" action="<?= e($base) ?>/delete-test-account" data-confirm="<?= e(__('admin.customer_view.confirm_delete_test_user')) ?>" style="margin-top:10px;">
      <?= csrfField() ?>
      <button class="btn btn-sm btn-danger" type="submit"><?= e(__('admin.customer_view.delete_test_user')) ?></button>
    </form>
  <?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.customer_view.payment_heading')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= e(__('admin.customer_view.payment_hint')) ?>
  </p>
  <form method="post" action="<?= e($base) ?>/payment">
    <?= csrfField() ?>
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
  <a class="btn btn-sm btn-secondary" href="<?= e($base) ?>/export"><i class="bi bi-download"></i> <?= e(__('admin.customer_view.export_data')) ?></a>
  <form method="post" action="<?= e($base) ?>/gdpr-delete" style="display:inline;" data-confirm="<?= e(__('admin.customer_view.confirm_gdpr_delete')) ?>">
    <?= csrfField() ?>
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
          <a href="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>"><?= e($order['order_number']) ?></a>
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
