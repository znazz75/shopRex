<?php

/**
 * Tier-2 compatibility shim - thin global-function delegates to the real
 * Services/Models classes, kept alive as a deliberate, permanent part of
 * the view-authoring convention (not a migration crutch): every view under
 * src/Views/ is a plain PHP file that gets `extract()`-into-scope data and
 * then just calls bare global functions like `e($value)` or
 * `getSetting('site_name')` - there's no `$this` or injected object
 * available inside a template, so these bare functions are the only way a
 * view reaches a real service. This file is where they live; each one is a
 * one-line delegate to the "real" implementation on a proper class
 * (reached via Core\Registry, since a template has no direct reference to
 * the Container). Every *business-logic* function has no shim here - it's
 * deleted outright once its one call site (a controller) is ported to the
 * class that replaces it. When adding a new helper here, prefer delegating
 * to a real Services/Models class rather than putting actual logic in this
 * file - see CLAUDE.md's "Tier-2 compatibility shim" section.
 *
 * Every function below is wrapped in `if (!function_exists('name'))` so
 * this file can safely be required more than once (or alongside a
 * same-named function defined elsewhere) without a fatal
 * "Cannot redeclare" error - this is standard practice for a file of
 * global function definitions like this one.
 */

use ShopRex\Core\Csrf;
use ShopRex\Core\FlashBag;
use ShopRex\Core\Registry;
use ShopRex\Core\Renderer;
use ShopRex\Models\Cart;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\DiscountCalculator;
use ShopRex\Services\I18n;
use ShopRex\Services\MenuTreeService;
use ShopRex\Services\SettingsRepository;
use ShopRex\Services\TaxCalculator;

if (!function_exists('e')) {
    /** HTML-escapes a value for safe output in a template (prevents XSS from user-supplied strings like a product name or search term) - short name ('e' for "escape") because it's used constantly throughout every view. */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatPrice')) {
    /** Formats a raw float amount as a display price string using the shop's currency symbol and German-style number formatting (comma decimal separator, dot thousands separator - e.g. "1.234,56 €"). */
    function formatPrice(float $amount): string
    {
        return CURRENCY_SYMBOL . number_format($amount, 2, ',', '.');
    }
}

if (!function_exists('__')) {
    /** Looks up a translated string by its 'namespace.key' for the current language, with token substitution (see Services\I18n::t()) - the main entry point templates use for every piece of user-facing text. */
    function __(string $key, array $vars = []): string
    {
        return I18n::t($key, $vars);
    }
}

if (!function_exists('getCurrentLanguage')) {
    /** Returns the current request's active language code ('en'/'de'/'fr'). */
    function getCurrentLanguage(): string
    {
        return I18n::current();
    }
}

if (!function_exists('languageSwitchUrl')) {
    /** Builds the URL that switches the site to language $code while staying on the current page - used by the language-picker links in the header. */
    function languageSwitchUrl(string $code): string
    {
        return I18n::switchUrl($code);
    }
}

if (!function_exists('csrfField')) {
    /** Echoes/returns the hidden CSRF input field for the current session - see Core\Csrf::field(). Every form in the app that submits via POST should include this. */
    function csrfField(): string
    {
        return Registry::container()->make(Csrf::class)->field();
    }
}

if (!function_exists('csrfToken')) {
    /** Returns the raw CSRF token string (not wrapped in an <input> tag) - used where the token is needed outside a plain HTML form, e.g. an AJAX request header. */
    function csrfToken(): string
    {
        return Registry::container()->make(Csrf::class)->token();
    }
}

if (!function_exists('themeStylesheetTag')) {
    /** Echoes/returns the active theme package's <link rel="stylesheet"> tag, if it has its own style.css - see Core\ThemeManager::stylesheetTag(). */
    function themeStylesheetTag(): string
    {
        return Registry::container()->make(Renderer::class)->theme()->stylesheetTag();
    }
}

// ---------------------------------------------------------------
// The rest of this file exists ONLY because includes/header.php,
// includes/footer.php, and includes/home.php are deliberately NOT
// rewritten (preserving theme-package fidelity byte-for-byte - see
// Core\Renderer's docblock) and still call these as bare functions/a
// bare constant. Every one of these delegates to a real Phase 2 service
// class - nothing here re-implements logic, it only relocates the call.
// ---------------------------------------------------------------

