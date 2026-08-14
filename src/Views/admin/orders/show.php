<?php
/**
 * @var array $order
 * @var array $statuses
 * @var array $paymentStatuses
 * @var array $items
 * @var array $payments
 * @var array|null $invoice
 */
?>
<div class="page-header">
  <h1><?= e(__('admin.order_view.heading', ['number' => $order['order_number']])) ?> <?php if ($order['is_test_order']): ?><span class="badge badge-pending"><?= e(__('admin.dashboard.test_badge')) ?></span><?php endif; ?></h1>
  <?php if ($invoice): ?>
    <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/order/<?= urlencode($order['order_number']) ?>/invoice" target="_blank"><?= e(__('admin.order_view.download_invoice', ['number' => $invoice['invoice_number']])) ?></a>
  <?php endif; ?>
</div>

<?php if ($order['is_test_order']): ?>
  <div class="flash flash-info"><i class="bi-flask" style="font-style:normal;">&#9888;</i> <?= e(__('admin.order_view.test_order_notice')) ?></div>
<?php endif; ?>

<div class="card">
  <p><strong><?= e(__('admin.dashboard.customer')) ?>:</strong> <?= e($order['customer_email']) ?></p>
  <p><strong><?= e(__('admin.order_view.placed')) ?>:</strong> <?= e(formatLocalDate($order['created_at'], true)) ?></p>
  <p><strong><?= e(__('admin.orders.payment_method')) ?>:</strong> <?= e(ucwords(str_replace('_', ' ', $order['payment_method']))) ?></p>
  <p><strong><?= e(__('checkout.shipping_address')) ?>:</strong><br>
    <?= e($order['shipping_name']) ?><br>
    <?= e($order['shipping_address1']) ?><?php if ($order['shipping_address2']): ?><br><?= e($order['shipping_address2']) ?><?php endif; ?><br>
    <?= e($order['shipping_postal_code']) ?> <?= e($order['shipping_city']) ?><br>
    <?= e($order['shipping_country']) ?>
  </p>
  <?php if ($order['customer_notes']): ?><p><strong><?= e(__('admin.order_view.customer_notes')) ?>:</strong> <?= nl2br(e($order['customer_notes'])) ?></p><?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.order_view.items')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.products')) ?></th><th><?= e(__('admin.order_view.qty')) ?></th><th><?= e(__('admin.order_view.unit_price')) ?></th><th><?= e(__('common.total')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?= e($item['product_name']) ?><?= $item['option_summary'] ? '<br><small>' . e($item['option_summary']) . '</small>' : '' ?></td>
        <td><?= (int)$item['quantity'] ?></td>
        <td><?= formatPrice((float)$item['unit_price']) ?></td>
        <td><?= formatPrice((float)$item['total_price']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div style="max-width:280px;margin-left:auto;margin-top:14px;">
    <div style="display:flex;justify-content:space-between;"><span><?= e(__('common.subtotal')) ?></span><span><?= formatPrice((float)$order['subtotal']) ?></span></div>
    <div style="display:flex;justify-content:space-between;"><span><?= e(__('common.shipping')) ?><?= !empty($order['shipping_method_name']) ? ' (' . e($order['shipping_method_name']) . ')' : '' ?></span><span><?= formatPrice((float)$order['shipping_cost']) ?></span></div>
    <div style="display:flex;justify-content:space-between;"><span><?= e(__('common.tax')) ?></span><span><?= formatPrice((float)$order['tax_total']) ?></span></div>
    <div style="display:flex;justify-content:space-between;font-weight:700;border-top:1px solid var(--color-border);padding-top:6px;margin-top:6px;"><span><?= e(__('common.total')) ?></span><span><?= formatPrice((float)$order['total']) ?></span></div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.order_view.payments')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.order_view.method')) ?></th><th><?= e(__('admin.order_view.transaction_id')) ?></th><th><?= e(__('admin.order_view.amount')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('common.date')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($payments as $p): ?>
      <tr>
        <td><?= e(ucwords(str_replace('_', ' ', $p['payment_method']))) ?></td>
        <td><?= e($p['transaction_id'] ?? '-') ?></td>
        <td><?= formatPrice((float)$p['amount']) ?></td>
        <td><span class="badge badge-<?= e($p['status'] === 'completed' ? 'paid' : $p['status']) ?>"><?= e($p['status']) ?></span></td>
        <td><?= e(formatLocalDate($p['created_at'], true)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.order_view.update_order')) ?></h2>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>">
    <?= csrfField() ?>
    <div class="form-grid">
      <div class="form-group">
        <label for="status"><?= e(__('admin.order_view.order_status')) ?></label>
        <select id="status" name="status">
          <?php foreach ($statuses as $s): ?>
            <option value="<?= $s ?>" <?= $order['status'] === $s ? 'selected' : '' ?>><?= e(__('admin.orders.status_' . $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="payment_status"><?= e(__('admin.order_view.payment_status')) ?></label>
        <select id="payment_status" name="payment_status">
          <?php foreach ($paymentStatuses as $s): ?>
            <option value="<?= $s ?>" <?= $order['payment_status'] === $s ? 'selected' : '' ?>><?= e(__('admin.order_view.pay_status_' . $s)) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="form-group">
      <label for="admin_notes"><?= e(__('admin.order_view.admin_notes')) ?></label>
      <textarea id="admin_notes" name="admin_notes" rows="3"><?= e($order['admin_notes'] ?? '') ?></textarea>
    </div>
    <div class="form-group">
      <label><input type="checkbox" name="notify_customer" value="1" style="width:auto;"> <?= e(__('admin.order_view.notify_customer')) ?></label>
    </div>
    <button class="btn" type="submit"><?= e(__('admin.order_view.save_changes')) ?></button>
  </form>
</div>
