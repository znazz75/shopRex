<?php

namespace ShopRex\Services;

use ShopRex\Models\Order;

/**
 * A small, immutable "answer" object returned by CheckoutService::placeOrder() -
 * exists as its own class (rather than just returning the Order, or an array)
 * so the controller can tell apart "order placed, nothing more to do" from
 * "order placed, but the customer still needs to be redirected off-site to
 * finish paying" (PayPal/Stripe) without inspecting payment-gateway internals.
 */
final class PlaceOrderResult
{
    public function __construct(
        // The order that was just created in the database (already saved).
        public readonly Order $order,
        // Where to send the customer's browser to complete payment (e.g. a
        // PayPal/Stripe hosted checkout URL), or null when the order is already
        // fully placed and the customer can go straight to the confirmation page
        // (e.g. bank transfer, or a test account using TestGateway).
        public readonly ?string $gatewayRedirectUrl,
    ) {
    }
}
