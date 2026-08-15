<?php

namespace ShopRex\Payment;

/**
 * "Pay by bank transfer" payment method - the customer wires money outside
 * the app entirely, so this class's only job is to tell the checkout flow
 * "there's nothing to redirect to, just leave the order pending". No
 * external API - order stays "pending" until an admin confirms the incoming
 * transfer in the back office.
 */
final class BankTransferGateway implements PaymentGateway
{
    /** Always returns the same "no redirect, still pending" result - bank transfer has no online step to kick off, unlike PayPal/Stripe. */
    public function start(array $order, array $items): array
    {
        return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
    }
}
