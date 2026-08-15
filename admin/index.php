<?php

/**
 * Admin front controller. Every admin request not served directly as a
 * real file (see the root .htaccess's rewrite block, ^admin(/.*)?$) lands
 * here; dispatch is entirely src/routes/admin.php's job from this point
 * on - see that file's docblock for the route table and the clean-URL/
 * legacy-alias pattern used throughout.
 *
 * Why this file is so short: the admin-side twin of the storefront's
 * index.php (see that file's comments for the fuller explanation) - this
 * is the only admin entry point web requests ever hit directly. It builds
 * the Container, registers the admin route table, and hands off to
 * Core\App; the actual per-request logic lives in Controllers\Admin\*
 * classes.
 */

// Builds the Container (see src/container.php's docblock) - `true` here
// means "admin", which wires up the fixed-layout Renderer/ThemeManager
// (no theme-package mechanism on the admin side) instead of the
// storefront's theme-aware ones.
$makeContainer = require __DIR__ . '/../src/bootstrap.php';
$container = $makeContainer(true);

// The Router matches the current request's URL/method against every
// registered admin route and will dispatch to the matching controller
// action, enforcing each route's ->capability() login/role gate first
// (see Core\Auth\AdminAuth).
$router = new \ShopRex\Core\Router($container);
// src/routes/admin.php returns a closure that registers every admin route
// ($router->get(...)/->post(...) calls) onto $router - see that file for
// the full route table.
(require __DIR__ . '/../src/routes/admin.php')($router, $container);

// Actually handles the current request: matches it against $router's
// routes and runs the matching controller action (or a 404/"no access"
// response if nothing matches or the capability check fails).
(new \ShopRex\Core\App($container))->run($router);
