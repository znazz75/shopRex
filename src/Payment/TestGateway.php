<?php

namespace ShopRex\Payment;

/**
 * Used instead of the real gateway whenever the order belongs to an
 * is_test_account customer. No network call - marked paid immediately so
 * the flow can be reviewed end-to-end without moving real money.
 */
final class TestGateway implements PaymentGateway
{
    public function start(array $order, array $items): array
    {
        return ['redirect_url' => null, 'transaction_id' => 'TEST-' . strtoupper(bin2hex(random_bytes(4))), 'status' => 'completed'];
    }
}
