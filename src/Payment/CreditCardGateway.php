<?php

namespace ShopRex\Payment;

/** Stripe Checkout Sessions (test mode by default). Direct port, capture() relocated from checkout_process.php's handleCapture() 'credit_card' branch + fetchStripeSession(). */
final class CreditCardGateway implements PaymentGateway, CapturableGateway
{
    public function __construct(private readonly PaymentSettings $settings)
    {
    }

    public function start(array $order, array $items): array
    {
        $secretKey = $this->settings->stripeSecretKey();
        if ($secretKey === 'sk_test_yourkey' || $secretKey === '') {
            // No real Stripe key configured - keep the demo flow working
            // without an external call.
            return ['redirect_url' => null, 'transaction_id' => null, 'status' => 'pending'];
        }

        $fields = [
            'mode' => 'payment',
            'success_url' => rtrim(SITE_URL, '/') . '/checkout/capture?gateway=credit_card&order=' . $order['order_number'] . '&action=capture&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim(SITE_URL, '/') . '/checkout?cancelled=1',
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

    public function capture(?string $storedIdentifier, ?string $submitted, float $orderTotal): CaptureResult
    {
        // SECURITY (docs/SECURITY_AUDIT.md finding #2): only ever capture
        // using the Stripe Checkout Session id THIS order's payment was
        // created with, never whatever session_id happens to be in the URL.
        if (!$storedIdentifier || $submitted !== $storedIdentifier) {
            return CaptureResult::failure();
        }

        $sessionData = $this->fetchStripeSession($storedIdentifier);
        $paidAmount = isset($sessionData['amount_total']) ? $sessionData['amount_total'] / 100 : null;

        $success = ($sessionData['payment_status'] ?? '') === 'paid'
            && is_numeric($paidAmount)
            && abs((float)$paidAmount - $orderTotal) < 0.01;

        return new CaptureResult($success, $storedIdentifier, (string)json_encode($sessionData));
    }

    private function fetchStripeSession(string $sessionId): array
    {
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->settings->stripeSecretKey() . ':',
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        return json_decode((string)$response, true) ?: [];
    }
}
