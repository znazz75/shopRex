<?php
/**
 * Admin -> Products -> Images -> Crop: interactive crop tool for one
 * product image, built on the third-party Cropper.js library (loaded from
 * a CDN below). The crop rectangle the admin drags out gets sent to the
 * server, which does the actual pixel-cropping/resizing server-side (see
 * Services\ImageProcessor) and stores the result as this image's
 * "cropped" version - the one products/images.php and getPrimaryImage()
 * prefer to show once it exists.
 *
 * @var array $image  The image being cropped - image_path (the original upload), product_id/product_name, any previously-saved crop_x/crop_y/crop_width/crop_height (the SELECTION rectangle from the last crop, so re-opening this tool restores the last selection instead of starting blank), and crop_target_width/crop_target_height (the OUTPUT size that selection was resized to last time - a separate pair of columns, since a selection's own size and what it was scaled to are two different things).
 * @var array $errors Validation error messages to show above the page.
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

  <?php /* crop_x/y/w/h start empty - JS fills them in right before submit (see the submit listener below) with whatever rectangle the admin actually selected in the Cropper.js widget; this form never has real values in these fields until that moment. */ ?>
  <form method="post" action="<?= e($saveUrl) ?>" id="cropForm" style="margin-top:20px;">
    <?= csrfField() ?>
    <input type="hidden" name="crop_x" id="crop_x">
    <input type="hidden" name="crop_y" id="crop_y">
    <input type="hidden" name="crop_w" id="crop_w">
    <input type="hidden" name="crop_h" id="crop_h">

    <?php /* Separate from the crop rectangle above: this is the final output image's pixel dimensions - the crop rectangle gets resized to exactly this size server-side (see ImageCropController). Pre-filled from crop_target_width/crop_target_height - the actual output size used last time - falling back to the selection size (crop_width/crop_height) and then a flat 800 default only when this image has never been cropped before at all. */ ?>
    <div class="form-grid">
      <div class="form-group">
        <label for="target_width"><?= e(__('admin.image_crop.output_width')) ?></label>
        <input type="number" id="target_width" name="target_width" min="1" value="<?= (int)($image['crop_target_width'] ?: $image['crop_width'] ?: 800) ?>">
      </div>
      <div class="form-group">
        <label for="target_height"><?= e(__('admin.image_crop.output_height')) ?></label>
        <input type="number" id="target_height" name="target_height" min="1" value="<?= (int)($image['crop_target_height'] ?: $image['crop_height'] ?: 800) ?>">
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
    <?php /* Only restore a previous selection when this image was actually cropped before (both dimensions present) - otherwise let Cropper.js pick its own default auto-crop-area, since there's no prior selection to restore. */ ?>
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
  // Right before the form submits, pull the crop rectangle's current
  // pixel coordinates out of the Cropper.js widget (getData(true) rounds
  // to whole pixels) and copy them into the hidden crop_x/y/w/h fields -
  // those fields are otherwise empty, this is the only place they get a
  // value. Blocks submission entirely if there's no selection at all
  // (width/height of 0), since the server can't crop nothing.
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
