<?php
/**
 * Storefront order confirmation page - shown right after checkout, and
 * revisitable afterwards by whoever is allowed to see it. Rendered by
 * Controllers\Storefront\OrderController::confirmation() at
 * /order/{orderNumber}/confirmation. Just the body; Core\Renderer::render()
 * wraps it with the theme's header.php/footer.php.
 *
 * Access control happens entirely in the controller before this view is
 * ever reached (Order::isAccessibleBy() / OrderController::isAccessible()):
 * a visitor may view this page if they (a) are the order's own logged-in
 * customer, (b) are a logged-in admin, or (c) just placed this exact order
 * in this session (a guest checkout, right after payment, before any
 * account exists to "own" it) - session-tracked via 'last_order_id', not a
 * URL flag, so the grant can't be copy-pasted into another browser. If
 * none of those apply the controller renders order/not_found instead of
 * this file. This view has no further permission logic of its own.
 *
 * @var \ShopRex\Models\Order $order         The order to display - already
 *                                             access-checked (see above).
 * @var array                 $items         This order's line items, each
 *                                             with product_name/option_summary
 *                                             (e.g. "Size: L", blank if none)/
 *                                             quantity/total_price.
 * @var bool                  $invoiceExists True if a PDF invoice row exists
 *                                             for this order yet (it may not,
 *                                             e.g. immediately after a
 *                                             pending bank-transfer order) -
 *                                             controls whether the download
 *                                             link at the bottom is shown.
 * Direct port of order_confirmation.php's body.
 */
?>

<?php // A same site-wide reminder as the header's test-mode banner, but
      // specific to this order - shown when the order itself was placed by
      // a test account (see CLAUDE.md's "Test accounts" section). ?>
<?php if ($order->isTestOrder): ?>
  <div class="alert alert-warning"><i class="bi bi-flask me-1"></i><strong><?= e(__('nav.test_mode_title')) ?></strong> &mdash; <?= e(__('order.test_banner')) ?></div>
<?php endif; ?>

<div class="alert alert-success"><i class="bi bi-check-circle me-1"></i> <?= e(__('order.thank_you')) ?></div>

<p><?= e(__('order.number')) ?>: <strong><?= e($order->orderNumber) ?></strong></p>
<p><?= e(__('order.status')) ?>: <span class="badge text-bg-secondary"><?= e(ucwords(str_replace('_', ' ', $order->status))) ?></span>
   &middot; <?= e(__('order.payment')) ?>: <span class="badge text-bg-secondary"><?= e(ucwords(str_replace('_', ' ', $order->paymentStatus))) ?></span></p>

<?php // Bank-transfer orders aren't paid at checkout time - show the
      // account details to wire the money to (falling back to the
      // BANK_* config constants if the equivalent setting was never
      // customized in Admin -> Settings) only while payment is still
      // pending; once it clears this block just stops matching. ?>
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

<?php // Same idea for "pay by invoice" (net terms) orders - a reminder
      // note only while payment is still outstanding. ?>
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
<?php // Only offer the invoice download once a PDF actually exists for
      // this order (see $invoiceExists above) - OrderController::downloadInvoice()
      // re-checks ownership/admin access again independently when this
      // link is followed, so nothing here needs to be trusted for security. ?>
<?php if ($invoiceExists): ?>
  <p><a class="btn btn-outline-secondary btn-sm" href="<?= rtrim(SITE_URL, '/') ?>/order/<?= urlencode($order->orderNumber) ?>/invoice"><i class="bi bi-file-earmark-pdf me-1"></i><?= e(__('order.invoice_download')) ?></a></p>
<?php endif; ?>
<p><a href="<?= rtrim(SITE_URL, '/') ?>/"><?= e(__('common.continue_shopping')) ?></a></p>
