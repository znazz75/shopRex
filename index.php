<?php

/**
 * Storefront front controller. Every storefront request not served
 * directly as a real file (see .htaccess's rewrite block) lands here;
 * dispatch is entirely src/routes/web.php's job from this point on - see
 * that file's docblock for the route table and the clean-URL/legacy-alias
 * pattern used throughout.
 */

$makeContainer = require __DIR__ . '/src/bootstrap.php';
$container = $makeContainer(false);

$router = new \ShopRex\Core\Router($container);
(require __DIR__ . '/src/routes/web.php')($router, $container);

(new \ShopRex\Core\App($container))->run($router);
