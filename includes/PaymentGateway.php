<?php
/**
 * *** LIKELY DEAD CODE - NOT LOADED ANYWHERE ***
 * As of the v2.00 OOP rewrite, nothing in this codebase `require`s this
 * file or references its unnamespaced classes/functions
 * (PayPalGateway/CreditCardGateway/BankTransferGateway/InvoiceGateway/
 * TestGateway/getPaymentGateway()/paypalMode()/etc.) - grepping the repo
 * for those symbols only turns up this file itself and documentation/
 * changelog mentions. src/container.php explicitly lists this file among
 * the "legacy classes kept as-is" that it *does* `require_once` (Cart.php,
 * SimplePdf.php, InvoiceGenerator.php, Mailer.php, ImageProcessor.php) -
 * but, unlike those, PaymentGateway.php is conspicuously absent from that
 * require_once list. It has been fully superseded by the `Payment\*`
 * namespace under src/ (`Payment\PaymentGateway` interface,
 * `Payment\PaymentGatewayFactory`, `Payment\PayPalGateway`/
 * `CreditCardGateway`/`BankTransferGateway`/`InvoiceGateway`/`TestGateway`),
 * which are direct ports of the classes below and are what
 * `Services\CheckoutService` actually calls today. This file is kept only
 * as historical reference / in case some external script still requires it
 * directly - the comments below describe what it used to do (and still
 * would do, unchanged, if loaded), not what currently runs in production.
 *
 * Payment gateway abstraction.
 *
 * Four concrete gateways are provided:
 *  - PayPalGateway      : PayPal Orders v2 REST API (sandbox by default)
 *  - CreditCardGateway  : Stripe Checkout Sessions (test mode by default)
 *  - BankTransferGateway: no external API - shows bank details, order is
 *                         held as "pending" until an admin confirms the
 *                         incoming transfer in the back office.
 *  - InvoiceGateway     : no external API either - like bank transfer, but
 *                         only ever offered to a customer whose account has
 *                         can_pay_on_invoice = 1 (Admin -> Customers ->
 *                         [customer] -> Payment). See isPaymentMethodEnabled()/
 *                         customerCanPayOnInvoice() in includes/functions.php.
 *
 * All four implement start($order, $items) which returns an array:
 *   ['redirect_url' => string|null, 'transaction_id' => string|null, 'status' => 'pending'|'completed']
 * The controller (checkout_process.php) stores this on the order/payment
 * record and, for redirect-based gateways, sends the customer onward.
 *
 * NOTE: this is a framework/starting point. Before going live, replace the
 * placeholder keys in config/config.php with real credentials and review
 * each gateway's webhook/IPN handling for production use.
 */

/**
 * Admin -> Settings -> Payment lets these be configured from the DB
 * (`settings` table); the config/config.php constants (in turn backed by
 * SHOPREX_* env vars) are only the fallback default for an unconfigured
 * install, same "constant is the default, DB setting overrides" pattern
 * used throughout (see getSetting() callers in includes/functions.php).
 */
/** 'live' if Admin -> Settings has PayPal set to live mode, otherwise always 'sandbox' (the safe default) - never trusts an unexpected setting value as "live". */
function paypalMode(): string
{
    return getSetting('paypal_mode', PAYPAL_MODE) === 'live' ? 'live' : 'sandbox';
}

