<?php

namespace ShopRex\Core\Auth;

/**
 * Direct port of currentCustomer()/requireCustomerLogin() from
 * includes/functions.php. Static for the same reason as AdminAuth - the
 * whole app already treats $_SESSION and the DB connection as ambient.
 */
final class CustomerAuth
{
    private static ?array $cachedCustomer = null;
    private static bool $lookedUp = false;

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

    public static function check(): bool
    {
        return self::current() !== null;
    }

    public static function forget(): void
    {
        self::$cachedCustomer = null;
        self::$lookedUp = false;
    }
}
