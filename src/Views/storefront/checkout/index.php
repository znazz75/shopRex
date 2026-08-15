<?php
/**
 * Storefront checkout page - shipping address, shipping method, payment
 * method, and an order summary, all in one POST-to-self form. Rendered by
 * Controllers\Storefront\CheckoutController::index() at /checkout. Just
 * the body; Core\Renderer::render() wraps it with the theme's
 * header.php/footer.php.
 *
 * IMPORTANT: everything shown here (shipping cost per method, tax, total)
 * is a *display* convenience only. The actual order-placing POST handler
 * (CheckoutController::process(), not in this file) re-derives shipping
 * cost, tax, and totals from the cart/settings server-side rather than
 * trusting any of the values this form submits - see CLAUDE.md's Security
 * posture section ("don't reintroduce trusting the URL/client directly").
 * The little inline <script> at the bottom that live-updates the on-page
 * total when the shopper changes shipping method is purely cosmetic for
 * the same reason - it can't affect what's actually charged.
 *
 * @var array      $items                    Cart line items being ordered
 *                                             (name/option_label/quantity/
 *                                             line_total) - same shape as
 *                                             cart/index.php's $items.
 * @var float      $subtotal                  Sum of every line item, before
 *                                             shipping/tax.
 * @var float      $shipping                  Cost of $selectedShippingMethodId
 *                                             specifically (not necessarily
 *                                             the cheapest, unlike the cart
 *                                             page's summary).
 * @var float      $tax                       Total tax across all items.
 * @var array      $taxBreakdown              Map of tax rate percent => tax
 *                                             amount at that rate.
 * @var float      $total                     subtotal + shipping + tax.
 * @var array      $shippingMethods           Every currently-active shipping
 *                                             method, each with its cost
 *                                             already computed for this
 *                                             cart's weight/subtotal/quantity.
 * @var int        $selectedShippingMethodId  Which method is pre-selected -
 *                                             whatever was posted back when
 *                                             the shopper changed it (this
 *                                             page re-submits itself on
 *                                             method change), or the first
 *                                             available method otherwise.
 * @var array|null $customer                  Logged-in customer's row, used
 *                                             only to pre-fill the email/name
 *                                             fields - null for a guest
 *                                             checkout (guest checkout is
 *                                             allowed, this isn't a login gate).
 * @var array      $availablePaymentMethods   Which payment method keys
 *                                             ('bank_transfer'/'paypal'/
 *                                             'credit_card'/'invoice') are
 *                                             actually offered right now -
 *                                             each depends on a Settings
 *                                             toggle, and 'invoice'
 *                                             additionally requires this
 *                                             specific customer to have
 *                                             invoice-payment permission.
 * @var bool       $cancelled                 True when redirected back here
 *                                             after backing out of an
 *                                             external payment gateway
 *                                             (PayPal/Stripe) - shows a
 *                                             "checkout was cancelled" notice.
 * Direct port of checkout.php's body.
 */
?>

<h1 class="h3 mb-4"><?= e(__('checkout.title')) ?></h1>

<?php if ($cancelled): ?>
  <div class="alert alert-danger"><?= e(__('checkout.cancelled')) ?></div>
<?php endif; ?>