/** The correct PayPal API host for the current mode - sandbox and live are entirely separate PayPal environments with different URLs. */
function paypalApiBase(): string
{
    return paypalMode() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

/** The PayPal REST API client id to authenticate with - DB setting overrides the config.php constant default. */
function paypalClientId(): string
{
    return getSetting('paypal_client_id', PAYPAL_CLIENT_ID) ?: PAYPAL_CLIENT_ID;
}

/** The PayPal REST API client secret to authenticate with - DB setting overrides the config.php constant default. */
function paypalClientSecret(): string
{
    return getSetting('paypal_client_secret', PAYPAL_CLIENT_SECRET) ?: PAYPAL_CLIENT_SECRET;
}

/** The Stripe publishable (client-side-safe) key - DB setting overrides the config.php constant default. */
function stripePublishableKey(): string
{
    return getSetting('stripe_publishable_key', STRIPE_PUBLISHABLE_KEY) ?: STRIPE_PUBLISHABLE_KEY;
}

/** The Stripe secret (server-side-only) key - DB setting overrides the config.php constant default. */
function stripeSecretKey(): string
{
    return getSetting('stripe_secret_key', STRIPE_SECRET_KEY) ?: STRIPE_SECRET_KEY;
}

/** The contract every payment method implements: given an order and its line items, kick off payment and report back how to proceed. */
interface PaymentGateway
{
    public function start(array $order, array $items): array;
}

class PayPalGateway implements PaymentGateway
{
    /**
     * Exchanges the configured client id/secret for a short-lived OAuth
     * access token via PayPal's client_credentials flow - required before
     * any other PayPal API call. Returns null (rather than throwing) on
     * any failure, so start() below can gracefully fall back to a
     * pending-only order instead of fataling the checkout.
     */
    private function getAccessToken(): ?string
    {
        $ch = curl_init(paypalApiBase() . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => paypalClientId() . ':' . paypalClientSecret(),
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            // Network-level failure (timeout, DNS, TLS, etc.) - log it for
            // the admin to investigate, but don't let it crash checkout.
            error_log('PayPal OAuth request failed: ' . $error);
            return null;
        }
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    /**
     * Creates a PayPal Order (v2 Checkout Orders API) for this shop order's
     * total, and returns the URL PayPal wants the customer sent to next to
     * approve payment.
     */
    public function start(array $order, array $items): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            // Credentials not configured / PayPal unreachable - fall back to a
            // pending order so the demo flow still completes end-to-end.
            return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
        }

        // reference_id ties PayPal's order back to this shop's order_number
        // for reconciliation; return_url/cancel_url are where PayPal sends
        // the customer back to after they approve or abandon payment.
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $order['order_number'],
                'amount' => [
                    'currency_code' => getSetting('currency', 'EUR'),
                    'value' => number_format((float)$order['total'], 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'return_url' => rtrim(SITE_URL, '/') . '/checkout_process.php?gateway=paypal&order=' . $order['order_number'] . '&action=capture',
                'cancel_url' => rtrim(SITE_URL, '/') . '/checkout.php?cancelled=1',
            ],
        ];

        $ch = curl_init(paypalApiBase() . '/v2/checkout/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT    => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string)$response, true);
        // PayPal returns a set of HAL-style links for the created order -
        // the one with rel "approve" is the URL to send the customer to.
        $approveUrl = null;
        foreach ($data['links'] ?? [] as $link) {
            if ($link['rel'] === 'approve') {
                $approveUrl = $link['href'];
            }
        }

        return [
            'redirect_url'   => $approveUrl,
            'transaction_id' => $data['id'] ?? null,
            'status'         => $approveUrl ? 'pending' : 'pending',
        ];
    }
}

class CreditCardGateway implements PaymentGateway
{
    /**
     * Creates a Stripe Checkout Session for this order's line items and
     * returns Stripe's hosted checkout page URL for the customer to pay on.
     */
    public function start(array $order, array $items): array
    {
        $secretKey = stripeSecretKey();
        if ($secretKey === 'sk_test_yourkey' || $secretKey === '') {
            // No real Stripe key configured - keep the demo flow working
            // without an external call.
            return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
        }

        // success_url includes Stripe's own {CHECKOUT_SESSION_ID} template
        // placeholder, which Stripe substitutes with the real session id
        // when redirecting the customer back - that id is what the capture
        // step later uses to verify payment (never a client-supplied value).
        $fields = [
            'mode' => 'payment',
            'success_url' => rtrim(SITE_URL, '/') . '/checkout_process.php?gateway=credit_card&order=' . $order['order_number'] . '&action=capture&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim(SITE_URL, '/') . '/checkout.php?cancelled=1',
            'customer_email' => $order['guest_email'] ?? $order['customer_email'] ?? '',
        ];

        // Stripe's Checkout Sessions API expects line items as indexed
        // form fields (line_items[0][...], line_items[1][...], ...) rather
        // than a JSON array - built up here field by field. Amounts are
        // converted to the smallest currency unit (cents) as Stripe requires.
        $i = 0;
        foreach ($items as $item) {
            $fields["line_items[$i][price_data][currency]"] = strtolower(getSetting('currency', 'EUR'));
            $fields["line_items[$i][price_data][product_data][name]"] = $item['product_name'];
            $fields["line_items[$i][price_data][unit_amount]"] = (int)round($item['unit_price'] * 100);
            $fields["line_items[$i][quantity]"] = $item['quantity'];
            $i++;
        }

        // Stripe authenticates via HTTP Basic auth with the secret key as
        // the username and an empty password (CURLOPT_USERPWD below).
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $secretKey . ':',
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode((string)$response, true);

        return [
            'redirect_url'   => $data['url'] ?? null,
            'transaction_id' => $data['id'] ?? null,
            'status'         => 'pending',
        ];
    }
}

class BankTransferGateway implements PaymentGateway
{
    /** Starts a bank-transfer "payment" - there's nothing to call externally, so this just reports back a pending status with no redirect. */
    public function start(array $order, array $items): array
    {
        // Nothing to call out to - the order is created as "pending" and
        // marked paid manually by an admin once the transfer arrives.
        return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
    }
}

/**
 * "Purchase on invoice" - only ever reachable for a customer whose account
 * has can_pay_on_invoice = 1 (checkout_process.php re-checks this
 * server-side, never trusting the submitted payment_method). Like bank
 * transfer, there's no external API: the order is created as "pending" and
 * an admin marks it paid once the invoice is actually settled.
 */
class InvoiceGateway implements PaymentGateway
{
    /** Starts an on-invoice "payment" - no external API call, just a pending status; see class docblock for the can_pay_on_invoice gating. */
    public function start(array $order, array $items): array
    {
        return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
    }
}

/**
 * Used instead of the real gateway whenever the order belongs to an
 * is_test_account customer (see checkout_process.php). Makes no network
 * call to PayPal/Stripe/anywhere - the trial order is simply marked paid
 * immediately so the flow can be reviewed end-to-end without moving any
 * real money.
 */
class TestGateway implements PaymentGateway
{
    /** Fakes an instantly-successful payment - a random TEST-xxxxxxxx transaction id and status 'completed', with no network call at all. */
    public function start(array $order, array $items): array
    {
        return ['redirect_url' => null, 'transaction_id' => 'TEST-' . strtoupper(bin2hex(random_bytes(4))), 'status' => 'completed'];
    }
}

/**
 * Factory: picks the right PaymentGateway implementation for a payment
 * method string (e.g. from the checkout form) - the single place that maps
 * method names to concrete classes, so callers never need a big switch
 * statement of their own. Throws for any method name that isn't one of the
 * four known ones (defends against a tampered/unexpected form value).
 */
function getPaymentGateway(string $method): PaymentGateway
{
    return match ($method) {
        'paypal'      => new PayPalGateway(),
        'credit_card' => new CreditCardGateway(),
        'bank_transfer' => new BankTransferGateway(),
        'invoice' => new InvoiceGateway(),
        default => throw new InvalidArgumentException('Unknown payment method: ' . $method),
    };
}
