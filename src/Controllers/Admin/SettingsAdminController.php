<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Core\ThemeManager;
use ShopRex\Services\GdprService;
use ShopRex\Services\I18n;
use ShopRex\Services\SettingsRepository;

/**
 * Direct port of admin/settings.php. Backs the single Admin -> Settings
 * screen where a super admin/manager edits shop-wide configuration: shop
 * name/email/currency/bank details, company/legal info, payment gateway
 * keys, color theme + storefront theme package, enabled languages, VAT
 * toggle, and the site URL - plus a button to manually trigger the GDPR
 * inactivity cleanup job. Exists as its own controller (rather than being
 * folded into a generic settings form) because every one of those fields
 * has its own validation/whitelist rules and a couple of them (site URL,
 * theme package) have real side effects beyond a plain key/value write.
 */
final class SettingsAdminController extends AdminCrudController
{
    /** Raw PDO handle - used here to read the settings table directly for display, bypassing SettingsRepository's cache (see its constructor comment below for why that distinction matters on this page). */
    private readonly \PDO $pdo;
    /** The write path for every setting saved on this page - a shared, cache-invalidating-on-write instance (see CLAUDE.md's "Settings" section and this class's docblock for the staleness gotcha it fixes). */
    private readonly SettingsRepository $settings;
    /** Enumerates/validates the storefront's available theme *packages* (layout structure, not color) for the theme picker - see the constructor comment on why this must be the storefront-flavored instance. */
    private readonly ThemeManager $themes;
    /** Runs the GDPR customer-inactivity cleanup job when the admin clicks the manual "run now" button. */
    private readonly GdprService $gdpr;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->settings = $container->make(SettingsRepository::class);
        // Deliberately the storefront-flavored instance, not the ambient
        // ThemeManager::class (which is admin-flavored - fixed layout, no
        // packages - on an admin request) - this page needs to enumerate
        // and validate against the STOREFRONT's available theme packages.
        // See src/container.php's 'ThemeManager.storefront' binding.
        $this->themes = $container->make('ThemeManager.storefront');
        $this->gdpr = $container->make(GdprService::class);
    }

    /** The "core" settings shown/saved on this page: setting_key => human-readable label for the form. Kept as one array so index()/save()/form() all loop over the exact same key list instead of repeating it three times. */
    private function fieldMeta(): array
    {
        return [
            'shop_name'           => __('admin.settings.shop_name'),
            'shop_email'          => __('admin.settings.shop_email'),
            'currency'            => __('admin.settings.currency'),
            'currency_symbol'     => __('admin.settings.currency_symbol'),
            'bank_account_holder' => __('admin.settings.bank_account_holder'),
            'bank_iban'           => __('admin.settings.bank_iban'),
            'bank_bic'            => __('admin.settings.bank_bic'),
            'bank_name'           => __('admin.settings.bank_name'),
        ];
    }

    /**
     * v2.00 - Admin -> Settings -> Company / Legal (Phase 6's
     * vat_id/company_registration_number/company_legal_name). Kept as a
     * separate array from fieldMeta() because these three settings don't
     * have seeded rows in sql/schema.sql, so they're saved with
     * SettingsRepository::upsert() rather than ::update() (see save()
     * below) - splitting the arrays keeps the two save loops simple.
     */
    private function legalFieldMeta(): array
    {
        return [
            'company_legal_name'           => __('admin.settings.company_legal_name'),
            'vat_id'                       => __('admin.settings.vat_id'),
            'company_registration_number'  => __('admin.settings.company_registration_number'),
        ];
    }

    /** GET /admin/settings - just renders the form with the currently saved values, no site-URL error to show yet. */
    public function index(Request $request): Response
    {
        $siteUrlError = null;
        return $this->form($siteUrlError);
    }

    /** Lets an admin trigger Services\GdprService's scheduled inactivity cleanup on demand (instead of waiting for whatever runs it automatically) and reports how many customers were warned/deleted. */
    public function runGdprCleanup(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $result = $this->gdpr->runInactivityCleanup();
        $this->flash('success', __('admin.settings.gdpr_cleanup_result', ['warned' => $result['warned'], 'deleted' => $result['deleted']]));
        return $this->redirect('/admin/settings');
    }

    /**
     * Changes the site's base URL - unlike every other setting on this
     * page, SITE_URL isn't a `settings` table row, it's a PHP constant
     * defined in config/installed.php (see writeInstalledConfigFile()
     * below), because it's needed before the DB connection/settings table
     * are even available. Handled as its own form/submit separate from
     * save() below because a wrong value here can break every link on the
     * site, so it gets its own stricter validation and its redirect uses
     * the *new* URL (proving the rewrite actually took effect) rather than
     * the normal $this->redirect() helper.
     */
    public function saveSiteUrl(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $newSiteUrl = rtrim(trim((string)$request->post('site_url', '')), '/');
        // Must look like an http(s) URL - an empty or malformed value here
        // would otherwise get baked into config/installed.php and break
        // every generated link/redirect on the site.
        if ($newSiteUrl === '' || !preg_match('~^https?://~i', $newSiteUrl)) {
            return $this->form(__('admin.settings.site_url_required'));
        }
        if (!$this->writeInstalledConfigFile(DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, $newSiteUrl)) {
            return $this->form(__('admin.settings.site_url_write_error'));
        }
        $this->flash('success', __('admin.settings.site_url_updated'));
        // Redirects to the settings page using the NEW url (not the old
        // SITE_URL constant still loaded in this request) - if the URL is
        // wrong, this redirect visibly fails/404s instead of silently
        // leaving the admin on a page that still thinks the old URL works.
        return Response::redirect(rtrim($newSiteUrl, '/') . '/admin/settings');
    }

    /**
     * Saves every setting on the main form in one POST (everything except
     * the site URL, which has its own saveSiteUrl() above). Each group of
     * fields below is validated/whitelisted against a known-safe set of
     * values before being written - a raw, unchecked $_POST value is never
     * passed straight to SettingsRepository, since these settings feed
     * into payment routing, tax calculation, and which theme/files get
     * loaded on every storefront request.
     */
    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        // Core shop info fields all have seeded rows in sql/schema.sql,
        // so plain UPDATE (::update()) is enough - no risk of "no row to
        // update" since install.php already inserted one for each key.
        foreach (array_keys($this->fieldMeta()) as $key) {
            $this->settings->update($key, trim((string)$request->post($key, '')));
        }
        // v2.00 - not seeded rows (same reasoning as the payment keys below), upsert.
        foreach (array_keys($this->legalFieldMeta()) as $key) {
            $this->settings->upsert($key, trim((string)$request->post($key, '')));
        }

        $paymentTextFields = ['paypal_client_id', 'stripe_publishable_key'];
        $paymentSecretFields = ['paypal_client_secret', 'stripe_secret_key'];
        // Payment gateway keys also aren't seeded rows (a fresh install has
        // no payment provider configured yet), so upsert() rather than
        // update() - update() would silently do nothing if the row doesn't
        // exist yet.
        foreach ($paymentTextFields as $key) {
            $this->settings->upsert($key, trim((string)$request->post($key, '')));
        }
        // Secret fields never round-trip their stored value back into the
        // form, so a blank submission means "unchanged", not "clear it".
        foreach ($paymentSecretFields as $key) {
            $val = trim((string)$request->post($key, ''));
            if ($val !== '') {
                $this->settings->upsert($key, $val);
            }
        }
        $paypalMode = $request->post('paypal_mode') === 'live' ? 'live' : 'sandbox';
        $this->settings->upsert('paypal_mode', $paypalMode);

        // Color theme (accent color scheme) - whitelisted against
        // colorThemes() below; falls back to 'default' rather than
        // rejecting the save outright if an unknown/tampered value arrives.
        $theme = array_key_exists($request->post('site_theme', ''), $this->colorThemes()) ? $request->post('site_theme') : 'default';
        $this->settings->update('site_theme', $theme);

        // Theme *package* (structurally different storefront layout) -
        // whitelisted against the storefront ThemeManager's real list of
        // installed packages, since this value later drives which
        // src/Views/storefront/theme/<key>/ directory gets required().
        $themePackage = array_key_exists($request->post('site_theme_package', ''), $this->themes->availablePackages()) ? $request->post('site_theme_package') : 'default';
        $this->settings->upsert('site_theme_package', $themePackage);

        $perPage = in_array($request->post('items_per_page_default', ''), \ShopRex\Services\PerPageResolver::OPTIONS, true) ? $request->post('items_per_page_default') : '20';
        $this->settings->update('items_per_page_default', $perPage);

        // Languages - computed from the submitted checkboxes directly
        // rather than re-reading it back via SettingsRepository (which
        // would still be safe here since it invalidates on write, but the
        // original's same-request-staleness caveat is why this pattern
        // exists at all - kept for clarity/parity, see SettingsRepository's
        // docblock).
        $allAvailableLangs = I18n::availableLanguages();
        // Only keep languages that both exist on disk AND were checked in
        // the form - array_intersect() against the real language list means
        // a crafted/unknown language code in the POST can't get enabled.
        $submittedLangs = array_values(array_intersect(array_keys($allAvailableLangs), (array)$request->post('enabled_languages', [])));
        if (!$submittedLangs) {
            // Safety net: unchecking every language box would otherwise
            // brick the storefront (no language left to render in) - fall
            // back to enabling all of them instead.
            $submittedLangs = array_keys($allAvailableLangs);
        }
        // The default language must itself be one of the enabled languages
        // - otherwise fall back to the first enabled one so the site never
        // ends up defaulting to a language nobody can see.
        $lang = in_array($request->post('default_language', ''), $submittedLangs, true) ? $request->post('default_language') : $submittedLangs[0];
        $this->settings->update('default_language', $lang);
        $this->settings->upsert('enabled_languages', implode(',', $submittedLangs));

        $vatEnabled = $request->post('vat_enabled') ? '1' : '0';
        $this->settings->update('vat_enabled', $vatEnabled);

        // Each payment method's on/off toggle - unchecked checkboxes are
        // simply absent from $_POST, so `$request->post($key)` being falsy
        // means "off" here.
        foreach (['payment_method_paypal_enabled', 'payment_method_credit_card_enabled', 'payment_method_bank_transfer_enabled'] as $key) {
            $this->settings->update($key, $request->post($key) ? '1' : '0');
        }

        // Clamped to a 4-month minimum so a typo/mistake here can't make
        // the GDPR cleanup job start deleting customer accounts almost
        // immediately.
        $gdprMonths = max(4, (int)$request->post('gdpr_inactivity_months', 24));
        $this->settings->update('gdpr_inactivity_months', (string)$gdprMonths);

        $this->flash('success', __('admin.settings.flash_saved'));
        return $this->redirect('/admin/settings');
    }

    /** Builds and renders the settings form, pre-filled with whatever's currently saved - shared by index() (no error) and the various save*() methods (re-rendered with validation errors on a failed submit). */
    private function form(?string $siteUrlError): Response
    {
        $current = [];
        // Reads the settings table directly via raw PDO instead of looping
        // SettingsRepository::get() per key - one query for every setting
        // at once, which is both simpler and cheaper than N individual
        // cached lookups for a form that needs to display all of them.
        foreach ($this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
            $current[$row['setting_key']] = $row['setting_value'];
        }

        $fields = $this->fieldMeta();
        $legalFields = $this->legalFieldMeta();
        $paymentTextFields = ['paypal_client_id' => __('admin.settings.paypal_client_id'), 'stripe_publishable_key' => __('admin.settings.stripe_publishable_key')];
        $paymentSecretFields = ['paypal_client_secret' => __('admin.settings.paypal_client_secret'), 'stripe_secret_key' => __('admin.settings.stripe_secret_key')];
        $availableThemePackages = $this->themes->availablePackages();
        $activeThemePackageKey = $this->themes->activePackageKey();
        $colorThemes = $this->colorThemes();
        $allAvailableLangsForForm = I18n::availableLanguages();
        $enabledLangsForForm = I18n::enabledLanguages();
        $perPageOptions = \ShopRex\Services\PerPageResolver::OPTIONS;

        return $this->render('settings/index', compact(
            'current', 'fields', 'legalFields', 'paymentTextFields', 'paymentSecretFields', 'availableThemePackages',
            'activeThemePackageKey', 'colorThemes', 'allAvailableLangsForForm', 'enabledLangsForForm', 'perPageOptions', 'siteUrlError'
        ) + ['pageTitle' => __('admin.settings')]);
    }

    /** Mirrors getActiveTheme()'s lookup array in src/view-helpers.php (color accent, separate from theme *packages*). List of hardcoded color presets (accent color + navbar color + Bootstrap light/dark mode) the admin can pick between - this recolors CSS variables within whichever theme package/layout is active, it doesn't change page structure. */
    private function colorThemes(): array
    {
        return [
            'default' => ['label' => 'Default (Light)', 'bs_theme' => 'light', 'accent' => '#0d6efd', 'navbar_bg' => '#212529'],
            'dark'    => ['label' => 'Midnight (Dark)', 'bs_theme' => 'dark', 'accent' => '#6ea8fe', 'navbar_bg' => '#000000'],
            'ocean'   => ['label' => 'Ocean (Teal)', 'bs_theme' => 'light', 'accent' => '#0d9488', 'navbar_bg' => '#0f766e'],
        ];
    }

    /**
     * Direct port of writeInstalledConfigFile() from includes/functions.php.
     * Regenerates config/installed.php (the file that stores the DB
     * connection details + site URL as plain PHP constants) from scratch -
     * this is the only place those five values live, since they're needed
     * before the database/settings table can even be reached.
     */
    private function writeInstalledConfigFile(string $host, string $port, string $name, string $user, string $pass, string $siteUrl): bool
    {
        // var_export() renders each value as a safely-quoted/escaped PHP
        // literal (e.g. turns a string with a quote in it into valid PHP),
        // so string-concatenating these into a PHP file can't produce
        // broken or injectable code even if a value contains special
        // characters.
        $content = "<?php\n"
            . "// Generated by install.php / Admin -> Settings on " . date('c') . ".\n"
            . "// Contains your database password - keep this file private (already in .gitignore).\n"
            . "define('DB_HOST', " . var_export($host, true) . ");\n"
            . "define('DB_PORT', " . var_export($port, true) . ");\n"
            . "define('DB_NAME', " . var_export($name, true) . ");\n"
            . "define('DB_USER', " . var_export($user, true) . ");\n"
            . "define('DB_PASS', " . var_export($pass, true) . ");\n"
            . "define('SITE_URL', " . var_export(rtrim($siteUrl, '/'), true) . ");\n";
        // file_put_contents() returns the number of bytes written, or
        // false on failure (e.g. the web server user can't write to this
        // path) - the !== false check is what turns that into a clean
        // true/false success signal for the caller.
        return file_put_contents(SHOPREX_INSTALLED_FILE, $content) !== false;
    }
}
