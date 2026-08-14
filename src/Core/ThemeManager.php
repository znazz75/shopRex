<?php

namespace ShopRex\Core;

use ShopRex\Services\SettingsRepository;

/**
 * Theme *package* resolution (a structurally different storefront layout,
 * not just colors) - byte-for-byte port of getAvailableThemePackages()/
 * getActiveThemePackageKey()/themeTemplatePath()/themeStylesheetTag() from
 * includes/functions.php. The color-accent THEMES const is a separate,
 * much smaller concern and stays as a plain constant read directly by the
 * default theme's header view via the view-helpers shim - not worth its
 * own class.
 *
 * A theme package's *manifest* (theme.json, discovered by glob) and its
 * *static asset* (style.css, linked directly from HTML) have to stay
 * web-servable, so they still live under themes/<key>/ at the project
 * root. Its PHP *templates* (header.php/footer.php/home.php) do not - and
 * must NOT, since src/ is blocked from direct access (src/.htaccess) the
 * same way config/includes/sql are - so those live under
 * src/Views/storefront/theme/<key>/, resolved separately from the
 * manifest/asset location. $manifestDir and $templatesDir are therefore
 * two different roots pointing at the same set of package keys.
 */
final class ThemeManager
{
    private readonly string $manifestDir;
    private readonly string $templatesDir;
    private readonly string $coreSlotDir;
    private ?array $packagesCache = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        string $manifestDir,
        string $templatesDir,
        string $coreSlotDir,
    ) {
        $this->manifestDir = rtrim($manifestDir, '/\\') . '/';
        $this->templatesDir = rtrim($templatesDir, '/\\') . '/';
        $this->coreSlotDir = rtrim($coreSlotDir, '/\\');
    }

    /** @return array<string, array{name: string, description: string}> */
    public function availablePackages(): array
    {
        if ($this->packagesCache !== null) {
            return $this->packagesCache;
        }
        $packages = [];
        foreach (glob($this->manifestDir . '*/theme.json') ?: [] as $file) {
            $key = basename(dirname($file));
            $meta = json_decode((string)file_get_contents($file), true) ?: [];
            $packages[$key] = [
                'name'        => $meta['name'] ?? ucfirst($key),
                'description' => $meta['description'] ?? '',
            ];
        }
        if (!$packages) {
            $packages['default'] = ['name' => 'Default', 'description' => ''];
        }
        ksort($packages);
        return $this->packagesCache = $packages;
    }

    public function activePackageKey(): string
    {
        $key = $this->settings->get('site_theme_package', 'default');
        return array_key_exists($key, $this->availablePackages()) ? $key : 'default';
    }

    /** Resolve one themeable slot ('header.php'/'footer.php'/'home.php') to a real file path. */
    public function resolve(string $template): string
    {
        $packagePath = $this->templatesDir . $this->activePackageKey() . '/' . $template;
        return is_file($packagePath) ? $packagePath : $this->coreSlotDir . '/' . $template;
    }

    public function stylesheetTag(): string
    {
        $key = $this->activePackageKey();
        if (!is_file($this->manifestDir . $key . '/style.css')) {
            return '';
        }
        return '<link rel="stylesheet" href="' . rtrim(SITE_URL, '/') . '/themes/' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '/style.css">';
    }
}
