<?php

/**
 * Tier-2 compatibility shim - thin global-function delegates to the new
 * classes, kept alive ONLY because a handful of legacy view templates are
 * deliberately NOT rewritten (includes/header.php, includes/footer.php,
 * includes/home.php, themes/sidebar/home.php - see Core\Renderer's
 * docblock for why: preserving theme-package fidelity byte-for-byte).
 * Every *business-logic* function has no shim here - it's deleted outright
 * once its one call site (a controller) is ported to the class that
 * replaces it.
 */

use ShopRex\Core\Csrf;
use ShopRex\Core\FlashBag;
use ShopRex\Core\Registry;
use ShopRex\Core\Renderer;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\DiscountCalculator;
use ShopRex\Services\I18n;
use ShopRex\Services\MenuTreeService;
use ShopRex\Services\SettingsRepository;
use ShopRex\Services\TaxCalculator;

if (!function_exists('e')) {
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatPrice')) {
    function formatPrice(float $amount): string
    {
        return CURRENCY_SYMBOL . number_format($amount, 2, ',', '.');
    }
}

if (!function_exists('__')) {
    function __(string $key, array $vars = []): string
    {
        return I18n::t($key, $vars);
    }
}

if (!function_exists('getCurrentLanguage')) {
    function getCurrentLanguage(): string
    {
        return I18n::current();
    }
}

if (!function_exists('languageSwitchUrl')) {
    function languageSwitchUrl(string $code): string
    {
        return I18n::switchUrl($code);
    }
}

if (!function_exists('csrfField')) {
    function csrfField(): string
    {
        return Registry::container()->make(Csrf::class)->field();
    }
}

if (!function_exists('csrfToken')) {
    function csrfToken(): string
    {
        return Registry::container()->make(Csrf::class)->token();
    }
}

if (!function_exists('themeStylesheetTag')) {
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
    function getSetting(string $key, ?string $default = null): ?string
    {
        return Registry::container()->make(SettingsRepository::class)->get($key, $default);
    }
}

if (!function_exists('getEnabledLanguages')) {
    function getEnabledLanguages(): array
    {
        return I18n::enabledLanguages();
    }
}

if (!function_exists('getFlashes')) {
    function getFlashes(): array
    {
        return Registry::container()->make(FlashBag::class)->pull();
    }
}

if (!function_exists('getMenuTree')) {
    function getMenuTree(string $location): array
    {
        return Registry::container()->make(MenuTreeService::class)->tree($location);
    }
}

if (!function_exists('resolveMenuUrl')) {
    function resolveMenuUrl(array $item): string
    {
        return Registry::container()->make(MenuTreeService::class)->resolveUrl($item);
    }
}

if (!function_exists('currentCustomer')) {
    function currentCustomer(): ?array
    {
        return \ShopRex\Core\Auth\CustomerAuth::current();
    }
}

if (!function_exists('getActiveTheme')) {
    /**
     * Color-accent theme (THEMES const in includes/functions.php) - a much
     * smaller, storefront-only concern than ThemeManager's *layout package*
     * resolution, not worth its own service class; kept as a constant
     * co-located with this, its only remaining caller.
     */
    function getActiveTheme(): array
    {
        static $themes = [
            'default' => ['label' => 'Default (Light)', 'bs_theme' => 'light', 'accent' => '#0d6efd', 'navbar_bg' => '#212529'],
            'dark'    => ['label' => 'Midnight (Dark)', 'bs_theme' => 'dark', 'accent' => '#6ea8fe', 'navbar_bg' => '#000000'],
            'ocean'   => ['label' => 'Ocean (Teal)', 'bs_theme' => 'light', 'accent' => '#0d9488', 'navbar_bg' => '#0f766e'],
        ];
        $key = getSetting('site_theme', 'default');
        return $themes[$key] ?? $themes['default'];
    }
}

if (!function_exists('getCategoryTree')) {
    function getCategoryTree(): array
    {
        return Registry::container()->make(CategoryTreeService::class)->tree();
    }
}

if (!function_exists('getCategoryUrl')) {
    function getCategoryUrl(array $category): string
    {
        return Registry::container()->make(CategoryTreeService::class)->urlFor($category);
    }
}

if (!function_exists('getCategoryIntroText')) {
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
    function getLegalDocumentUrl(string $type): string
    {
        return rtrim(SITE_URL, '/') . '/legal/' . urlencode($type);
    }
}

if (!function_exists('getActiveDiscount')) {
    function getActiveDiscount(array $product): ?array
    {
        return Registry::container()->make(DiscountCalculator::class)->activeFor($product);
    }
}

if (!function_exists('getTaxRatePercent')) {
    function getTaxRatePercent(array $product): float
    {
        return Registry::container()->make(TaxCalculator::class)->percentFor($product);
    }
}

if (!function_exists('getGrossPrice')) {
    function getGrossPrice(array $product): float
    {
        return Registry::container()->make(TaxCalculator::class)->grossPrice($product);
    }
}

if (!function_exists('vatIsEnabled')) {
    function vatIsEnabled(): bool
    {
        return Registry::container()->make(TaxCalculator::class)->vatEnabled();
    }
}

if (!function_exists('getPrimaryImage')) {
    function getPrimaryImage(array $product): string
    {
        if (!empty($product['primary_cropped_image'])) {
            return UPLOAD_URL . $product['primary_cropped_image'];
        }
        if (!empty($product['primary_image'])) {
            return UPLOAD_URL . $product['primary_image'];
        }
        return rtrim(SITE_URL, '/') . '/assets/img/placeholder.svg';
    }
}

if (!function_exists('formatDiscountDateRange')) {
    function formatDiscountDateRange(array $discount): ?string
    {
        return Registry::container()->make(DiscountCalculator::class)->dateRangeLabel($discount);
    }
}

if (!defined('PER_PAGE_OPTIONS')) {
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
    function formatLocalDate(string $datetime, bool $withTime = false, ?string $lang = null): string
    {
        return I18n::formatLocalDate($datetime, $withTime, $lang);
    }
}

if (!function_exists('formatTaxRateNumber')) {
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
    function currentAdmin(): ?array
    {
        return \ShopRex\Core\Auth\AdminAuth::current();
    }
}

if (!function_exists('adminCan')) {
    function adminCan(?array $admin, string $capability): bool
    {
        if (!$admin) {
            return false;
        }
        $allowedRoles = \ShopRex\Core\Auth\AdminAuth::CAPABILITIES[$capability] ?? [];
        return in_array($admin['role'], $allowedRoles, true);
    }
}

if (!function_exists('adminRoleLabel')) {
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
        $path = (string)(parse_url($uri, PHP_URL_PATH) ?: '/');

        $basePath = defined('SITE_URL') ? (string)parse_url(SITE_URL, PHP_URL_PATH) : '';
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '') {
            $path = '/';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/') ?: '/';
        }
        return $path;
    }
}

if (!function_exists('renderPagination')) {
    function renderPagination(int $currentPage, int $totalPages, array $queryParams): void
    {
        \ShopRex\Support\Pagination::render($currentPage, $totalPages, $queryParams);
    }
}
