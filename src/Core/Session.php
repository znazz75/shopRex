<?php

namespace ShopRex\Core;

/**
 * Thin, typed wrapper over $_SESSION. session_start() itself still happens
 * in config/config.php (hardened cookie params - HttpOnly/SameSite=Lax/
 * Secure - are session-wide and only need setting once, before the session
 * opens) - this class never starts or configures the session, only reads
 * and writes keys within it. Replaces the ad hoc $_SESSION[...] access
 * that used to be scattered across ~10 files (cart, csrf_token, flash,
 * language, per_page, customer_id, admin_id, last_product_url,
 * last_order_id - see the exploration notes in the architecture plan).
 */
final class Session
{
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Read and clear in one step - used for flash-style one-shot reads. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        unset($_SESSION[$key]);
        return $value;
    }

    public function regenerate(): void
    {
        session_regenerate_id(true);
    }
}
