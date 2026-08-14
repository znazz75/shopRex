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
    private ?array $treeCache = null;

    public function __construct(private readonly \PDO $pdo, private readonly SettingsRepository $settings)
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

    public function tree(): array
    {
        if ($this->treeCache === null) {
            $rows = $this->pdo->query('SELECT * FROM categories ORDER BY name')->fetchAll();
            $this->treeCache = $this->buildTree($rows);
        }
        return $this->treeCache;
    }

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

    public function descendantIds(int $categoryId): array
    {
        $rows = $this->pdo->query('SELECT id, parent_id FROM categories')->fetchAll();
        $childrenOf = [];
        foreach ($rows as $row) {
            $parent = $row['parent_id'] !== null ? (int)$row['parent_id'] : 0;
            $childrenOf[$parent][] = (int)$row['id'];
        }

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

    /** Ancestor chain from root down to (and including) $categoryId - for breadcrumbs. */
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

        $path = [];
        $current = $byId[$categoryId] ?? null;
        while ($current) {
            array_unshift($path, $current);
            $parentId = $current['parent_id'] !== null ? (int)$current['parent_id'] : null;
            $current = $parentId ? ($byId[$parentId] ?? null) : null;
        }
        return $path;
    }

    /** Backs the /category/{slug} route - see Controllers\Storefront\CatalogController::category(). */
    public function findBySlug(string $slug): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE slug = ?');
        $stmt->execute([$slug]);
        return $stmt->fetch() ?: null;
    }

    /** Canonical URL for a category - used everywhere a category link is generated (menus, breadcrumbs, subcategory chips). */
    public function urlFor(array $category): string
    {
        return rtrim(SITE_URL, '/') . '/category/' . urlencode($category['slug']);
    }

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

    public function isOrDescendant(int $categoryId, int $candidateParentId): bool
    {
        return in_array($candidateParentId, $this->descendantIds($categoryId), true);
    }

    /** Per-language category intro text - falls back $lang -> default language -> null. */
    public function introText(int $categoryId, string $lang): ?string
    {
        $stmt = $this->pdo->prepare('SELECT language, intro_text FROM category_translations WHERE category_id = ? AND language IN (?, ?)');
        $defaultLang = $this->settings->get('default_language', 'en');
        $stmt->execute([$categoryId, $lang, $defaultLang]);

        $byLang = [];
        foreach ($stmt->fetchAll() as $row) {
            $byLang[$row['language']] = $row['intro_text'];
        }

        $text = $byLang[$lang] ?? $byLang[$defaultLang] ?? null;
        return ($text !== null && trim($text) !== '') ? $text : null;
    }
}
