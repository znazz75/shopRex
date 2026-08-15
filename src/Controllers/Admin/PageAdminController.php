<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\I18n;
use ShopRex\Services\SettingsRepository;
use ShopRex\Support\Slugger;

/**
 * Manages CMS pages (Admin -> Pages) - both "system" pages the storefront
 * depends on by fixed slug (e.g. an imprint/about page a theme links to
 * directly) and free-form pages an admin creates. Direct port of
 * admin/pages.php. Note that a page's content field is stored and later
 * rendered as trusted, unescaped HTML by design (same trust model as
 * WordPress page content) - see CLAUDE.md's Security posture section;
 * anyone with access to this controller (Super Admin or Manager, via the
 * 'pages' capability) can inject arbitrary markup/scripts into the
 * storefront through it. Unlike product translations (an overlay table),
 * each language of a page is its own separate row here - see CLAUDE.md's
 * "CMS pages ... use a third pattern again" note.
 */
final class PageAdminController extends AdminCrudController
{
    // Shared PDO connection - pages are read/written directly via hand-written SQL.
    private readonly \PDO $pdo;
    // Used here just to look up the shop's configured default language.
    private readonly SettingsRepository $settings;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    /** Lists pages in one language at a time, and loads a specific page into the edit form via ?edit={id} - or, via ?slug=..., pre-fills a "create" form for a known system-page slug that doesn't have a row yet. */
    public function index(Request $request): Response
    {
        $errors = [];
        // Only actually-enabled languages are offered here (see CLAUDE.md's
        // i18n section) - editing a page for a disabled language wouldn't be reachable.
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
            // The page being edited dictates which language tab is shown, not
            // the ?lang= that was in the URL - each page row is a single
            // language, so there's no ambiguity here.
            $lang = $editPage['language'];
        } elseif ($request->get('slug')) {
            // No ?edit= given, but a ?slug= is - this is the "create the missing
            // translation for a known system page" flow (see missingTranslations
            // below): if that slug already exists as a system page in *some*
            // language, pre-fill the create form with its title so the admin
            // doesn't have to retype it, but leave the content blank for them to
            // translate.
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

    /** Handles create, update, and delete for a page, all from the same form/route - which action runs depends on which fields were submitted. */
    public function save(Request $request): Response
    {
        // Blocks a forged page-edit submission (CSRF) - especially important
        // here since page content is rendered as unescaped HTML (see class
        // docblock), so a forged save could inject a script into the storefront.
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $availableLangs = I18n::enabledLanguages();
        $lang = $this->settings->get('default_language', 'en');

        // Delete branch - the delete button posts delete_id instead of the full
        // edit form, so this is checked first and returns early.
        if ($request->post('delete_id') !== null) {
            $deleteId = (int)$request->post('delete_id');
            $stmt = $this->pdo->prepare('SELECT is_system FROM pages WHERE id = ?');
            $stmt->execute([$deleteId]);
            $isSystem = $stmt->fetchColumn();

            if ($isSystem === false) {
                $this->flash('error', __('admin.pages.not_found'));
            } elseif ((int)$isSystem === 1) {
                // System pages (ones a theme links to by fixed slug) can't be
                // deleted through this UI - removing one would break whatever
                // storefront link points at it. They can still be edited, just
                // not removed.
                $this->flash('error', __('admin.pages.system_page_protected'));
            } else {
                $this->pdo->prepare('DELETE FROM pages WHERE id = ?')->execute([$deleteId]);
                $this->flash('success', __('admin.pages.flash_deleted'));
            }
            return $this->redirect('/admin/pages?lang=' . urlencode($lang));
        }

        $errors = [];
        $id = $request->post('id') !== '' && $request->post('id') !== null ? (int)$request->post('id') : null;
        // Whitelist check: fall back to English if the submitted language isn't
        // one of the enabled ones.
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
            // A system page's slug is fixed (it's what the storefront/theme links
            // to) - never let it be edited through this form, even if the admin
            // tampered with the submitted slug field.
            $slug = $editPageForSystemCheck['slug'];
        } else {
            // Derive a URL-safe slug from the submitted slug (or the title, if no
            // slug was given) - see Support\Slugger::slug().
            $slug = Slugger::slug(trim((string)$request->post('slug', $title)));
            // If this slug happens to match an existing system page's slug (e.g.
            // an admin manually typed a slug like "imprint" that a theme already
            // reserves), treat this new page as a system page too - keeps a
            // "regular" page from accidentally shadowing a reserved one under a
            // different is_system flag.
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
                    // Note: language is intentionally not part of this UPDATE - an
                    // existing page's language is fixed once created (each
                    // language is its own row, see class docblock).
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
                // (slug, language) is presumably a unique constraint in the schema -
                // a duplicate-key error becomes a friendly "this slug already exists
                // in this language" message instead of a raw SQL error.
                $errors[] = str_contains($e->getMessage(), 'Duplicate')
                    ? __('admin.pages.duplicate_slug')
                    : __('admin.pages.save_error', ['message' => $e->getMessage()]);
            }
        }

        // Validation (or a DB error) failed - re-render the form pre-filled with
        // what was submitted, on the language tab the admin was editing.
        $lang = $postLang;
        $editPage = ['id' => $id, 'title' => $title, 'slug' => $slug, 'content' => $content, 'is_system' => $isSystem ? 1 : 0, 'language' => $postLang];
        [$pages, $missingTranslations] = $this->listAndMissing($lang, $availableLangs);

        return $this->render('pages/index', [...compact('errors', 'availableLangs', 'lang', 'editPage', 'pages', 'missingTranslations'), 'pageTitle' => __('admin.pages')]);
    }

    /** Loads every page in one language plus, separately, which of the known system-page slugs (gathered across all languages) have no row yet in this language - so the view can prompt "translate this page" for the gaps. */
    private function listAndMissing(string $lang, array $availableLangs): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM pages WHERE language = ? ORDER BY is_system DESC, title');
        $stmt->execute([$lang]);
        $pages = $stmt->fetchAll();

        // DISTINCT because the same system slug can appear once per language;
        // this collects the full set of system slugs/titles regardless of which
        // language they exist in.
        $allSystemSlugs = $this->pdo->query('SELECT DISTINCT slug, title FROM pages WHERE is_system = 1 ORDER BY title')->fetchAll();
        // array_column() pulls just the 'slug' value out of every row in $pages
        // into a flat list, then array_filter() keeps only the system slugs that
        // ISN'T in that list - i.e. the ones with no row in the current $lang yet.
        $presentSlugs = array_column($pages, 'slug');
        $missingTranslations = array_filter($allSystemSlugs, fn ($s) => !in_array($s['slug'], $presentSlugs, true));

        return [$pages, $missingTranslations];
    }
}
