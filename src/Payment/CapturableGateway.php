<?php

namespace ShopRex\Payment;

/**
 * Implemented only by the two redirect-based gateways (PayPal, Stripe
 * credit card) - BankTransferGateway/InvoiceGateway/TestGateway never
 * redirect out, so there's nothing to capture.
 *
 * $storedIdentifier is the value already saved on this order's `payments`
 * row (the PayPal order id, or the Stripe Checkout Session id) -
 * $submitted is whatever the gateway's return-URL query string handed
 * back. Binding capture to $storedIdentifier and only ever *comparing*
 * against $submitted (never substituting it in) is the fix for
 * docs/SECURITY_AUDIT.md finding #2: trusting $submitted directly would
 * let anyone pay for a different, real order of their own and then replay
 * that real token/session id against an unrelated order number, marking
 * it paid for free.
 */
interface CapturableGateway
{
    public function capture(?string $storedIdentifier, ?string $submitted, float $orderTotal): CaptureResult;
}
