<?php
/**
 * @var array $items
 * @var float $subtotal
 * @var float $tax
 * @var array $taxBreakdown
 * @var float $shipping
 * @var array $activeShippingMethods
 * @var string $continueShoppingUrl
 * Direct port of cart.php's body.
 */
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
  <h1 class="h3 mb-0"><?= e(__('cart.title')) ?></h1>
  <a class="btn btn-outline-secondary btn-sm" href="<?= e($continueShoppingUrl) ?>"><i class="bi bi-arrow-left me-1"></i><?= e(__('common.continue_shopping')) ?></a>
</div>

<?php if (empty($items)): ?>
  <p class="text-secondary"><?= e(__('cart.empty')) ?> <a href="<?= rtrim(SITE_URL, '/') ?>/"><?= e(__('common.continue_shopping')) ?></a>.</p>
<?php else: ?>
  <div class="row g-4">
    <div class="col-lg-8">
      <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/cart/update">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update">
        <div class="table-responsive">
          <table class="table align-middle">
            <thead>
              <tr>
                <th></th><th><?= e(__('cart.product')) ?></th>
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
                    <?php if ($item['quantity'] > $item['available_stock']): ?>
                      <br><small class="text-danger"><?= e(__('cart.only_left', ['n' => $item['available_stock']])) ?></small>
                    <?php endif; ?>
                  </td>
                  <td><?= formatPrice($item['unit_price']) ?></td>
                  <td>
                    <input class="form-control form-control-sm" type="number" min="0" max="<?= (int)($item['max_order_quantity'] ?? 99) ?>" style="width:80px;"
                           name="quantity[<?= e($item['key']) ?>]" value="<?= (int)$item['quantity'] ?>">
                    <?php if ($item['max_order_quantity']): ?>
                      <br><small class="text-secondary"><?= e(__('product.max_order_quantity_note', ['n' => $item['max_order_quantity']])) ?></small>
                    <?php endif; ?>
                  </td>
                  <td class="text-end fw-semibold"><?= formatPrice($item['line_total']) ?></td>
                  <td>
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
          <div class="d-flex justify-content-between mb-2">
            <span><?= e(__('common.shipping')) ?><?= count($activeShippingMethods) > 1 ? ' (' . e(__('cart.shipping_from')) . ')' : '' ?></span>
            <span><?= $shipping > 0 ? formatPrice($shipping) : e(__('common.free')) ?></span>
          </div>
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
