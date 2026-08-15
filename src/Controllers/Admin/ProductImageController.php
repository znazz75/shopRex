<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/**
 * Manages a single product's photo gallery: uploading new images, editing
 * their captions, choosing which one is "primary" (shown first/used as
 * the thumbnail), deleting them, and drag-to-reorder. Direct port of
 * admin/product_images.php + admin/product_image_reorder.php, merged into
 * one controller because both legacy pages operated on the exact same
 * product_images table for the exact same product - keeping them together
 * avoids duplicating the "does this product exist" / "does this image
 * belong to this product" guard logic.
 */
final class ProductImageController extends AdminCrudController
{
    // Shared PDO connection - product_images rows are read/written directly here.
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

    /** Shows the image-management screen for one product: its existing images in display order. */
    public function index(Request $request): Response
    {
        $productId = (int)$request->routeParam('id', 0);
        $product = $this->fetchProduct($productId);
        if (!$product) {
            $this->flash('error', __('admin.product_edit.not_found'));
            return $this->redirect('/admin/products');
        }

        $errors = [];
        $stmt = $this->pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order');
        $stmt->execute([$productId]);
        $images = $stmt->fetchAll();

        $pageTitle = __('admin.product_images.title', ['name' => $product['name']]);
        return $this->render('products/images', compact('product', 'productId', 'images', 'errors', 'pageTitle'));
    }

