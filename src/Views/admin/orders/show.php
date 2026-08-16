<?php
/**
 * Admin -> Orders -> single order detail/edit page: shows the order's
 * customer/shipping info, line items, payment history, and a small form
 * to update its status/payment-status/admin notes.
 *
 * @var array      $order           The order row itself - customer_email, shipping_*, subtotal/shipping_cost/tax_total/total, status, payment_status, admin_notes, is_test_order, etc.
 * @var array      $statuses        Every possible order status value, for the "Order Status" dropdown (looked up via 'admin.orders.status_'.$s, same keys as the orders list page's filter).
 * @var array      $paymentStatuses Every possible payment status value, for the "Payment Status" dropdown (looked up via 'admin.order_view.pay_status_'.$s).
 * @var array      $items           This order's line items (product_name, option_summary, quantity, unit_price, total_price).
 * @var array      $payments        This order's payment attempts/records (payment_method, transaction_id, amount, status, created_at) - can be more than one row if a payment was retried.
 * @var array|null $invoice         The generated invoice for this order, or null if none exists yet (e.g. order not yet paid) - only used to decide whether to show the "download invoice" link.
 * @var array      $products        Every active product (id, name, sku, price), for the "Edit Line Items" card's product dropdowns.
 * @var array      $shippingMethods Every active shipping method (id, name), for the "Edit Line Items" card's shipping method dropdown.
 * @var array      $editLog         This order's create/edit/cancel history (Services\OrderEditingService), newest first - action, summary, username, created_at.
 * @var bool       $canDelete       Whether the current admin may cancel this order (AdminAuth::CAPABILITIES['orders_delete'] - Super Admin only) - the Cancel button/form only renders when true.
 */
