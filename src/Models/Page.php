<?php

namespace ShopRex\Models;

use ShopRex\Core\Model;
use ShopRex\Services\SettingsRepository;

/**
 * CMS page (Admin -> Pages). Content is rendered as trusted, unescaped
 * HTML by design - see page.php's original comment, preserved on
 * Controllers\Storefront\PageController::show(). Direct port of page.php's
 * fallback chain: visitor language -> site default language -> any row
 * for that slug -> not found.
 */
class Page extends Model
{
    protected static string $table = 'pages';

    // The URL-friendly identifier shared across every language's copy of
    // this page (e.g. "about-us") - what appears in /page/{slug}.
    public string $slug = '';
    // Which language this specific row's content is written in - CMS pages
    // use "one whole row per (slug, language)" rather than the
    // TranslationOverlay pattern other content uses (see CLAUDE.md).
    public string $language = '';
    public string $title = '';
    // Raw HTML, rendered unescaped/trusted by design - see class docblock.
    public string $content = '';
    // True for built-in pages the app relies on existing (e.g. legal pages
    // wired into checkout) - typically protected from deletion in the admin UI.
    public bool $isSystem = false;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    /** Looks up one page by slug in the visitor's language, with a graceful fallback chain so a page that hasn't been translated yet still renders instead of 404ing. */
    public static function findForSlugAndLanguage(string $slug, string $lang, SettingsRepository $settings): ?self
    {
        // First choice: an exact match for this slug in the visitor's
        // current language.
        $stmt = static::pdo()->prepare('SELECT * FROM pages WHERE slug = ? AND language = ?');
        $stmt->execute([$slug, $lang]);
        $row = $stmt->fetch();

        if (!$row) {
            // Second choice: the same slug in the site's configured default
            // language (skipped if that's the same language already tried above).
            $defaultLang = $settings->get('default_language', 'en');
            if ($defaultLang !== $lang) {
                $stmt->execute([$slug, $defaultLang]);
                $row = $stmt->fetch();
            }
        }
        if (!$row) {
            // Last resort: any row at all for this slug, in whatever
            // language it happens to exist - better to show something than
            // a 404 for a page an admin clearly created.
            $stmt = static::pdo()->prepare('SELECT * FROM pages WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
            $row = $stmt->fetch();
        }

        return $row ? (new self())->fill($row) : null;
    }
}
