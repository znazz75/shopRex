<?php

namespace ShopRex\Payment;

/**
 * Talks to Stripe's real Checkout Sessions API (test mode by default) to
 * take a credit card payment - "CreditCard" is the shop-facing name for
 * what Stripe calls Checkout. This is one of the highest-security-
 * sensitivity files in the app: see the SECURITY comment on capture()
 * below and docs/SECURITY_AUDIT.md finding #2 for the exact attack it
 * defends against (a shopper substituting a *different* order number onto
 * their own real, paid Stripe session to get someone else's order marked
 * paid for free).
 *
 * Stripe Checkout Sessions (test mode by default). Direct port, capture() relocated from checkout_process.php's handleCapture() 'credit_card' branch + fetchStripeSession().
 */
final class CreditCardGateway implements PaymentGateway, CapturableGateway
{
    public function __construct(private readonly PaymentSettings $settings)
    {
    }

    /**
     * Creates a Stripe Checkout Session for this order's items/total and
     * returns the URL to redirect the shopper to Stripe's own hosted
     * payment page. The session id returned here is what gets saved as
     * this order's `payments.transaction_id` - the value capture() later
     * trusts instead of anything the return URL supplies (see capture()'s
     * security note).
     */
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
            // {CHECKOUT_SESSION_ID} is a literal placeholder token Stripe
            // itself substitutes with the real session id when it redirects
            // the shopper back - it is not PHP interpolation. Note this URL
            // also carries the order number as a plain query parameter -
            // see capture()'s docblock for why that alone is never trusted.
            'success_url' => rtrim(SITE_URL, '/') . '/checkout/capture?gateway=credit_card&order=' . $order['order_number'] . '&action=capture&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => rtrim(SITE_URL, '/') . '/checkout?cancelled=1',
            'customer_email' => $order['guest_email'] ?? $order['customer_email'] ?? '',
        ];

        // Stripe's Checkout API expects each line item as indexed form
        // fields (line_items[0][...], line_items[1][...], ...) rather than
        // a JSON array, so this builds that shape by hand.
        $i = 0;
        foreach ($items as $item) {
            $fields["line_items[$i][price_data][currency]"] = strtolower(getSetting('currency', 'EUR'));
            $fields["line_items[$i][price_data][product_data][name]"] = $item['product_name'];
            // Stripe expects amounts in the smallest currency unit (cents
            // for EUR/USD), hence *100 and rounding to a whole integer.
            $fields["line_items[$i][price_data][unit_amount]"] = (int)round($item['unit_price'] * 100);
            $fields["line_items[$i][quantity]"] = $item['quantity'];
            $i++;
        }

        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            // Stripe authenticates API calls via HTTP Basic Auth with the
            // secret key as the username and an empty password.
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

    /**
     * Confirms that money actually changed hands for THIS order and only
     * this order, then hands back the result for CheckoutController/
     * CheckoutService to act on.
     *
     * SECURITY (docs/SECURITY_AUDIT.md finding #2): $storedIdentifier is
     * the Stripe Checkout Session id that was saved on THIS shop order's
     * `payments` row back when start() created it; $submitted is whatever
     * the `session_id` query parameter says on the browser's return from
     * Stripe - i.e. attacker-controlled input, since it arrives via a
     * plain GET redirect with no CSRF protection possible. The only thing
     * this method ever uses to talk to Stripe is $storedIdentifier -
     * $submitted is used *exclusively* to check it matches, never passed
     * to Stripe itself. Without this check, an attacker could pay for a
     * cheap order of their own, get a real/valid Stripe session id back,
     * then replay that real session id against someone else's (or their
     * own more expensive) order number to get it marked paid without
     * paying for it. Any mismatch, or a missing stored identifier, fails
     * closed via CaptureResult::failure() - it never falls back to
     * trusting $submitted.
     */
    public function capture(?string $storedIdentifier, ?string $submitted, float $orderTotal): CaptureResult
    {
        // SECURITY (docs/SECURITY_AUDIT.md finding #2): only ever capture
        // using the Stripe Checkout Session id THIS order's payment was
        // created with, never whatever session_id happens to be in the URL.
        if (!$storedIdentifier || $submitted !== $storedIdentifier) {
            return CaptureResult::failure();
        }

        $sessionData = $this->fetchStripeSession($storedIdentifier);
        // Stripe reports amount_total in the smallest currency unit
        // (cents), so divide by 100 to get back to a normal decimal amount
        // comparable to $orderTotal.
        $paidAmount = isset($sessionData['amount_total']) ? $sessionData['amount_total'] / 100 : null;

        // Success requires ALL three: Stripe reports the session as fully
        // paid, an amount was actually returned, and that amount matches
        // the order total to within a cent (floating-point-safe comparison
        // rather than exact equality) - a partial or short payment must
        // never be treated as a full payment.
        $success = ($sessionData['payment_status'] ?? '') === 'paid'
            && is_numeric($paidAmount)
            && abs((float)$paidAmount - $orderTotal) < 0.01;

        return new CaptureResult($success, $storedIdentifier, (string)json_encode($sessionData));
    }

    /**
     * Looks up a Checkout Session's current status directly from Stripe
     * (a read-only GET, no state changes on Stripe's side) so capture()
     * can verify it independently rather than trusting anything the
     * browser's return URL claims. Kept private/internal because capture()
     * above is the only safe entry point - it's what enforces that
     * $sessionId came from this order's own stored identifier, never from
     * unchecked user input.
     */
    private function fetchStripeSession(string $sessionId): array
    {
        // urlencode() guards against the session id containing characters
        // that would break the URL path.
        $ch = curl_init('https://api.stripe.com/v1/checkout/sessions/' . urlencode($sessionId));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $this->settings->stripeSecretKey() . ':',
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);
        // ?: [] turns a failed/empty decode into an empty array so callers
        // can safely use ?? on its keys instead of checking for null first.
        return json_decode((string)$response, true) ?: [];
    }
}
