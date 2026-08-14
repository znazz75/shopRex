<?php

namespace ShopRex\Services;

/**
 * Direct port of includes/i18n.php. Static, booted once per request from
 * src/container.php right after SettingsRepository exists - same "ambient
 * singleton" pattern as Core\Auth\AdminAuth/CustomerAuth, and necessary
 * here specifically because the global __() function (src/view-helpers.php)
 * has to keep working unmodified inside every legacy-style view template,
 * with no request object in scope to thread a service through.
 */
final class I18n
{
    private static ?SettingsRepository $settings = null;
    private static string $langDir = '';
    private static ?array $availableCache = null;
    private static array $stringsCache = [];

    public static function boot(SettingsRepository $settings, string $langDir): void
    {
        self::$settings = $settings;
        self::$langDir = rtrim($langDir, '/\\') . '/';
        self::$availableCache = null;
        self::$stringsCache = [];
    }

    /** @return array<string,string> code => display name, from every includes/lang/*.php file on disk. */
    public static function availableLanguages(): array
    {
        if (self::$availableCache !== null) {
            return self::$availableCache;
        }
        $languages = [];
        foreach (glob(self::$langDir . '*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            $strings = include $file;
            $languages[$code] = $strings['_meta_name'] ?? strtoupper($code);
        }
        if (!$languages) {
            $languages = ['en' => 'English'];
        }
        ksort($languages);
        return self::$availableCache = $languages;
    }

    /** Subset an admin has actually enabled - see includes/i18n.php's getEnabledLanguages() docblock for the full fallback rationale. */
    public static function enabledLanguages(): array
    {
        $available = self::availableLanguages();
        $raw = self::$settings?->get('enabled_languages');
        if ($raw === null || trim($raw) === '') {
            return $available;
        }

        $codes = array_filter(array_map('trim', explode(',', $raw)));
        $enabled = array_intersect_key($available, array_flip($codes));
        if ($enabled) {
            return $enabled;
        }

        $defaultLang = self::$settings?->get('default_language', 'en');
        if ($defaultLang !== null && isset($available[$defaultLang])) {
            return [$defaultLang => $available[$defaultLang]];
        }
        return $available ?: ['en' => 'English'];
    }

    public static function current(): string
    {
        $available = array_keys(self::enabledLanguages());

        if (isset($_GET['lang']) && in_array($_GET['lang'], $available, true)) {
            $_SESSION['language'] = $_GET['lang'];
        }

        $lang = $_SESSION['language'] ?? self::$settings?->get('default_language', 'en') ?? 'en';
        return in_array($lang, $available, true) ? $lang : ($available[0] ?? 'en');
    }

    public static function loadStrings(string $lang): array
    {
        if (!isset(self::$stringsCache[$lang])) {
            $file = self::$langDir . $lang . '.php';
            self::$stringsCache[$lang] = is_file($file) ? include $file : [];
        }
        return self::$stringsCache[$lang];
    }

    public static function t(string $key, array $vars = []): string
    {
        $lang = self::current();
        $strings = self::loadStrings($lang);
        $value = $strings[$key] ?? self::loadStrings('en')[$key] ?? $key;

        foreach ($vars as $name => $val) {
            $value = str_replace('{' . $name . '}', (string)$val, $value);
        }
        return $value;
    }

    public static function switchUrl(string $code): string
    {
        $params = $_GET;
        $params['lang'] = $code;
        return '?' . http_build_query($params);
    }

    public static function formatLocalDate(string $datetime, bool $withTime = false, ?string $lang = null): string
    {
        $ts = strtotime($datetime);
        if (!$ts) {
            return $datetime;
        }
        $lang = $lang ?? self::current();

        if ($lang === 'de') {
            return date($withTime ? 'd.m.Y H:i' : 'd.m.Y', $ts);
        }

        if ($lang === 'fr') {
            static $frenchMonths = [
                'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
            ];
            $datePart = (int)date('j', $ts) . ' ' . $frenchMonths[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
            return $withTime ? $datePart . ' ' . date('H:i', $ts) : $datePart;
        }

        return date($withTime ? 'M j, Y H:i' : 'M j, Y', $ts);
    }
}
