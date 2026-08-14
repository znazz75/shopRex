<?php

namespace ShopRex\Support;

/**
 * Direct port of renderMenuAdminTree() from admin/menus.php - recursive
 * HTML renderer for the sortable admin menu tree. Each nesting level gets
 * its own jQuery UI Sortable list (scoped to that parent's children only),
 * so dragging can only reorder siblings, never re-parent.
 */
final class MenuAdminTreeRenderer
{
    public static function render(array $nodes, string $location): void
    {
        if (empty($nodes)) {
            echo '<p style="color:var(--color-muted);">' . e(__('admin.menus.no_items_yet')) . '</p>';
            return;
        }
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
            echo '<form method="post" action="' . rtrim(SITE_URL, '/') . '/admin/menus" style="display:inline;" data-confirm="' . e(__('admin.menus.confirm_delete', ['label' => $node['label']])) . '">';
            echo csrfField();
            echo '<input type="hidden" name="delete_id" value="' . (int)$node['id'] . '">';
            echo '<button class="btn btn-sm btn-danger" type="submit">' . e(__('common.delete')) . '</button>';
            echo '</form>';
            echo '</div>';
            if ($children) {
                self::render($children, $location);
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}
