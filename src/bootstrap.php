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

require_once __DIR__ . '/../config/config.php';

if (!IS_INSTALLED) {
    header('Location: ' . (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/admin') ? '../install.php' : 'install.php'));
    exit;
}

require_once __DIR__ . '/../config/database.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'ShopRex\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
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
