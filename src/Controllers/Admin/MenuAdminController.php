<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Auth\AdminAuth;
use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\MenuTreeService;

/**
 * Direct port of admin/menus.php + admin/menu_reorder.php. Manages the two
 * navigation menus (main nav + footer) an admin builds out of items that
 * link to a category, a CMS page, or a custom URL - including nesting one
 * item under another and drag-and-drop reordering (reorder()). Exists as
 * its own controller rather than folding into CategoryAdminController/
 * PageAdminController because a menu item is its own entity with its own
 * tree structure, independent of the category/page tree it might point at.
 */
final class MenuAdminController extends AdminCrudController
{
    /** Raw database handle for this controller's queries against `menu_items`. */
    private readonly \PDO $pdo;
    /** Builds/queries the menu item tree (parent/child nesting) for one location ("main" or "footer"). */
    private readonly MenuTreeService $menus;
    /** The category tree service - reused here for its generic flatten() helper (see index()) and to populate the "link to category" picker. */
    private readonly CategoryTreeService $categories;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->menus = $container->make(MenuTreeService::class);
        $this->categories = $container->make(CategoryTreeService::class);
    }

    /** GET /admin/menus - lists one location's ("main"/"footer") menu tree and, if ?edit=id is set, pre-fills the edit form for that item. */
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

        // Which location's tab is showing - defaults to whatever location
        // the item being edited belongs to, or "main" if nothing's being
        // edited; then whitelisted to the only two valid locations either way.
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

    /** Handles both the delete button and the create/update form for a single menu item, all posted to the same route (distinguished by whether delete_id is present). */
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
        // Prevents creating a cycle: an item can't be moved to be a child
        // of one of its own descendants, since that would make the tree
        // unreachable/infinite when walked top-down.
        if ($id && $parentId && $this->menus->isOrDescendant($id, $parentId)) {
            $errors[] = __('admin.menus.cannot_move_under_own_subitem');
        }
        // A parent item must belong to the same menu location as the
        // child - stops an item from ending up nested under a parent that
        // only renders on the OTHER menu (main vs footer), where it would
        // never actually display.
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
                // New items are appended to the end of their location's
                // list - one more than the current highest sort_order
                // (COALESCE handles the "no items yet" case, where MAX()
                // would otherwise return null).
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

        // $ids arrives already in the new display order (the browser drag
        // handler sends the list top-to-bottom) - so each item's position
        // in the array IS its new sort_order, just written straight back
        // to the row. "AND location = ?" stops one drag operation from
        // being able to touch an item that belongs to the other menu.
        $stmt = $this->pdo->prepare('UPDATE menu_items SET sort_order = ? WHERE id = ? AND location = ?');
        foreach ($ids as $index => $id) {
            $stmt->execute([$index, $id, $location]);
        }

        return Response::json(['success' => true]);
    }
}