if (!function_exists('getSetting')) {
    /** Reads one admin-configurable setting by key (e.g. 'site_name'), or $default if it was never set - see Services\SettingsRepository::get(). */
    function getSetting(string $key, ?string $default = null): ?string
    {
        return Registry::container()->make(SettingsRepository::class)->get($key, $default);
    }
}

if (!function_exists('getEnabledLanguages')) {
    /** Returns the languages an admin has actually enabled (not every language file that merely exists on disk) - see CLAUDE.md's i18n section for why this distinction matters for anything user-facing. */
    function getEnabledLanguages(): array
    {
        return I18n::enabledLanguages();
    }
}

if (!function_exists('getFlashes')) {
    /** Retrieves and clears the queued one-shot flash messages for this request - see Core\FlashBag::pull(). Templates call this once, near the top of the page, to display any pending success/error banners. */
    function getFlashes(): array
    {
        return Registry::container()->make(FlashBag::class)->pull();
    }
}

if (!function_exists('getMenuTree')) {
    /** Returns the nested menu-item tree (parents with a 'children' array) for one menu $location (e.g. 'header'/'footer'), as configured under Admin -> Menus. */
    function getMenuTree(string $location): array
    {
        return Registry::container()->make(MenuTreeService::class)->tree($location);
    }
}

if (!function_exists('resolveMenuUrl')) {
    /** Turns a menu item row into the actual URL it should link to, based on its configured link type (product/category/page/custom URL/...). */
    function resolveMenuUrl(array $item): string
    {
        return Registry::container()->make(MenuTreeService::class)->resolveUrl($item);
    }
}

if (!function_exists('currentCustomer')) {
    /** Returns the currently logged-in customer's DB row, or null if nobody is logged in - see Core\Auth\CustomerAuth::current(). */
    function currentCustomer(): ?array
    {
        return \ShopRex\Core\Auth\CustomerAuth::current();
    }
}

if (!function_exists('getActiveTheme')) {
    /**
     * Color-accent theme - a much smaller, storefront-only concern than
     * ThemeManager's *layout package* resolution, not worth its own
     * service class; the lookup table lives as a static local array right
     * below, co-located with this, its only remaining caller.
     */
    function getActiveTheme(): array
    {
        // static local array acts as a lightweight constant lookup table -
        // each entry is one selectable color scheme: its display label, the
        // Bootstrap 'data-bs-theme' value (light/dark), and the CSS
        // variable overrides (accent color, navbar background).
        static $themes = [
            'default' => ['label' => 'Default (Light)', 'bs_theme' => 'light', 'accent' => '#0d6efd', 'navbar_bg' => '#212529'],
            'dark'    => ['label' => 'Midnight (Dark)', 'bs_theme' => 'dark', 'accent' => '#6ea8fe', 'navbar_bg' => '#000000'],
            'ocean'   => ['label' => 'Ocean (Teal)', 'bs_theme' => 'light', 'accent' => '#0d9488', 'navbar_bg' => '#0f766e'],
        ];
        $key = getSetting('site_theme', 'default');
        // Fall back to 'default' if the stored setting refers to a color
        // theme key that no longer exists (e.g. removed from this array).
        return $themes[$key] ?? $themes['default'];
    }
}

if (!function_exists('getCartItemCount')) {
    /** Total line-item quantity currently in the visitor's session cart - the small red badge number on the storefront nav's cart icon. */
    function getCartItemCount(): int
    {
        return Registry::container()->make(Cart::class)->count();
    }
}

if (!function_exists('getCategoryTree')) {
    /** Returns the full nested category tree (parents with a 'children' array), with each name translated into the visitor's current language - as used for the storefront's main navigation. */
    function getCategoryTree(): array
    {
        return Registry::container()->make(CategoryTreeService::class)->translatedTree();
    }
}

if (!function_exists('getCategoryUrl')) {
    /** Builds the clean /category/{slug}-style URL for a given category row. */
    function getCategoryUrl(array $category): string
    {
        return Registry::container()->make(CategoryTreeService::class)->urlFor($category);
    }
}

