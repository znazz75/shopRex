<?php

namespace ShopRex\Services;

/**
 * Direct port of getSetting() from includes/functions.php, with one
 * deliberate behavior change: the old function cached the whole `settings`
 * table in a function-local `static $cache`, which meant a setting written
 * earlier in the same request was NOT visible to a later getSetting() call
 * in that same request unless the caller threaded the new value through
 * explicitly (documented gotcha - see CLAUDE.md). Because this class is a
 * single shared instance (registered once in the Container) instead of a
 * function-local static, set() invalidates its own cache immediately, so
 * a write is visible to the very next get() in the same request.
 *
 * Flagged in the architecture plan's risk list as needing its own
 * regression pass in admin/settings.php's port (Phase 8) - some existing
 * save-handler code may have been written assuming the old staleness.
 */
final class SettingsRepository
{
    private ?array $cache = null;

    public function __construct(private readonly \PDO $pdo)
    {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $this->loadIfNeeded();
        return $this->cache[$key] ?? $default;
    }

    /** UPDATE-only, for the seeded rows that already have a row in the settings table. */
    public function update(string $key, ?string $value): void
    {
        $this->pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?')->execute([$value, $key]);
        $this->invalidate();
    }

    /** INSERT ... ON DUPLICATE KEY UPDATE, for settings that may not have a seeded row yet. */
    public function upsert(string $key, ?string $value): void
    {
        $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
        )->execute([$key, $value]);
        $this->invalidate();
    }

    public function isPaymentMethodEnabled(string $method): bool
    {
        return match ($method) {
            'paypal'        => $this->get('payment_method_paypal_enabled', '1') === '1',
            'credit_card'   => $this->get('payment_method_credit_card_enabled', '1') === '1',
            'bank_transfer' => $this->get('payment_method_bank_transfer_enabled', '1') === '1',
            default         => false,
        };
    }

    public function customerCanPayOnInvoice(?array $customer): bool
    {
        return $customer !== null && !empty($customer['can_pay_on_invoice']);
    }

    private function loadIfNeeded(): void
    {
        if ($this->cache !== null) {
            return;
        }
        $this->cache = [];
        foreach ($this->pdo->query('SELECT setting_key, setting_value FROM settings')->fetchAll() as $row) {
            $this->cache[$row['setting_key']] = $row['setting_value'];
        }
    }

    private function invalidate(): void
    {
        $this->cache = null;
    }
}
