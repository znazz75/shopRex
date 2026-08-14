<?php

namespace ShopRex\Payment;

/**
 * Result of a CapturableGateway::capture() call - what
 * Services\CheckoutService::handleCapture() uses to decide whether to call
 * Models\Order::markPaid().
 */
final class CaptureResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $transactionId,
        public readonly string $rawResponse,
    ) {
    }

    public static function failure(): self
    {
        return new self(false, null, '');
    }
}
