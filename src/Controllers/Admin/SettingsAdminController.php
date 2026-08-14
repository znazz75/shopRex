<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Core\ThemeManager;
use ShopRex\Services\GdprService;
use ShopRex\Services\I18n;
use ShopRex\Services\SettingsRepository;

/** Direct port of admin/settings.php. */
final class SettingsAdminController extends AdminCrudController
{
    private readonly \PDO $pdo;
    private readonly SettingsRepository $settings;
    private readonly ThemeManager $themes;
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

    /** v2.00 - Admin -> Settings -> Company / Legal (Phase 6's vat_id/company_registration_number/company_legal_name). */
    private function legalFieldMeta(): array
    {
        return [
            'company_legal_name'           => __('admin.settings.company_legal_name'),
            'vat_id'                       => __('admin.settings.vat_id'),
            'company_registration_number'  => __('admin.settings.company_registration_number'),
        ];
    }

    public function index(Request $request): Response
    {
        $siteUrlError = null;
        return $this->form($siteUrlError);
    }

    public function runGdprCleanup(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $result = $this->gdpr->runInactivityCleanup();
        $this->flash('success', __('admin.settings.gdpr_cleanup_result', ['warned' => $result['warned'], 'deleted' => $result['deleted']]));
        return $this->redirect('/admin/settings');
    }

    public function saveSiteUrl(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $newSiteUrl = rtrim(trim((string)$request->post('site_url', '')), '/');
        if ($newSiteUrl === '' || !preg_match('~^https?://~i', $newSiteUrl)) {
            return $this->form(__('admin.settings.site_url_required'));
        }
        if (!$this->writeInstalledConfigFile(DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASS, $newSiteUrl)) {
            return $this->form(__('admin.settings.site_url_write_error'));
        }
        $this->flash('success', __('admin.settings.site_url_updated'));
        return Response::redirect(rtrim($newSiteUrl, '/') . '/admin/settings');
    }

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        foreach (array_keys($this->fieldMeta()) as $key) {
            $this->settings->update($key, trim((string)$request->post($key, '')));
        }
        // v2.00 - not seeded rows (same reasoning as the payment keys below), upsert.
        foreach (array_keys($this->legalFieldMeta()) as $key) {
            $this->settings->upsert($key, trim((string)$request->post($key, '')));
        }

        $paymentTextFields = ['paypal_client_id', 'stripe_publishable_key'];
        $paymentSecretFields = ['paypal_client_secret', 'stripe_secret_key'];
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

        $theme = array_key_exists($request->post('site_theme', ''), $this->colorThemes()) ? $request->post('site_theme') : 'default';
        $this->settings->update('site_theme', $theme);

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
        $submittedLangs = array_values(array_intersect(array_keys($allAvailableLangs), (array)$request->post('enabled_languages', [])));
        if (!$submittedLangs) {
            $submittedLangs = array_keys($allAvailableLangs);
        }
        $lang = in_array($request->post('default_language', ''), $submittedLangs, true) ? $request->post('default_language') : $submittedLangs[0];
        $this->settings->update('default_language', $lang);
        $this->settings->upsert('enabled_languages', implode(',', $submittedLangs));

        $vatEnabled = $request->post('vat_enabled') ? '1' : '0';
        $this->settings->update('vat_enabled', $vatEnabled);

        foreach (['payment_method_paypal_enabled', 'payment_method_credit_card_enabled', 'payment_method_bank_transfer_enabled'] as $key) {
            $this->settings->update($key, $request->post($key) ? '1' : '0');
        }

        $gdprMonths = max(4, (int)$request->post('gdpr_inactivity_months', 24));
        $this->settings->update('gdpr_inactivity_months', (string)$gdprMonths);

        $this->flash('success', __('admin.settings.flash_saved'));
        return $this->redirect('/admin/settings');
    }

    private function form(?string $siteUrlError): Response
    {
        $current = [];
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

    /** Mirrors the THEMES const in includes/functions.php (color accent, separate from theme *packages*). */
    private function colorThemes(): array
    {
        return [
            'default' => ['label' => 'Default (Light)', 'bs_theme' => 'light', 'accent' => '#0d6efd', 'navbar_bg' => '#212529'],
            'dark'    => ['label' => 'Midnight (Dark)', 'bs_theme' => 'dark', 'accent' => '#6ea8fe', 'navbar_bg' => '#000000'],
            'ocean'   => ['label' => 'Ocean (Teal)', 'bs_theme' => 'light', 'accent' => '#0d9488', 'navbar_bg' => '#0f766e'],
        ];
    }

    /** Direct port of writeInstalledConfigFile() from includes/functions.php. */
    private function writeInstalledConfigFile(string $host, string $port, string $name, string $user, string $pass, string $siteUrl): bool
    {
        $content = "<?php\n"
            . "// Generated by install.php / Admin -> Settings on " . date('c') . ".\n"
            . "// Contains your database password - keep this file private (already in .gitignore).\n"
            . "define('DB_HOST', " . var_export($host, true) . ");\n"
            . "define('DB_PORT', " . var_export($port, true) . ");\n"
            . "define('DB_NAME', " . var_export($name, true) . ");\n"
            . "define('DB_USER', " . var_export($user, true) . ");\n"
            . "define('DB_PASS', " . var_export($pass, true) . ");\n"
            . "define('SITE_URL', " . var_export(rtrim($siteUrl, '/'), true) . ");\n";
        return file_put_contents(SHOPREX_INSTALLED_FILE, $content) !== false;
    }
}
