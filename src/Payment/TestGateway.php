<?php

namespace ShopRex\Payment;

/**
 * Used instead of the real gateway whenever the order belongs to an
 * is_test_account customer. No network call - marked paid immediately so
 * the flow can be reviewed end-to-end without moving real money. Kept as a
 * separate class (rather than a flag on the other gateways) so "this is a
 * test order" is a clean swap at the factory level - see
 * CLAUDE.md's "Test accounts" section for how these orders are then
 * excluded from real financial figures.
 */
final class TestGateway implements PaymentGateway
{
    /** Immediately reports success with a fake transaction id - random hex, not a real gateway reference - since no real payment ever happens for a test account. */
    public function start(array $order, array $items): array
    {
        // 'TEST-' prefix makes a test transaction id visually unmistakable
        // from a real PayPal/Stripe one anywhere it's displayed (admin order
        // view, invoices, logs).
        return ['redirect_url' => null, 'transaction_id' => 'TEST-' . strtoupper(bin2hex(random_bytes(4))), 'status' => 'completed'];
    }
}
