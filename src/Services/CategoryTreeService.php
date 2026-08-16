<?php

namespace ShopRex\Services;

/**
 * Direct port of the category-tree functions in includes/functions.php
 * (buildCategoryTree/getCategoryTree/flattenCategoryTree/
 * getCategoryDescendantIds/getCategoryPath/findCategoryNode/
 * isCategoryOrDescendant/getCategoryIntroText). Operates on plain arrays
 * (row shape: id/parent_id/name/slug/... plus a children[] key once
 * treed) rather than a hydrated Category object, because its main
 * consumer is includes/home.php - a template deliberately NOT rewritten,
 * to preserve theme-package fidelity (see Core\Renderer's docblock) -
 * which reads exactly this array shape today and must keep doing so.
 */
final class CategoryTreeService
{
    // Memoizes tree() for the rest of the request - the category tree is
    // read repeatedly per request (menus, breadcrumbs, sidebars) but rarely
    // changes mid-request, so it's cheap to build once and reuse.
    private ?array $treeCache = null;

    public function __construct(private readonly \PDO $pdo, private readonly SettingsRepository $settings)
    {
    }

    /** Turns a flat list of category rows (each with a parent_id) into a nested tree, attaching each row's direct children under a 'children' key - the recursion walks down one parent_id level at a time. */
    public function buildTree(array $rows, ?int $parentId = null): array
    {
        $branch = [];
        foreach ($rows as $row) {
            $rowParent = $row['parent_id'] !== null ? (int)$row['parent_id'] : null;
            // Only rows whose parent_id matches the level we're currently
            // building belong in this branch; everything else gets picked up
            // by a deeper recursive call instead.
            if ($rowParent === $parentId) {
                $row['children'] = $this->buildTree($rows, (int)$row['id']);
                $branch[] = $row;
            }
        }
        return $branch;
    }

    /**
     * The full category tree, root categories first with their descendants
     * nested under 'children' - built once per request and cached. Names
     * here are always the DEFAULT language (the raw categories.name
     * column) - this is the method every ADMIN picker uses
     * (Controllers\Admin\CategoryAdminController/MenuAdminController/
     * ProductEditController), where showing a translated name would be
     * actively wrong (an admin managing categories shouldn't see names
     * shift depending on their own UI language). Storefront callers that
     * need a translated name should use translatedTree() below instead.
     */
    public function tree(): array
    {
        if ($this->treeCache === null) {
            $rows = $this->pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
            $this->treeCache = $this->buildTree($rows);
        }
        return $this->treeCache;
    }

    /**
     * Same tree as tree(), but with every node's 'name' overlaid with its
     * translation into $lang (falling back to the default-language name
     * where no translation exists yet) - for storefront consumers only
     * (the sidebar theme's nav widget, Controllers\Storefront\CatalogController's
     * subcategory-chips lookup). Does not touch tree()'s own cache - this
     * is a translated copy built fresh from it each call.
     */
    public function translatedTree(?string $lang = null): array
    {
        $lang = $lang ?? I18n::current();
        $flat = $this->flatten($this->tree());
        $this->overlayNames($flat, $lang);

        // Re-nest: flatten() already dropped 'children'/added 'depth', so
        // rebuild the tree structure from the (now-translated) flat list
        // rather than trying to walk the original nested tree and patch
        // names in place.
        foreach ($flat as &$row) {
            unset($row['depth']);
        }
        unset($row);
        return $this->buildTree($flat);
    }

    /** Collapses a nested tree back into a flat, depth-first list with a 'depth' field added to each row - used for rendering an indented <select> or admin list where nesting needs to show as indentation rather than actual nesting. */
    public function flatten(array $tree, int $depth = 0): array
    {
        $flat = [];
        foreach ($tree as $node) {
            $children = $node['children'] ?? [];
            unset($node['children']);
            $node['depth'] = $depth;
            $flat[] = $node;
            // Recurse into children before moving to the next sibling, so
            // the result is depth-first order (parent immediately followed
            // by its own subtree, not grouped by level).
            if ($children) {
                $flat = array_merge($flat, $this->flatten($children, $depth + 1));
            }
        }
        return $flat;
    }

    /** Every category ID in $categoryId's subtree, including itself - used to make "show products from this category and all its subcategories" filters work. */
    public function descendantIds(int $categoryId): array
    {
        $rows = $this->pdo->query('SELECT id, parent_id FROM categories')->fetchAll();
        // Builds a [parent_id => [child_id, child_id, ...]] adjacency map
        // once, so descendants can be walked in memory instead of running a
        // new query per level.
        $childrenOf = [];
        foreach ($rows as $row) {
            $parent = $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
            $childrenOf[$parent][] = (int)$row['id'];
        }

        // Breadth/depth-first walk (order doesn't matter here) using $queue
        // as a work list: start at $categoryId itself, and keep pulling
        // children off the queue until there are none left to explore.
        $ids = [$categoryId];
        $queue = [$categoryId];
        while ($queue) {
            $current = array_pop($queue);
            foreach ($childrenOf[$current] ?? [] as $childId) {
                $ids[] = $childId;
                $queue[] = $childId;
            }
        }
        return $ids;
    }

