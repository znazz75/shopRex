<?php

namespace ShopRex\Payment;

/**
 * The one contract every payment method (PayPal, Stripe credit card, bank
 * transfer, invoice, test) must implement - this is what lets
 * Services\CheckoutService kick off a payment without caring which method
 * the customer picked. Having a shared interface, rather than each gateway
 * exposing its own differently-named method, is what makes
 * PaymentGatewayFactory::make() able to hand back "a gateway" and have the
 * caller use it uniformly.
 *
 * Unchanged contract from includes/PaymentGateway.php: start($order,$items)
 * returns ['redirect_url'=>?string, 'transaction_id'=>?string, 'status'=>'pending'|'completed'].
 * $order/$items stay plain arrays here (not Models\Order/OrderItem) because
 * that's the exact shape Services\CheckoutService already has on hand at
 * the point it calls this (freshly fetched via Order::findByNumber()/
 * items()) and because PayPalGateway/CreditCardGateway only ever read a
 * handful of scalar fields out of them - no benefit to a heavier object
 * here, matching the original function-based signature 1:1.
 */
interface PaymentGateway
{
    /**
     * Kicks off payment for a freshly-created order: for the redirect-based
     * gateways (PayPal/Stripe) this makes the initial API call and returns a
     * URL to send the shopper to; for the non-redirect methods (bank
     * transfer/invoice/test) it just decides the order's starting payment
     * status. Never throws on a failed external call - it degrades to a
     * 'pending' order instead, so a misconfigured/unreachable gateway
     * doesn't break checkout entirely.
     */
    public function start(array $order, array $items): array;
}
