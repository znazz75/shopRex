<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\SettingsRepository;

/** Direct port of admin/categories.php. */
final class CategoryAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;
    private readonly CategoryTreeService $categories;
    private readonly SettingsRepository $settings;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->categories = $container->make(CategoryTreeService::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    public function index(Request $request): Response
    {
        $errors = [];
        $availableLangs = \ShopRex\Services\I18n::enabledLanguages();
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
            $introStmt = $this->pdo->prepare('SELECT intro_text FROM category_translations WHERE category_id = ? AND language = ?');
            $introStmt->execute([$editId, $lang]);
            $introText = (string)($introStmt->fetchColumn() ?: '');
        }

        $flatTree = $this->categories->flatten($this->categories->tree());
        $counts = [];
        foreach ($this->pdo->query('SELECT category_id, COUNT(*) AS cnt FROM products GROUP BY category_id')->fetchAll() as $row) {
            if ($row['category_id'] !== null) {
                $counts[(int)$row['category_id']] = (int)$row['cnt'];
            }
        }

        return $this->render('categories/index', [...compact('errors', 'availableLangs', 'lang', 'editCategory', 'introText', 'flatTree', 'counts'), 'pageTitle' => __('admin.categories')]);
    }

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
        if ($id && $parentId && $this->categories->isOrDescendant($id, $parentId)) {
            $errors[] = __('admin.categories.cannot_move_under_own_subcategory');
        }

        if (!$errors) {
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
                $introStmt = $this->pdo->prepare(
                    'INSERT INTO category_translations (category_id, language, intro_text) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE intro_text = VALUES(intro_text)'
                );
                $introStmt->execute([$id, $postLang, $introText !== '' ? $introText : null]);
                return $this->redirect('/admin/categories?lang=' . urlencode($postLang));
            } catch (\PDOException $e) {
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
