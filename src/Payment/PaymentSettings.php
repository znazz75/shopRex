<?php

namespace ShopRex\Payment;

use ShopRex\Services\SettingsRepository;

/**
 * Central place to look up PayPal/Stripe credentials and mode (live vs.
 * sandbox/test) - a real-world stand-in for "the shop's payment provider
 * account settings". Exists as its own class, rather than each gateway
 * reading settings itself, so PayPalGateway/CreditCardGateway don't
 * duplicate the same "DB setting overrides config.php constant" fallback
 * logic, and so an admin changing a key in Settings takes effect without a
 * code/config.php deploy.
 *
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

    /** Whether PayPal is configured for real ('live') payments or the sandbox/test environment - defaults to config.php's PAYPAL_MODE constant unless an admin has overridden it in Settings, and treats anything other than the literal string 'live' as sandbox (fail-safe: an unrecognized value never accidentally goes live). */
    public function paypalMode(): string
    {
        return $this->settings->get('paypal_mode', PAYPAL_MODE) === 'live' ? 'live' : 'sandbox';
    }

    /** The PayPal REST API host to call - the sandbox and live PayPal environments are entirely separate hosts, so every API call elsewhere in PayPalGateway starts from whichever base this returns. */
    public function paypalApiBase(): string
    {
        return $this->paypalMode() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
    }

    /** The PayPal REST app's client ID - DB setting wins if one is saved (and non-empty), otherwise falls back to the PAYPAL_CLIENT_ID constant from config.php. */
    public function paypalClientId(): string
    {
        return $this->settings->get('paypal_client_id', PAYPAL_CLIENT_ID) ?: PAYPAL_CLIENT_ID;
    }

    /** The PayPal REST app's client secret - same DB-overrides-config fallback pattern as paypalClientId(); this is a genuine credential, never expose it to the storefront/client side. */
    public function paypalClientSecret(): string
    {
        return $this->settings->get('paypal_client_secret', PAYPAL_CLIENT_SECRET) ?: PAYPAL_CLIENT_SECRET;
    }

    /** Stripe's publishable key - safe to expose client-side (that's what "publishable" means), used wherever the theme needs to talk to Stripe.js directly. */
    public function stripePublishableKey(): string
    {
        return $this->settings->get('stripe_publishable_key', STRIPE_PUBLISHABLE_KEY) ?: STRIPE_PUBLISHABLE_KEY;
    }

    /** Stripe's secret key - a genuine credential used for server-side API calls only (creating/looking up Checkout Sessions); never send this to the browser. */
    public function stripeSecretKey(): string
    {
        return $this->settings->get('stripe_secret_key', STRIPE_SECRET_KEY) ?: STRIPE_SECRET_KEY;
    }
}
