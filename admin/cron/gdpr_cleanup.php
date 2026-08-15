<?php
/**
 * CLI-only entry point for the GDPR inactivity cleanup (see
 * Services\GdprService::runInactivityCleanup() for the actual logic). Run
 * daily via system cron - see README.md for a sample crontab line:
 *
 *   php /path/to/shopRex/admin/cron/gdpr_cleanup.php
 *
 * Refuses to run over HTTP - there is no admin auth check here by design
 * (a real cron job has no browser session to check), so exposing this to
 * the web would let anyone trigger account deletions. Admins can also run
 * it on demand from Admin -> Settings, which calls the exact same
 * GdprService method directly (in-process, already behind the admin
 * login) - this script just boots the same app container a normal web
 * request would and calls it from the command line instead.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../../config/config.php';

if (!IS_INSTALLED) {
    fwrite(STDERR, "shopRex is not installed yet - nothing to clean up.\n");
    exit(1);
}

// Boots the real app container - autoloader, database connection,
// settings/i18n/mailer wiring, everything a normal web request gets -
// exactly like index.php/admin/index.php do, just from the CLI instead of
// through the router. `false` (storefront-flavored container) is an
// arbitrary choice here: GdprService doesn't touch anything the storefront
// vs. admin distinction affects (that only changes which Renderer/
// ThemeManager get built, and this script never renders a view).
$makeContainer = require __DIR__ . '/../../src/bootstrap.php';
$container = $makeContainer(false);

$gdpr = $container->make(\ShopRex\Services\GdprService::class);
$result = $gdpr->runInactivityCleanup();

echo "shopRex GDPR cleanup - " . $result['checked_at'] . "\n";
echo "  Deletion warnings sent: " . $result['warned'] . "\n";
echo "  Accounts deleted: " . $result['deleted'] . "\n";
