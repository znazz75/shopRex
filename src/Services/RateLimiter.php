<?php

namespace ShopRex\Services;

/**
 * Direct port of the brute-force throttle functions in includes/functions.php
 * (loginAttemptIdentifier/isRateLimited/recordFailedLoginAttempt/
 * clearLoginAttempts), generalized into one class parameterized by table
 * name instead of four free functions hardcoded to `login_attempts` - the
 * same class instantiated against a different table backs the Phase 6
 * contact-form rate limit (contact_message_attempts).
 *
 * Backed by a DB table rather than the session, since a session resets the
 * moment an attacker starts a fresh one - the whole point is to survive that.
 */
final class RateLimiter
{
    public function __construct(
        private readonly \PDO $pdo,
        // Which table stores attempts for this instance - lets the same class
        // be reused for both login throttling and the contact-form rate limit
        // by just pointing it at a different table.
        private readonly string $table = 'login_attempts',
    ) {
    }

    /**
     * "ip|lowercased-account" so a limit on one account/IP pair doesn't
     * lock out every other visitor sharing the same IP (e.g. behind NAT/a
     * corporate proxy) from *other* accounts.
     */
    public function identifierFor(string $account): string
    {
        return ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . '|' . strtolower(trim($account));
    }

    /** True once $identifier has racked up $maxAttempts or more failures within the last $windowMinutes - this is the actual "are you locked out right now" check callers make before letting a login/submission through. */
    public function tooManyAttempts(string $identifier, int $maxAttempts = 5, int $windowMinutes = 15): bool
    {
        // Counts only recent rows (DATE_SUB(NOW(), INTERVAL ? MINUTE)) so the
        // lockout window slides forward automatically - old failures age out
        // without needing a separate cleanup job.
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE identifier = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$identifier, $windowMinutes]);
        return (int)$stmt->fetchColumn() >= $maxAttempts;
    }

    /** Logs one failed attempt for $identifier - called after a bad password/login so it counts toward the tooManyAttempts() threshold. */
    public function recordFailedAttempt(string $identifier): void
    {
        $this->pdo->prepare("INSERT INTO {$this->table} (identifier) VALUES (?)")->execute([$identifier]);
    }

    /** Wipes any recorded failures for $identifier - called after a successful login so a legitimate user isn't left partway toward a lockout from earlier typos. */
    public function clearAttempts(string $identifier): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE identifier = ?")->execute([$identifier]);
    }
}
