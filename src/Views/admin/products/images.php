<?php
/**
 * @var array $product
 * @var int $productId
 * @var array $images
 * @var array $errors
 */
$base = rtrim(SITE_URL, '/') . '/admin/products/' . $productId . '/images';
?>
<div class="page-header">
  <h1><?= e(__('admin.product_images.heading', ['name' => $product['name']])) ?></h1>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/products/<?= (int)$productId ?>/edit">&larr; <?= e(__('admin.product_images.back_to_product')) ?></a>
</div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.product_images.upload_image')) ?></h2>
  <form method="post" action="<?= e($base) ?>" enctype="multipart/form-data" class="form-grid">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="upload">
    <div class="form-group"><label for="image"><?= e(__('admin.product_images.image_file')) ?></label><input type="file" id="image" name="image" accept="image/*" required></div>
    <div class="form-group"><label for="description"><?= e(__('admin.product_images.description_caption')) ?></label><input type="text" id="description" name="description" placeholder="<?= e(__('admin.product_images.gallery_placeholder')) ?>"></div>
    <div class="form-group" style="align-self:end;"><button class="btn" type="submit"><?= e(__('admin.product_images.upload')) ?></button></div>
  </form>
</div>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.product_images.images_count', ['n' => count($images)])) ?></h2>
  <p style="color:var(--color-muted);font-size:13px;">
    <?= str_replace('%icon%', '<i style="font-style:normal;">&#10021;</i>', e(__('admin.product_images.drag_hint'))) ?>
  </p>

  <ul id="imageSortable" class="image-manager-list">
    <?php foreach ($images as $img): ?>
      <li data-id="<?= (int)$img['id'] ?>" class="image-manager-row">
        <span class="drag-handle">&#10021;</span>
        <img src="<?= e(\ShopRex\Models\Product::imageUrl($img)) ?>" alt="">
        <div class="image-manager-fields">
          <form method="post" action="<?= e($base) ?>" class="inline-desc-form">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="update_description">
            <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
            <input type="text" name="description" value="<?= e($img['description'] ?? '') ?>" placeholder="<?= e(__('admin.product_images.description_caption')) ?>" onblur="this.form.requestSubmit()">
          </form>
          <div style="font-size:12px;color:var(--color-muted);">
            <?= $img['cropped_path'] ? e(__('admin.product_images.cropped_ready')) : e(__('admin.product_images.not_cropped_yet')) ?>
            <?php if ($img['is_primary']): ?> &middot; <strong><?= e(__('admin.product_images.primary')) ?></strong><?php endif; ?>
          </div>
        </div>
        <div class="image-manager-actions">
          <a class="btn btn-sm btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/images/<?= (int)$img['id'] ?>/crop"><?= e(__('admin.product_images.crop')) ?></a>
          <?php if (!$img['is_primary']): ?>
            <form method="post" action="<?= e($base) ?>" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="set_primary">
              <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
              <button class="btn btn-sm btn-secondary" type="submit"><?= e(__('admin.product_images.set_primary')) ?></button>
            </form>
          <?php endif; ?>
          <form method="post" action="<?= e($base) ?>" style="display:inline;" data-confirm="<?= e(__('admin.product_images.confirm_delete')) ?>">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
            <button class="btn btn-sm btn-danger" type="submit"><?= e(__('common.delete')) ?></button>
          </form>
        </div>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php if (empty($images)): ?><p style="color:var(--color-muted);"><?= e(__('admin.product_images.none_yet')) ?></p><?php endif; ?>
</div>

<style>
  .image-manager-list { list-style: none; margin: 0; padding: 0; }
  .image-manager-row { display: flex; align-items: center; gap: 14px; background: #fff; border: 1px solid var(--color-border); border-radius: 8px; padding: 10px 14px; margin-bottom: 8px; }
  .image-manager-row img { width: 64px; height: 64px; object-fit: cover; border-radius: 6px; flex-shrink: 0; }
  .image-manager-fields { flex: 1; }
  .image-manager-fields input[type="text"] { width: 100%; padding: 6px 8px; border: 1px solid var(--color-border); border-radius: 6px; }
  .image-manager-actions { display: flex; gap: 6px; flex-shrink: 0; }
  .drag-handle { cursor: grab; color: var(--color-muted); }
  .image-sortable-placeholder { border: 2px dashed var(--color-border); border-radius: 8px; height: 84px; margin-bottom: 8px; }
</style>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.13.2/jquery-ui.min.js"></script>
<script>
  $(function () {
    $('#imageSortable').sortable({
      handle: '.drag-handle',
      placeholder: 'image-sortable-placeholder',
      update: function () {
        var ids = $(this).children('li').map(function () { return $(this).data('id'); }).get();
        $.post('<?= rtrim(SITE_URL, '/') ?>/admin/products/<?= (int)$productId ?>/images/reorder', {
          csrf_token: <?= json_encode(csrfToken()) ?>,
          product_id: <?= (int)$productId ?>,
          ids: ids
        });
      }
    });
  });
</script>