    /**
     * Ancestor chain from root down to (and including) $categoryId - for
     * breadcrumbs. Only ever called from storefront code
     * (Controllers\Storefront\CatalogController/ProductController), so
     * names are always overlaid with the visitor's current language
     * (falling back to the default-language name where untranslated) -
     * unlike tree(), there's no admin caller here that needs the raw name.
     */
    public function path(?int $categoryId): array
    {
        if (!$categoryId) {
            return [];
        }
        $rows = $this->pdo->query('SELECT id, parent_id, name, slug FROM categories')->fetchAll();
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }

        // Walks upward from $categoryId to its parent, grandparent, etc.,
        // prepending each one (array_unshift) so the final array reads
        // root-first / current-category-last, matching how a breadcrumb
        // trail is displayed left to right.
        $path = [];
        $current = $byId[$categoryId] ?? null;
        while ($current) {
            array_unshift($path, $current);
            $parentId = $current['parent_id'] !== null ? (int)$current['parent_id'] : null;
            $current = $parentId ? ($byId[$parentId] ?? null) : null;
        }
        $this->overlayNames($path, I18n::current());
        return $path;
    }

    /** Backs the /category/{slug} route - see Controllers\Storefront\CatalogController::category(). Storefront-only caller, so the name is overlaid with the visitor's current language same as path() above. */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE slug = ?');
        $stmt->execute([$slug]);
        $category = $stmt->fetch();
        if (!$category) {
            return null;
        }
        $rows = [$category];
        $this->overlayNames($rows, I18n::current());
        return $rows[0];
    }

    /** Canonical URL for a category - used everywhere a category link is generated (menus, breadcrumbs, subcategory chips). */
    public function urlFor(array $category): string
    {
        return rtrim(SITE_URL, '/') . '/category/' . urlencode($category['slug']);
    }

    /** Recursively searches a (sub)tree for the node with id $categoryId - used e.g. to resolve a menu item's linked category without a fresh database query, since the tree is already cached in memory. */
    public function findNode(array $nodes, int $categoryId): ?array
    {
        foreach ($nodes as $node) {
            if ((int)$node['id'] === $categoryId) {
                return $node;
            }
            if (!empty($node['children'])) {
                $found = $this->findNode($node['children'], $categoryId);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /** True if $candidateParentId is $categoryId itself or somewhere in its subtree - e.g. used to stop an admin from re-parenting a category underneath its own descendant, which would create a cycle. */
    public function isOrDescendant(int $categoryId, int $candidateParentId): bool
    {
        return in_array($candidateParentId, $this->descendantIds($categoryId), true);
    }

    /** Per-language category intro text - falls back $lang -> default language -> null. */
    public function introText(int $categoryId, string $lang): ?string
    {
        // Fetches both the requested language and the default language in one
        // query (IN (?, ?)) rather than two round trips, since the fallback
        // below needs both anyway.
        $stmt = $this->pdo->prepare('SELECT language, intro_text FROM category_translations WHERE category_id = ? AND language IN (?, ?)');
        $defaultLang = $this->settings->get('default_language', 'en');
        $stmt->execute([$categoryId, $lang, $defaultLang]);

        $byLang = [];
        foreach ($stmt->fetchAll() as $row) {
            $byLang[$row['language']] = $row['intro_text'];
        }

        // Fallback chain: requested language, then the default language,
        // then nothing at all (no intro text configured for this category).
        $text = $byLang[$lang] ?? $byLang[$defaultLang] ?? null;
        // Treats a saved-but-blank/whitespace-only intro the same as "not
        // set", so an empty <p></p> block doesn't get rendered.
        return ($text !== null && trim($text) !== '') ? $text : null;
    }

    /**
     * Overlays each row's 'name' with its category_translations.name
     * translation into $lang, in place - mirrors
     * Services\TranslationOverlay::applyToProduct()'s per-field-fallback
     * behavior: a row is only touched when a non-empty translation
     * actually exists for it, otherwise it keeps whatever name it already
     * had (the default-language categories.name column). A no-op when
     * $lang is the shop's own default language, same as
     * TranslationOverlay does for products - the default language's name
     * already lives on the row itself, there's nothing to overlay.
     *
     * @param array $rows Flat list of category rows, each needing an 'id' key - modified by reference.
     */
    public function overlayNames(array &$rows, string $lang): void
    {
        if (!$rows || $lang === $this->settings->get('default_language', 'en')) {
            return;
        }

        $ids = array_column($rows, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "SELECT category_id, name FROM category_translations WHERE language = ? AND category_id IN ($placeholders) AND name IS NOT NULL AND name != ''"
        );
        $stmt->execute([$lang, ...$ids]);
        $namesByCategoryId = array_column($stmt->fetchAll(), 'name', 'category_id');

        foreach ($rows as &$row) {
            if (!empty($namesByCategoryId[$row['id']])) {
                $row['name'] = $namesByCategoryId[$row['id']];
            }
        }
        unset($row);
    }

    /** Every language's (name, intro_text) row for $categoryId, keyed by language - for the admin edit form's per-language tabs. Mirrors Services\TranslationOverlay::translationsForProduct(). */
    public function translationsForCategory(int $categoryId): array
    {
        $stmt = $this->pdo->prepare('SELECT language, name, intro_text FROM category_translations WHERE category_id = ?');
        $stmt->execute([$categoryId]);
        $byLang = [];
        foreach ($stmt->fetchAll() as $row) {
            $byLang[$row['language']] = $row;
        }
        return $byLang;
    }
}
