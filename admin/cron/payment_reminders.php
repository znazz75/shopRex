<?php
/**
 * CLI-only entry point for the payment-reminder sweep (see
 * Services\PaymentReminderService::runAutomaticReminders() for the actual
 * logic). Run daily via system cron - see README.md for a sample crontab
 * line:
 *
 *   php /path/to/shopRex/admin/cron/payment_reminders.php
 *
 * Safe to run unconditionally regardless of whether an admin has actually
 * turned automatic sending on (Admin -> Settings -> Payment Reminders) -
 * runAutomaticReminders() itself no-ops when that setting is off, so this
 * crontab line never needs editing when the setting is later toggled.
 *
 * Refuses to run over HTTP, same reasoning as admin/cron/gdpr_cleanup.php:
 * a real cron job has no browser session to check, so exposing this to
 * the web would let anyone trigger it. Admins can also send a reminder for
 * one order on demand from that order's own admin page, which calls
 * Services\PaymentReminderService::sendReminder() directly (in-process,
 * already behind the admin login) - this script just boots the same app
 * container a normal web request would and calls the bulk method from the
 * command line instead.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line.');
}

require_once __DIR__ . '/../../config/config.php';

if (!IS_INSTALLED) {
    fwrite(STDERR, "shopRex is not installed yet - nothing to send.\n");
    exit(1);
}

// Boots the real app container - see admin/cron/gdpr_cleanup.php's own
// docblock for why `false` (storefront-flavored container) is the right
// choice here too: this script never renders a view, only sends email.
$makeContainer = require __DIR__ . '/../../src/bootstrap.php';
$container = $makeContainer(false);

$result = $container->make(\ShopRex\Services\PaymentReminderService::class)->runAutomaticReminders();

echo "shopRex payment reminders - " . $result['checked_at'] . "\n";
if (!$result['enabled']) {
    echo "  Automatic sending is off (Admin -> Settings -> Payment Reminders) - nothing sent.\n";
} else {
    echo "  Unpaid orders checked: " . $result['checked'] . "\n";
    echo "  Reminders sent: " . $result['sent'] . "\n";
}
