<?php
/**
 * Storefront shopping cart page - lists everything currently in the
 * session-based cart (Models\Cart, rehydrated from the DB on every load so
 * prices/stock shown here are always current, never stale - see CLAUDE.md's
 * "Cart" section) with editable quantities, a remove button per line, and
 * an order summary. Rendered by Controllers\Storefront\CartController::index()
 * at /cart. Just the body; Core\Renderer::render() wraps it with the
 * theme's header.php/footer.php.
 *
 * Both forms on this page (quantity update, remove) post to
 * Controllers\Storefront\CartController::handleAction() - a single POST
 * endpoint dispatched by an 'action' hidden field (add|update|remove),
 * matching the original single-endpoint cart_action.php shape. All actual
 * cart mutation happens through Models\Cart's API server-side; this view
 * never touches $_SESSION directly.
 *
 * @var array  $items                 Cart line items - each has key (a
 *                                      unique identifier for this exact
 *                                      product+option combination, used to
 *                                      target quantity/remove actions at
 *                                      one row), name/slug/image,
 *                                      option_label (blank if the product
 *                                      has no options), unit_price,
 *                                      quantity, line_total (unit_price *
 *                                      quantity), available_stock, and
 *                                      max_order_quantity (0/null = no cap).
 * @var float  $subtotal               Sum of every line item's line_total,
 *                                      before shipping/tax.
 * @var float  $tax                    Total tax across all items (sum of
 *                                      $taxBreakdown's values).
 * @var array  $taxBreakdown           Map of tax rate percent => tax amount
 *                                      at that rate, for shops with more
 *                                      than one VAT rate in the cart at once.
 * @var float  $shipping               The cheapest currently-available
 *                                      shipping method's cost for this
 *                                      cart's weight/subtotal/quantity (0.0
 *                                      if no method qualifies, shown as "Free").
 * @var array  $activeShippingMethods  Every shipping method that currently
 *                                      qualifies for this cart - only its
 *                                      count matters here (>1 means the
 *                                      shown $shipping cost is a "from"
 *                                      price, not necessarily what checkout
 *                                      will actually charge).
 * @var string $continueShoppingUrl    Where the "Continue shopping" link
 *                                      goes - the last product page visited
 *                                      this session, or the homepage if none.
 * Direct port of cart.php's body.
 */
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <h1 class="h3 mb-0"><?= e(__('cart.title')) ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e($continueShoppingUrl) ?>"><i class="bi bi-arrow-left me-1"></i><?= e(__('common.continue_shopping')) ?></a>
</div>

<?php // Nothing in the cart - point the shopper back to shopping instead of
      // showing an empty table/summary card. ?>
<?php if (empty($items)): ?>
  <p class="text-secondary"><?= e(__('cart.empty')) ?> <a href="<?= rtrim(SITE_URL, '/') ?>/"><?= e(__('common.continue_shopping')) ?></a>.</p>
