<?php
/**
 * OOP application bootstrap.
 *
 * This is the src/ tree's entry point, required once by index.php and
 * admin/index.php (the new front controllers - see docs/OOP_ARCHITECTURE.md
 * and CLAUDE.md for the overall shape). It intentionally does NOT replace
 * config/config.php or config/database.php - both already do exactly one
 * job each (define constants + start the hardened session; PDO singleton)
 * and are left untouched, including the installer redirect and the
 * HTTPS-forcing logic in config/config.php.
 *
 * No Composer, no framework - just a hand-rolled PSR-4-ish autoloader
 * mapping the ShopRex\ namespace onto src/, matching the project's
 * "zero dependencies to run" constraint (see CLAUDE.md).
 */

// config/config.php defines constants (SITE_URL, IS_INSTALLED, SHOPREX_VERSION,
// ...) and starts the hardened session (HttpOnly/SameSite/Secure cookie
// params) - required first since almost everything below depends on those
// constants existing.
require_once __DIR__ . '/../config/config.php';

if (!IS_INSTALLED) {
    // Nothing has been configured yet (no config/installed.php and no
    // SHOPREX_DB_* env vars) - send the visitor to the installer instead of
    // letting the app try (and fail) to use a database connection that
    // doesn't exist yet. The path differs because admin/index.php lives one
    // directory deeper than index.php, so it needs an extra "../" to reach
    // install.php at the project root.
    header('Location: ' . (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin') ? '../install.php' : 'install.php'));
    exit;
}

// Only safe to open the DB connection once we know the app is installed -
// database.php's PDO singleton would otherwise fail against a nonexistent
// database.
require_once __DIR__ . '/../config/database.php';

// Hand-rolled PSR-4-ish autoloader: whenever PHP encounters an unknown
// class in the ShopRex\ namespace (e.g. ShopRex\Core\Router), this converts
// it to a file path under src/ (ShopRex\Core\Router -> src/Core/Router.php)
// and requires it on demand - this is what lets every class in src/ be used
// without a manual require_once for each one, and without Composer.
spl_autoload_register(function (string $class): void {
    $prefix = 'ShopRex\\';
    if (!str_starts_with($class, $prefix)) {
        // Not one of our classes (could be a built-in PHP class, or a
        // legacy global-namespace class like \Database) - let some other
        // autoloader (or nothing) handle it instead.
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
    // If the file doesn't exist, silently do nothing - PHP will then throw
    // its own normal "Class not found" error, which is more informative
    // than anything this autoloader could add.
});

// Tier-2 compatibility shim: a handful of untouched legacy view templates
// (includes/header.php, includes/footer.php, includes/home.php,
// themes/sidebar/home.php) still call these as bare functions. Every
// *business-logic* function is ported into a class outright with no shim -
// see the "Compatibility shim" note in the architecture plan.
require_once __DIR__ . '/view-helpers.php';

// Returns the Container factory closure - see src/container.php for why
// it's a factory (fn(bool $isAdmin): Container) rather than a single
// built instance. The two front controllers (index.php, admin/index.php)
// each call it, then pull a Router out of their own src/routes/*.php file
// and run it against that container.
return require __DIR__ . '/container.php';
