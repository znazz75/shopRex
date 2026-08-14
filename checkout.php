<?php
require_once __DIR__ . '/includes/bootstrap.php';

$cart = Cart::getItems();
$items = $cart['items'];
$subtotal = $cart['subtotal'];

if (empty($items)) {
    setFlash('error', __('cart.empty'));
    redirect(rtrim(SITE_URL, '/') . '/cart.php');
}

$cartWeightKg = Cart::getWeightKg();
$totalQuantity = Cart::count();
$shippingMethods = Cart::getActiveShippingMethods();
foreach ($shippingMethods as &$method) {
    $method['cost'] = Cart::calculateShippingForMethod((int)$method['id'], $cartWeightKg, $subtotal, $totalQuantity);
}
unset($method);

$selectedShippingMethodId = (int)($_POST['shipping_method_id'] ?? ($shippingMethods[0]['id'] ?? 0));
$shipping = 0.0;
foreach ($shippingMethods as $method) {
    if ((int)$method['id'] === $selectedShippingMethodId) {
        $shipping = $method['cost'];
        break;
    }
}

$tax = $cart['tax_total'];
$taxBreakdown = $cart['tax_breakdown'];
$total = $subtotal + $shipping + $tax;

$customer = currentCustomer();

// Which payment methods to actually offer: paypal/credit_card/bank_transfer
// are gated by their Admin -> Settings -> Payment Methods toggle; invoice
// is never a site-wide toggle, only a per-customer grant (Admin ->
// Customers -> [customer] -> Payment) - see includes/functions.php.
$availablePaymentMethods = [];
if (isPaymentMethodEnabled('bank_transfer')) $availablePaymentMethods[] = 'bank_transfer';
if (isPaymentMethodEnabled('paypal')) $availablePaymentMethods[] = 'paypal';
if (isPaymentMethodEnabled('credit_card')) $availablePaymentMethods[] = 'credit_card';
if (customerCanPayOnInvoice($customer)) $availablePaymentMethods[] = 'invoice';

$pageTitle = __('checkout.title');
require themeTemplatePath('header.php');
?>

<h1 class="h3 mb-4"><?= e(__('checkout.title')) ?></h1>

<?php if (!empty($_GET['cancelled'])): ?>
  <div class="alert alert-danger"><?= e(__('checkout.cancelled')) ?></div>
<?php endif; ?>

