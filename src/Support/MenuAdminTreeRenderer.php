<?php

namespace ShopRex\Support;

/**
 * Direct port of renderMenuAdminTree() from admin/menus.php - recursive
 * HTML renderer for the sortable admin menu tree. Each nesting level gets
 * its own jQuery UI Sortable list (scoped to that parent's children only),
 * so dragging can only reorder siblings, never re-parent.
 *
 * In plain terms: this draws the nested, drag-to-reorder list of menu items
 * on the Admin -> Menus screen. It's recursive because a menu item can have
 * child items (a dropdown), which can themselves be reordered independently
 * of their siblings at other levels - render() calls itself for each node's
 * children.
 */
final class MenuAdminTreeRenderer
{
    /**
     * Echoes the full nested <ul> menu-tree HTML for one menu $location
     * (e.g. 'header' or 'footer'), given the already-built tree of $nodes
     * (each with a 'children' array - see Services\MenuTreeService for how
     * the flat DB rows get turned into this nested shape). Recurses into
     * each node's children to render sub-menus.
     */
    public static function render(array $nodes, string $location): void
    {
        // Nothing to show yet for this location - render a friendly empty
        // state instead of an empty <ul>.
        if (empty($nodes)) {
            echo '<p style="color:var(--color-muted);">' . e(__('admin.menus.no_items_yet')) . '</p>';
            return;
        }
        // data-location lets the page's jQuery UI Sortable script know which
        // menu location this list belongs to, so drag-reordering one
        // location's items posts back with the right location.
        echo '<ul class="menu-sortable" data-location="' . e($location) . '">';
        foreach ($nodes as $node) {
            $children = $node['children'] ?? [];
            echo '<li data-id="' . (int)$node['id'] . '">';
            echo '<div class="menu-item-row">';
            echo '<span class="drag-handle">&#10021;</span>';
            echo '<span class="label">' . e($node['label']);
            echo ' <small style="color:var(--color-muted);">(' . e($node['link_type']) . ')</small>';
            if (!$node['is_active']) {
                echo ' <span class="badge badge-cancelled">' . e(__('admin.menus.inactive')) . '</span>';
            }
            echo '</span>';
            echo '<a class="btn btn-sm btn-secondary" href="' . rtrim(SITE_URL, '/') . '/admin/menus?edit=' . (int)$node['id'] . '">' . e(__('common.edit')) . '</a>';
            // A tiny standalone delete form per row (rather than one shared
            // form for the whole page) so each row's delete button can post
            // just that row's id independently, with its own CSRF token and
            // JS confirm-dialog prompt (data-confirm, wired up by the page's
            // JS to intercept submission and ask before deleting).
            echo '<form method="post" action="' . rtrim(SITE_URL, '/') . '/admin/menus" style="display:inline;" data-confirm="' . e(__('admin.menus.confirm_delete', ['label' => $node['label']])) . '">';
            echo csrfField();
            echo '<input type="hidden" name="delete_id" value="' . (int)$node['id'] . '">';
            echo '<button class="btn btn-sm btn-danger" type="submit">' . e(__('common.delete')) . '</button>';
            echo '</form>';
            echo '</div>';
            if ($children) {
                // Recurse to render this node's own children as a nested
                // sortable list, scoped separately so dragging a child only
                // reorders it among its siblings, never moves it to a
                // different parent.
                self::render($children, $location);
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}
