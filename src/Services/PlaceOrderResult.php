<?php

namespace ShopRex\Services;

use ShopRex\Models\Order;

final class PlaceOrderResult
{
    public function __construct(
        public readonly Order $order,
        public readonly ?string $gatewayRedirectUrl,
    ) {
    }
}