<form method="post" action="<?= rtrim(SITE_URL, '/') ?>/checkout_process.php">
  <?= csrfField() ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <fieldset class="card mb-4">
        <div class="card-body">
          <legend class="h5"><?= e(__('checkout.contact')) ?></legend>
          <div class="mb-3">
            <label class="form-label" for="email"><?= e(__('common.email')) ?></label>
            <input class="form-control" type="email" id="email" name="email" required value="<?= e($customer['email'] ?? '') ?>">
          </div>
        </div>
      </fieldset>

      <fieldset class="card mb-4">
        <div class="card-body">
          <legend class="h5"><?= e(__('checkout.shipping_address')) ?></legend>
          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label" for="shipping_name"><?= e(__('checkout.full_name')) ?></label>
              <input class="form-control" type="text" id="shipping_name" name="shipping_name" required
                     value="<?= e(trim(($customer['first_name'] ?? '') . ' ' . ($customer['last_name'] ?? ''))) ?>">
            </div>
            <div class="col-md-6">
              <label class="form-label" for="shipping_country"><?= e(__('checkout.country')) ?></label>
              <input class="form-control" type="text" id="shipping_country" name="shipping_country" required value="Germany">
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label" for="shipping_address1"><?= e(__('checkout.address1')) ?></label>
            <input class="form-control" type="text" id="shipping_address1" name="shipping_address1" required>
          </div>
          <div class="mb-3">
            <label class="form-label" for="shipping_address2"><?= e(__('checkout.address2')) ?></label>
            <input class="form-control" type="text" id="shipping_address2" name="shipping_address2">
          </div>
          <div class="row g-3">
            <div class="col-md-6">
              <label class="form-label" for="shipping_postal_code"><?= e(__('checkout.postal_code')) ?></label>
              <input class="form-control" type="text" id="shipping_postal_code" name="shipping_postal_code" required>
            </div>
            <div class="col-md-6">
              <label class="form-label" for="shipping_city"><?= e(__('checkout.city')) ?></label>
              <input class="form-control" type="text" id="shipping_city" name="shipping_city" required>
            </div>
          </div>
        </div>
      </fieldset>

      <?php if (count($shippingMethods) > 1): ?>
      <fieldset class="card mb-4">
        <div class="card-body">
          <legend class="h5"><?= e(__('checkout.shipping_method')) ?></legend>
          <div class="list-group">
            <?php foreach ($shippingMethods as $method): ?>
              <label class="list-group-item payment-option d-flex gap-2 justify-content-between align-items-center">
                <span class="d-flex gap-2">
                  <input class="form-check-input flex-shrink-0 shipping-method-radio" type="radio" name="shipping_method_id"
                         value="<?= (int)$method['id'] ?>" data-cost="<?= e($method['cost']) ?>"
                         <?= (int)$method['id'] === $selectedShippingMethodId ? 'checked' : '' ?>>
                  <span><?= e($method['name']) ?></span>
                </span>
                <span><?= $method['cost'] > 0 ? formatPrice($method['cost']) : e(__('common.free')) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </fieldset>
      <?php elseif (!empty($shippingMethods)): ?>
        <input type="hidden" name="shipping_method_id" value="<?= (int)$shippingMethods[0]['id'] ?>">
      <?php endif; ?>

      <fieldset class="card mb-4">
        <div class="card-body">
          <legend class="h5"><?= e(__('checkout.payment_method')) ?></legend>
          <?php if (empty($availablePaymentMethods)): ?>
            <div class="alert alert-warning mb-0"><?= e(__('checkout.no_payment_methods')) ?></div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($availablePaymentMethods as $i => $method): ?>
                <?php
                $icon = ['paypal' => 'bi-paypal', 'credit_card' => 'bi-credit-card', 'bank_transfer' => 'bi-bank', 'invoice' => 'bi-receipt'][$method];
                $label = __('checkout.' . $method);
                ?>
                <label class="list-group-item payment-option d-flex gap-2">
                  <input class="form-check-input flex-shrink-0" type="radio" name="payment_method" value="<?= e($method) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                  <span><i class="bi <?= $icon ?> me-1"></i> <?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <?php if (in_array('bank_transfer', $availablePaymentMethods, true)): ?>
              <div id="bank-details" class="alert alert-info mt-3 d-none" data-payment-method="bank_transfer">
                <?= e(__('checkout.bank_transfer_note')) ?>
              </div>
            <?php endif; ?>
            <?php if (in_array('invoice', $availablePaymentMethods, true)): ?>
              <div id="invoice-details" class="alert alert-info mt-3 d-none" data-payment-method="invoice">
                <?= e(__('checkout.invoice_note')) ?>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      </fieldset>

      <div class="mb-3">
        <label class="form-label" for="customer_notes"><?= e(__('checkout.order_notes')) ?></label>
        <textarea class="form-control" id="customer_notes" name="customer_notes" rows="3"></textarea>
      </div>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-body">
          <h2 class="h5"><?= e(__('cart.summary')) ?></h2>
          <?php foreach ($items as $item): ?>
            <div class="d-flex justify-content-between small mb-2">
              <span><?= e($item['name']) ?> <?= $item['option_label'] ? '(' . e($item['option_label']) . ')' : '' ?> &times;<?= (int)$item['quantity'] ?></span>
              <span><?= formatPrice($item['line_total']) ?></span>
            </div>
          <?php endforeach; ?>
          <hr>
          <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.subtotal')) ?> (<?= e(__('cart.net_price')) ?>)</span><span><?= formatPrice($subtotal) ?></span></div>
          <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.shipping')) ?></span><span id="checkoutShippingCost"><?= $shipping > 0 ? formatPrice($shipping) : e(__('common.free')) ?></span></div>
          <?php foreach ($taxBreakdown as $rate => $amount): ?>
            <div class="d-flex justify-content-between mb-2 small text-secondary"><span><?= e(__('cart.vat_amount', ['rate' => formatTaxRateNumber((float)$rate)])) ?></span><span><?= formatPrice($amount) ?></span></div>
          <?php endforeach; ?>
          <hr>
          <div class="d-flex justify-content-between fw-bold fs-5 mb-3"><span><?= e(__('common.total')) ?></span><span id="checkoutTotal"><?= formatPrice($total) ?></span></div>
          <button class="btn btn-primary w-100" type="submit" <?= empty($availablePaymentMethods) ? 'disabled' : '' ?>><?= e(__('checkout.place_order')) ?></button>
        </div>
      </div>
    </div>
  </div>
</form>

<?php if (count($shippingMethods) > 1): ?>
<script>
  // Recompute the displayed shipping cost/total client-side when a
  // shipping method is picked - all the per-method costs are already
  // known server-side (data-cost, one per radio), so this needs no
  // round trip. Mirrors formatPrice() in includes/functions.php exactly
  // (same fixed comma/period grouping regardless of language/currency).
  (function () {
    var currencySymbol = <?= json_encode(CURRENCY_SYMBOL) ?>;
    var fixedTotal = <?= json_encode(round($subtotal + $tax, 2)) ?>; // everything except shipping
    var freeLabel = <?= json_encode(__('common.free')) ?>;
    function formatPrice(amount) {
      return currencySymbol + amount.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d)(?=,))/g, '.');
    }
    function updateTotals() {
      var checked = document.querySelector('.shipping-method-radio:checked');
      var cost = checked ? parseFloat(checked.dataset.cost) : 0;
      document.getElementById('checkoutShippingCost').textContent = cost > 0 ? formatPrice(cost) : freeLabel;
      document.getElementById('checkoutTotal').textContent = formatPrice(fixedTotal + cost);
    }
    document.querySelectorAll('.shipping-method-radio').forEach(function (radio) {
      radio.addEventListener('change', updateTotals);
    });
  })();
</script>
<?php endif; ?>

<?php require themeTemplatePath('footer.php'); ?>
