<?php

namespace ShopRex\Core;

/**
 * Holds a reference to this request's built Container so the small,
 * fixed set of global compatibility-shim functions in
 * src/view-helpers.php (called from legacy view templates with no
 * request/container object in scope) can reach container-managed
 * services like Csrf/Renderer. Set once, in src/container.php, right
 * after the Container is built.
 *
 * In plain terms: normally, the Container is passed explicitly from object
 * to object (App -> Router -> Controller -> ...). But plain global helper
 * functions like e()/getSetting() (used inside view templates) have no such
 * chain to receive it through - they're just called bare, like
 * `<?= getSetting('site_name') ?>`. Registry is the one deliberate escape
 * hatch: a single global static slot that holds "the current request's
 * Container", so those helper functions can still reach real services.
 * Keep this usage limited to src/view-helpers.php - application code should
 * always receive its Container explicitly instead of reaching for Registry.
 */
final class Registry
{
    /** The current request's Container instance, or null before src/container.php has run (i.e. before the app has finished booting). */
    private static ?Container $container = null;

    /** Stores the booted Container so later calls to container() can retrieve it - called exactly once, from src/container.php. */
    public static function set(Container $container): void
    {
        self::$container = $container;
    }

    /** Retrieves the previously-stored Container, or throws if set() was never called - a clear "you forgot to boot the app first" error rather than a confusing null-pointer-style failure later on. */
    public static function container(): Container
    {
        return self::$container ?? throw new \RuntimeException('Container has not been booted yet.');
    }
}
