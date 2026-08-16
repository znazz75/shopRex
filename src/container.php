<?php

/**
 * Builds the Container. Returns a factory closure rather than a built
 * Container directly, because the one thing that legitimately differs
 * between the storefront and admin front controllers is *which views
 * directory + slot resolution* Renderer uses (storefront is theme-package
 * aware via ThemeManager; admin has a single fixed layout, same as today -
 * there's no "admin/themes/" mechanism to preserve). Everything else
 * (Session, Csrf, FlashBag, SettingsRepository, Mailer, ...) is identical
 * either way.
 *
 *   $makeContainer = require __DIR__ . '/src/bootstrap.php';
 *   $container = $makeContainer(isAdmin: false); // or true, from admin/index.php
 *
 * In plain terms: this file is the app's "wiring diagram" - it's the one
 * place that says how every shared service (database connection, cart,
 * settings, mailer, payment gateways, ...) gets built and what it depends
 * on. Nothing here does real work itself; it just registers factory
 * closures on the Container so services get built lazily, only when
 * something actually asks for them via Container::make().
 */

use ShopRex\Core\Container;
use ShopRex\Core\Registry;
use ShopRex\Core\Session;
use ShopRex\Core\Csrf;
use ShopRex\Core\FlashBag;
use ShopRex\Core\Renderer;
use ShopRex\Core\ThemeManager;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\DiscountCalculator;
use ShopRex\Services\I18n;
use ShopRex\Services\MenuTreeService;
use ShopRex\Services\PerPageResolver;
use ShopRex\Services\SettingsRepository;
use ShopRex\Services\TaxCalculator;
use ShopRex\Services\TranslationOverlay;
use ShopRex\Models\Cart;
use ShopRex\Payment\PaymentGatewayFactory;
use ShopRex\Payment\PaymentSettings;

// v3.00: every class that used to live under includes/ (Cart, SimplePdf,
// InvoiceGenerator, Mailer, ImageProcessor, GdprTools/GdprCleanup) has been
// ported into the ShopRex\ namespace under src/ and is now reached through
// the ordinary spl_autoload_register autoloader (src/bootstrap.php) like
// everything else - no more manual require_once calls needed here. The
// includes/ directory itself no longer exists (see CHANGELOG.md's v3.00
// entry for the full list of what moved where).

