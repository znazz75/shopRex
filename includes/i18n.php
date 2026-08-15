<?php
/**
 * File-level purpose: minimal file-based i18n (internationalization)
 * system for the site's UI chrome text (buttons, labels, messages) - as
 * opposed to Services\TranslationOverlay, which handles *product content*
 * translation (see CLAUDE.md's "i18n" and "Product/option translation"
 * sections for the distinction between the two).
 *
 * Add a language by dropping a new includes/lang/xx.php file that returns a
 * flat associative array using the same keys as includes/lang/en.php (the
 * fallback for any key it doesn't have) - no other code changes are needed;
 * it's auto-detected everywhere a language picker is shown (storefront
 * header, admin header, Admin -> Settings -> default language).
 *
 * Why this file still exists as-is: this whole file is one of the "legacy
 * classes kept as-is" in spirit (a set of global functions rather than a
 * class here, but the same category - see CLAUDE.md). `Services\I18n` is
 * described in its own docblock as a "direct port" of this file - i.e. an
 * independent reimplementation of the same logic as static methods
 * (I18n::t(), I18n::current(), ...), NOT a wrapper around these global
 * functions. Every normal web request (routed through index.php/
 * admin/index.php -> src/bootstrap.php) defines its own global `__()` in
 * src/view-helpers.php that calls Services\I18n::t() instead - this file's
 * own `__()` below is never loaded on that path, so the two never coexist
 * in the same request (that would be a fatal "cannot redeclare" error).
 * This file remains in active use specifically by
 * admin/cron/gdpr_cleanup.php (a standalone CLI entry point outside the
 * src/ autoloader/Container - see that file and includes/GdprCleanup.php),
 * which `require_once`s it directly; includes/GdprCleanup.php's own call to
 * formatLocalDate() (for the deletion-warning email's date) resolves to
 * this file's copy of that function when reached via that script.
 */

// Directory every includes/lang/xx.php file lives in - the single source
// of truth this whole file's language auto-discovery scans.
define('LANG_DIR', __DIR__ . '/lang/');

/**
 * ['en' => 'English', 'de' => 'Deutsch', ...] - built from whatever
 * includes/lang/*.php files exist, using each file's '_meta_name' entry
 * (falls back to the uppercased code if a file doesn't set one).
 */
function getAvailableLanguages(): array
{
    // Memoized in a function-local static - the language file list can't
    // change mid-request, so this only needs to scan the disk once.
    static $languages = null;
    if ($languages !== null) {
        return $languages;
    }
    $languages = [];
    // Every *.php file directly under includes/lang/ counts as an
    // available language - basename() without the extension gives the
    // language code (e.g. "de.php" -> "de").
    foreach (glob(LANG_DIR . '*.php') ?: [] as $file) {
        $code = basename($file, '.php');
        $strings = include $file;
        $languages[$code] = $strings['_meta_name'] ?? strtoupper($code);
    }
    // Guards against a misconfigured/empty lang directory leaving the
    // whole site with no language at all.
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
    // No setting saved yet (e.g. fresh install) - every available language
    // counts as enabled.
    if ($raw === null || trim($raw) === '') {
        return $available;
    }

    // 'enabled_languages' is stored as a comma-separated list of codes;
    // array_intersect_key + array_flip turns that into "only the
    // available languages whose code appears in that list".
    $codes = array_filter(array_map('trim', explode(',', $raw)));
    $enabled = array_intersect_key($available, array_flip($codes));
    if ($enabled) {
        return $enabled;
    }

    // The enabled list pointed at codes that don't actually exist on disk
    // (e.g. a language file was deleted after being enabled) - fall back
    // to just the configured default language...
    $defaultLang = getSetting('default_language', 'en');
    if (isset($available[$defaultLang])) {
        return [$defaultLang => $available[$defaultLang]];
    }
    // ...and if even that isn't available, fall back to every available
    // language, or finally a hardcoded English, so the site is never left
    // with zero usable languages.
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

    // Only accept ?lang= if it's actually one of the currently-enabled
    // languages - otherwise a crafted ?lang= value could pick a disabled
    // or nonexistent language. When accepted, it's remembered in the
    // session so it applies to the rest of this visit, not just this page.
    if (isset($_GET['lang']) && in_array($_GET['lang'], $available, true)) {
        $_SESSION['language'] = $_GET['lang'];
    }

    $lang = $_SESSION['language'] ?? getSetting('default_language', 'en');
    // Re-validate the final choice too, in case a language that used to be
    // enabled (and got remembered in the session) was disabled since -
    // falls back to whichever enabled language comes first alphabetically.
    return in_array($lang, $available, true) ? $lang : ($available[0] ?? 'en');
}

/** Returns the raw 'namespace.key' => 'string' array for one language file (or an empty array if that language has no file), reading the file only once per request no matter how many times a key from it is looked up. */
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
    // Three-level fallback: this language's string, else English's, else
    // the raw key itself (e.g. "cart.items_left") - so a missing
    // translation is at least visible/debuggable rather than blank.
    $value = $strings[$key] ?? loadLanguageStrings('en')[$key] ?? $key;

    // Simple {name} placeholder substitution, e.g. __('cart.items_left', ['count' => 3]).
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
    // Start from every query parameter already on this page (filters,
    // search terms, pagination, ...) and just change/add 'lang' - so
    // switching language never loses whatever else the visitor was doing.
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
    // strtotime() returns false for a string it can't parse - return the
    // original text unchanged rather than showing a bogus/wrong date.
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
        // date('n', $ts) is the 1-based month number (1-12); subtracting 1
        // turns it into a 0-based index into $frenchMonths.
        $datePart = (int)date('j', $ts) . ' ' . $frenchMonths[(int)date('n', $ts) - 1] . ' ' . date('Y', $ts);
        return $withTime ? $datePart . ' ' . date('H:i', $ts) : $datePart;
    }

    // Every other enabled language (including English) uses this
    // English-style format, e.g. "Aug 25, 2026".
    return date($withTime ? 'M j, Y H:i' : 'M j, Y', $ts);
}
