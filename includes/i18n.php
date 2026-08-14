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
 * Current visitor's language: a ?lang= override (persisted to the session,
 * same pattern as getPerPage()), else whatever was already chosen this
 * session, else the admin-configured default (Admin -> Settings).
 */
function getCurrentLanguage(): string
{
    $available = array_keys(getAvailableLanguages());

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
    $format = $lang === 'de'
        ? ($withTime ? 'd.m.Y H:i' : 'd.m.Y')
        : ($withTime ? 'M j, Y H:i' : 'M j, Y');
    return date($format, $ts);
}