return function (bool $isAdmin = false): Container {
    $container = new Container();

    // Everything below is registered with Container::singleton(), which
    // just records *how* to build each service (a closure) without
    // building it yet - see Core\Container's docblock. Each closure
    // receives the container itself ($c) so it can pull its own
    // dependencies out by id, wiring up the whole dependency graph by hand
    // (no autowiring/reflection magic - deliberate, see Container's
    // docblock for why).

    // The shared PDO database connection - delegates to the existing
    // legacy Database::getConnection() singleton (config/database.php)
    // rather than opening a second connection, so OOP and legacy code
    // share one PDO instance/transaction state.
    $container->singleton(\PDO::class, fn () => Database::getConnection());

    $container->singleton(Session::class, fn () => new Session());
    $container->singleton(Csrf::class, fn (Container $c) => new Csrf($c->make(Session::class)));
    $container->singleton(FlashBag::class, fn (Container $c) => new FlashBag($c->make(Session::class)));

    $container->singleton(SettingsRepository::class, fn (Container $c) => new SettingsRepository($c->make(\PDO::class)));
    // Admin -> Numbering (customer/invoice/RMA-ticket/withdrawal-request
    // sequential numbers) - see Services\NumberSequenceService's docblock.
    $container->singleton(\ShopRex\Services\NumberSequenceService::class, fn (Container $c) => new \ShopRex\Services\NumberSequenceService($c->make(\PDO::class)));

    $container->singleton(CategoryTreeService::class, fn (Container $c) => new CategoryTreeService($c->make(\PDO::class), $c->make(SettingsRepository::class)));
    $container->singleton(MenuTreeService::class, fn (Container $c) => new MenuTreeService($c->make(\PDO::class), $c->make(CategoryTreeService::class)));
    $container->singleton(DiscountCalculator::class, fn () => new DiscountCalculator());
    $container->singleton(TaxCalculator::class, fn (Container $c) => new TaxCalculator($c->make(\PDO::class), $c->make(SettingsRepository::class), $c->make(DiscountCalculator::class)));
    $container->singleton(TranslationOverlay::class, fn (Container $c) => new TranslationOverlay($c->make(\PDO::class), $c->make(SettingsRepository::class)));
    $container->singleton(PerPageResolver::class, fn (Container $c) => new PerPageResolver($c->make(SettingsRepository::class)));

    $container->singleton(Cart::class, fn (Container $c) => new Cart(
        $c->make(Session::class),
        $c->make(\PDO::class),
        $c->make(TranslationOverlay::class),
        $c->make(DiscountCalculator::class),
        $c->make(TaxCalculator::class)
    ));

    $container->singleton(PaymentSettings::class, fn (Container $c) => new PaymentSettings($c->make(SettingsRepository::class)));
    $container->singleton(PaymentGatewayFactory::class, fn (Container $c) => new PaymentGatewayFactory($c->make(PaymentSettings::class)));

    $container->singleton(\ShopRex\Services\CheckoutService::class, fn (Container $c) => new \ShopRex\Services\CheckoutService(
        $c->make(\PDO::class),
        $c->make(Cart::class),
        $c->make(PaymentGatewayFactory::class),
        $c->make(SettingsRepository::class),
        $c->make(\ShopRex\Services\NumberSequenceService::class)
    ));

    // Shared by login.php/register.php/forgot_password.php (this instance)
    // and, from Phase 6, a second instance bound to contact_message_attempts
    // for the contact form.
    $container->singleton(\ShopRex\Services\RateLimiter::class, fn (Container $c) => new \ShopRex\Services\RateLimiter($c->make(\PDO::class), 'login_attempts'));
    // Second RateLimiter instance, same class, bound to a different table -
    // see Services\RateLimiter's docblock.
    $container->singleton('RateLimiter.contact', fn (Container $c) => new \ShopRex\Services\RateLimiter($c->make(\PDO::class), 'contact_message_attempts'));
    $container->singleton(\ShopRex\Services\GdprService::class, fn (Container $c) => new \ShopRex\Services\GdprService($c->make(\PDO::class), $c->make(SettingsRepository::class)));
    $container->singleton(\ShopRex\Services\PdfDocumentGenerator::class, fn () => new \ShopRex\Services\PdfDocumentGenerator());

    $projectRoot = dirname(__DIR__);

    // Always the storefront-flavored ThemeManager, regardless of whether
    // this request is admin or storefront - Admin -> Settings' layout/
    // color-theme pickers need to enumerate the STOREFRONT's available
    // theme packages even though the ambient ThemeManager::class binding
    // below is the admin-flavored one (fixed layout, no packages) on an
    // admin request. Cheap to build twice; ThemeManager itself does no I/O
    // until availablePackages()/resolve() are actually called.
    $container->singleton('ThemeManager.storefront', fn (Container $c) => new ThemeManager(
        $c->make(SettingsRepository::class),
        $projectRoot . '/themes',
        $projectRoot . '/src/Views/storefront/theme',
        $projectRoot . '/src/Views/storefront/theme/default'
    ));

    if ($isAdmin) {
        // Admin has one fixed layout - no package/override mechanism exists
        // on this side, so manifestDir/templatesDir are both pointed at a
        // directory that never contains a matching <package>/header.php;
        // resolve() always falls through to coreSlotDir, i.e.
        // src/Views/admin/layout/*.php.
        $container->singleton(ThemeManager::class, fn (Container $c) => new ThemeManager(
            $c->make(SettingsRepository::class),
            $projectRoot . '/admin/themes-none',
            $projectRoot . '/admin/themes-none',
            $projectRoot . '/src/Views/admin/layout'
        ));
        $container->singleton(Renderer::class, fn (Container $c) => new Renderer(
            $projectRoot . '/src/Views/admin',
            $c->make(ThemeManager::class)
        ));
    } else {
        // manifestDir (theme.json + style.css - stays web-servable) and
        // templatesDir (header.php/footer.php/home.php - blocked from
        // direct access under src/, see src/.htaccess) are deliberately
        // different roots pointing at the same package keys - see
        // ThemeManager's docblock. Same instance as 'ThemeManager.storefront'
        // above - only one is actually built (Container::singleton() closures
        // are lazy), this alias just makes ThemeManager::class resolve to it
        // on a storefront request the same way it always has.
        $container->singleton(ThemeManager::class, fn (Container $c) => $c->make('ThemeManager.storefront'));
        $container->singleton(Renderer::class, fn (Container $c) => new Renderer(
            $projectRoot . '/src/Views/storefront',
            $c->make(ThemeManager::class)
        ));
    }

    // Stash this container in the static Registry so the small set of
    // global compatibility-shim functions in view-helpers.php (called from
    // legacy view templates with no container in scope) can still reach
    // container-managed services - see Core\Registry's docblock.
    Registry::set($container);
    // Boots the i18n system (loads the current language's translation
    // strings) once per request, right after SettingsRepository is
    // available, since I18n needs it to know which language is active.
    I18n::boot($container->make(SettingsRepository::class), $projectRoot . '/includes/lang');

    return $container;
};
