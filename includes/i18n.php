<?php
/**
 * Minimal file-based i18n system.
 *
 * Add a language by dropping a new includes/lang/xx.php file that returns a
 * flat associative array using the same keys as includes/lang/en.php (the
 * fallback for any key it doesn't have) - no other code changes are needed;
 * it's auto-detected everywhere a language picker is shown (storefront
 * header, admin header, Admin -> Settings -> default language).
 */

define('LANG_DIR', __DIR__ . '/lang/');

/**
 * ['en' => 'English', 'de' => 'Deutsch', ...] - built from whatever
 * includes/lang/*.php files exist, using each file's '_meta_name' entry
 * (falls back to the uppercased code if a file doesn't set one).
 */
function getAvailableLanguages(): array
{
    static $languages = null;
    if ($languages !== null) {
        return $languages;
    }
    $languages = [];
    foreach (glob(LANG_DIR . '*.php') ?: [] as $file) {
        $code = basename($file, '.php');
        $strings = include $file;
        $languages[$code] = $strings['_meta_name'] ?? strtoupper($code);
    }
    if (!$languages) {
        $languages = ['en' => 'English'];
    }
    ksort($languages);
    return $languages;
}

/**
 * Subset of getAvailableLanguages() actually offered anywhere a language
 * can be picked or switched to - the storefront/admin header picker,
 * `?lang=` (getCurrentLanguage() below), Admin -> Settings -> Default
 * language, and the per-language tabs in Admin -> Pages / Email Templates
 * / Categories / Products -> edit. Admin -> Settings -> Languages lets an
 * admin narrow this down (e.g. to a single language, at which point every
 * language-switcher UI disappears entirely - see the `count(...) > 1`
 * checks wherever one is rendered) without deleting the underlying
 * includes/lang/xx.php file, which stays usable for formatting/loading
 * strings for anything that already captured that language (an existing
 * order/customer's stored `language` column, e.g.) even while disabled.
 *
 * Defaults to every available language (today's behavior) until an admin
 * actually saves a narrower selection via the `enabled_languages` setting
 * (a comma-separated list of codes). Always returns at least one language
 * - the configured default language if it's still available, else
 * whatever comes first - so the site is never left with zero usable
 * languages (e.g. if a previously-enabled language's file is later
 * deleted from disk).
 */
function getEnabledLanguages(): array
{
    $available = getAvailableLanguages();
    $raw = getSetting('enabled_languages');
    if ($raw === null || trim($raw) === '') {
        return $available;
    }

    $codes = array_filter(array_map('trim', explode(',', $raw)));
    $enabled = array_intersect_key($available, array_flip($codes));
    if ($enabled) {
        return $enabled;
    }

    $defaultLang = getSetting('default_language', 'en');
    if (isset($available[$defaultLang])) {
        return [$defaultLang => $available[$defaultLang]];
    }
    return $available ?: ['en' => 'English'];
}

/**
 * Current visitor's language: a ?lang= override (persisted to the session,
 * same pattern as getPerPage()), else whatever was already chosen this
 * session, else the admin-configured default (Admin -> Settings). Only
 * ever resolves to an enabled language (see getEnabledLanguages()) - a
 * disabled one isn't reachable via ?lang= even if its file still exists.
 */
function getCurrentLanguage(): string
{
    $available = array_keys(getEnabledLanguages());

    if (isset($_GET['lang']) && in_array($_GET['lang'], $available, true)) {
        $_SESSION['language'] = $_GET['lang'];
    }

    $lang = $_SESSION['language'] ?? getSetting('default_language', 'en');
    return in_array($lang, $available, true) ? $lang : ($available[0] ?? 'en');
}

function loadLanguageStrings(string $lang): array
{
    static $cache = [];
    if (!isset($cache[$lang])) {
        $file = LANG_DIR . $lang . '.php';
        $cache[$lang] = is_file($file) ? include $file : [];
    }
    return $cache[$lang];
}

/**
 * Translate $key for the current visitor's language, falling back to
 * English and then to the raw key itself, so a missing translation degrades
 * to a readable-ish string instead of blank output.
 * $vars does {token} replacement: __('cart.items_left', ['count' => 3]).
 */
function __(string $key, array $vars = []): string
{
    $lang = getCurrentLanguage();
    $strings = loadLanguageStrings($lang);
    $value = $strings[$key] ?? loadLanguageStrings('en')[$key] ?? $key;

    foreach ($vars as $name => $val) {
        $value = str_replace('{' . $name . '}', (string)$val, $value);
    }
    return $value;
}

/**
 * URL that switches the visitor's language while staying on the current
 * page (preserves the rest of the query string) - used by the language
 * switcher in both includes/header.php and admin/includes/header.php.
 */
function languageSwitchUrl(string $code): string
{
    $params = $_GET;
    $params['lang'] = $code;
    return '?' . http_build_query($params);
}

/**
 * Locale-aware date formatting (short: "Aug 25, 2026" / DE: "25.08.2026").
 * Pass $lang explicitly for contexts with no visitor session to read a
 * current language from (e.g. the GDPR cleanup cron job formatting a date
 * for a specific customer's stored language preference).
 */
function formatLocalDate(string $datetime, bool $withTime = false, ?string $lang = null): string
{
    $ts = strtotime($datetime);
    if (!$ts) {
        return $datetime;
    }
    $lang = $lang ?? getCurrentLanguage();

    if ($lang === 'de') {
        return date($withTime ? 'd.m.Y H:i' : 'd.m.Y', $ts);
    }

    if ($lang === 'fr') {
        // PHP's date() isn't locale-aware - there's no built-in way to get
        // "14 août 2026" without the intl extension, which this project
        // avoids requiring (see README's "zero required dependencies"
        // philosophy) - so month names are spelled out by hand instead.
        // Add a similar branch here for any other language that wants
        // non-English month names; a numeric style like German's above
        // needs no such branch at all.
        static $frenchMonths = [
            'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
            'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre',
        ];
        $datePart = (int)date('j', $ts) . ' ' . $frenchMonths[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
        return $withTime ? $datePart . ' ' . date('H:i', $ts) : $datePart;
    }

    return date($withTime ? 'M j, Y H:i' : 'M j, Y', $ts);
}
