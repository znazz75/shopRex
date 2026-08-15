<?php

namespace ShopRex\Core\Auth;

/**
 * Direct port of currentCustomer()/requireCustomerLogin() from
 * includes/functions.php. Static for the same reason as AdminAuth - the
 * whole app already treats $_SESSION and the DB connection as ambient.
 *
 * In plain terms: this is the storefront equivalent of AdminAuth - it
 * answers "which customer (if any) is logged in on this browsing session?"
 * It's deliberately much simpler than AdminAuth because customers don't
 * have roles/capabilities, just "logged in" or "not logged in".
 */
final class CustomerAuth
{
    /** Memoized result of the currently-logged-in customer's DB row lookup (see $lookedUp) - null both before the lookup has run and when nobody is logged in. */
    private static ?array $cachedCustomer = null;

    /** Whether current() has already queried the database this request - avoids re-querying the customers table on every call within the same request. */
    private static bool $lookedUp = false;

    /**
     * Returns the logged-in customer's full DB row (as an array), or null if
     * nobody is logged in. Memoized per request just like
     * AdminAuth::current() - see that method's comment for why.
     */
    public static function current(): ?array
    {
        if (empty($_SESSION['customer_id'])) {
            return null;
        }
        if (!self::$lookedUp) {
            $stmt = \Database::getConnection()->prepare('SELECT * FROM customers WHERE id = ?');
            $stmt->execute([$_SESSION['customer_id']]);
            self::$cachedCustomer = $stmt->fetch() ?: null;
            self::$lookedUp = true;
        }
        return self::$cachedCustomer;
    }

    /** Convenience boolean form of current() for places that only need to know "is someone logged in", not who. */
    public static function check(): bool
    {
        return self::current() !== null;
    }

    /** Reset the per-request memoized lookup - call right after login/logout so a later current() call in the same request re-queries instead of returning a stale result. */
    public static function forget(): void
    {
        self::$cachedCustomer = null;
        self::$lookedUp = false;
    }
}
