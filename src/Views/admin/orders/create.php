<?php
/**
 * Admin -> Orders -> Create: manual order entry (e.g. a phone/mail order)
 * - available to managers and Super Admin. Submitted to
 * OrderAdminController::store() -> Services\OrderEditingService::createManualOrder(),
 * which re-derives every price/tax/stock figure server-side from whatever
 * product+quantity+options this form's repeating line-item rows submit -
 * nothing here is trusted at face value, same posture as the real
 * storefront checkout.
 *
 * Line-item option selection is deliberately a plain "Option value IDs"
 * text field (comma-separated), not a cascading product-options picker -
 * a full dynamic variant-picker UI is a larger feature than this form
 * needs; an admin creating an order for a product with options can find
 * the right IDs on that product's own edit page.
 *
 * @var array $errors          Validation error messages to show above the form.
 * @var array $products        Every active product (id, name, sku, price), for each line's product dropdown.
 * @var array $shippingMethods Every active shipping method (id, name), for the shipping method dropdown.
 * @var array $customers       Every active customer (id, first_name, last_name, email), for the customer picker.
 */
$base = rtrim(SITE_URL, '/') . '/admin/orders/create';
?>
<div class="page-header">
  <h1><?= e(__('admin.orders.create.title')) ?></h1>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/orders">&larr; <?= e(__('admin.orders')) ?></a>
</div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<form method="post" action="<?= e($base) ?>">
  <?= csrfField() ?>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.orders.create.customer_heading')) ?></h2>
    <div class="form-grid">
      <div class="form-group">
        <label for="customer_id"><?= e(__('admin.orders.create.existing_customer')) ?></label>
        <select id="customer_id" name="customer_id">
          <option value=""><?= e(__('admin.orders.create.guest_option')) ?></option>
          <?php foreach ($customers as $c): ?>
            <option value="<?= (int)$c['id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name'] . ' (' . $c['email'] . ')') ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group">
        <label for="guest_email"><?= e(__('admin.orders.create.guest_email')) ?></label>
        <input type="email" id="guest_email" name="guest_email">
        <small style="color:var(--color-muted);display:block;"><?= e(__('admin.orders.create.guest_email_hint')) ?></small>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.orders.create.shipping_heading')) ?></h2>
    <div class="form-grid">
      <div class="form-group"><label for="shipping_name"><?= e(__('checkout.full_name')) ?></label><input type="text" id="shipping_name" name="shipping_name" required></div>
      <div class="form-group"><label for="shipping_address1"><?= e(__('checkout.address1')) ?></label><input type="text" id="shipping_address1" name="shipping_address1" required></div>
      <div class="form-group"><label for="shipping_address2"><?= e(__('checkout.address2')) ?></label><input type="text" id="shipping_address2" name="shipping_address2"></div>
      <div class="form-group"><label for="shipping_city"><?= e(__('checkout.city')) ?></label><input type="text" id="shipping_city" name="shipping_city" required></div>
      <div class="form-group"><label for="shipping_postal_code"><?= e(__('checkout.postal_code')) ?></label><input type="text" id="shipping_postal_code" name="shipping_postal_code" required></div>
      <div class="form-group"><label for="shipping_country"><?= e(__('checkout.country')) ?></label><input type="text" id="shipping_country" name="shipping_country" required></div>
      <div class="form-group">
        <label for="shipping_method_id"><?= e(__('checkout.shipping_method')) ?></label>
        <select id="shipping_method_id" name="shipping_method_id">
          <option value=""><?= e(__('admin.orders.create.no_shipping')) ?></option>
          <?php foreach ($shippingMethods as $m): ?>
            <option value="<?= (int)$m['id'] ?>"><?= e($m['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.orders.create.items_heading')) ?></h2>
    <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.orders.create.items_hint')) ?></p>
    <div id="lineItemRows"></div>
    <button type="button" class="btn btn-sm btn-secondary" onclick="addLineItemRow()"><?= e(__('admin.orders.create.add_row')) ?></button>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.orders.create.payment_heading')) ?></h2>
    <div class="form-grid">
      <div class="form-group">
        <label for="payment_method"><?= e(__('admin.orders.payment_method')) ?></label>
        <select id="payment_method" name="payment_method" required>
          <option value="bank_transfer"><?= e(__('checkout.bank_transfer')) ?></option>
          <option value="invoice"><?= e(__('checkout.invoice')) ?></option>
          <option value="paypal">PayPal</option>
          <option value="credit_card"><?= e(__('checkout.credit_card')) ?></option>
        </select>
      </div>
      <div class="form-group">
        <label for="payment_status"><?= e(__('order.payment')) ?></label>
        <select id="payment_status" name="payment_status">
          <option value="pending"><?= e(__('admin.orders.status_pending')) ?></option>
          <option value="paid"><?= e(__('admin.orders.status_paid')) ?></option>
        </select>
      </div>
    </div>
  </div>

  <div class="card">
    <h2 style="margin-top:0;"><?= e(__('admin.orders.create.notes_heading')) ?></h2>
    <div class="form-grid">
      <div class="form-group"><label for="customer_notes"><?= e(__('checkout.order_notes')) ?></label><textarea id="customer_notes" name="customer_notes" rows="2"></textarea></div>
      <div class="form-group"><label for="admin_notes"><?= e(__('admin.order_view.admin_notes')) ?></label><textarea id="admin_notes" name="admin_notes" rows="2"></textarea></div>
    </div>
  </div>

  <button class="btn" type="submit"><?= e(__('admin.orders.create.submit')) ?></button>