if (!function_exists('getCategoryIntroText')) {
    /** Returns the per-language intro/description text for one category, or null if none is set for that language - see CLAUDE.md's note that category *names* aren't translated this way, only intro_text is. */
    function getCategoryIntroText(int $categoryId, string $lang): ?string
    {
        return Registry::container()->make(CategoryTreeService::class)->introText($categoryId, $lang);
    }
}

if (!function_exists('getLegalDocuments')) {
    /** Every legal document type currently on offer, one row (type + best-language title) each - see Models\LegalDocument::allForLanguage(). */
    function getLegalDocuments(): array
    {
        $settings = Registry::container()->make(SettingsRepository::class);
        return \ShopRex\Models\LegalDocument::allForLanguage(
            Registry::container()->make(\PDO::class),
            I18n::current(),
            $settings->get('default_language', 'en')
        );
    }
}

if (!function_exists('getLegalDocumentUrl')) {
    /** Builds the download URL (/legal/{type}) for one legal document type - urlencode()'d since $type is admin-defined free text and could contain characters that aren't safe unescaped in a URL path segment. */
    function getLegalDocumentUrl(string $type): string
    {
        return rtrim(SITE_URL, '/') . '/legal/' . urlencode($type);
    }
}

if (!function_exists('getActiveDiscount')) {
    /** Returns the currently-active discount for a product (if any is running right now), or null - see Services\DiscountCalculator::activeFor(). */
    function getActiveDiscount(array $product): ?array
    {
        return Registry::container()->make(DiscountCalculator::class)->activeFor($product);
    }
}

if (!function_exists('getTaxRatePercent')) {
    /** Returns the VAT/tax percentage that applies to this product (e.g. 19.0) - see Services\TaxCalculator::percentFor(). */
    function getTaxRatePercent(array $product): float
    {
        return Registry::container()->make(TaxCalculator::class)->percentFor($product);
    }
}

if (!function_exists('getGrossPrice')) {
    /** Returns the product's price including tax (what the customer actually pays) - see Services\TaxCalculator::grossPrice(). */
    function getGrossPrice(array $product): float
    {
        return Registry::container()->make(TaxCalculator::class)->grossPrice($product);
    }
}

if (!function_exists('vatIsEnabled')) {
    /** True if the shop is configured to charge/display VAT at all - some shops (e.g. small-business/Kleinunternehmer in Germany) legally don't. */
    function vatIsEnabled(): bool
    {
        return Registry::container()->make(TaxCalculator::class)->vatEnabled();
    }
}

if (!function_exists('getPrimaryImage')) {
    /** Resolves the image URL to show for a product: prefers an admin-cropped version if one exists, falls back to the uploaded original, and finally to a generic placeholder graphic if the product has no image at all. */
    function getPrimaryImage(array $product): string
    {
        // A cropped version (made via Admin -> Products' image cropper) is
        // the intentionally-framed one an admin chose, so prefer it over
        // the raw upload whenever it exists.
        if (!empty($product['primary_cropped_image'])) {
            return UPLOAD_URL . $product['primary_cropped_image'];
        }
        if (!empty($product['primary_image'])) {
            return UPLOAD_URL . $product['primary_image'];
        }
        // No image at all - show a placeholder rather than a broken <img>.
        return rtrim(SITE_URL, '/') . '/assets/img/placeholder.svg';
    }
}

if (!function_exists('formatDiscountDateRange')) {
    /** Formats a discount's start/end dates into a human-readable label (e.g. "Aug 1 - Aug 15"), or null if the discount has no date restriction. */
    function formatDiscountDateRange(array $discount): ?string
    {
        return Registry::container()->make(DiscountCalculator::class)->dateRangeLabel($discount);
    }
}

if (!defined('PER_PAGE_OPTIONS')) {
    // Exposes the fixed list of selectable "items per page" values (see
    // Services\PerPageResolver::OPTIONS) as a plain global constant, since
    // templates rendering a "results per page" dropdown have no service
    // instance in scope to call a method on.
    define('PER_PAGE_OPTIONS', \ShopRex\Services\PerPageResolver::OPTIONS);
}

