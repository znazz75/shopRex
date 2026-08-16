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
 * per-language `name`/`intro_text` (see CLAUDE.md's "Product/option
 * translation" section - the default-language name always lives on
 * `categories.name`/`slug`, exactly like products; every other language's
 * name lives in the sibling `category_translations` table, same table
 * `intro_text` already used - see Services\CategoryTreeService::overlayNames()
 * for the storefront-side read overlay this feeds).
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

        $defaultLang = $this->settings->get('default_language', 'en');

        $editId = $request->get('edit') !== null ? (int)$request->get('edit') : null;
        $editCategory = null;
        $introText = '';
        $nameForLang = '';
        if ($editId) {
            $stmt = $this->pdo->prepare('SELECT * FROM categories WHERE id = ?');
            $stmt->execute([$editId]);
            $editCategory = $stmt->fetch();
            if (!$editCategory) {
                $this->flash('error', __('admin.categories.not_found'));
                return $this->redirect('/admin/categories');
            }
            // On the default-language tab, the Name field shows the raw
            // categories.name column (the "real" name that also drives the
            // slug); on any other tab it shows that language's translation
            // (blank if not yet translated) - same per-tab split intro_text
            // already has, via the same translationsForCategory() lookup.
            $categoryTranslations = $this->categories->translationsForCategory($editId);
            $introText = (string)($categoryTranslations[$lang]['intro_text'] ?? '');
            $nameForLang = $lang === $defaultLang
                ? (string)$editCategory['name']
                : (string)($categoryTranslations[$lang]['name'] ?? '');
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

        return $this->render('categories/index', [...compact('errors', 'availableLangs', 'lang', 'defaultLang', 'editCategory', 'introText', 'nameForLang', 'flatTree', 'counts'), 'pageTitle' => __('admin.categories')]);
    }

    /** Handles delete plus create/update (name/description/parent + the active language's name/intro text) for a category, all posted to the same route. */
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
        $defaultLang = $this->settings->get('default_language', 'en');
        // Brand-new categories always define their name on the default-
        // language tab (that's the only tab shown when creating, and it's
        // the name categories.name/slug are derived from) - an existing
        // category's name is only required when saving the default-
        // language tab, since a translation is optional (blank falls back
        // to the default name on the storefront, same as products).
        $isDefaultLangSave = !$id || $postLang === $defaultLang;

        if ($isDefaultLangSave && $name === '') {
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
            try {
                if ($isDefaultLangSave) {
                    // Slug is derived from the name automatically (not
                    // admin-editable) - it's what shows up in the
                    // category's URL. Only the default-language save
                    // touches name/slug on the categories row itself.
                    $slug = \ShopRex\Support\Slugger::slug($name);
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
                    // Upsert the intro text row for the default language
                    // too (intro_text is always per-language, including
                    // the default one - unlike name, it has no "lives on
                    // the main row" home) - name is deliberately left out
                    // of this statement so category_translations never
                    // gets a default-language name row (mirrors
                    // product_translations' own convention).
                    $introStmt = $this->pdo->prepare(
                        'INSERT INTO category_translations (category_id, language, intro_text) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE intro_text = VALUES(intro_text)'
                    );
                    $introStmt->execute([$id, $postLang, $introText !== '' ? $introText : null]);
                } else {
                    // Non-default-language tab: name/slug on the
                    // categories row are left untouched; description/
                    // parent_id still update since the form always
                    // resubmits their current (language-agnostic) values
                    // regardless of which tab is active.
                    $stmt = $this->pdo->prepare('UPDATE categories SET description=?, parent_id=? WHERE id=?');
                    $stmt->execute([$description, $parentId, $id]);
                    $this->flash('success', __('admin.categories.flash_updated'));

                    // Name here is optional (blank means "not translated
                    // yet", falls back to the default name on the
                    // storefront) - upserted alongside intro_text in the
                    // same statement, since both belong to this one
                    // (category_id, language) row.
                    $translationStmt = $this->pdo->prepare(
                        'INSERT INTO category_translations (category_id, language, name, intro_text) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE name = VALUES(name), intro_text = VALUES(intro_text)'
                    );
                    $translationStmt->execute([$id, $postLang, $name !== '' ? $name : null, $introText !== '' ? $introText : null]);
                }
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
        // Re-display exactly what was submitted, on whichever tab was
        // active - $name here is the just-typed value for THAT tab (either
        // the default name or a translation), not necessarily
        // categories.name, so it's passed straight through as nameForLang
        // rather than re-derived.
        $editCategory = ['id' => $id, 'name' => $name, 'description' => $description, 'parent_id' => $parentId];
        $nameForLang = $name;
        $flatTree = $this->categories->flatten($this->categories->tree());
        $counts = [];
        foreach ($this->pdo->query('SELECT category_id, COUNT(*) AS cnt FROM products GROUP BY category_id')->fetchAll() as $row) {
            if ($row['category_id'] !== null) {
                $counts[(int)$row['category_id']] = (int)$row['cnt'];
            }
        }

        return $this->render('categories/index', [...compact('errors', 'availableLangs', 'lang', 'defaultLang', 'editCategory', 'introText', 'nameForLang', 'flatTree', 'counts'), 'pageTitle' => __('admin.categories')]);
    }
}