</form>

<?php /* $productOptions is built once here (not inline in the JS template string below) so e()/json_encode() escaping happens through the normal PHP view layer, not hand-rolled inside JS. */ ?>
<script>
  var productOptionsHtml = <?= json_encode('<option value="">' . __('admin.orders.create.choose_product') . '</option>' . implode('', array_map(
      fn ($p) => '<option value="' . (int)$p['id'] . '">' . e($p['name']) . ' (' . e($p['sku'] ?? '') . ') - ' . formatPrice((float)$p['price']) . '</option>',
      $products
  ))) ?>;
  var lineItemRowIndex = 0;

  // Appends one repeating line-item row (product select + quantity +
  // optional option-value-ids text field + a remove button) - same plain
  // "build a template string, appendChild it" approach used elsewhere in
  // this admin (e.g. the product-image reorder script), no framework.
  function addLineItemRow(orderItemId, productId, quantity, optionValueIds) {
    var container = document.getElementById('lineItemRows');
    var row = document.createElement('div');
    row.className = 'form-grid';
    row.style.alignItems = 'end';
    var idx = lineItemRowIndex++;
    row.innerHTML =
      '<input type="hidden" name="order_item_id[]" value="' + (orderItemId || '') + '">' +
      '<div class="form-group"><label><?= e(__("admin.orders.create.product")) ?></label><select name="product_id[]" id="lineProduct' + idx + '">' + productOptionsHtml + '</select></div>' +
      '<div class="form-group"><label><?= e(__("admin.orders.create.quantity")) ?></label><input type="number" min="1" name="quantity[]" value="' + (quantity || 1) + '"></div>' +
      '<div class="form-group"><label><?= e(__("admin.orders.create.option_value_ids")) ?></label><input type="text" name="option_value_ids[]" placeholder="<?= e(__("admin.orders.create.option_value_ids_placeholder")) ?>" value="' + (optionValueIds || '') + '"></div>' +
      '<div class="form-group"><button type="button" class="btn btn-sm btn-danger" onclick="this.closest(\'.form-grid\').remove()"><?= e(__("common.delete")) ?></button></div>';
    container.appendChild(row);
    if (productId) {
      document.getElementById('lineProduct' + idx).value = productId;
    }
  }

  // Every "Create Order" form starts with one blank row ready to fill in.
  document.addEventListener('DOMContentLoaded', function () { addLineItemRow(); });
</script>
