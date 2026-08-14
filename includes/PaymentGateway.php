<?php
/**
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
function paypalMode(): string
{
    return getSetting('paypal_mode', PAYPAL_MODE) === 'live' ? 'live' : 'sandbox';
}

function paypalApiBase(): string
{
    return paypalMode() === 'live' ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
}

function paypalClientId(): string
{
    return getSetting('paypal_client_id', PAYPAL_CLIENT_ID) ?: PAYPAL_CLIENT_ID;
}

function paypalClientSecret(): string
{
    return getSetting('paypal_client_secret', PAYPAL_CLIENT_SECRET) ?: PAYPAL_CLIENT_SECRET;
}

function stripePublishableKey(): string
{
    return getSetting('stripe_publishable_key', STRIPE_PUBLISHABLE_KEY) ?: STRIPE_PUBLISHABLE_KEY;
}

function stripeSecretKey(): string
{
    return getSetting('stripe_secret_key', STRIPE_SECRET_KEY) ?: STRIPE_SECRET_KEY;
}

interface PaymentGateway
{
    public function start(array $order, array $items): array;
}

class PayPalGateway implements PaymentGateway
{
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
            error_log('PayPal OAuth request failed: ' . $error);
            return null;
        }
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    public function start(array $order, array $items): array
    {
        $token = $this->getAccessToken();
        if (!$token) {
            // Credentials not configured / PayPal unreachable - fall back to a
            // pending order so the demo flow still completes end-to-end.
            return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
        }

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
    public function start(array $order, array $items): array
    {
        $secretKey = stripeSecretKey();
        if ($secretKey === 'sk_test_yourkey' || $secretKey === '') {
            // No real Stripe key configured - keep the demo flow working
            // without an external call.
            return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
        }

        $fields = [
            'mode' => 'payment',
            'success_url' => rtrim(SITE_URL, '/') . '/checkout_process.php?gateway=credit_card&order=' . $order['order_number'] . '&action=capture&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim(SITE_URL, '/') . '/checkout.php?cancelled=1',
            'customer_email' => $order['guest_email'] ?? $order['customer_email'] ?? '',
        ];

        $i = 0;
        foreach ($items as $item) {
            $fields["line_items[$i][price_data][currency]"] = strtolower(getSetting('currency', 'EUR'));
            $fields["line_items[$i][price_data][product_data][name]"] = $item['product_name'];
            $fields["line_items[$i][price_data][unit_amount]"] = (int)round($item['unit_price'] * 100);
            $fields["line_items[$i][quantity]"] = $item['quantity'];
            $i++;
        }

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
    public function start(array $order, array $items): array
    {
        return ['redirect_url' => null, 'transaction_id' => 'TEST-' . strtoupper(bin2hex(random_bytes(4))), 'status' => 'completed'];
    }
}

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
