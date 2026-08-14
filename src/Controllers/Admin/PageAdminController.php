<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\I18n;
use ShopRex\Services\SettingsRepository;
use ShopRex\Support\Slugger;

/** Direct port of admin/pages.php. */
final class PageAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;
    private readonly SettingsRepository $settings;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    public function index(Request $request): Response
    {
        $errors = [];
        $availableLangs = I18n::enabledLanguages();
        $lang = $request->get('lang', $this->settings->get('default_language', 'en'));
        if (!array_key_exists($lang, $availableLangs)) {
            $lang = 'en';
        }

        $editId = $request->get('edit') !== null ? (int)$request->get('edit') : null;
        $editPage = null;
        if ($editId) {
            $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE id = ?');
            $stmt->execute([$editId]);
            $editPage = $stmt->fetch();
            if (!$editPage) {
                $this->flash('error', __('admin.pages.not_found'));
                return $this->redirect('/admin/pages');
            }
            $lang = $editPage['language'];
        } elseif ($request->get('slug')) {
            $prefillSlug = $request->get('slug');
            $sysCheck = $this->pdo->prepare('SELECT title FROM pages WHERE slug = ? AND is_system = 1 LIMIT 1');
            $sysCheck->execute([$prefillSlug]);
            $sysTitle = $sysCheck->fetchColumn();
            if ($sysTitle !== false) {
                $editPage = ['id' => null, 'slug' => $prefillSlug, 'title' => $sysTitle, 'content' => '', 'is_system' => 1, 'language' => $lang];
            }
        }

        [$pages, $missingTranslations] = $this->listAndMissing($lang, $availableLangs);

        return $this->render('pages/index', [...compact('errors', 'availableLangs', 'lang', 'editPage', 'pages', 'missingTranslations'), 'pageTitle' => __('admin.pages')]);
    }

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $availableLangs = I18n::enabledLanguages();
        $lang = $this->settings->get('default_language', 'en');

        if ($request->post('delete_id') !== null) {
            $deleteId = (int)$request->post('delete_id');
            $stmt = $this->pdo->prepare('SELECT is_system FROM pages WHERE id = ?');
            $stmt->execute([$deleteId]);
            $isSystem = $stmt->fetchColumn();

            if ($isSystem === false) {
                $this->flash('error', __('admin.pages.not_found'));
            } elseif ((int)$isSystem === 1) {
                $this->flash('error', __('admin.pages.system_page_protected'));
            } else {
                $this->pdo->prepare('DELETE FROM pages WHERE id = ?')->execute([$deleteId]);
                $this->flash('success', __('admin.pages.flash_deleted'));
            }
            return $this->redirect('/admin/pages?lang=' . urlencode($lang));
        }

        $errors = [];
        $id = $request->post('id') !== '' && $request->post('id') !== null ? (int)$request->post('id') : null;
        $postLang = array_key_exists($request->post('language', ''), $availableLangs) ? $request->post('language') : 'en';
        $title = trim((string)$request->post('title', ''));
        $content = (string)$request->post('content', '');

        $editPageForSystemCheck = null;
        if ($id) {
            $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE id = ?');
            $stmt->execute([$id]);
            $editPageForSystemCheck = $stmt->fetch();
        }
        $isSystem = $id && $editPageForSystemCheck && (int)$editPageForSystemCheck['is_system'] === 1;

        if ($isSystem) {
            $slug = $editPageForSystemCheck['slug'];
        } else {
            $slug = Slugger::slug(trim((string)$request->post('slug', $title)));
            $sysCheck = $this->pdo->prepare('SELECT COUNT(*) FROM pages WHERE slug = ? AND is_system = 1');
            $sysCheck->execute([$slug]);
            if ((int)$sysCheck->fetchColumn() > 0) {
                $isSystem = true;
            }
        }

        if ($title === '') {
            $errors[] = __('admin.pages.title_required');
        }

        if (!$errors) {
            try {
                if ($id) {
                    $stmt = $this->pdo->prepare('UPDATE pages SET title=?, slug=?, content=? WHERE id=?');
                    $stmt->execute([$title, $slug, $content, $id]);
                    $this->flash('success', __('admin.pages.flash_updated'));
                } else {
                    $stmt = $this->pdo->prepare('INSERT INTO pages (title, slug, language, content, is_system) VALUES (?, ?, ?, ?, ?)');
                    $stmt->execute([$title, $slug, $postLang, $content, $isSystem ? 1 : 0]);
                    $this->flash('success', __('admin.pages.flash_created'));
                }
                return $this->redirect('/admin/pages?lang=' . urlencode($postLang));
            } catch (\PDOException $e) {
                $errors[] = str_contains($e->getMessage(), 'Duplicate')
                    ? __('admin.pages.duplicate_slug')
                    : __('admin.pages.save_error', ['message' => $e->getMessage()]);
            }
        }

        $lang = $postLang;
        $editPage = ['id' => $id, 'title' => $title, 'slug' => $slug, 'content' => $content, 'is_system' => $isSystem ? 1 : 0, 'language' => $postLang];
        [$pages, $missingTranslations] = $this->listAndMissing($lang, $availableLangs);

        return $this->render('pages/index', [...compact('errors', 'availableLangs', 'lang', 'editPage', 'pages', 'missingTranslations'), 'pageTitle' => __('admin.pages')]);
    }

    private function listAndMissing(string $lang, array $availableLangs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE language = ? ORDER BY is_system DESC, title');
        $stmt->execute([$lang]);
        $pages = $stmt->fetchAll();

        $allSystemSlugs = $this->pdo->query('SELECT DISTINCT slug, title FROM pages WHERE is_system = 1 ORDER BY title')->fetchAll();
        $presentSlugs = array_column($pages, 'slug');
        $missingTranslations = array_filter($allSystemSlugs, fn ($s) => !in_array($s['slug'], $presentSlugs, true));

        return [$pages, $missingTranslations];
    }
}
