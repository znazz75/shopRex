<?php
/**
 * @var array $image
 * @var array $errors
 */
$saveUrl = rtrim(SITE_URL, '/') . '/admin/images/' . (int)$image['id'] . '/crop';
?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">

<div class="page-header">
  <h1><?= e(__('admin.image_crop.title')) ?></h1>
  <a class="btn btn-secondary" href="<?= rtrim(SITE_URL, '/') ?>/admin/products/<?= (int)$image['product_id'] ?>/images">&larr; <?= e(__('admin.image_crop.back_to_images')) ?></a>
</div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <p><strong><?= e($image['product_name']) ?></strong></p>
  <div style="max-width:720px;">
    <img id="cropTarget" src="<?= e(UPLOAD_URL . $image['image_path']) ?>" style="max-width:100%;display:block;">
  </div>

  <form method="post" action="<?= e($saveUrl) ?>" id="cropForm" style="margin-top:20px;">
    <?= csrfField() ?>
    <input type="hidden" name="crop_x" id="crop_x">
    <input type="hidden" name="crop_y" id="crop_y">
    <input type="hidden" name="crop_w" id="crop_w">
    <input type="hidden" name="crop_h" id="crop_h">

    <div class="form-grid">
      <div class="form-group">
        <label for="target_width"><?= e(__('admin.image_crop.output_width')) ?></label>
        <input type="number" id="target_width" name="target_width" min="1" value="<?= (int)($image['crop_width'] ?: 800) ?>">
      </div>
      <div class="form-group">
        <label for="target_height"><?= e(__('admin.image_crop.output_height')) ?></label>
        <input type="number" id="target_height" name="target_height" min="1" value="<?= (int)($image['crop_height'] ?: 800) ?>">
      </div>
    </div>
    <p style="color:var(--color-muted);font-size:13px;"><?= e(__('admin.image_crop.drag_hint')) ?></p>
    <button class="btn" type="submit"><?= e(__('admin.image_crop.save_crop')) ?></button>
  </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.js"></script>
<script>
  var image = document.getElementById('cropTarget');
  var cropper = new Cropper(image, {
    viewMode: 1,
    autoCropArea: 0.9,
    background: false,
    <?php if ($image['crop_width'] && $image['crop_height']): ?>
    ready: function () {
      cropper.setData({
        x: <?= (int)$image['crop_x'] ?>,
        y: <?= (int)$image['crop_y'] ?>,
        width: <?= (int)$image['crop_width'] ?>,
        height: <?= (int)$image['crop_height'] ?>
      });
    }
    <?php endif; ?>
  });

  var selectionRequiredMsg = <?= json_encode(__('admin.image_crop.selection_required')) ?>;
  document.getElementById('cropForm').addEventListener('submit', function (e) {
    var data = cropper.getData(true);
    if (!data.width || !data.height) {
      e.preventDefault();
      alert(selectionRequiredMsg);
      return;
    }
    document.getElementById('crop_x').value = data.x;
    document.getElementById('crop_y').value = data.y;
    document.getElementById('crop_w').value = data.width;
    document.getElementById('crop_h').value = data.height;
  });
</script>
