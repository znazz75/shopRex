<?php
/**
 * File-level purpose: automated GDPR "right to erasure" housekeeping for
 * inactive customer accounts. After Admin -> Settings -> Data Retention's
 * configured period of inactivity minus 3 months, a customer is emailed a
 * warning; 3 months after that warning (i.e. at the full retention period),
 * their account is erased via GdprTools::deleteCustomer(). Test accounts
 * (is_test_account = 1) are never touched, since they're not real people's
 * data in the first place.
 *
 * This is one of the "legacy classes kept as-is" (a plain global function
 * here rather than a class, but the same category - see CLAUDE.md's
 * "Legacy classes kept as-is" section). The new `Services\GdprService` is a
 * direct port of this same logic for use from the OOP admin controllers;
 * this file remains in use as-is because admin/cron/gdpr_cleanup.php (a
 * standalone CLI entry point, run via a real system cron job outside of any
 * web request) still requires it directly rather than going through the
 * src/ autoloader/Container.
 *
 * Run this from a real system cron via admin/cron/gdpr_cleanup.php (see
 * README.md for a sample crontab line), or on demand from Admin -> Settings.
 */

/**
 * Runs one pass of the two-step inactivity cleanup (warn, then delete) and
 * returns a small summary array for the caller (cron script output, or the
 * Admin -> Settings "run now" screen) to display. Safe to call repeatedly
 * (e.g. once a day) - each step only acts on customers who haven't already
 * been warned/deleted, so re-running never double-sends warnings or
 * double-deletes.
 */
function runGdprInactivityCleanup(): array
{
    // Needs to be at least 4 so "warn 3 months before deletion" is meaningful.
    $months = max(4, (int)getSetting('gdpr_inactivity_months', '24'));
    $pdo = db();

    // Step 1: warn customers approaching the deletion threshold. "Last
    // activity" is the most recent of: last login, account creation, and
    // their most recent order - whichever is latest.
    // Anyone whose last activity is older than (retention period - 3
    // months) is due a warning now, so their deletion lands right at the
    // full configured retention period.
    $warnCutoff = date('Y-m-d H:i:s', strtotime('-' . ($months - 3) . ' months'));
    // is_test_account = 0 excludes demo/trial accounts (see CLAUDE.md's
    // "Test accounts" section - these never count as real customer data).
    // deletion_warning_sent_at IS NULL means "hasn't already been warned",
    // so a repeat run of this function never sends a second warning email.
    // GREATEST(...) picks whichever of the three activity signals is most
    // recent, so a customer who logged in yesterday but registered years
    // ago is correctly treated as "still active".
    $toWarn = $pdo->prepare(
        "SELECT c.* FROM customers c
         WHERE c.is_test_account = 0 AND c.status = 'active' AND c.deletion_warning_sent_at IS NULL
           AND GREATEST(
                 COALESCE(c.last_login_at, c.created_at),
                 c.created_at,
                 COALESCE((SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.id), c.created_at)
               ) < ?"
    );
    $toWarn->execute([$warnCutoff]);

    $warned = 0;
    foreach ($toWarn->fetchAll() as $customer) {
        // The date shown in the warning email is always exactly 3 months
        // from today (when the warning is actually sent), formatted in the
        // customer's own saved language preference - not derived from
        // $warnCutoff, so it reflects "3 months from now", not "3 months
        // from whenever they went inactive".
        $deletionDate = formatLocalDate(date('Y-m-d', strtotime('+3 months')), false, $customer['language'] ?? 'en');
        if (Mailer::sendAccountDeletionWarning($customer, $deletionDate)) {
            // Only stamp deletion_warning_sent_at if the email actually sent -
            // if delivery failed, leave it NULL so the next run retries.
            $pdo->prepare('UPDATE customers SET deletion_warning_sent_at = NOW() WHERE id = ?')->execute([$customer['id']]);
            $warned++;
        }
    }

    // Step 2: erase customers whose 3-month warning grace period has passed.
    $deleteCutoff = date('Y-m-d H:i:s', strtotime('-3 months'));
    // Anyone already warned (deletion_warning_sent_at IS NOT NULL) more than
    // 3 months ago is now past their grace period and gets erased.
    $toDelete = $pdo->prepare(
        "SELECT id FROM customers WHERE is_test_account = 0 AND deletion_warning_sent_at IS NOT NULL AND deletion_warning_sent_at < ?"
    );
    $toDelete->execute([$deleteCutoff]);

    $deleted = 0;
    foreach ($toDelete->fetchAll() as $row) {
        // Actual erasure (scrub PII from orders, hard-delete the customer
        // row) is delegated to GdprTools - see that file for exactly what
        // is and isn't removed.
        GdprTools::deleteCustomer((int)$row['id']);
        $deleted++;
    }

    return ['warned' => $warned, 'deleted' => $deleted, 'checked_at' => date('c')];
}