?>
<div class="page-header">
  <h1><?= e(__('admin.order_view.heading', ['number' => $order['order_number']])) ?> <?php if ($order['is_test_order']): ?><span class="badge badge-pending"><?= e(__('admin.dashboard.test_badge')) ?></span><?php endif; ?></h1>
  <?php /* Reuses the storefront's own invoice-download route (/order/{number}/invoice, the same one a customer uses) rather than a separate admin-only PDF endpoint - see Services\InvoiceService. Only shown once an invoice actually exists for this order. */ ?>
  <?php if ($invoice): ?>
    <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/order/<?= urlencode($order['order_number']) ?>/invoice" target="_blank"><?= e(__('admin.order_view.download_invoice', ['number' => $invoice['invoice_number']])) ?></a>
    <?php /* Re-sends the same PDF already shown by the download link above, as an email attachment - Services\Mailer::sendInvoiceEmail(). A plain POST form styled as a button, not a link, since it's a state-changing action (sends an email, writes to email_log). */ ?>
    <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>/resend-invoice" style="display:inline;">
      <?= csrfField() ?>
      <button class="btn btn-secondary" type="submit"><?= e(__('admin.orders.resend.submit')) ?></button>
    </form>
  <?php endif; ?>
  <?php /* v3.10 - Services\PaymentReminderService. Only shown while the order is actually still unpaid; always available regardless of the Admin -> Settings -> Payment Reminders automatic-send toggle, which only controls the daily cron sweep. */ ?>
  <?php if ($order['payment_status'] === 'pending'): ?>
    <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>/send-payment-reminder" style="display:inline;">
      <?= csrfField() ?>
      <button class="btn btn-secondary" type="submit"><?= e(__('admin.orders.payment_reminder.submit')) ?></button>
    </form>
    <?php if (!empty($order['payment_reminder_sent_at'])): ?>
      <small style="color:var(--color-muted);display:block;margin-top:4px;"><?= e(__('admin.orders.payment_reminder.last_sent', ['date' => formatLocalDate($order['payment_reminder_sent_at'])])) ?></small>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php /* Extra, more prominent warning banner (beyond the small badge in the heading) reminding the admin this order used TestGateway and never touched real stock/finance figures - see CLAUDE.md's "Test accounts" section. */ ?>
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
  <?php /* Customer-submitted notes are optional, so only shown when present; nl2br(e(...)) escapes the text first (safe from injected HTML) and then converts the customer's own line breaks into <br> tags so multi-line notes still read correctly. */ ?>
  <?php if ($order['customer_notes']): ?><p><strong><?= e(__('admin.order_view.customer_notes')) ?>:</strong> <?= nl2br(e($order['customer_notes'])) ?></p><?php endif; ?>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.order_view.items')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.products')) ?></th><th><?= e(__('admin.order_view.qty')) ?></th><th><?= e(__('admin.order_view.unit_price')) ?></th><th><?= e(__('common.total')) ?></th></tr></thead>
    <tbody>
    <?php /* option_summary is a human-readable string of any chosen product options (e.g. "Size: L, Color: Red") - only shown as a second line when the item actually has options. */ ?>
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
  <?php /* Order totals breakdown - these are the amounts frozen onto the order at checkout time (not recalculated live), so this always reflects what the customer actually paid even if tax rates/shipping prices change later. */ ?>
  <div style="max-width:280px;margin-left:auto;margin-top:14px;">
    <div style="display:flex;justify-content:space-between;"><span><?= e(__('common.subtotal')) ?></span><span><?= formatPrice((float)$order['subtotal']) ?></span></div>
    <div style="display:flex;justify-content:space-between;"><span><?= e(__('common.shipping')) ?><?= !empty($order['shipping_method_name']) ? ' (' . e($order['shipping_method_name']) . ')' : '' ?></span><span><?= formatPrice((float)$order['shipping_cost']) ?></span></div>
    <div style="display:flex;justify-content:space-between;"><span><?= e(__('common.tax')) ?></span><span><?= formatPrice((float)$order['tax_total']) ?></span></div>
    <div style="display:flex;justify-content:space-between;font-weight:700;border-top:1px solid var(--color-border);padding-top:6px;margin-top:6px;"><span><?= e(__('common.total')) ?></span><span><?= formatPrice((float)$order['total']) ?></span></div>
  </div>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.orders.edit.heading')) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.orders.edit.hint')) ?></p>
  <?php /* Only shown when an invoice already exists for this order - see Services\OrderEditingService's class docblock for exactly what "regenerates in place" means here (same invoice number, not a compliant credit note). */ ?>
  <?php if ($invoice): ?>
    <div class="flash flash-info"><?= e(__('admin.orders.edit.invoice_warning')) ?></div>
  <?php endif; ?>
  <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>/items">
    <?= csrfField() ?>
    <div class="form-group">
      <label for="edit_shipping_method_id"><?= e(__('checkout.shipping_method')) ?></label>
      <select id="edit_shipping_method_id" name="shipping_method_id">
        <option value=""><?= e(__('admin.orders.create.no_shipping')) ?></option>
        <?php foreach ($shippingMethods as $m): ?>
          <option value="<?= (int)$m['id'] ?>" <?= (int)($order['shipping_method_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= e($m['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div id="editItemRows"></div>
    <button type="button" class="btn btn-sm btn-secondary" onclick="addEditItemRow()"><?= e(__('admin.orders.create.add_row')) ?></button>
    <div style="margin-top:14px;"><button class="btn" type="submit"><?= e(__('admin.orders.edit.submit')) ?></button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.order_view.payments')) ?></h2>
  <table class="data-table">
    <thead><tr><th><?= e(__('admin.order_view.method')) ?></th><th><?= e(__('admin.order_view.transaction_id')) ?></th><th><?= e(__('admin.order_view.amount')) ?></th><th><?= e(__('common.status')) ?></th><th><?= e(__('common.date')) ?></th></tr></thead>
    <tbody>
    <?php /* One row per payment attempt (a retried/reattempted payment shows as multiple rows here). The badge maps the internal 'completed' status to the more customer-facing 'paid' CSS class name; every other status uses its own name directly as the badge class. */ ?>
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
    <?php /* Unchecked by default (no "checked" attribute or value passed in) - saving a status change is silent unless the admin explicitly opts in to emailing the customer about it. */ ?>
    <div class="form-group">
      <label><input type="checkbox" name="notify_customer" value="1" style="width:auto;"> <?= e(__('admin.order_view.notify_customer')) ?></label>
    </div>
    <button class="btn" type="submit"><?= e(__('admin.order_view.save_changes')) ?></button>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.orders.log.heading')) ?></h2>
  <?php if (empty($editLog)): ?>
    <p style="color:var(--color-muted);"><?= e(__('admin.orders.log.none_yet')) ?></p>
  <?php else: ?>
    <table class="data-table">
      <thead><tr><th><?= e(__('common.date')) ?></th><th><?= e(__('admin.orders.log.action')) ?></th><th><?= e(__('admin.orders.log.summary')) ?></th><th><?= e(__('admin.orders.log.by')) ?></th></tr></thead>
      <tbody>
      <?php foreach ($editLog as $entry): ?>
        <tr>
          <td><?= e(formatLocalDate($entry['created_at'], true)) ?></td>
          <td><?= e(__('admin.orders.log.action_' . $entry['action'])) ?></td>
          <td><?= e($entry['summary']) ?></td>
          <td><?= e($entry['username'] ?? '-') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>
</div>

<?php /* "Delete" in this project means cancel + restock, never a real SQL DELETE - see Services\OrderEditingService::cancelAndRestock()'s docblock. Only rendered for an admin who actually has the separate 'orders_delete' capability (Super Admin only) - a manager viewing this same page simply never sees this card at all. */ ?>
<?php if ($canDelete && $order['status'] !== 'cancelled'): ?>
  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.orders.cancel.heading')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.orders.cancel.hint')) ?></p>
    <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/orders/<?= (int)$order['id'] ?>/cancel" data-confirm="<?= e(__('admin.orders.cancel.confirm')) ?>">
      <?= csrfField() ?>
      <button class="btn btn-danger" type="submit"><?= e(__('admin.orders.cancel.submit')) ?></button>
    </form>
  </div>
