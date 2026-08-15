<?php

namespace ShopRex\Payment;

/**
 * Talks to PayPal's real Orders v2 REST API (sandbox by default, live when
 * configured - see PaymentSettings::paypalMode()) to create and later
 * capture a PayPal payment. This is one of the highest-security-sensitivity
 * files in the app: see the SECURITY comment on capture() below and
 * docs/SECURITY_AUDIT.md finding #2 for the exact attack it defends
 * against (a shopper substituting a *different* order number onto their
 * own real, paid PayPal token to get someone else's order marked paid for
 * free).
 *
 * PayPal Orders v2 REST API (sandbox by default). Direct port of
 * includes/PaymentGateway.php's PayPalGateway - start() body unchanged;
 * capture() is checkout_process.php's handleCapture() 'paypal' branch
 * plus capturePayPalOrder(), relocated here per the architecture plan
 * ("relocate capture logic into the owning gateway").
 */
final class PayPalGateway implements PaymentGateway, CapturableGateway
{
    public function __construct(private readonly PaymentSettings $settings)
    {
    }

    /**
     * Logs in to PayPal as this shop (not as the customer) using the
     * client ID/secret from PaymentSettings, via OAuth2's "client
     * credentials" grant - the standard way a server authenticates itself
     * to PayPal's API before it can create or capture orders. Returns null
     * (rather than throwing) on any failure so callers can fall back to a
     * pending order instead of crashing checkout.
     */
    private function getAccessToken(): ?string
    {
        // CURLOPT_USERPWD sends the client ID/secret as HTTP Basic Auth,
        // which is how PayPal's OAuth2 token endpoint expects app
        // credentials to be presented.
        $ch = curl_init($this->settings->paypalApiBase() . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $this->settings->paypalClientId() . ':' . $this->settings->paypalClientSecret(),
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        // curl_exec() returns false (not an exception) on a transport-level
        // failure (timeout, DNS, connection refused) - log it for
        // diagnosis and let the caller treat "no token" as "PayPal
        // unavailable right now".
        if ($response === false) {
            error_log('PayPal OAuth request failed: ' . $error);
            return null;
        }
        $data = json_decode($response, true);
        return $data['access_token'] ?? null;
    }

    /**
     * Creates a new PayPal order (PayPal's term, distinct from this shop's
     * own order) for the shop's order total and returns the URL to redirect
     * the shopper to so they can approve payment on PayPal's site. The
     * PayPal order id returned here is what gets saved as this order's
     * `payments.transaction_id` - the value capture() later trusts instead
     * of anything the return URL supplies (see capture()'s security note).
     */
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
                // reference_id ties PayPal's order back to this shop's order
                // number for PayPal's own records/dashboard - it is NOT what
                // capture() relies on for security (see below).
                'reference_id' => $order['order_number'],
                'amount' => [
                    'currency_code' => getSetting('currency', 'EUR'),
                    // number_format(...,2,'.','') formats the total as a
                    // plain "1234.56" string with a dot decimal separator and
                    // no thousands separator - the exact format PayPal's API
                    // requires, regardless of the site's display locale.
                    'value' => number_format((float)$order['total'], 2, '.', ''),
                ],
            ]],
            'application_context' => [
                // Where PayPal sends the shopper's browser back to after they
                // approve (or cancel) payment on PayPal's site. Note this URL
                // carries the order number as a plain query parameter - see
                // capture()'s docblock for why that alone is never trusted.
                'return_url' => rtrim(SITE_URL, '/') . '/checkout/capture?gateway=paypal&order=' . $order['order_number'] . '&action=capture',
                'cancel_url' => rtrim(SITE_URL, '/') . '/checkout?cancelled=1',
            ],
        ];

        $ch = curl_init($this->settings->paypalApiBase() . '/v2/checkout/orders');
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
        // PayPal's response includes a set of HATEOAS-style links for
        // different actions; the one with rel "approve" is the URL the
        // shopper needs to visit to actually approve the payment.
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

    /**
     * Confirms that money actually changed hands for THIS order and only
     * this order, then hands back the result for CheckoutController/
     * CheckoutService to act on.
     *
     * SECURITY (docs/SECURITY_AUDIT.md finding #2): $storedIdentifier is
     * the PayPal order id that was saved on THIS shop order's `payments`
     * row back when start() created it; $submitted is whatever the
     * `token` query parameter says on the browser's return from PayPal -
     * i.e. attacker-controlled input, since it arrives via a plain GET
     * redirect with no CSRF protection possible. The only thing this
     * method ever uses to talk to PayPal is $storedIdentifier - $submitted
     * is used *exclusively* to check it matches, never passed to PayPal
     * itself. Without this check, an attacker could pay for a cheap order
     * of their own, get a real/valid PayPal token back, then replay that
     * real token against someone else's (or their own more expensive)
     * order number to get it marked paid without paying for it. Any
     * mismatch, or a missing stored identifier, fails closed via
     * CaptureResult::failure() - it never falls back to trusting
     * $submitted.
     */
    public function capture(?string $storedIdentifier, ?string $submitted, float $orderTotal): CaptureResult
    {
        // SECURITY (docs/SECURITY_AUDIT.md finding #2): only ever capture
        // using the PayPal order id THIS order's payment was created for -
        // a $submitted token that doesn't match it is ignored, never
        // substituted in.
        if (!$storedIdentifier || $submitted !== $storedIdentifier) {
            return CaptureResult::failure();
        }

        $captureResponse = $this->capturePayPalOrder($storedIdentifier);
        // Drill into PayPal's nested capture response for the amount they
        // actually captured - this is what gets compared against the
        // order's expected total below, independent of whatever the shop
        // itself believes the total should be.
        $capturedAmount = $captureResponse['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? null;

        // Success requires ALL three: PayPal reports the capture as fully
        // completed, an amount was actually returned, and that amount
        // matches the order total to within a cent (floating-point-safe
        // comparison rather than exact equality) - a partial or short
        // payment must never be treated as a full payment.
        $success = ($captureResponse['status'] ?? '') === 'COMPLETED'
            && is_numeric($capturedAmount)
            && abs((float)$capturedAmount - $orderTotal) < 0.01;

        return new CaptureResult($success, $storedIdentifier, (string)json_encode($captureResponse));
    }

    /**
     * The actual PayPal API call that finalizes ("captures") a
     * previously-approved order and moves the money. Kept private/internal
     * because capture() above is the only safe entry point - it's what
     * enforces that $paypalOrderId came from this order's own stored
     * identifier, never from unchecked user input.
     */
    private function capturePayPalOrder(string $paypalOrderId): array
    {
        // Re-authenticate with a fresh OAuth token for the capture call
        // (tokens are short-lived and start()'s token isn't reused across
        // requests since start() and capture() happen in separate HTTP
        // requests, minutes or longer apart).
        $ch = curl_init($this->settings->paypalApiBase() . '/v1/oauth2/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_USERPWD        => $this->settings->paypalClientId() . ':' . $this->settings->paypalClientSecret(),
            CURLOPT_POSTFIELDS     => 'grant_type=client_credentials',
            CURLOPT_TIMEOUT        => 15,
        ]);
        $tokenResponse = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        $token = $tokenResponse['access_token'] ?? null;
        if (!$token) {
            return [];
        }

        // POST .../orders/{id}/capture is PayPal's endpoint for actually
        // pulling the approved funds - urlencode() guards against the id
        // containing characters that would break the URL path.
        $ch = curl_init($this->settings->paypalApiBase() . '/v2/checkout/orders/' . urlencode($paypalOrderId) . '/capture');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
            CURLOPT_POSTFIELDS     => '{}',
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = json_decode((string)curl_exec($ch), true);
        curl_close($ch);
        // ?: [] turns a failed/empty decode into an empty array so callers
        // can safely use ?? on its keys instead of checking for null first.
        return $response ?: [];
    }
}
