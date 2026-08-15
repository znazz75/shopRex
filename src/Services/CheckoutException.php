<?php

namespace ShopRex\Services;

/**
 * Thrown by CheckoutService for the same "flash an error, redirect back"
 * conditions checkout_process.php used to handle inline. $redirectPath is
 * where the controller should send the customer back to.
 */
final class CheckoutException extends \RuntimeException
{
    /**
     * $message becomes the flash-error text shown to the customer; $redirectPath
     * is which page the controller sends them back to (e.g. back to the cart if
     * stock ran out, or back to checkout if a required field was missing).
     */
    public function __construct(
        string $message,
        // Where to redirect the browser after catching this exception - defaults
        // to the checkout page itself since most failures happen there.
        public readonly string $redirectPath = '/checkout',
    ) {
        parent::__construct($message);
    }
}
