<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;

/** Direct port of admin/product_images.php + admin/product_image_reorder.php. */
final class ProductImageController extends AdminCrudController
{
    private readonly \PDO $pdo;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
    }

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

    public function action(Request $request): Response
    {
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
            if (empty($file['name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = __('admin.product_images.choose_file');
            } else {
                $allowed = ['jpg' => true, 'jpeg' => true, 'png' => true, 'webp' => true, 'gif' => true];
                $allowedMimes = ['image/jpeg' => true, 'image/png' => true, 'image/webp' => true, 'image/gif' => true];
                $ext = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION));
                // Content-sniff, not just the extension - see
                // docs/SECURITY_AUDIT.md finding #6.
                $imageInfo = @getimagesize($file['tmp_name']);
                if (!isset($allowed[$ext]) || !$imageInfo || !isset($allowedMimes[$imageInfo['mime']]) || $file['size'] > 8 * 1024 * 1024) {
                    $errors[] = __('admin.product_images.file_requirements');
                } else {
                    if (!is_dir(UPLOAD_DIR)) {
                        mkdir(UPLOAD_DIR, 0755, true);
                    }
                    $filename = 'product-' . $productId . '-' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], UPLOAD_DIR . $filename)) {
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
            $this->pdo->prepare('UPDATE product_images SET description = ? WHERE id = ? AND product_id = ?')
                ->execute([trim((string)$request->post('description', '')), $imageId, $productId]);
            $this->flash('success', __('admin.product_images.flash_description_updated'));
        } elseif ($action === 'set_primary') {
            $imageId = (int)$request->post('image_id', 0);
            $this->pdo->prepare('UPDATE product_images SET is_primary = 0 WHERE product_id = ?')->execute([$productId]);
            $this->pdo->prepare('UPDATE product_images SET is_primary = 1 WHERE id = ? AND product_id = ?')->execute([$imageId, $productId]);
            $this->flash('success', __('admin.product_images.flash_primary_updated'));
        } elseif ($action === 'delete') {
            $imageId = (int)$request->post('image_id', 0);
            $stmt = $this->pdo->prepare('SELECT * FROM product_images WHERE id = ? AND product_id = ?');
            $stmt->execute([$imageId, $productId]);
            $image = $stmt->fetch();
            if ($image) {
                foreach (array_filter([$image['image_path'], $image['cropped_path']]) as $file) {
                    $path = UPLOAD_DIR . $file;
                    if (is_file($path)) {
                        @unlink($path);
                    }
                }
                $this->pdo->prepare('DELETE FROM product_images WHERE id = ?')->execute([$imageId]);
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

        $stmt = $this->pdo->prepare('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order');
        $stmt->execute([$productId]);
        $images = $stmt->fetchAll();
        $pageTitle = __('admin.product_images.title', ['name' => $product['name']]);
        return $this->render('products/images', compact('product', 'productId', 'images', 'errors', 'pageTitle'));
    }

    /** AJAX endpoint - JSON 403 on failure, matching the original's inline-check pattern (see MenuAdminController::reorder()). */
    public function reorder(Request $request): Response
    {
        if (!AdminAuth::current() || !AdminAuth::can('products')) {
            return Response::json(['success' => false, 'error' => 'Forbidden'], 403);
        }
        if (!$this->csrf->verify($request->post('csrf_token'))) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $productId = (int)$request->post('product_id', 0);
        $ids = array_map('intval', (array)$request->post('ids', []));

        if (!$productId || empty($ids)) {
            return Response::json(['success' => false, 'error' => 'Missing product_id or ids'], 400);
        }

        $stmt = $this->pdo->prepare('UPDATE product_images SET sort_order = ? WHERE id = ? AND product_id = ?');
        foreach ($ids as $index => $id) {
            $stmt->execute([$index, $id, $productId]);
        }

        return Response::json(['success' => true]);
    }

    private function nextImageSortOrder(int $productId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM product_images WHERE product_id = ?');
        $stmt->execute([$productId]);
        return (int)$stmt->fetchColumn();
    }

    private function fetchProduct(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }
}
