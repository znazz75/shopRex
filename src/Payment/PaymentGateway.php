<?php

namespace ShopRex\Payment;

/**
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
    public function start(array $order, array $items): array;
}