    /** Single form-post handler for every image action (upload/rename/set-primary/delete) on one product - which branch runs depends on the submitted "action" field. */
    public function action(Request $request): Response
    {
        // Blocks a forged image-management submission (CSRF) - relevant here
        // since "upload" writes a file to disk and "delete" removes one - see
        // Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $productId = (int)$request->routeParam('id', 0);
        $product = $this->fetchProduct($productId);
        if (!$product) {
            $this->flash('error', __('admin.product_edit.not_found'));
            return $this->redirect('/admin/products');
        }

        $errors = [];
        $action = (string)$request->post('action', '');

        if ($action === 'upload') {
            $file = $request->file('image');
            // UPLOAD_ERR_OK means PHP actually received a complete file - any
            // other value (including "no file chosen") is rejected up front.
            if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = __('admin.product_images.choose_file');
            } else {
                $allowed = ['jpg' => true, 'jpeg' => true, 'png' => true, 'webp' => true, 'gif' => true];
                $allowedMimes = ['image/jpeg' => true, 'image/png' => true, 'image/webp' => true, 'image/gif' => true];
                $ext = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION));
                // Content-sniff, not just the extension - see
                // docs/SECURITY_AUDIT.md finding #6. getimagesize() only
                // succeeds and returns real image dimensions/mime info if the
                // file's actual bytes are a valid image, so a file merely
                // *named* "photo.jpg" that's actually a script can't pass this
                // check even though its extension looks fine. @ suppresses the
                // warning getimagesize() emits for a non-image file - the
                // failure is handled explicitly via the falsy return value instead.
                $imageInfo = @getimagesize($file['tmp_name']);
                if (!isset($allowed[$ext]) || !$imageInfo || !isset($allowedMimes[$imageInfo['mime']]) || $file['size'] > 8 * 1024 * 1024) {
                    $errors[] = __('admin.product_images.file_requirements');
                } else {
                    if (!is_dir(UPLOAD_DIR)) {
                        mkdir(UPLOAD_DIR, 0755, true);
                    }
                    // uniqid() in the filename avoids collisions between
                    // different uploads for the same product, and avoids using
                    // the attacker/user-supplied original filename directly on disk.
                    $filename = 'product-' . $productId . '-' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
                        // The very first image uploaded for a product automatically
                        // becomes its primary/thumbnail image, since otherwise a
                        // freshly-created product would show no image at all.
                        $isFirst = $this->nextImageSortOrder($productId) === 0;
                        $stmt = $this->pdo->prepare(
                            'INSERT INTO product_images (product_id, image_path, description, sort_order, is_primary) VALUES (?, ?, ?, ?, ?)'
                        );
                        $stmt->execute([$productId, $filename, trim((string)$request->post('description', '')), $this->nextImageSortOrder($productId), $isFirst ? 1 : 0]);
                        $this->flash('success', __('admin.product_images.flash_uploaded'));
                    } else {
                        $errors[] = __('admin.product_images.save_upload_error');
                    }
                }
            }
        } elseif ($action === 'update_description') {
            $imageId = (int)$request->post('image_id', 0);
            // AND product_id = ? scopes the update to an image that actually
            // belongs to this product - stops a crafted image_id from editing
            // (or, in the delete/set_primary branches below, deleting/promoting)
            // another product's image.
            $this->pdo->prepare('UPDATE product_images SET description = ? WHERE id = ? AND product_id = ?')
                ->execute([trim((string)$request->post('description', '')), $imageId, $productId]);
            $this->flash('success', __('admin.product_images.flash_description_updated'));
        } elseif ($action === 'set_primary') {
            $imageId = (int)$request->post('image_id', 0);
            // Two-step "only one primary image per product" swap: first clear
            // is_primary on every image for this product, then set it on just
            // the chosen one - simpler than a conditional UPDATE and the table
            // is small enough per product that this is cheap.
            $this->pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
            $this->pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')->execute([$imageId, $productId]);
            $this->flash('success', __('admin.product_images.flash_primary_updated'));
        } elseif ($action === 'delete') {
            $imageId = (int)$request->post('image_id', 0);
            $stmt = $this->pdo->prepare('SELECT * FROM product_images WHERE id = ? AND product_id = ?');
            $stmt->execute([$imageId, $productId]);
            $image = $stmt->fetch();
            if ($image) {
                // array_filter() drops empty/null values - cropped_path may be
                // null if this image was never cropped, so this only attempts
                // to delete files that actually exist as paths.
                foreach (array_filter([$image['image_path'], $image['cropped_path']]) as $file) {
                    $path = UPLOAD_DIR . $file;
                    if (is_file($path)) {
                        // @ suppresses a warning if the file was already missing
                        // (e.g. manually removed) - deletion should still proceed
                        // either way.
                        @unlink($path);
                    }
                }
                $this->pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
                // If the deleted image was the primary one, promote whichever
                // image is now first in sort order to primary - otherwise the
                // product would be left with no primary image at all.
                if ((int)$image['is_primary'] === 1) {
                    $next = $this->pdo->prepare('SELECT id FROM product_images WHERE product_id = ? ORDER BY sort_order LIMIT 1');
                    $next->execute([$productId]);
                    if ($nextId = $next->fetchColumn()) {
                        $this->pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ?')->execute([$nextId]);
                    }
                }
                $this->flash('success', __('admin.product_images.flash_deleted'));
            }
        }

        if (!$errors) {
            return $this->redirect('/admin/products/' . $productId . '/images');
        }

        // An error occurred (e.g. bad upload) - re-render the same page with the
        // current image list plus the error, instead of redirecting (which
        // would lose the error message).
        $stmt = $this->pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order');
        $stmt->execute([$productId]);
        $images = $stmt->fetchAll();
        $pageTitle = __('admin.product_images.title', ['name' => $product['name']]);
        return $this->render('products/images', compact('product', 'productId', 'images', 'errors', 'pageTitle'));
    }

    /** AJAX endpoint - JSON 403 on failure, matching the original's inline-check pattern (see MenuAdminController::reorder()). */
    public function reorder(Request $request): Response
    {
        // This route isn't declared with ->capability() in the route table (it's
        // an AJAX endpoint called via JS drag-and-drop, not a normal page
        // navigation), so the login+capability checks that would normally
        // happen in the Router are done by hand here instead, returning JSON
        // rather than redirecting to the login page (a redirect would be
        // useless/confusing to an AJAX caller).
        if (!AdminAuth::current() || !AdminAuth::can('products')) {
            return Response::json(['success' => false, 'error' => 'Forbidden'], 403);
        }
        // Same CSRF check as everywhere else, just verified manually and
        // returning JSON instead of using Controller::requireCsrf() (which
        // returns an HTML error response, wrong content-type for an AJAX call).
        if (!$this->csrf->verify($request->post('csrf_token'))) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $productId = (int)$request->post('product_id', 0);
        // array_map('intval', ...) coerces every submitted id to a real integer
        // up front, so nothing but plain ints ever reaches the SQL below.
        $ids = array_map('intval', (array)$request->post('ids', []));

        if (!$productId || empty($ids)) {
            return Response::json(['success' => false, 'error' => 'Missing product_id or ids'], 400);
        }

        // $index (0, 1, 2, ...) becomes each image's new sort_order, in the
        // order the frontend submitted the ids (i.e. the order the admin
        // dragged them into) - AND product_id = ? again scopes each update to
        // this product only, so an id belonging to a different product can't
        // be reordered via this endpoint.
        $stmt = $this->pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ? AND product_id = ?');
        foreach ($ids as $index => $id) {
            $stmt->execute([$index, $id, $productId]);
        }

        return Response::json(['success' => true]);
    }

    /** Works out the sort_order value a newly-uploaded image should get - one past whatever the current highest sort_order is (or 0 if this product has no images yet). */
    private function nextImageSortOrder(int $productId): int
    {
        // COALESCE(MAX(...), -1) + 1 : if there are no existing images, MAX()
        // returns NULL, so COALESCE substitutes -1 first, making the +1 come
        // out to 0 - the first image always gets sort_order 0.
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?');
        $stmt->execute([$productId]);
        return (int)$stmt->fetchColumn();
    }

    /** Looks up one product by id, or null if it doesn't exist. */
    private function fetchProduct(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
