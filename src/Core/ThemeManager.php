<?php

namespace ShopRex\Core;

use ShopRex\Services\SettingsRepository;

/**
 * Theme *package* resolution (a structurally different storefront layout,
 * not just colors) - byte-for-byte port of getAvailableThemePackages()/
 * getActiveThemePackageKey()/themeTemplatePath()/themeStylesheetTag() from
 * the original procedural app. The color-accent theme is a separate, much
 * smaller concern (see getActiveTheme()'s static lookup array in
 * src/view-helpers.php) - not worth its own class.
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
 *
 * In plain terms: a "theme package" is a whole alternate look for the
 * storefront (different header/footer/homepage layout, not just different
 * colors). This class figures out which package is currently active (an
 * admin setting) and, for each of the three swappable page pieces
 * (header/footer/home), which actual file on disk should be used - the
 * active package's own version if it provides one, otherwise the default
 * theme's version as a fallback so a package doesn't have to override every
 * single piece to be usable.
 */
final class ThemeManager
{
    /** Folder that holds each package's web-servable theme.json + style.css (themes/<key>/) - trailing slash always present, normalized in the constructor. */
    private readonly string $manifestDir;

    /** Folder that holds each package's PHP templates (src/Views/storefront/theme/<key>/, blocked from direct web access) - trailing slash always present. */
    private readonly string $templatesDir;

    /** Folder holding the default/fallback templates used whenever the active package doesn't provide its own version of a slot. */
    private readonly string $coreSlotDir;

    /** Memoized result of availablePackages() so the themes/ directory only gets glob()'d once per request, not once per call. */
    private ?array $packagesCache = null;

    public function __construct(
        private readonly SettingsRepository $settings,
        string $manifestDir,
        string $templatesDir,
        string $coreSlotDir,
    ) {
        // Normalize away any trailing slash the caller passed in, then add
        // exactly one back, so path concatenation elsewhere in this class
        // (e.g. $this->manifestDir . '*/theme.json') never ends up with a
        // doubled or missing slash regardless of how the caller formatted it.
        $this->manifestDir = rtrim($manifestDir, '/\\') . '/';
        $this->templatesDir = rtrim($templatesDir, '/\\') . '/';
        $this->coreSlotDir = rtrim($coreSlotDir, '/\\');
    }

    /**
     * Scans themes/*\/theme.json to build the list of installed theme
     * packages, keyed by folder name, with each one's display name/
     * description read out of its manifest (falling back to a
     * capitalized folder name if the manifest omits 'name'). Always
     * includes at least a 'default' entry even if no theme.json files
     * exist at all, so callers never have to handle an empty list.
     * @return array<string, array{name: string, description: string}>
     */
    public function availablePackages(): array
    {
        if ($this->packagesCache !== null) {
            return $this->packagesCache;
        }
        $packages = [];
        // glob() finds every themes/<anything>/theme.json file - each match
        // is one installed theme package; dirname()+basename() recovers just
        // the <anything> folder name to use as this package's key.
        foreach (glob($this->manifestDir . '*/theme.json') ?: [] as $file) {
            $key = basename(dirname($file));
            $meta = json_decode((string)file_get_contents($file), true) ?: [];
            $packages[$key] = [
                'name'        => $meta['name'] ?? ucfirst($key),
                'description' => $meta['description'] ?? '',
            ];
        }
        if (!$packages) {
            // No theme.json files found at all (e.g. a fresh install before
            // any packages were added) - still offer a usable 'default'
            // entry rather than an empty list.
            $packages['default'] = ['name' => 'Default', 'description' => ''];
        }
        // Alphabetical by key, purely so the admin theme picker has a
        // stable, predictable order.
        ksort($packages);
        return $this->packagesCache = $packages;
    }

    /** Returns the currently active theme package's key (the admin-configured 'site_theme_package' setting), falling back to 'default' if the configured key no longer refers to an installed package (e.g. it was deleted from disk). */
    public function activePackageKey(): string
    {
        $key = $this->settings->get('site_theme_package', 'default');
        return array_key_exists($key, $this->availablePackages()) ? $key : 'default';
    }

    /** Resolve one themeable slot ('header.php'/'footer.php'/'home.php') to a real file path. */
    public function resolve(string $template): string
    {
        $packagePath = $this->templatesDir . $this->activePackageKey() . '/' . $template;
        // If the active package provides its own version of this slot, use
        // it; otherwise silently fall back to the shared default template so
        // a package only has to override the slots it actually wants to
        // customize.
        return is_file($packagePath) ? $packagePath : $this->coreSlotDir . '/' . $template;
    }

    /** Builds the <link> tag for the active theme package's style.css, or an empty string if that package doesn't ship one - safe to echo directly into a page's <head>. */
    public function stylesheetTag(): string
    {
        $key = $this->activePackageKey();
        if (!is_file($this->manifestDir . $key . '/style.css')) {
            return '';
        }
        // htmlspecialchars() on the key even though it currently only ever
        // comes from a folder name/admin setting - cheap defense-in-depth
        // against it ever containing HTML-special characters.
        return '<link rel="stylesheet" href="' . rtrim(SITE_URL, '/') . '/themes/' . htmlspecialchars($key, ENT_QUOTES, 'UTF-8') . '/style.css">';
    }
}
