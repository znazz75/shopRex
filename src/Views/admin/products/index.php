<?php
/**
 * @var string $search
 * @var array $products
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

<table class="data-table">
  <thead><tr><th></th><th><?= e(__('admin.products.name')) ?></th><th><?= e(__('admin.dashboard.sku')) ?></th><th><?= e(__('admin.products.category')) ?></th><th><?= e(__('admin.products.price_net')) ?></th><?php if (vatIsEnabled()): ?><th><?= e(__('admin.products.price_gross')) ?></th><th><?= e(__('admin.products.tax')) ?></th><?php endif; ?><th><?= e(__('admin.products.discount')) ?></th><th><?= e(__('admin.products.availability')) ?></th><th><?= e(__('admin.dashboard.stock')) ?></th><th><?= e(__('common.status')) ?></th><th></th></tr></thead>
  <tbody>
  <?php foreach ($products as $p): ?>
    <?php $discount = getActiveDiscount($p); ?>
    <tr>
      <td><img src="<?= e(getPrimaryImage($p)) ?>" alt=""></td>
      <td><a href="<?= rtrim(SITE_URL, '/') ?>/admin/products/<?= (int)$p['id'] ?>/edit"><?= e($p['name']) ?></a></td>
      <td><?= e($p['sku']) ?></td>
      <td><?= e($p['category_name'] ?? '-') ?></td>
      <td><?= formatPrice((float)$p['price']) ?></td>
      <?php if (vatIsEnabled()): ?>
        <td><?= formatPrice(getGrossPrice($p)) ?></td>
        <td><?= $p['tax_rate_name'] ? e($p['tax_rate_name']) . ' (' . e($p['tax_rate_percent']) . '%)' : '&mdash;' ?></td>
      <?php endif; ?>
      <td>
        <?php if ($discount): ?>
          <span class="badge badge-processing"><?= e($discount['label']) ?></span>
        <?php elseif ($p['discount_type'] !== 'none'): ?>
          <span class="badge badge-pending"><?= e(__('admin.products.scheduled_expired')) ?></span>
        <?php else: ?>
          &mdash;
        <?php endif; ?>
      </td>
      <td>
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
  <?php if (empty($products)): ?><tr><td colspan="<?= vatIsEnabled() ? 12 : 10 ?>"><?= e(__('admin.products.none_found')) ?></td></tr><?php endif; ?>
  </tbody>
</table>