<form method="post" action="<?= rtrim(SITE_URL, '/') ?>/checkout">
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

      <?php // With more than one shipping method available, let the shopper pick
      // (radio list, each tagged data-cost="" for the live-updating script
      // at the bottom of this file). With exactly one, skip the UI clutter
      // and just submit it via a hidden field. With zero, nothing is
      // rendered here at all - CheckoutController::process() still handles
      // that case (an order with no valid shipping method simply can't be
      // placed). ?>
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
          <?php // If Admin -> Settings has every payment method switched off
                // (or this customer doesn't qualify for the only one enabled,
                // e.g. invoice), there's genuinely nothing to pay with - show
                // a warning instead of an empty radio list, and the Place
                // Order button gets `disabled` further down for the same reason. ?>
          <?php if (empty($availablePaymentMethods)): ?>
            <div class="alert alert-warning mb-0"><?= e(__('checkout.no_payment_methods')) ?></div>
          <?php else: ?>
            <div class="list-group">
              <?php foreach ($availablePaymentMethods as $i => $method): ?>
                <?php
                // Method-specific icon + translated label; the first method
                // in the (settings-defined) list is pre-selected by default.
                $icon = ['paypal' => 'bi-paypal', 'credit_card' => 'bi-credit-card', 'bank_transfer' => 'bi-bank', 'invoice' => 'bi-receipt'][$method];
                $label = __('checkout.' . $method);
                ?>
                <label class="list-group-item payment-option d-flex gap-2">
                  <input class="form-check-input flex-shrink-0" type="radio" name="payment_method" value="<?= e($method) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                  <span><i class="bi <?= $icon ?> me-1"></i> <?= e($label) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
            <?php // These explanatory notes start hidden (d-none) and are
                  // toggled visible by assets/js/main.js when the matching
                  // radio is selected (matched via data-payment-method) -
                  // only rendered at all when that payment method is
                  // actually offered, so there's nothing for the JS to
                  // reveal if it isn't. ?>
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
          <?php // The shipping cost and grand total below carry id=""
                // attributes specifically so the <script> at the bottom of
                // this file can live-update them when the shopper picks a
                // different shipping method, without a full page reload -
                // purely cosmetic, see this file's top docblock for why that's
                // safe (the real charge is always re-derived server-side). ?>
          <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.shipping')) ?></span><span id="checkoutShippingCost"><?= $shipping > 0 ? formatPrice($shipping) : e(__('common.free')) ?></span></div>
          <?php foreach ($taxBreakdown as $rate => $amount): ?>
            <div class="d-flex justify-content-between mb-2 small text-secondary"><span><?= e(__('cart.vat_amount', ['rate' => formatTaxRateNumber((float)$rate)])) ?></span><span><?= formatPrice($amount) ?></span></div>
          <?php endforeach; ?>
          <hr>
          <div class="d-flex justify-content-between fw-bold fs-5 mb-3"><span><?= e(__('common.total')) ?></span><span id="checkoutTotal"><?= formatPrice($total) ?></span></div>
          <?php // Disabled rather than hidden when there's no way to pay, so
                // it's still visible as a clear dead-end instead of just
                // vanishing - matches the warning box shown above in the
                // payment-method fieldset. ?>
          <button class="btn btn-primary w-100" type="submit" <?= empty($availablePaymentMethods) ? 'disabled' : '' ?>><?= e(__('checkout.place_order')) ?></button>
        </div>
      </div>
    </div>
  </div>
</form>

<?php // Only needed when there's actually a choice of shipping method to
      // react to (the radio list above only renders in that case too) -
      // with 0 or 1 methods there's nothing for this script to update. ?>
<?php if (count($shippingMethods) > 1): ?>
<script>
  (function () {
    // PHP-computed values baked into the page as JSON so this script
    // doesn't need a network round-trip to react to a shipping-method
    // change - fixedTotal is subtotal+tax (everything that does NOT change
    // when shipping method changes), so the new total is just that plus
    // whichever method's cost is now selected.
    var currencySymbol = <?= json_encode(CURRENCY_SYMBOL) ?>;
    var fixedTotal = <?= json_encode(round($subtotal + $tax, 2)) ?>; // everything except shipping
    var freeLabel = <?= json_encode(__('common.free')) ?>;
    function formatPrice(amount) {
      return currencySymbol + amount.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d)(?=,))/g, '.');
    }
    // Re-reads whichever shipping radio is currently checked and rewrites
    // the two summary-card spans (by the id="" attributes set above) - this
    // is purely a same-page display refresh, not a real price calculation;
    // the server recomputes everything from scratch when the order is placed.
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
