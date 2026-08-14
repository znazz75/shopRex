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

    public function tooManyAttempts(string $identifier, int $maxAttempts = 5, int $windowMinutes = 15): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE identifier = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        $stmt->execute([$identifier, $windowMinutes]);
        return (int)$stmt->fetchColumn() >= $maxAttempts;
    }

    public function recordFailedAttempt(string $identifier): void
    {
        $this->pdo->prepare("INSERT INTO {$this->table} (identifier) VALUES (?)")->execute([$identifier]);
    }

    public function clearAttempts(string $identifier): void
    {
        $this->pdo->prepare("DELETE FROM {$this->table} WHERE identifier = ?")->execute([$identifier]);
    }
}
