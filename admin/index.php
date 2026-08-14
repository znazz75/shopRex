<?php

/**
 * Admin front controller. Every admin request not served directly as a
 * real file (see the root .htaccess's rewrite block, ^admin(/.*)?$) lands
 * here; dispatch is entirely src/routes/admin.php's job from this point
 * on - see that file's docblock for the route table and the clean-URL/
 * legacy-alias pattern used throughout.
 */

$makeContainer = require __DIR__ . '/../src/bootstrap.php';
$container = $makeContainer(true);

$router = new \ShopRex\Core\Router($container);
(require __DIR__ . '/../src/routes/admin.php')($router, $container);

(new \ShopRex\Core\App($container))->run($router);