<?php else: ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <?php // The whole line-item table is ONE form that posts every row's
            // new quantity[key]=N at once to /cart/update - not one form per
            // row. The per-row remove button below is a separate mechanism
            // (see the `form="remove-..."` attribute a few lines down). ?>
      <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/cart/update">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update">
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th></th><th><?= e(__('cart.product')) ?></th>
                <?php // When VAT display is on, prices shown in this table
                      // are net (tax-exclusive) - the tax gets added
                      // separately in the summary card, so the column header
                      // says so to avoid confusion with the order total. ?>
                <th><?= e(__('common.price')) ?><?= vatIsEnabled() ? ' (' . e(__('cart.net_price')) . ')' : '' ?></th>
                <th><?= e(__('common.quantity')) ?></th><th class="text-end"><?= e(__('common.total')) ?></th><th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $item): ?>
                <tr>
                  <td><img class="cart-thumb" src="<?= e($item['image']) ?>" alt=""></td>
                  <td>
                    <a class="text-body fw-semibold text-decoration-none" href="<?= rtrim(SITE_URL, '/') ?>/product/<?= e($item['slug']) ?>"><?= e($item['name']) ?></a>
                    <?php if ($item['option_label']): ?><br><small class="text-secondary"><?= e($item['option_label']) ?></small><?php endif; ?>
                    <?php // Since the cart is rehydrated from the DB on every
                          // load, stock may have dropped below what's already
                          // in the cart (someone else bought the last few) -
                          // flag it here rather than silently clamping, so
                          // checkout doesn't surprise the shopper later. ?>
                    <?php if ($item['quantity'] > $item['available_stock']): ?>
                      <br><small class="text-danger"><?= e(__('cart.only_left', ['n' => $item['available_stock']])) ?></small>
                    <?php endif; ?>
                  </td>
                  <td><?= formatPrice($item['unit_price']) ?></td>
                  <td>
                    <?php // The max="" attribute is a client-side convenience
                          // only (caps the number spinner) - the server
                          // re-validates against real stock/limits on submit
                          // regardless of what value is posted here. ?>
                    <input class="form-control form-control-sm" type="number" min="0" max="<?= (int)($item['max_order_quantity'] ?? 99) ?>" style="width:80px;"
                           name="quantity[<?= e($item['key']) ?>]" value="<?= (int)$item['quantity'] ?>">
                    <?php if ($item['max_order_quantity']): ?>
                      <br><small class="text-secondary"><?= e(__('product.max_order_quantity_note', ['n' => $item['max_order_quantity']])) ?></small>
                    <?php endif; ?>
                  </td>
                  <td class="text-end fw-semibold"><?= formatPrice($item['line_total']) ?></td>
                  <td>
                    <?php // This button is physically inside the "update"
                          // form above, but the HTML5 form="remove-{key}"
                          // attribute makes it submit a *different*,
                          // standalone form instead (declared just below,
                          // outside the table) - that's how one row's trash
                          // icon can trigger "remove just this item" without
                          // nesting a second <form> inside the first (which
                          // HTML doesn't allow) or submitting every row's
                          // quantities along with it. ?>
                    <button class="btn btn-sm btn-outline-danger" form="remove-<?= e($item['key']) ?>" type="submit">
                      <i class="bi bi-trash"></i>
                    </button>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <button class="btn btn-outline-secondary" type="submit"><?= e(__('cart.update')) ?></button>
      </form>

      <?php // One tiny standalone form per line item, existing purely to be
            // the target of that row's remove button (form="remove-{key}"
            // above) - each posts action=remove + which item's key to drop. ?>
      <?php foreach ($items as $item): ?>
        <form id="remove-<?= e($item['key']) ?>" method="post" action="<?= rtrim(SITE_URL, '/') ?>/cart/remove" data-confirm="<?= e(__('cart.confirm_remove')) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="action" value="remove">
          <input type="hidden" name="key" value="<?= e($item['key']) ?>">
        </form>
      <?php endforeach; ?>
    </div>

    <div class="col-lg-4">
      <div class="card">
        <div class="card-body">
          <h2 class="h5"><?= e(__('cart.summary')) ?></h2>
          <div class="d-flex justify-content-between mb-2"><span><?= e(__('common.subtotal')) ?><?= vatIsEnabled() ? ' (' . e(__('cart.net_price')) . ')' : '' ?></span><span><?= formatPrice($subtotal) ?></span></div>
          <?php // $shipping is the cheapest qualifying method's cost; if more
                // than one method currently qualifies, label it "from" since
                // checkout may offer (and the shopper may pick) a pricier
                // one. A cost of exactly 0 is shown as "Free" rather than
                // "€0.00". ?>
          <div class="d-flex justify-content-between mb-2">
            <span><?= e(__('common.shipping')) ?><?= count($activeShippingMethods) > 1 ? ' (' . e(__('cart.shipping_from')) . ')' : '' ?></span>
            <span><?= $shipping > 0 ? formatPrice($shipping) : e(__('common.free')) ?></span>
          </div>
          <?php // One line per distinct VAT rate present in the cart (a shop
                // can have products taxed at different rates) - shows how
                // much tax at each rate contributes to the total. ?>
          <?php foreach ($taxBreakdown as $rate => $amount): ?>
            <div class="d-flex justify-content-between mb-2 small text-secondary"><span><?= e(__('cart.vat_amount', ['rate' => formatTaxRateNumber((float)$rate)])) ?></span><span><?= formatPrice($amount) ?></span></div>
          <?php endforeach; ?>
          <hr>
          <div class="d-flex justify-content-between fw-bold fs-5 mb-3"><span><?= e(__('common.total')) ?></span><span><?= formatPrice($subtotal + $shipping + $tax) ?></span></div>
          <a class="btn btn-primary w-100" href="<?= rtrim(SITE_URL, '/') ?>/checkout"><?= e(__('cart.proceed_to_checkout')) ?></a>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
