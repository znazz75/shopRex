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
    private array $treeCache = [];

    public function __construct(private readonly \PDO $pdo, private readonly CategoryTreeService $categories)
    {
    }

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

    public function descendantIds(int $itemId): array
    {
        $rows = $this->pdo->query('SELECT id, parent_id FROM menu_items')->fetchAll();
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

    public function isOrDescendant(int $itemId, int $candidateParentId): bool
    {
        return in_array($candidateParentId, $this->descendantIds($itemId), true);
    }

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
                $val = $item['link_value'];
                return preg_match('~^https?://~i', $val) ? $val : $base . '/' . ltrim($val, '/');
        }
    }
}
