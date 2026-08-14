<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('products');

$imageId = (int)($_GET['image_id'] ?? $_POST['image_id'] ?? 0);
$stmt = db()->prepare(
    'SELECT pi.*, p.name AS product_name FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE pi.id = ?'
);
$stmt->execute([$imageId]);
$image = $stmt->fetch();

if (!$image) {
    setFlash('error', __('admin.image_crop.not_found'));
    redirect(rtrim(SITE_URL, '/') . '/admin/products.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();

    if (!ImageProcessor::isSupported()) {
        $errors[] = __('admin.image_crop.gd_unavailable');
    } else {
        $x = (int)round((float)($_POST['crop_x'] ?? 0));
        $y = (int)round((float)($_POST['crop_y'] ?? 0));
        $w = (int)round((float)($_POST['crop_w'] ?? 0));
        $h = (int)round((float)($_POST['crop_h'] ?? 0));
        $targetW = max(1, (int)($_POST['target_width'] ?? $w));
        $targetH = max(1, (int)($_POST['target_height'] ?? $h));

        if ($w <= 0 || $h <= 0) {
            $errors[] = __('admin.image_crop.selection_required');
        }

        if (!$errors) {
            try {
                $sourcePath = UPLOAD_DIR . $image['image_path'];
                $basename = 'product-' . $image['product_id'] . '-' . $image['id'] . '-cropped-' . time();
                $newFile = ImageProcessor::cropAndSave($sourcePath, $x, $y, $w, $h, $targetW, $targetH, $basename);

                // Clean up the previous cropped derivative, if any.
                if (!empty($image['cropped_path']) && is_file(UPLOAD_DIR . $image['cropped_path'])) {
                    @unlink(UPLOAD_DIR . $image['cropped_path']);
                }

                $update = db()->prepare(
                    'UPDATE product_images SET cropped_path=?, crop_x=?, crop_y=?, crop_width=?, crop_height=? WHERE id=?'
                );
                $update->execute([$newFile, $x, $y, $w, $h, $imageId]);

                setFlash('success', __('admin.image_crop.flash_cropped'));
                redirect(rtrim(SITE_URL, '/') . '/admin/product_images.php?product_id=' . $image['product_id']);
            } catch (Throwable $e) {
                $errors[] = __('admin.image_crop.crop_error', ['message' => $e->getMessage()]);
            }
        }
    }
}

$pageTitle = __('admin.image_crop.title');
require __DIR__ . '/includes/header.php';
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/cropperjs@1.6.2/dist/cropper.min.css">

<div class="page-header">
  <h1><?= e(__('admin.image_crop.title')) ?></h1>
  <a class="btn btn-secondary" href="product_images.php?product_id=<?= (int)$image['product_id'] ?>">&larr; <?= e(__('admin.image_crop.back_to_images')) ?></a>
</div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <p><strong><?= e($image['product_name']) ?></strong></p>
  <div style="max-width:720px;">
    <img id="cropTarget" src="<?= e(UPLOAD_URL . $image['image_path']) ?>" style="max-width:100%;display:block;">
  </div>

  <form method="post" id="cropForm" style="margin-top:20px;">
    <?= csrfField() ?>
    <input type="hidden" name="image_id" value="<?= (int)$image['id'] ?>">
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

<?php require __DIR__ . '/includes/footer.php'; ?>
