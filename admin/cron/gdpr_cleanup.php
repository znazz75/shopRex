<?php
/**
 * CLI-only entry point for the GDPR inactivity cleanup (see
 * includes/GdprCleanup.php for the actual logic). Run daily via system
 * cron - see README.md for a sample crontab line:
 *
 *   php /path/to/shopRex/admin/cron/gdpr_cleanup.php
 *
 * Refuses to run over HTTP - there is no admin auth check here by design
 * (a real cron job has no browser session to check), so exposing this to
 * the web would let anyone trigger account deletions. Admins can also run
 * it on demand from Admin -> Settings, which calls runGdprInactivityCleanup()
 * directly (in-process, already behind the admin login).
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

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/Mailer.php';
require_once __DIR__ . '/../../includes/GdprTools.php';
require_once __DIR__ . '/../../includes/GdprCleanup.php';

$result = runGdprInactivityCleanup();

echo "shopRex GDPR cleanup - " . $result['checked_at'] . "\n";
echo "  Deletion warnings sent: " . $result['warned'] . "\n";
echo "  Accounts deleted: " . $result['deleted'] . "\n";
