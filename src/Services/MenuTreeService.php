<?php

namespace ShopRex\Services;

/**
 * Direct port of the menu-tree functions in includes/functions.php
 * (buildMenuTree/getMenuTree/getMenuItemDescendantIds/
 * isMenuItemOrDescendant/resolveMenuUrl). Kept array-based for the same
 * reason as CategoryTreeService - the storefront theme header/footer
 * templates (src/Views/storefront/theme/*) read this exact shape.
 */
final class MenuTreeService
{
    // Memoizes tree() per location ('main'/'footer') for the rest of the
    // request, since header/footer templates and admin menu screens can each
    // ask for the same location's tree more than once per request.
    private array $treeCache = [];

    public function __construct(private readonly \PDO $pdo, private readonly CategoryTreeService $categories)
    {
    }

    /** Turns a flat list of menu-item rows (each with a parent_id) into a nested tree, attaching each row's direct children under a 'children' key - same shape/logic as CategoryTreeService::buildTree(), kept separate because menu items and categories are different tables/entities. */
    public function buildTree(array $rows, ?int $parentId = null): array
    {
        $branch = [];
        foreach ($rows as $row) {
            $rowParent = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            if ($rowParent === $parentId) {
                $row['children'] = $this->buildTree($rows, (int)$row['id']);
                $branch[] = $row;
            }
        }
        return $branch;
    }

    /** Nested menu tree for a location ('main' or 'footer'), active items only. */
    public function tree(string $location): array
    {
        if (!isset($this->treeCache[$location])) {
            $stmt = $this->pdo->prepare('SELECT * FROM menu_items WHERE location = ? AND is_active = 1 ORDER BY sort_order, id');
            $stmt->execute([$location]);
            $this->treeCache[$location] = $this->buildTree($stmt->fetchAll());
        }
        return $this->treeCache[$location];
    }

    /** Every menu-item ID in $itemId's subtree, including itself - e.g. used to stop an admin from re-parenting a menu item underneath its own descendant (see isOrDescendant()). */
    public function descendantIds(int $itemId): array
    {
        $rows = $this->pdo->query('SELECT id, parent_id FROM menu_items')->fetchAll();
        // Same adjacency-map + work-queue walk as CategoryTreeService::descendantIds().
        $childrenOf = [];
        foreach ($rows as $row) {
            $parent = $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
            $childrenOf[$parent][] = (int)$row['id'];
        }
        $ids = [$itemId];
        $queue = [$itemId];
        while ($queue) {
            $current = array_pop($queue);
            foreach ($childrenOf[$current] ?? [] as $childId) {
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }
        return $ids;
    }

    /** True if $candidateParentId is $itemId itself or somewhere in its subtree - guards the admin "move menu item" UI against creating a parent/child cycle. */
    public function isOrDescendant(int $itemId, int $candidateParentId): bool
    {
        return in_array($candidateParentId, $this->descendantIds($itemId), true);
    }

    /** Works out the actual URL a menu item should link to, based on its link_type - this is the single place that keeps menu links in sync with the app's clean-URL scheme (see CLAUDE.md: no .php or ?query links anywhere). */
    public function resolveUrl(array $item): string
    {
        $base = rtrim(SITE_URL, '/');
        switch ($item['link_type']) {
            case 'category':
                // link_value for a 'category'-type menu item is a category
                // ID; the tree's already cached, so this doesn't add a
                // fresh query per menu item.
                $node = $this->categories->findNode($this->categories->tree(), (int)$item['link_value']);
                return $node ? $this->categories->urlFor($node) : $base . '/';
            case 'page':
                return $base . '/page/' . urlencode($item['link_value']);
            default: // custom
                // A "custom" link can be either a full external URL (kept
                // as-is) or a relative in-site path (prefixed with the site
                // base URL) - the regex distinguishes the two cases.
                $val = $item['link_value'];
                return preg_match('~^https?://~i', $val) ? $val : $base . '/' . ltrim($val, '/');
        }
    }
}
