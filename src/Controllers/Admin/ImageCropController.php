<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/** Direct port of admin/image_crop.php. */
final class ImageCropController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    public function edit(Request $request): Response
    {
        return $this->form($request, []);
    }

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $imageId = (int)$request->routeParam('id', 0);
        $image = $this->fetchImage($imageId);
        if (!$image) {
            $this->flash('error', __('admin.image_crop.not_found'));
            return $this->redirect('/admin/products');
        }

        $errors = [];
        if (!\ImageProcessor::isSupported()) {
            $errors[] = __('admin.image_crop.gd_unavailable');
        } else {
            $x = (int)round((float)$request->post('crop_x', 0));
            $y = (int)round((float)$request->post('crop_y', 0));
            $w = (int)round((float)$request->post('crop_w', 0));
            $h = (int)round((float)$request->post('crop_h', 0));
            $targetW = max(1, (int)$request->post('target_width', $w));
            $targetH = max(1, (int)$request->post('target_height', $h));

            if ($w <= 0 || $h <= 0) {
                $errors[] = __('admin.image_crop.selection_required');
            }

            if (!$errors) {
                try {
                    $sourcePath = UPLOAD_DIR . $image['image_path'];
                    $basename = 'product-' . $image['product_id'] . '-' . $image['id'] . '-cropped-' . time();
                    $newFile = \ImageProcessor::cropAndSave($sourcePath, $x, $y, $w, $h, $targetW, $targetH, $basename);

                    if (!empty($image['cropped_path']) && is_file(UPLOAD_DIR . $image['cropped_path'])) {
                        @unlink(UPLOAD_DIR . $image['cropped_path']);
                    }

                    $this->pdo->prepare('UPDATE product_images SET cropped_path=?, crop_x=?, crop_y=?, crop_width=?, crop_height=? WHERE id=?')
                        ->execute([$newFile, $x, $y, $w, $h, $imageId]);

                    $this->flash('success', __('admin.image_crop.flash_cropped'));
                    return $this->redirect('/admin/products/' . $image['product_id'] . '/images');
                } catch (\Throwable $e) {
                    $errors[] = __('admin.image_crop.crop_error', ['message' => $e->getMessage()]);
                }
            }
        }

        return $this->form($request, $errors);
    }

    private function form(Request $request, array $errors): Response
    {
        $imageId = (int)$request->routeParam('id', 0);
        $image = $this->fetchImage($imageId);
        if (!$image) {
            $this->flash('error', __('admin.image_crop.not_found'));
            return $this->redirect('/admin/products');
        }

        return $this->render('products/image_crop', compact('image', 'errors') + ['pageTitle' => __('admin.image_crop.title')]);
    }

    private function fetchImage(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT pi.*, p.name AS product_name FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE pi.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
