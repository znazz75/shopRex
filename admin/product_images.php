<?php
require_once __DIR__ . '/includes/bootstrap.php';
requireAdminPermission('products');

$productId = (int)($_GET['product_id'] ?? 0);
$stmt = db()->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$productId]);
$product = $stmt->fetch();
if (!$product) {
    setFlash('error', __('admin.product_edit.not_found'));
    redirect(rtrim(SITE_URL, '/') . '/admin/products.php');
}

$errors = [];

function nextImageSortOrder(int $productId): int
{
    $stmt = db()->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?');
    $stmt->execute([$productId]);
    return (int)$stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload') {
        if (empty($_FILES['image']['name']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            $errors[] = __('admin.product_images.choose_file');
        } else {
            $allowed = ['jpg' => true, 'jpeg' => true, 'png' => true, 'webp' => true, 'gif' => true];
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (!isset($allowed[$ext]) || $_FILES['image']['size'] > 8 * 1024 * 1024) {
                $errors[] = __('admin.product_images.file_requirements');
            } else {
                if (!is_dir(UPLOAD_DIR)) {
                    mkdir(UPLOAD_DIR, 0755, true);
                }
                $filename = 'product-' . $productId . '-' . uniqid() . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], UPLOAD_DIR . $filename)) {
                    $isFirst = nextImageSortOrder($productId) === 0;
                    $stmt = db()->prepare(
                        'INSERT INTO product_images (product_id, image_path, description, sort_order, is_primary) VALUES (?, ?, ?, ?, ?)'
                    );
                    $stmt->execute([$productId, $filename, trim($_POST['description'] ?? ''), nextImageSortOrder($productId), $isFirst ? 1 : 0]);
                    setFlash('success', __('admin.product_images.flash_uploaded'));
                } else {
                    $errors[] = __('admin.product_images.save_upload_error');
                }
            }
        }
    } elseif ($action === 'update_description') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $stmt = db()->prepare('UPDATE product_images SET description = ? WHERE id = ? AND product_id = ?');
        $stmt->execute([trim($_POST['description'] ?? ''), $imageId, $productId]);
        setFlash('success', __('admin.product_images.flash_description_updated'));
    } elseif ($action === 'set_primary') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        db()->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
        db()->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')->execute([$imageId, $productId]);
        setFlash('success', __('admin.product_images.flash_primary_updated'));
    } elseif ($action === 'delete') {
        $imageId = (int)($_POST['image_id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM product_images WHERE id = ? AND product_id = ?');
        $stmt->execute([$imageId, $productId]);
        $image = $stmt->fetch();
        if ($image) {
            foreach (array_filter([$image['image_path'], $image['cropped_path']]) as $file) {
                $path = UPLOAD_DIR . $file;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            db()->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
            if ((int)$image['is_primary'] === 1) {
                // Promote the next image (if any) to primary so the listing/cart never show a broken thumbnail.
                $next = db()->prepare('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort_order LIMIT 1');
                $next->execute([$productId]);
                if ($nextId = $next->fetchColumn()) {
                    db()->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')->execute([$nextId]);
                }
            }
            setFlash('success', __('admin.product_images.flash_deleted'));
        }
    }

    if (!$errors) {
        redirect(rtrim(SITE_URL, '/') . '/admin/product_images.php?product_id=' . $productId);
    }
}

$stmt = db()->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order');
$stmt->execute([$productId]);
$images = $stmt->fetchAll();

$pageTitle = __('admin.product_images.title', ['name' => $product['name']]);
require __DIR__ . '/includes/header.php';
?>

<div class="page-header">
  <h1><?= e(__('admin.product_images.heading', ['name' => $product['name']])) ?></h1>
  <a class="btn btn-secondary" href="product_edit.php?id=<?= (int)$productId ?>">&larr; <?= e(__('admin.product_images.back_to_product')) ?></a>
</div>
<?php foreach ($errors as $error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endforeach; ?>

<div class="card">
  <h2 style="margin-top:0;"><?= e(__('admin.product_images.upload_image')) ?></h2>
  <form method="post" enctype="multipart/form-data" class="form-grid">
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
        <img src="<?= e(getImageUrl($img)) ?>" alt="">
        <div class="image-manager-fields">
          <form method="post" class="inline-desc-form">
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
          <a class="btn btn-sm btn-secondary" href="image_crop.php?image_id=<?= (int)$img['id'] ?>"><?= e(__('admin.product_images.crop')) ?></a>
          <?php if (!$img['is_primary']): ?>
            <form method="post" style="display:inline;">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="set_primary">
              <input type="hidden" name="image_id" value="<?= (int)$img['id'] ?>">
              <button class="btn btn-sm btn-secondary" type="submit"><?= e(__('admin.product_images.set_primary')) ?></button>
            </form>
          <?php endif; ?>
          <form method="post" style="display:inline;" data-confirm="<?= e(__('admin.product_images.confirm_delete')) ?>">
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
        $.post('product_image_reorder.php', {
          csrf_token: <?= json_encode(csrfToken()) ?>,
          product_id: <?= (int)$productId ?>,
          ids: ids
        });
      }
    });
  });
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
