<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\MenuTreeService;

/** Direct port of admin/menus.php + admin/menu_reorder.php. */
final class MenuAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;
    private readonly MenuTreeService $menus;
    private readonly CategoryTreeService $categories;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->menus = $container->make(MenuTreeService::class);
        $this->categories = $container->make(CategoryTreeService::class);
    }

    public function index(Request $request): Response
    {
        $errors = [];
        $editId = $request->get('edit') !== null ? (int)$request->get('edit') : null;
        $editItem = null;
        if ($editId) {
            $stmt = $this->pdo->prepare('SELECT * FROM menu_items WHERE id = ?');
            $stmt->execute([$editId]);
            $editItem = $stmt->fetch();
            if (!$editItem) {
                $this->flash('error', __('admin.menus.not_found'));
                return $this->redirect('/admin/menus');
            }
        }

        $activeLocation = $request->get('location', $editItem['location'] ?? 'main');
        $activeLocation = in_array($activeLocation, ['main', 'footer'], true) ? $activeLocation : 'main';

        $categories = $this->categories->flatten($this->categories->tree());
        $pages = $this->pdo->query('SELECT slug, title FROM pages ORDER BY title')->fetchAll();
        $menuTree = $this->menus->tree($activeLocation);
        // CategoryTreeService::flatten() is structurally generic (only
        // needs a node's children[]/depth), reused here for the menu
        // tree's own parent-item picker rather than duplicating the
        // recursion.
        $menuTreeFlat = $this->categories->flatten($menuTree);

        return $this->render('menus/index', [...compact('errors', 'editItem', 'activeLocation', 'categories', 'pages', 'menuTree', 'menuTreeFlat'), 'pageTitle' => __('admin.menus')]);
    }

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        if ($request->post('delete_id') !== null) {
            $this->pdo->prepare('DELETE FROM menu_items WHERE id = ?')->execute([(int)$request->post('delete_id')]);
            $this->flash('success', __('admin.menus.flash_deleted'));
            return $this->redirect('/admin/menus');
        }

        $errors = [];
        $id = $request->post('id') !== '' && $request->post('id') !== null ? (int)$request->post('id') : null;
        $location = in_array($request->post('location', ''), ['main', 'footer'], true) ? $request->post('location') : 'main';
        $label = trim((string)$request->post('label', ''));
        $linkType = in_array($request->post('link_type', ''), ['custom', 'category', 'page'], true) ? $request->post('link_type') : 'custom';
        $linkValue = trim((string)$request->post('link_value', ''));
        $parentId = $request->post('parent_id', '') !== '' ? (int)$request->post('parent_id') : null;
        $openNewTab = $request->post('open_new_tab') ? 1 : 0;
        $isActive = $request->post('is_active') ? 1 : 0;

        if ($label === '') {
            $errors[] = __('admin.menus.label_required');
        }
        if ($linkValue === '') {
            $errors[] = __('admin.menus.link_target_required');
        }
        if ($id && $parentId === $id) {
            $errors[] = __('admin.menus.cannot_be_own_parent');
        }
        if ($id && $parentId && $this->menus->isOrDescendant($id, $parentId)) {
            $errors[] = __('admin.menus.cannot_move_under_own_subitem');
        }
        if (!$errors && $parentId) {
            $parentStmt = $this->pdo->prepare('SELECT location FROM menu_items WHERE id = ?');
            $parentStmt->execute([$parentId]);
            $parentLocation = $parentStmt->fetchColumn();
            if ($parentLocation === false || $parentLocation !== $location) {
                $errors[] = __('admin.menus.parent_same_menu');
            }
        }

        if (!$errors) {
            if ($id) {
                $stmt = $this->pdo->prepare('UPDATE menu_items SET location=?, parent_id=?, label=?, link_type=?, link_value=?, open_new_tab=?, is_active=? WHERE id=?');
                $stmt->execute([$location, $parentId, $label, $linkType, $linkValue, $openNewTab, $isActive, $id]);
                $this->flash('success', __('admin.menus.flash_updated'));
            } else {
                $maxSortStmt = $this->pdo->prepare('SELECT COALESCE(MAX(sort_order),0) FROM menu_items WHERE location = ?');
                $maxSortStmt->execute([$location]);
                $maxSort = (int)$maxSortStmt->fetchColumn();
                $stmt = $this->pdo->prepare('INSERT INTO menu_items (location, parent_id, label, link_type, link_value, open_new_tab, is_active, sort_order) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([$location, $parentId, $label, $linkType, $linkValue, $openNewTab, $isActive, $maxSort + 1]);
                $this->flash('success', __('admin.menus.flash_added'));
            }
            return $this->redirect('/admin/menus?location=' . $location);
        }

        $editItem = [
            'id' => $id, 'location' => $location, 'parent_id' => $parentId, 'label' => $label,
            'link_type' => $linkType, 'link_value' => $linkValue, 'open_new_tab' => $openNewTab, 'is_active' => $isActive,
        ];
        $activeLocation = $location;
        $categories = $this->categories->flatten($this->categories->tree());
        $pages = $this->pdo->query('SELECT slug, title FROM pages ORDER BY title')->fetchAll();
        $menuTree = $this->menus->tree($activeLocation);
        $menuTreeFlat = $this->categories->flatten($menuTree);

        return $this->render('menus/index', [...compact('errors', 'editItem', 'activeLocation', 'categories', 'pages', 'menuTree', 'menuTreeFlat'), 'pageTitle' => __('admin.menus')]);
    }

    /**
     * AJAX endpoint for the jQuery UI Sortable lists - direct port of
     * admin/menu_reorder.php, including its inline auth/CSRF checks (JSON
     * 403, not the redirect-based denial the Router's ->capability() gate
     * would give) - this route is deliberately NOT capability-gated at the
     * router level, see src/routes/admin.php.
     */
    public function reorder(Request $request): Response
    {
        if (!AdminAuth::current() || !AdminAuth::can('menus')) {
            return Response::json(['success' => false, 'error' => 'Forbidden'], 403);
        }
        if (!$this->csrf->verify($request->post('csrf_token'))) {
            return Response::json(['success' => false, 'error' => 'Invalid CSRF token'], 403);
        }

        $location = in_array($request->post('location', ''), ['main', 'footer'], true) ? $request->post('location') : null;
        $ids = array_map('intval', (array)$request->post('ids', []));

        if (!$location || empty($ids)) {
            return Response::json(['success' => false, 'error' => 'Missing location or ids'], 400);
        }

        $stmt = $this->pdo->prepare('UPDATE menu_items SET sort_order = ? WHERE id = ? AND location = ?');
        foreach ($ids as $index => $id) {
            $stmt->execute([$index, $id, $location]);
        }

        return Response::json(['success' => true]);
    }
}
