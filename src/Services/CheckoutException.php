<?php

namespace ShopRex\Services;

/**
 * Thrown by CheckoutService for the same "flash an error, redirect back"
 * conditions checkout_process.php used to handle inline. $redirectPath is
 * where the controller should send the customer back to.
 */
final class CheckoutException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $redirectPath = '/checkout')
    {
        parent::__construct($message);
    }
}