<?php endif; ?>

<?php /* Same repeating-row product/quantity/option-value-ids editor as Admin -> Orders -> Create (see that view's docblock for why option selection is a plain text field, not a picker) - the only difference is every existing line item pre-fills a row (including its order_item_id, so OrderEditingService::applyLineItemChanges() can tell "quantity changed on an existing line" apart from "a brand-new line"). */ ?>
<script>
  var editProductOptionsHtml = <?= json_encode('<option value="">' . __('admin.orders.create.choose_product') . '</option>' . implode('', array_map(
      fn ($p) => '<option value="' . (int)$p['id'] . '">' . e($p['name']) . ' (' . e($p['sku'] ?? '') . ') - ' . formatPrice((float)$p['price']) . '</option>',
      $products
  ))) ?>;
  var editItemRowIndex = 0;

  function addEditItemRow(orderItemId, productId, quantity, optionValueIds) {
    var container = document.getElementById('editItemRows');
    var row = document.createElement('div');
    row.className = 'form-grid';
    row.style.alignItems = 'end';
    var idx = editItemRowIndex++;
    row.innerHTML =
      '<input type="hidden" name="order_item_id[]" value="' + (orderItemId || '') + '">' +
      '<div class="form-group"><label><?= e(__("admin.orders.create.product")) ?></label><select name="product_id[]" id="editLineProduct' + idx + '">' + editProductOptionsHtml + '</select></div>' +
      '<div class="form-group"><label><?= e(__("admin.orders.create.quantity")) ?></label><input type="number" min="1" name="quantity[]" value="' + (quantity || 1) + '"></div>' +
      '<div class="form-group"><label><?= e(__("admin.orders.create.option_value_ids")) ?></label><input type="text" name="option_value_ids[]" placeholder="<?= e(__("admin.orders.create.option_value_ids_placeholder")) ?>" value="' + (optionValueIds || '') + '"></div>' +
      '<div class="form-group"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'.form-grid\').remove()"><?= e(__("common.delete")) ?></button></div>';
    container.appendChild(row);
    if (productId) {
      document.getElementById('editLineProduct' + idx).value = productId;
    }
  }

  document.addEventListener('DOMContentLoaded', function () {
    <?php foreach ($items as $item): ?>
      addEditItemRow(
        <?= (int)$item['id'] ?>,
        <?= (int)$item['product_id'] ?>,
        <?= (int)$item['quantity'] ?>,
        <?= json_encode($item['option_value_ids'] ?? '') ?>
      );
    <?php endforeach; ?>
  });
</script>
