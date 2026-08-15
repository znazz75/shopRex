<?php
/**
 * Admin -> Products: the searchable product catalog list. Each row surfaces
 * several derived states via global helper functions (see
 * src/view-helpers.php) rather than raw columns - active discount, VAT
 * gross price, availability window, low-stock flag - so this file is
 * mostly about *when* to show which badge/column, not raw data access.
 *
 * @var string $search   The current search-box value (name/SKU query), also used to keep the search box filled in after submitting.
 * @var array  $products The (already search-filtered) product rows.
 */
?>
<div class="page-header">
  <h1><?= e(__('admin.products')) ?></h1>
  <a class="btn" href="<?= rtrim(SITE_URL, '/') ?>/admin/products/create">+ <?= e(__('admin.products.add')) ?></a>
</div>

<form class="toolbar" method="get">
  <input type="text" name="q" placeholder="<?= e(__('admin.products.search_placeholder')) ?>" value="<?= e($search) ?>">
  <button class="btn btn-secondary" type="submit"><?= e(__('common.search')) ?></button>
</form>

<?php /* The gross-price and tax-rate columns only make sense when VAT is charged at all (see vatIsEnabled()) - some shops legally don't charge VAT, so those two columns (and their matching <td>s per row below, plus the colspan on the empty-state row) are entirely omitted rather than shown empty. */ ?>
<table class="data-table">
  <thead><tr><th></th><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('admin.dashboard.sku')) ?></th><th><?= e(__('admin.products.category')) ?></th><th><?= e(__('admin.products.price_net')) ?></th><?php if (vatIsEnabled()): ?><th><?= e(__('admin.products.price_gross')) ?></th><th><?= e(__('admin.products.tax')) ?></th><?php endif; ?><th><?= e(__('admin.products.discount')) ?></th><th><?= e(__('admin.products.availability')) ?></th><th><?= e(__('admin.dashboard.stock')) ?></th><th><?= e(__('common.status')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($products as $p): ?>
    <?php /* getActiveDiscount() returns the discount actually in effect RIGHT NOW (respecting any scheduled start/end dates via Services\DiscountCalculator), or null - a product can have a discount configured but not currently active (e.g. scheduled for the future, or already expired), which is why the badge logic below checks discount_type separately from this. */ ?>
    <?php $discount = getActiveDiscount($p); ?>
    <tr>
      <?php /* getPrimaryImage() prefers an admin-cropped image over the raw upload, and falls back to a placeholder graphic if the product has no image at all - see src/view-helpers.php. */ ?>
      <td><img src="<?= e(getPrimaryImage($p)) ?>" alt=""></td>
      <td><a href="<?= rtrim(SITE_URL, '/') ?>/admin/products/<?= (int)$p['id'] ?>/edit"><?= e($p['name']) ?></a></td>
      <td><?= e($p['sku']) ?></td>
      <td><?= e($p['category_name'] ?? '-') ?></td>
      <td><?= formatPrice((float)$p['price']) ?></td>
      <?php if (vatIsEnabled()): ?>
        <?php /* getGrossPrice() adds this product's tax rate on top of the net price stored on it - what the customer would actually pay. */ ?>
        <td><?= formatPrice(getGrossPrice($p)) ?></td>
        <td><?= $p['tax_rate_name'] ? e($p['tax_rate_name']) . ' (' . e($p['tax_rate_percent']) . '%)' : '&mdash;' ?></td>
      <?php endif; ?>
      <td>
        <?php /* Three possible states: a discount is live right now (green-ish badge with its label); a discount is configured but not currently active, i.e. scheduled/expired (amber badge); or there's no discount at all (dash). */ ?>
        <?php if ($discount): ?>
          <span class="badge badge-processing"><?= e($discount['label']) ?></span>
        <?php elseif ($p['discount_type'] !== 'none'): ?>
          <span class="badge badge-pending"><?= e(__('admin.products.scheduled_expired')) ?></span>
        <?php else: ?>
          &mdash;
        <?php endif; ?>
      </td>
      <td>
        <?php /* A product can have an optional available_from/available_until window (e.g. a seasonal item). isRowCurrentlyAvailable() checks that window against the current time; the elseif then distinguishes "currently active but time-limited" from "no window at all, always available" for the badge text. */ ?>
        <?php if (!\ShopRex\Models\Product::isRowCurrentlyAvailable($p)): ?>
          <span class="badge badge-cancelled"><?= e(__('admin.products.outside_window')) ?></span>
        <?php elseif ($p['available_from'] || $p['available_until']): ?>
          <span class="badge badge-completed"><?= e(__('admin.products.active_windowed')) ?></span>
        <?php else: ?>
          <?= e(__('admin.products.always')) ?>
        <?php endif; ?>
      </td>
      <td><?= $p['stock_quantity'] <= $p['stock_threshold'] ? '<span class="badge badge-low">' . (int)$p['stock_quantity'] . '</span>' : (int)$p['stock_quantity'] ?></td>
      <td><span class="badge badge-<?= $p['status'] === 'active' ? 'completed' : 'pending' ?>"><?= e($p['status']) ?></span></td>
      <td>
        <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/products/<?= (int)$p['id'] ?>/edit"><?= e(__('common.edit')) ?></a>
        <form method="post" action="<?= rtrim(SITE_URL, '/') ?>/admin/products/delete" style="display:inline;" data-confirm="<?= e(__('admin.products.confirm_delete')) ?>">
          <?= csrfField() ?>
          <input type="hidden" name="delete_id" value="<?= (int)$p['id'] ?>">
          <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  <?php /* colspan must match however many <th> columns are actually rendered above - 12 with the VAT columns shown, 10 without - otherwise the empty-state message wouldn't span the full table width. */ ?>
  <?php if (empty($products)): ?><tr><td colspan="<?= vatIsEnabled() ? 12 : 10 ?>"><?= e(__('admin.products.none_found')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
