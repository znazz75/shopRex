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
    // Kept as a static so the global __() helper (src/view-helpers.php) can call
    // I18n::t() from anywhere without a service being threaded through - see
    // class docblock for why this ambient-singleton pattern is used here.
    private static ?SettingsRepository $settings = null;
    // Filesystem folder holding one PHP file per language (includes/lang/),
    // always stored with a trailing slash so callers can just concatenate.
    private static string $langDir = '';
    // Memoizes availableLanguages() for the rest of the request; null means
    // "not computed yet" (distinct from an empty-but-computed array).
    private static ?array $availableCache = null;
    // Memoizes loadStrings() per language code so re-reading the same
    // language's strings later in the request doesn't re-include the file.
    private static array $stringsCache = [];

    /** Wires this static class up to a real SettingsRepository and the on-disk language folder, and clears any caches left over from a previous boot (relevant in long-running contexts, e.g. tests, where the class isn't freshly loaded each time). */
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
        // Every *.php file under includes/lang/ counts as an available
        // language - this is the auto-discovery mechanism CLAUDE.md
        // describes (same pattern as theme packages).
        foreach (glob(self::$langDir . '*.php') ?: [] as $file) {
            $code = basename($file, '.php');
            $strings = include $file;
            // Each lang file can declare its own human-readable display name
            // via a special '_meta_name' key; falls back to the uppercased
            // code (e.g. "DE") if it doesn't.
            $languages[$code] = $strings['_meta_name'] ?? strtoupper($code);
        }
        // Guards against a misconfigured/empty lang directory leaving the
        // whole site with no language at all.
        if (!$languages) {
            $languages = ['en' => 'English'];
        }
        ksort($languages);
        return self::$availableCache = $languages;
    }

    /** Subset an admin has actually enabled - see availableLanguages() above for the full fallback rationale this builds on. */
    public static function enabledLanguages(): array
    {
        $available = self::availableLanguages();
        $raw = self::$settings?->get('enabled_languages');
        // No setting saved yet (fresh install) - treat every available
        // language as enabled rather than showing none.
        if ($raw === null || trim($raw) === '') {
            return $available;
        }

        // 'enabled_languages' is stored as a comma-separated list of codes;
        // array_intersect_key + array_flip turns that into "only the
        // available languages whose code appears in the enabled list".
        $codes = array_filter(array_map('trim', explode(',', $raw)));
        $enabled = array_intersect_key($available, array_flip($codes));
        if ($enabled) {
            return $enabled;
        }

        // The enabled list pointed at codes that don't actually exist on disk
        // (e.g. a language file was deleted after being enabled) - fall back
        // to just the configured default language...
        $defaultLang = self::$settings?->get('default_language', 'en');
        if ($defaultLang !== null && isset($available[$defaultLang])) {
            return [$defaultLang => $available[$defaultLang]];
        }
        // ...and if even that isn't available, fall back to every available
        // language, or finally to a hardcoded English so the site never ends
        // up with zero usable languages.
        return $available ?: ['en' => 'English'];
    }

    /** Decides which language this specific request should render in, honoring an explicit ?lang= switch (remembered in the session for later requests) and otherwise falling back through the session's remembered choice, then the site default. */
    public static function current(): string
    {
        $available = array_keys(self::enabledLanguages());

        // Only accept ?lang= if it's actually one of the currently-enabled
        // languages - otherwise a crafted ?lang= value could silently pick a
        // disabled/nonexistent language.
        if (isset($_GET['lang']) && in_array($_GET['lang'], $available, true)) {
            $_SESSION['language'] = $_GET['lang'];
        }

        $lang = $_SESSION['language'] ?? self::$settings?->get('default_language', 'en') ?? 'en';
        // Re-validates the final choice too, in case a language that used to
        // be enabled (and got remembered in the session) was disabled since.
        return in_array($lang, $available, true) ? $lang : ($available[0] ?? 'en');
    }

    /** Returns the raw 'namespace.key' => 'string' array for one language file, reading the file only once per request no matter how many times it's requested. */
    public static function loadStrings(string $lang): array
    {
        if (!isset(self::$stringsCache[$lang])) {
            $file = self::$langDir . $lang . '.php';
            self::$stringsCache[$lang] = is_file($file) ? include $file : [];
        }
        return self::$stringsCache[$lang];
    }

    /** The actual translation lookup behind the global __() helper: finds $key in the current language, falls back to English if missing there too, and finally falls back to the raw key itself so a missing translation is visible/debuggable rather than blank. $vars fills in {token} placeholders inside the string. */
    public static function t(string $key, array $vars = []): string
    {
        $lang = self::current();
        $strings = self::loadStrings($lang);
        $value = $strings[$key] ?? self::loadStrings('en')[$key] ?? $key;

        // Simple {name} placeholder substitution, e.g. __('cart.items_count', ['count' => 3]).
        foreach ($vars as $name => $val) {
            $value = str_replace('{' . $name . '}', (string)$val, $value);
        }
        return $value;
    }

    /** Builds a link that switches the language while keeping every other current query parameter (e.g. staying on the same search/filter page, just in a different language). */
    public static function switchUrl(string $code): string
    {
        $params = $_GET;
        $params['lang'] = $code;
        return '?' . http_build_query($params);
    }

    /** Formats a MySQL datetime string the way a human in the given language expects to read it, since PHP's date() has no built-in locale awareness - German uses numeric d.m.Y, French spells the month name out, everything else uses an English-style "M j, Y". */
    public static function formatLocalDate(string $datetime, bool $withTime = false, ?string $lang = null): string
    {
        $ts = strtotime($datetime);
        // strtotime() returns false for an unparseable string - return the
        // original text unchanged rather than showing a bogus date.
        if (!$ts) {
            return $datetime;
        }
        $lang = $lang ?? self::current();

        if ($lang === 'de') {
            return date($withTime ? 'd.m.Y H:i' : 'd.m.Y', $ts);
        }

        if ($lang === 'fr') {
            // Hand-spelled French month names, since PHP's date() only knows
            // English month names regardless of server locale (see CLAUDE.md).
            static $frenchMonths = [
                'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
                'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
            ];
            // date('n', $ts) is the 1-based month number; -1 turns it into a
            // 0-based index into $frenchMonths.
            $datePart = (int)date('j', $ts) . ' ' . $frenchMonths[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
            return $withTime ? $datePart . ' ' . date('H:i', $ts) : $datePart;
        }

        return date($withTime ? 'M j, Y H:i' : 'M j, Y', $ts);
    }
}
