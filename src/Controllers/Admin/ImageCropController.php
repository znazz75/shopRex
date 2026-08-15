<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\ImageProcessor;

/**
 * Direct port of admin/image_crop.php. Lets an admin crop one product
 * image to a chosen rectangle/target size (e.g. to make a square thumbnail
 * out of a wider photo) using Services\ImageProcessor (GD-based).
 * The crop is saved as a second file alongside the original - the original
 * upload is never overwritten, so re-cropping or reverting is non-destructive.
 */
final class ImageCropController extends AdminCrudController
{
    /** Raw database handle for this controller's queries against `product_images`. */
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** GET /admin/products/images/{id}/crop - shows the crop tool for one product image. */
    public function edit(Request $request): Response
    {
        return $this->form($request, []);
    }

    /** Applies the submitted crop rectangle to one product image, replacing any previous crop for that image. */
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
        // GD (PHP's image extension) might not be installed on this
        // server - cropping is impossible without it, so this is checked
        // before touching any of the posted coordinates.
        if (!ImageProcessor::isSupported()) {
            $errors[] = __('admin.image_crop.gd_unavailable');
        } else {
            // Crop coordinates arrive as the browser's crop-tool
            // JavaScript reports them (which may include fractional
            // pixels) - round()ed to whole pixels since GD's crop
            // functions work in integer pixel coordinates.
            $x = (int)round((float)$request->post('crop_x', 0));
            $y = (int)round((float)$request->post('crop_y', 0));
            $w = (int)round((float)$request->post('crop_w', 0));
            $h = (int)round((float)$request->post('crop_h', 0));
            // Target (output) size defaults to the crop selection's own
            // size if none was specified - i.e. "just crop, don't resize"
            // - clamped to at least 1px so a stray 0/negative value can't
            // produce a broken zero-size image.
            $targetW = max(1, (int)$request->post('target_width', $w));
            $targetH = max(1, (int)$request->post('target_height', $h));

            if ($w <= 0 || $h <= 0) {
                $errors[] = __('admin.image_crop.selection_required');
            }

            if (!$errors) {
                try {
                    $sourcePath = UPLOAD_DIR . $image['image_path'];
                    // Filename includes product/image IDs plus a
                    // timestamp - guarantees a fresh, unique filename on
                    // every crop so browsers never serve a cached copy of
                    // a previous crop under the same URL.
                    $basename = 'product-' . $image['product_id'] . '-' . $image['id'] . '-cropped-' . time();
                    $newFile = ImageProcessor::cropAndSave($sourcePath, $x, $y, $w, $h, $targetW, $targetH, $basename);

                    // Only remove the OLD cropped file after the new one
                    // was successfully written above - avoids ending up
                    // with neither an old nor a new cropped image if
                    // something had failed mid-way.
                    if (!empty($image['cropped_path']) && is_file(UPLOAD_DIR . $image['cropped_path'])) {
                        @unlink(UPLOAD_DIR . $image['cropped_path']);
                    }

                    // crop_width/crop_height stay the SELECTION rectangle's
                    // own size; crop_target_width/height separately record
                    // what that selection was actually resized to, so
                    // reopening this tool later can pre-fill the "Output
                    // Width/Height" fields with the real previous output
                    // size instead of the selection size (see the view's
                    // docblock for why those aren't the same thing).
                    $this->pdo->prepare('UPDATE product_images SET cropped_path=?, crop_x=?, crop_y=?, crop_width=?, crop_height=?, crop_target_width=?, crop_target_height=? WHERE id=?')
                        ->execute([$newFile, $x, $y, $w, $h, $targetW, $targetH, $imageId]);

                    $this->flash('success', __('admin.image_crop.flash_cropped'));
                    return $this->redirect('/admin/products/' . $image['product_id'] . '/images');
                } catch (\Throwable $e) {
                    $errors[] = __('admin.image_crop.crop_error', ['message' => $e->getMessage()]);
                }
            }
        }

        return $this->form($request, $errors);
    }

    /** Renders the crop tool page for one image, given by the {id} route parameter - shared by edit() (no errors yet) and save() (re-shown with validation errors on a failed crop). */
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

    /** Looks up one product image row by ID, joined with its owning product's name (shown as a page heading/breadcrumb). */
    private function fetchImage(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT pi.*, p.name AS product_name FROM product_images pi JOIN products p ON p.id = pi.product_id WHERE pi.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
