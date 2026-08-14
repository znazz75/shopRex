<?php
/**
 * @var \ShopRex\Models\Order $order
 * @var array $items
 * @var bool $invoiceExists
 * Direct port of order_confirmation.php's body.
 */
?>

<?php if ($order->isTestOrder): ?>
  <div class="alert alert-warning"><i class="bi bi-flask me-1"></i><strong><?= e(__('nav.test_mode_title')) ?></strong> &mdash; <?= e(__('order.test_banner')) ?></div>
<?php endif; ?>

<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i> <?= e(__('order.thank_you')) ?></div>

<p><?= e(__('order.number')) ?>: <strong><?= e($order->orderNumber) ?></strong></p>
<p><?= e(__('order.status')) ?>: <span class="badge text-bg-secondary"><?= e(ucwords(str_replace('_', ' ', $order->status))) ?></span>
   &middot; <?= e(__('order.payment')) ?>: <span class="badge text-bg-secondary"><?= e(ucwords(str_replace('_', ' ', $order->paymentStatus))) ?></span></p>

<?php if ($order->paymentMethod === 'bank_transfer' && $order->paymentStatus === 'pending'): ?>
  <div class="alert alert-info">
    <strong><?= e(__('order.bank_transfer_instructions', ['amount' => formatPrice($order->total)])) ?></strong><br>
    <?= e(__('order.bank_account_holder')) ?>: <?= e(getSetting('bank_account_holder', BANK_ACCOUNT_HOLDER)) ?><br>
    <?= e(__('order.bank_iban')) ?>: <?= e(getSetting('bank_iban', BANK_IBAN)) ?><br>
    <?= e(__('order.bank_bic')) ?>: <?= e(getSetting('bank_bic', BANK_BIC)) ?><br>
    <?= e(__('order.bank_name')) ?>: <?= e(getSetting('bank_name', BANK_NAME)) ?><br>
    <?= e(__('order.bank_reference')) ?>: <?= e($order->orderNumber) ?><br><br>
    <?= e(__('order.will_ship_on_payment')) ?>
  </div>
<?php endif; ?>

<?php if ($order->paymentMethod === 'invoice' && $order->paymentStatus === 'pending'): ?>
  <div class="alert alert-info">
    <?= e(__('order.invoice_instructions')) ?>
  </div>
<?php endif; ?>

<div class="table-responsive mt-3">
  <table class="table">
    <thead><tr><th><?= e(__('order.item')) ?></th><th><?= e(__('common.quantity')) ?></th><th class="text-end"><?= e(__('common.total')) ?></th></tr></thead>
    <tbody>
    <?php foreach ($items as $item): ?>
      <tr>
        <td><?= e($item['product_name']) ?><?= $item['option_summary'] ? '<br><small class="text-secondary">' . e($item['option_summary']) . '</small>' : '' ?></td>
        <td><?= (int)$item['quantity'] ?></td>
        <td class="text-end"><?= formatPrice((float)$item['total_price']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="max-width:360px;margin-left:auto;">
  <div class="card-body">
    <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.subtotal')) ?></span><span><?= formatPrice($order->subtotal) ?></span></div>
    <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.shipping')) ?><?= $order->shippingMethodName ? ' (' . e($order->shippingMethodName) . ')' : '' ?></span><span><?= formatPrice($order->shippingCost) ?></span></div>
    <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.tax')) ?></span><span><?= formatPrice($order->taxTotal) ?></span></div>
    <hr>
    <div class="d-flex justify-content-between fw-bold fs-5"><span><?= e(__('common.total')) ?></span><span><?= formatPrice($order->total) ?></span></div>
  </div>
</div>

<p class="mt-4"><?= e(__('order.confirmation_email_sent', ['email' => $order->customerEmail])) ?></p>
<?php if ($invoiceExists): ?>
  <p><a class="btn btn-outline-secondary btn-sm" href="<?= rtrim(SITE_URL, '/') ?>/order/<?= urlencode($order->orderNumber) ?>/invoice"><i class="bi bi-file-earmark-pdf me-1"></i><?= e(__('order.invoice_download')) ?></a></p>
<?php endif; ?>
<p><a href="<?= rtrim(SITE_URL, '/') ?>/"><?= e(__('common.continue_shopping')) ?></a></p>
