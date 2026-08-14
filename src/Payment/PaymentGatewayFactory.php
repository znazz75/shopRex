<?php

namespace ShopRex\Payment;

/** Direct port of getPaymentGateway() from includes/PaymentGateway.php. */
final class PaymentGatewayFactory
{
    public function __construct(private readonly PaymentSettings $settings)
    {
    }

    public function make(string $method): PaymentGateway
    {
        return match ($method) {
            'paypal'        => new PayPalGateway($this->settings),
            'credit_card'   => new CreditCardGateway($this->settings),
            'bank_transfer' => new BankTransferGateway(),
            'invoice'       => new InvoiceGateway(),
            default         => throw new \InvalidArgumentException('Unknown payment method: ' . $method),
        };
    }
}
