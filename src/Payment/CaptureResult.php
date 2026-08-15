<?php

namespace ShopRex\Payment;

/**
 * Result of a CapturableGateway::capture() call - what
 * Services\CheckoutService::handleCapture() uses to decide whether to call
 * Models\Order::markPaid(). Exists as its own small value object (rather
 * than a raw array) so callers get typed, named fields instead of having to
 * remember array keys for something this security-sensitive.
 */
final class CaptureResult
{
    public function __construct(
        // True only when the gateway confirmed the payment completed AND the
        // captured amount matched the order total - see PayPalGateway/
        // CreditCardGateway::capture() for the actual check.
        public readonly bool $success,
        // The gateway's transaction/session id, for storing on the order's
        // payments row as a paper trail; null when capture failed.
        public readonly ?string $transactionId,
        // The raw JSON response from the gateway, kept as-is (not parsed)
        // for logging/debugging a disputed or failed payment later.
        public readonly string $rawResponse,
    ) {
    }

    /** Shorthand for "capture did not succeed" - used whenever the identifier check fails or the gateway call itself doesn't confirm payment, so callers don't need to hand-build a failed CaptureResult inline. */
    public static function failure(): self
    {
        return new self(false, null, '');
    }
}
