<?php

namespace ShopRex\Payment;

/** No external API - order stays "pending" until an admin confirms the incoming transfer in the back office. */
final class BankTransferGateway implements PaymentGateway
{
    public function start(array $order, array $items): array
    {
        return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
    }
}
