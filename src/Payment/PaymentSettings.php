<?php

namespace ShopRex\Payment;

use ShopRex\Services\SettingsRepository;

/**
 * Direct port of the config helper functions at the top of
 * includes/PaymentGateway.php (paypalMode/paypalApiBase/paypalClientId/
 * paypalClientSecret/stripePublishableKey/stripeSecretKey) - all follow
 * the "DB setting overrides config.php constant" pattern. Shared by
 * PayPalGateway/CreditCardGateway instead of duplicated as free functions.
 */
final class PaymentSettings
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function paypalMode(): string
    {
        return $this->settings->get('paypal_mode', PAYPAL_MODE) === 'live' ? 'live' : 'sandbox';
    }

    public function paypalApiBase(): string
    {
        return $this->paypalMode() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    public function paypalClientId(): string
    {
        return $this->settings->get('paypal_client_id', PAYPAL_CLIENT_ID) ?: PAYPAL_CLIENT_ID;
    }

    public function paypalClientSecret(): string
    {
        return $this->settings->get('paypal_client_secret', PAYPAL_CLIENT_SECRET) ?: PAYPAL_CLIENT_SECRET;
    }

    public function stripePublishableKey(): string
    {
        return $this->settings->get('stripe_publishable_key', STRIPE_PUBLISHABLE_KEY) ?: STRIPE_PUBLISHABLE_KEY;
    }

    public function stripeSecretKey(): string
    {
        return $this->settings->get('stripe_secret_key', STRIPE_SECRET_KEY) ?: STRIPE_SECRET_KEY;
    }
}
