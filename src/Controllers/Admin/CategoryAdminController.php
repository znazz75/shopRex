<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\SettingsRepository;

/**
 * Direct port of admin/categories.php. Manages the product category tree
 * (parent/child nesting, one row per category) plus each category's
 * per-language `intro_text` (see CLAUDE.md's "Product/option translation"
 * section - unlike product names, a category's *name* is NOT translated,
 * only its intro text is, via the sibling `category_translations` table).
 */
final class CategoryAdminController extends AdminCrudController
{
    /** Raw database handle for this controller's queries against `categories`/`category_translations`. */
    private readonly \PDO $pdo;
    /** Builds/queries the category tree (parent/child nesting) and its flattened, indented list used by pickers. */
    private readonly CategoryTreeService $categories;
    /** Used here just to read the shop's default language, as the initial language tab to show when no ?lang= is given. */
    private readonly SettingsRepository $settings;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->categories = $container->make(CategoryTreeService::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    /** GET /admin/categories - lists the category tree (flattened/indented) with product counts, and if ?edit=id is set, loads that category (name + the selected language's intro text) into the edit form. */
    public function index(Request $request): Response
    {
        $errors = [];
        $availableLangs = \ShopRex\Services\I18n::enabledLanguages();
        // Which language tab is active - defaults to the shop's default
        // language rather than always English, since a shop whose default
        // language is e.g. French would otherwise always open on an
        // (empty) English intro text.
        $lang = $request->get('lang', $this->settings->get('default_language', 'en'));
        if (!array_key_exists($lang, $availableLangs)) {
            $lang = 'en';
        }

        $editId = $request->get('edit') !== null ? (int)$request->get('edit') : null;
        $editCategory = null;
        $introText = '';
        if ($editId) {
            $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = ?');
            $stmt->execute([$editId]);
            $editCategory = $stmt->fetch();
            if (!$editCategory) {
                $this->flash('error', __('admin.categories.not_found'));
                return $this->redirect('/admin/categories');
            }
            // Category name lives on the `categories` row itself (not
            // translated), but intro_text is per-language, so it's a
            // separate lookup against category_translations for whichever
            // language tab is currently active.
            $introStmt = $this->pdo->prepare('SELECT intro_text FROM category_translations WHERE category_id = ? AND language = ?');
            $introStmt->execute([$editId, $lang]);
            $introText = (string)($introStmt->fetchColumn() ?: '');
        }

        $flatTree = $this->categories->flatten($this->categories->tree());
        $counts = [];
        // Product counts per category, looked up once as a category_id =>
        // count map rather than a per-row query, so the tree listing below
        // can show each category's product count without an N+1 query.
        foreach ($this->pdo->query('SELECT category_id, COUNT(*) AS cnt FROM products GROUP BY category_id')->fetchAll() as $row) {
            if ($row['category_id'] !== null) {
                $counts[(int)$row['category_id']] = (int)$row['cnt'];
            }
        }

        return $this->render('categories/index', [...compact('errors', 'availableLangs', 'lang', 'editCategory', 'introText', 'flatTree', 'counts'), 'pageTitle' => __('admin.categories')]);
    }

    /** Handles delete plus create/update (name/description/parent + the active language's intro text) for a category, all posted to the same route. */
    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        if ($request->post('delete_id') !== null) {
            $deleteId = (int)$request->post('delete_id');
            $stmt = $this->pdo->prepare('SELECT parent_id FROM categories WHERE id = ?');
            $stmt->execute([$deleteId]);
            $parentId = $stmt->fetchColumn();
            // Deleting a category re-parents its own children up to ITS
            // parent (grandparent becomes the new parent) rather than
            // deleting them too or leaving them pointing at a now-missing
            // row - a category's children survive its own deletion.
            $this->pdo->prepare('UPDATE categories SET parent_id = ? WHERE parent_id = ?')->execute([$parentId ?: null, $deleteId]);
            $this->pdo->prepare('DELETE FROM categories WHERE id = ?')->execute([$deleteId]);
            $this->flash('success', __('admin.categories.flash_deleted'));
            return $this->redirect('/admin/categories');
        }

        $availableLangs = \ShopRex\Services\I18n::enabledLanguages();
        $errors = [];

        $id = $request->post('id') !== '' && $request->post('id') !== null ? (int)$request->post('id') : null;
        $name = trim((string)$request->post('name', ''));
        $description = trim((string)$request->post('description', ''));
        $parentId = $request->post('parent_id', '') !== '' ? (int)$request->post('parent_id') : null;
        $postLang = array_key_exists($request->post('language', ''), $availableLangs) ? $request->post('language') : 'en';
        $introText = trim((string)$request->post('intro_text', ''));

        if ($name === '') {
            $errors[] = __('admin.categories.name_required');
        }
        if ($id && $parentId === $id) {
            $errors[] = __('admin.categories.cannot_be_own_parent');
        }
        // Prevents creating a cycle: a category can't be moved to be a
        // child of one of its own descendants, since that would make the
        // tree unreachable/infinite when walked top-down.
        if ($id && $parentId && $this->categories->isOrDescendant($id, $parentId)) {
            $errors[] = __('admin.categories.cannot_move_under_own_subcategory');
        }

        if (!$errors) {
            // Slug is derived from the name automatically (not admin-
            // editable) - it's what shows up in the category's URL.
            $slug = \ShopRex\Support\Slugger::slug($name);
            try {
                if ($id) {
                    $stmt = $this->pdo->prepare('UPDATE categories SET name=?, slug=?, description=?, parent_id=? WHERE id=?');
                    $stmt->execute([$name, $slug, $description, $parentId, $id]);
                    $this->flash('success', __('admin.categories.flash_updated'));
                } else {
                    $stmt = $this->pdo->prepare('INSERT INTO categories (name, slug, description, parent_id) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$name, $slug, $description, $parentId]);
                    $id = (int)$this->pdo->lastInsertId();
                    $this->flash('success', __('admin.categories.flash_added'));
                }
                // Upsert (INSERT ... ON DUPLICATE KEY UPDATE) for the
                // intro text row, since only one language is edited per
                // submit - a brand-new category has no existing translation
                // row yet, while an existing one already has one for other
                // languages, so this single statement handles both cases.
                // A blank textarea is stored as NULL rather than an empty
                // string, so the storefront can distinguish "no intro text
                // for this language" cleanly.
                $introStmt = $this->pdo->prepare(
                    'INSERT INTO category_translations (category_id, language, intro_text) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE intro_text = VALUES(intro_text)'
                );
                $introStmt->execute([$id, $postLang, $introText !== '' ? $introText : null]);
                return $this->redirect('/admin/categories?lang=' . urlencode($postLang));
            } catch (\PDOException $e) {
                // A duplicate-key error here means the generated slug
                // already belongs to another category (slugs are unique) -
                // surfaced as a friendlier "duplicate name" message instead
                // of a raw SQL error, since the admin only typed a name,
                // not a slug.
                $errors[] = str_contains($e->getMessage(), 'Duplicate')
                    ? __('admin.categories.duplicate_name')
                    : __('admin.categories.save_error', ['message' => $e->getMessage()]);
            }
        }

        $lang = $postLang;
        $editCategory = ['id' => $id, 'name' => $name, 'description' => $description, 'parent_id' => $parentId];
        $flatTree = $this->categories->flatten($this->categories->tree());
        $counts = [];
        foreach ($this->pdo->query('SELECT category_id, COUNT(*) AS cnt FROM products GROUP BY category_id')->fetchAll() as $row) {
            if ($row['category_id'] !== null) {
                $counts[(int)$row['category_id']] = (int)$row['cnt'];
            }
        }

        return $this->render('categories/index', [...compact('errors', 'availableLangs', 'lang', 'editCategory', 'introText', 'flatTree', 'counts'), 'pageTitle' => __('admin.categories')]);
    }
}
