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

    public function __construct(
        private readonly \PDO $pdo,
        private readonly CategoryTreeService $categories,
        private readonly SettingsRepository $settings
    ) {
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

    /**
     * Nested menu tree for a location ('main' or 'footer'), active items
     * only. Labels here are always the DEFAULT language (the raw
     * menu_items.label column) - this is the method the ADMIN menu editor
     * uses (Controllers\Admin\MenuAdminController), where a translated
     * label would be actively wrong (an admin managing menus shouldn't see
     * labels shift depending on their own UI language). Storefront callers
     * that need a translated label should use translatedTree() below
     * instead.
     */
    public function tree(string $location): array
    {
        if (!isset($this->treeCache[$location])) {
            $stmt = $this->pdo->prepare('SELECT * FROM menu_items WHERE location = ? AND is_active = 1 ORDER BY sort_order, id');
            $stmt->execute([$location]);
            $this->treeCache[$location] = $this->buildTree($stmt->fetchAll());
        }
        return $this->treeCache[$location];
    }

    /**
     * Same tree as tree(), but with every node's 'label' overlaid with its
     * translation into $lang (falling back to the default-language label
     * where no translation exists yet) - for storefront consumers only
     * (see src/view-helpers.php's getMenuTree(), used by the header/footer
     * nav templates). Does not touch tree()'s own cache - this is a
     * translated copy built fresh from it each call. Mirrors
     * Services\CategoryTreeService::translatedTree() exactly.
     */
    public function translatedTree(string $location, ?string $lang = null): array
    {
        $lang = $lang ?? I18n::current();
        $flat = $this->flatten($this->tree($location));
        $this->overlayLabels($flat, $lang);

        foreach ($flat as &$row) {
            unset($row['depth']);
        }
        unset($row);
        return $this->buildTree($flat);
    }

    /** Collapses a nested tree back into a flat, depth-first list with a 'depth' field added to each row - same shape as CategoryTreeService::flatten(), used here only by translatedTree()'s overlay-then-rebuild step. */
    public function flatten(array $tree, int $depth = 0): array
    {
        $flat = [];
        foreach ($tree as $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);
            $node['depth'] = $depth;
            $flat[] = $node;
            if ($children) {
                $flat = array_merge($flat, $this->flatten($children, $depth + 1));
            }
        }
        return $flat;
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

    /**
     * Overlays each row's 'label' with its menu_item_translations.label
     * translation into $lang, in place - mirrors
     * Services\CategoryTreeService::overlayNames() exactly: a row is only
     * touched when a non-empty translation actually exists for it,
     * otherwise it keeps whatever label it already had (the
     * default-language menu_items.label column). A no-op when $lang is the
     * shop's own default language - the default language's label already
     * lives on the row itself, there's nothing to overlay.
     *
     * @param array $rows Flat list of menu-item rows, each needing an 'id' key - modified by reference.
     */
    public function overlayLabels(array &$rows, string $lang): void
    {
        if (!$rows || $lang === $this->settings->get('default_language', 'en')) {
            return;
        }

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT menu_item_id, label FROM menu_item_translations WHERE language = ? AND menu_item_id IN ($placeholders) AND label IS NOT NULL AND label != ''"
        );
        $stmt->execute([$lang, ...$ids]);
        $labelsByItemId = array_column($stmt->fetchAll(), 'label', 'menu_item_id');

        foreach ($rows as &$row) {
            if (!empty($labelsByItemId[$row['id']])) {
                $row['label'] = $labelsByItemId[$row['id']];
            }
        }
        unset($row);
    }

    /** Every language's label row for $itemId, keyed by language - for the admin edit form's per-language tabs. Mirrors Services\CategoryTreeService::translationsForCategory(). */
    public function translationsForMenuItem(int $itemId): array
    {
        $stmt = $this->pdo->prepare('SELECT language, label FROM menu_item_translations WHERE menu_item_id = ?');
        $stmt->execute([$itemId]);
        $byLang = [];
        foreach ($stmt->fetchAll() as $row) {
            $byLang[$row['language']] = $row;
        }
        return $byLang;
    }
}
