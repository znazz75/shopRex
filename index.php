<?php

/**
 * Storefront front controller. Every storefront request not served
 * directly as a real file (see .htaccess's rewrite block) lands here;
 * dispatch is entirely src/routes/web.php's job from this point on - see
 * that file's docblock for the route table and the clean-URL/legacy-alias
 * pattern used throughout.
 *
 * Why this file is so short: per CLAUDE.md's architecture, shopRex routes
 * requests through a router rather than mapping URLs to physical .php
 * files, so this is the *only* storefront entry point web requests ever
 * hit directly (see the root .htaccess's rewrite block, which sends
 * everything that isn't a real file/directory here). Its whole job is to
 * wire up the dependency-injection Container, build the route table, and
 * hand off to Core\App - all the actual per-request logic lives in
 * Controllers\Storefront\* classes, not here.
 */

// Builds the Container (see src/container.php's docblock) - a factory
// closure rather than an already-built Container, since storefront vs
// admin need slightly different Renderer/ThemeManager wiring (see that
// docblock). `false` here means "storefront", not "admin".
$makeContainer = require __DIR__ . '/src/bootstrap.php';
$container = $makeContainer(false);

// The Router matches the current request's URL/method against every
// registered route and will dispatch to the matching controller action.
$router = new \ShopRex\Core\Router($container);
// src/routes/web.php returns a closure that registers every storefront
// route ($router->get(...)/->post(...) calls) onto $router - see that
// file for the full route table.
(require __DIR__ . '/src/routes/web.php')($router, $container);

// Actually handles the current request: matches it against $router's
// routes and runs the matching controller action (or a 404/error
// response if nothing matches).
(new \ShopRex\Core\App($container))->run($router);
