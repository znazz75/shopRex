<?php

namespace ShopRex\Payment;

/**
 * Second, optional contract - on top of PaymentGateway::start() - for
 * gateways where "the customer paid" is a separate step that happens later,
 * after they've been redirected away to PayPal/Stripe and back. It's kept
 * as its own interface (rather than folded into PaymentGateway) precisely
 * because it doesn't apply to every gateway: implemented only by the two
 * redirect-based gateways (PayPal, Stripe credit card) - BankTransferGateway/
 * InvoiceGateway/TestGateway never redirect out, so there's nothing to
 * capture, and PHP interfaces let CheckoutController check
 * `instanceof CapturableGateway` instead of every gateway needing a no-op
 * capture() method.
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
    /**
     * Verifies that the payment identified by $storedIdentifier (never
     * $submitted - see the class docblock's security note) really was
     * completed with the gateway, and that the amount actually paid matches
     * $orderTotal, before the caller is allowed to mark the order paid.
     * Returns a CaptureResult rather than throwing so a failed/mismatched
     * capture is just "not successful", not an exception the caller has to
     * catch.
     */
    public function capture(?string $storedIdentifier, ?string $submitted, float $orderTotal): CaptureResult;
}
