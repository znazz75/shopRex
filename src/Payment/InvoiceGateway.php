<?php

namespace ShopRex\Payment;

/**
 * "Purchase on invoice" - only ever reachable for a customer whose account
 * has can_pay_on_invoice = 1 (Services\CheckoutService re-checks this
 * server-side, never trusting the submitted payment_method). Like bank
 * transfer, no external API: the order is created "pending" and an admin
 * marks it paid once the invoice is actually settled. Kept as its own
 * class (rather than reusing BankTransferGateway) so the eligibility rule
 * and the payment method's identity stay separate and each can evolve
 * independently even though today they behave identically.
 */
final class InvoiceGateway implements PaymentGateway
{
    /** Always returns the same "no redirect, still pending" result - like bank transfer, invoice payment has no online step; the order simply waits for an admin to mark it paid once the invoice is settled. */
    public function start(array $order, array $items): array
    {
        return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
    }
}
