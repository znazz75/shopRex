<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('products');

// Delete
if (isset($_POST['delete_id'])) {
    requireCsrf();
    db()->prepare('DELETE FROM products WHERE id = ?')->execute([(int)$_POST['delete_id']]);
    setFlash('success', __('admin.products.flash_deleted'));
    redirect(rtrim(SITE_URL, '/') . '/admin/products.php');
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT p.*, c.name AS category_name, tr.name AS tax_rate_name, tr.rate AS tax_rate_percent,
               (SELECT image_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_image,
               (SELECT cropped_path FROM product_images WHERE product_id = p.id ORDER BY is_primary DESC, sort_order ASC LIMIT 1) AS primary_cropped_image
        FROM products p LEFT JOIN categories c ON c.id = p.category_id LEFT JOIN tax_rates tr ON tr.id = p.tax_rate_id";
$params = [];
if ($search !== '') {
    $sql .= ' WHERE p.name LIKE ? OR p.sku LIKE ?';
    $params = ["%$search%", "%$search%"];
}
$sql .= ' ORDER BY p.created_at DESC';
$stmt = db()->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = __('admin.products');
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><?= e(__('admin.products')) ?></h1>
  <a class="btn" href="product_edit.php">+ <?= e(__('admin.products.add')) ?></a>
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
      <td><a href="product_edit.php?id=<?= (int)$p['id'] ?>"><?= e($p['name']) ?></a></td>
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
        <?php if (!isProductCurrentlyAvailable($p)): ?>
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
        <a class="btn btn-sm btn-secondary" href="product_edit.php?id=<?= (int)$p['id'] ?>"><?= e(__('common.edit')) ?></a>
        <form method="post" style="display:inline;" data-confirm="<?= e(__('admin.products.confirm_delete')) ?>">
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

<?php require __DIR__ . '/includes/footer.php'; ?>
