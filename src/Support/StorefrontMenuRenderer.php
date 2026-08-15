<?php

namespace ShopRex\Support;

/**
 * Recursive HTML renderer for the storefront's main nav (Bootstrap dropdowns)
 * and footer link list, used by the default and sidebar theme packages'
 * header.php/footer.php. Replaces the renderMainMenu()/renderMenuDropdownItems()/
 * renderFooterMenu() bare functions that used to be defined inline inside
 * includes/header.php/footer.php - same static-render-class convention as
 * Support\MenuAdminTreeRenderer on the admin side.
 *
 * In plain terms: this class draws the actual <nav>/<footer> menu HTML that
 * visitors see on the storefront, built from whatever an admin configured
 * under Admin -> Menus. Splitting it out of header.php/footer.php means the
 * same rendering logic can be reused by every theme package's templates.
 */
final class StorefrontMenuRenderer
{
    /** Any depth of nesting (Admin -> Menus) renders as a Bootstrap dropdown. */
    public static function renderMain(array $nodes): void
    {
        foreach ($nodes as $node) {
            $children = $node['children'] ?? [];
            if ($children) {
                // Has sub-items -> render as a Bootstrap dropdown toggle
                // (href="#" since the label itself isn't a real link, just
                // something to click/hover to reveal the dropdown).
                echo '<li class="nav-item dropdown">';
                echo '<a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">' . e($node['label']) . '</a>';
                echo '<ul class="dropdown-menu">';
                self::renderDropdownItems($children);
                echo '</ul></li>';
            } else {
                // Leaf item -> a plain link. resolveMenuUrl() (see
                // src/view-helpers.php) figures out the actual href based on
                // this menu item's configured link type (product/category/
                // page/custom URL/...).
                echo '<li class="nav-item"><a class="nav-link" href="' . e(resolveMenuUrl($node)) . '"' . ($node['open_new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($node['label']) . '</a></li>';
            }
        }
    }

    /** Renders the <li> rows inside one open dropdown, including one extra level of nested (indented) sub-items - menus only support two visible levels in the main nav, deeper nesting isn't rendered here. */
    private static function renderDropdownItems(array $nodes): void
    {
        foreach ($nodes as $node) {
            $children = $node['children'] ?? [];
            echo '<li><a class="dropdown-item" href="' . e(resolveMenuUrl($node)) . '"' . ($node['open_new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($node['label']) . '</a></li>';
            if ($children) {
                // A dropdown item can itself have children - shown as
                // extra-indented (ps-4 = left-padding) items directly under
                // it rather than a further nested dropdown, keeping the
                // menu simple to navigate with a mouse.
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
                // Skip parent items entirely here - the calling template
                // renders each parent as its own footer column heading and
                // calls back in for just that parent's children, so
                // rendering the parent again here would duplicate it.
                continue;
            }
            echo '<li class="mb-2"><a class="link-light link-underline-opacity-0 link-underline-opacity-75-hover" href="' . e(resolveMenuUrl($node)) . '"' . ($node['open_new_tab'] ? ' target="_blank" rel="noopener"' : '') . '>' . e($node['label']) . '</a></li>';
        }
    }

    /**
     * Admin -> Legal Documents' entries (Controllers\Storefront\LegalDocumentController
     * serves each at /legal/{type}) - `type` is admin-defined free text, so
     * the storefront can't hardcode which ones exist; this just lists
     * whatever getLegalDocuments() currently finds. Rendered as its own
     * block rather than folded into renderFooter() since these come from
     * a different source table than menu_items, not because the markup
     * differs.
     */
    public static function renderFooterLegalDocuments(array $documents): void
    {
        foreach ($documents as $doc) {
            echo '<li class="mb-2"><a class="link-light link-underline-opacity-0 link-underline-opacity-75-hover" href="' . e(getLegalDocumentUrl($doc['type'])) . '">' . e($doc['title']) . '</a></li>';
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
            // "Active" = this exact category is the one currently being
            // browsed (gets the highlighted style); "in chain" = this
            // category is an ancestor of the active one, so its own
            // children should stay expanded to show the path down to it.
            $isActive = $activeCategoryId === (int)$node['id'];
            $isInChain = in_array((int)$node['id'], $activeChainIds, true);
            echo '<li>';
            echo '<a href="' . e(getCategoryUrl($node)) . '"'
                . ' class="' . ($isActive ? 'active' : '') . '">' . e($node['name']) . '</a>';
            // Only recurse into (i.e. render/expand) this branch's children
            // if it's on the path to (or is itself) the active category -
            // every other branch stays collapsed, keeping a deep catalog's
            // sidebar readable.
            if (!empty($node['children']) && ($isInChain || $isActive)) {
                self::renderSidebarCategoryTree($node['children'], $activeCategoryId, $activeChainIds);
            }
            echo '</li>';
        }
        echo '</ul>';
    }
}
