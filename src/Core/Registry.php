<?php

namespace ShopRex\Core;

/**
 * Holds a reference to this request's built Container so the small,
 * fixed set of global compatibility-shim functions in
 * src/view-helpers.php (called from legacy view templates with no
 * request/container object in scope) can reach container-managed
 * services like Csrf/Renderer. Set once, in src/container.php, right
 * after the Container is built.
 */
final class Registry
{
    private static ?Container $container = null;

    public static function set(Container $container): void
    {
        self::$container = $container;
    }

    public static function container(): Container
    {
        return self::$container ?? throw new \RuntimeException('Container has not been booted yet.');
    }
}
