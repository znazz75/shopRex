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

    public string $slug = '';
    public string $language = '';
    public string $title = '';
    public string $content = '';
    public bool $isSystem = false;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    public static function findForSlugAndLanguage(string $slug, string $lang, SettingsRepository $settings): ?self
    {
        $stmt = static::pdo()->prepare('SELECT * FROM pages WHERE slug = ? AND language = ?');
        $stmt->execute([$slug, $lang]);
        $row = $stmt->fetch();

        if (!$row) {
            $defaultLang = $settings->get('default_language', 'en');
            if ($defaultLang !== $lang) {
                $stmt->execute([$slug, $defaultLang]);
                $row = $stmt->fetch();
            }
        }
        if (!$row) {
            $stmt = static::pdo()->prepare('SELECT * FROM pages WHERE slug = ? LIMIT 1');
            $stmt->execute([$slug]);
            $row = $stmt->fetch();
        }

        return $row ? (new self())->fill($row) : null;
    }
}
