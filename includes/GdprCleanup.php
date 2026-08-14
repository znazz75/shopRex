<?php
/**
 * Automated inactivity cleanup: after Admin -> Settings -> Data Retention's
 * configured period of inactivity minus 3 months, a customer is emailed a
 * warning; 3 months after that warning (i.e. at the full retention period),
 * their account is erased via GdprTools::deleteCustomer(). Test accounts
 * are never touched.
 *
 * Run this from a real system cron via admin/cron/gdpr_cleanup.php (see
 * README.md for a sample crontab line), or on demand from Admin -> Settings.
 */
function runGdprInactivityCleanup(): array
{
    // Needs to be at least 4 so "warn 3 months before deletion" is meaningful.
    $months = max(4, (int)getSetting('gdpr_inactivity_months', '24'));
    $pdo = db();

    // Step 1: warn customers approaching the deletion threshold. "Last
    // activity" is the most recent of: last login, account creation, and
    // their most recent order - whichever is latest.
    $warnCutoff = date('Y-m-d H:i:s', strtotime('-' . ($months - 3) . ' months'));
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
        $deletionDate = formatLocalDate(date('Y-m-d', strtotime('+3 months')), false, $customer['language'] ?? 'en');
        if (Mailer::sendAccountDeletionWarning($customer, $deletionDate)) {
            $pdo->prepare('UPDATE customers SET deletion_warning_sent_at = NOW() WHERE id = ?')->execute([$customer['id']]);
            $warned++;
        }
    }

    // Step 2: erase customers whose 3-month warning grace period has passed.
    $deleteCutoff = date('Y-m-d H:i:s', strtotime('-3 months'));
    $toDelete = $pdo->prepare(
        "SELECT id FROM customers WHERE is_test_account = 0 AND deletion_warning_sent_at IS NOT NULL AND deletion_warning_sent_at < ?"
    );
    $toDelete->execute([$deleteCutoff]);

    $deleted = 0;
    foreach ($toDelete->fetchAll() as $row) {
        GdprTools::deleteCustomer((int)$row['id']);
        $deleted++;
    }

    return ['warned' => $warned, 'deleted' => $deleted, 'checked_at' => date('c')];
}
