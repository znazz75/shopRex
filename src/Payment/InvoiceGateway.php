<?php

namespace ShopRex\Payment;

/**
 * "Purchase on invoice" - only ever reachable for a customer whose account
 * has can_pay_on_invoice = 1 (Services\CheckoutService re-checks this
 * server-side, never trusting the submitted payment_method). Like bank
 * transfer, no external API: the order is created "pending" and an admin
 * marks it paid once the invoice is actually settled.
 */
final class InvoiceGateway implements PaymentGateway
{
    public function start(array $order, array $items): array
    {
        return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
    }
}