if (!function_exists('db')) {
    /**
     * Kept as a bare global (matching includes/functions.php's db()) purely
     * because the still-loaded-as-is legacy InvoiceGenerator/Mailer classes
     * call it directly - see src/container.php's docblock on why those two
     * aren't ported to Services\* yet (deferred, not skipped).
     */
    function db(): PDO
    {
        return Database::getConnection();
    }
}

if (!function_exists('formatLocalDate')) {
    /** Formats a raw datetime string for display in the current (or explicitly given) language - handles the non-English month-name spelling PHP's own date() can't do, see Services\I18n::formatLocalDate(). */
    function formatLocalDate(string $datetime, bool $withTime = false, ?string $lang = null): string
    {
        return I18n::formatLocalDate($datetime, $withTime, $lang);
    }
}

if (!function_exists('formatTaxRateNumber')) {
    /** Formats a raw tax rate float (e.g. 19.0) as a display string, trimming a trailing ".0" where the rate is a whole number - see Services\TaxCalculator::formatRateNumber(). */
    function formatTaxRateNumber(float $rate): string
    {
        return Registry::container()->make(TaxCalculator::class)->formatRateNumber($rate);
    }
}

// ---------------------------------------------------------------
// admin/includes/header.php/footer.php are likewise NOT rewritten (same
// fidelity rationale as the storefront templates) and still call these as
// bare functions - delegates to Core\Auth\AdminAuth.
// ---------------------------------------------------------------

if (!function_exists('currentAdmin')) {
    /** Returns the currently logged-in admin's DB row, or null if nobody is logged in - see Core\Auth\AdminAuth::current(). */
    function currentAdmin(): ?array
    {
        return \ShopRex\Core\Auth\AdminAuth::current();
    }
}

if (!function_exists('adminCan')) {
    /** Checks whether a given (already-fetched) $admin row's role is allowed to use $capability - a template-friendly variant of AdminAuth::can() that takes the admin row directly instead of re-looking it up. */
    function adminCan(?array $admin, string $capability): bool
    {
        if (!$admin) {
            return false;
        }
        // Unknown capability defaults to an empty allow-list ("nobody") -
        // same fail-closed behavior as AdminAuth::can(), see that method's
        // comment for why.
        $allowedRoles = \ShopRex\Core\Auth\AdminAuth::CAPABILITIES[$capability] ?? [];
        return in_array($admin['role'], $allowedRoles, true);
    }
}

if (!function_exists('adminRoleLabel')) {
    /** Returns the display label for an admin role string - see Core\Auth\AdminAuth::roleLabel(). */
    function adminRoleLabel(string $role): string
    {
        return \ShopRex\Core\Auth\AdminAuth::roleLabel($role);
    }
}

if (!function_exists('currentPath')) {
    /**
     * Same SITE_URL-based stripping as Core\Request::path() (not a call to
     * it - a view has no Request instance in scope), for the one thing a
     * template needs the current path for: admin layout's nav
     * active-highlighting. Keep this in sync with Request::path() if that
     * ever changes.
     */
    function currentPath(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        // Strip the query string, keeping only the path portion of the URL.
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');

        // Same "chop off the install's subdirectory prefix" logic as
        // Core\Request::path() - see that method's docblock for the full
        // reasoning on why this is derived from SITE_URL specifically.
        $basePath = defined('SITE_URL') ? (string)parse_url(SITE_URL, PHP_URL_PATH) : '';
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '') {
            $path = '/';
        }
        // Normalize away a trailing slash, same as Request::path() (but
        // written as a one-liner here rather than a separate if-block).
        if (strlen($path) > 1) {
            $path = rtrim($path, '/') ?: '/';
        }
        return $path;
    }
}

if (!function_exists('renderPagination')) {
    /** Echoes the pagination control markup for a listing page - see Support\Pagination::render(). */
    function renderPagination(int $currentPage, int $totalPages, array $queryParams): void
    {
        \ShopRex\Support\Pagination::render($currentPage, $totalPages, $queryParams);
    }
}
