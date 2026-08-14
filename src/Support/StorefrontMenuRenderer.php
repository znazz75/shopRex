<?php

namespace ShopRex\Support;

/**
 * Recursive HTML renderer for the storefront's main nav (Bootstrap dropdowns)
 * and footer link list, used by the default and sidebar theme packages'
 * header.php/footer.php. Replaces the renderMainMenu()/renderMenuDropdownItems()/
 * renderFooterMenu() bare functions that used to be defined inline inside
 * includes/header.php/footer.php - same static-render-class convention as
 * Support\MenuAdminTreeRenderer on the admin side.
 */
final class StorefrontMenuRenderer
{
    /** Any depth of nesting (Admin -> Menus) renders as a Bootstrap dropdown. */
    public static function renderMain(array $nodes): void
    {
        foreach ($nodes as $node) {
            $children = $node['children'] ?? [];
            if ($children) {
                echo '<li class="nav-item dropdown">';
                echo '<a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">' . e($node['label']) . '</a>';
                echo '<ul class="dropdown-menu">';
                self::renderDropdownItems($children);
                echo '</ul></li>';
            } else {
                echo '<li class="nav-item"><a class="nav-link" href="' . e(resolveMenuUrl($node)) . '"' . ($node['open_new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($node['label']) . '</a></li>';
            }
        }
    }

    private static function renderDropdownItems(array $nodes): void
    {
        foreach ($nodes as $node) {
            $children = $node['children'] ?? [];
            echo '<li><a class="dropdown-item" href="' . e(resolveMenuUrl($node)) . '"' . ($node['open_new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($node['label']) . '</a></li>';
            if ($children) {
                foreach ($children as $child) {
                    echo '<li><a class="dropdown-item ps-4" href="' . e(resolveMenuUrl($child)) . '">' . e($child['label']) . '</a></li>';
                }
            }
        }
    }

    /** A footer item with children renders as its own labeled column instead (built inline by the caller), so only leaves are echoed here. */
    public static function renderFooter(array $nodes): void
    {
        foreach ($nodes as $node) {
            if (!empty($node['children'])) {
                continue;
            }
            echo '<li class="mb-2"><a class="link-light link-underline-opacity-0 link-underline-opacity-75-hover" href="' . e(resolveMenuUrl($node)) . '"' . ($node['open_new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($node['label']) . '</a></li>';
        }
    }

    /**
     * "Sidebar Filters" theme package's persistent category tree
     * (src/Views/storefront/theme/sidebar/home.php) - only expands a
     * branch that's either the active category itself or one of its
     * ancestors, so browsing stays a manageable size regardless of how
     * deep the catalog goes.
     */
    public static function renderSidebarCategoryTree(array $nodes, ?int $activeCategoryId, array $activeChainIds): void
    {
        echo '<ul class="sidebar-category-tree">';
        foreach ($nodes as $node) {
            $isActive = $activeCategoryId === (int)$node['id'];
            $isInChain = in_array((int)$node['id'], $activeChainIds, true);
            echo '<li>';
            echo '<a href="' . e(getCategoryUrl($node)) . '"'
                . ' class="' . ($isActive ? 'active' : '') . '">' . e($node['name']) . '</a>';
            if (!empty($node['children']) && ($isInChain || $isActive)) {
                self::renderSidebarCategoryTree($node['children'], $activeCategoryId, $activeChainIds);
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}
