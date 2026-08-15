<?php

namespace ShopRex\Payment;

/**
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

    private function getAccessToken(): ?string
    {
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
        $capturedAmount = $captureResponse['purchase_units'][0]['payments']['captures'][0]['amount']['value'] ?? null;

        $success = ($captureResponse['status'] ?? '') === 'COMPLETED'
            && is_numeric($capturedAmount)
            && abs((float)$capturedAmount - $orderTotal) < 0.01;

        return new CaptureResult($success, $storedIdentifier, (string)json_encode($captureResponse));
    }

    private function capturePayPalOrder(string $paypalOrderId): array
    {
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
        return $response ?: [];
    }
}
