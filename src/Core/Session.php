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
 *
 * In plain terms: this class exists so the rest of the app doesn't reach
 * into the global $_SESSION array directly - every place that needs to
 * remember something between page loads (who's logged in, what's in the
 * cart, a one-time success message) goes through here instead, which makes
 * it much easier to see every place session data is touched.
 */
final class Session
{
    /** Reads a value out of the session by key, or returns $default if that key was never set. */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /** Stores a value in the session under $key, overwriting whatever was there before. */
    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    /** True if $key currently exists in the session (regardless of its value). */
    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    /** Deletes $key from the session, if present. */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /** Read and clear in one step - used for flash-style one-shot reads. */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $_SESSION[$key] ?? $default;
        // Removing it immediately after reading is what makes this "pull"
        // rather than "get" - callers use this for values that should only
        // ever be seen once (e.g. a flash message shown on the next page load).
        unset($_SESSION[$key]);
        return $value;
    }

    /**
     * Swaps the current session ID for a brand-new random one while keeping
     * the session's data intact. Called after a successful login (and other
     * privilege changes) so an attacker who somehow obtained the pre-login
     * session ID (session fixation) can't ride along into the now-authenticated
     * session - the old ID stops being valid the moment this runs.
     */
    public function regenerate(): void
    {
        // true = also delete the old session file on the server, not just
        // issue a new ID, so the old session cannot be reused at all.
        session_regenerate_id(true);
    }
}
