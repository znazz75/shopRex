<?php

namespace ShopRex\Payment;

/**
 * Turns a payment method's string name (as stored on an order / submitted
 * from the checkout form) into the actual gateway object that knows how to
 * process it. Exists so the rest of the app (Services\CheckoutService) only
 * has to know the string identifier for a payment method, not which
 * concrete class implements it or how to construct it (PayPal/CreditCard
 * need PaymentSettings injected, the others don't) - classic factory
 * pattern. Direct port of getPaymentGateway() from includes/PaymentGateway.php.
 */
final class PaymentGatewayFactory
{
    public function __construct(private readonly PaymentSettings $settings)
    {
    }

    /**
     * Maps a payment method identifier to a freshly-constructed gateway
     * instance implementing PaymentGateway. Throws on an unrecognized
     * method rather than silently picking a default, so a typo'd or
     * tampered-with payment method string fails loudly instead of quietly
     * routing to the wrong gateway.
     */
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
