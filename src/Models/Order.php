<?php

namespace ShopRex\Models;

use ShopRex\Core\Model;

/**
 * A real, typed order object. Direct port of the order-related pieces of
 * checkout_process.php/order_confirmation.php/invoice_download.php:
 * fetchOrderByNumber() -> findByNumber(), the duplicated 3-way access
 * check in order_confirmation.php/invoice_download.php -> isAccessibleBy()
 * (now one implementation instead of two copies), markOrderPaid() ->
 * markPaid() (idempotency guard preserved verbatim - see docs/SECURITY_AUDIT.md
 * finding #3).
 */
class Order extends Model
{
    protected static string $table = 'orders';

    // Human-facing, hard-to-guess order identifier (e.g. "SR20260815-A1B2C3")
    // - see Services\CheckoutService::generateOrderNumber(). This, not the
    // numeric id, is what's used in URLs/emails.
    public string $orderNumber = '';
    // Set for a logged-in customer's order; null for a guest checkout (see
    // $guestEmail below for how a guest is still identified).
    public ?int $customerId = null;
    // Email address for a guest order (no customer account) - null when
    // $customerId is set, since a logged-in customer's email comes from
    // their account instead.
    public ?string $guestEmail = null;
    // Fulfillment workflow state (e.g. pending/processing/shipped/...) -
    // distinct from $paymentStatus below, which tracks money, not shipping.
    public string $status = 'pending';
    // Which payment method was used - paypal/credit_card/bank_transfer/invoice.
    public string $paymentMethod = '';
    // Whether payment has actually been received - starts 'pending', flips
    // to 'paid' via markPaid() once a gateway confirms/captures it.
    public string $paymentStatus = 'pending';
    // True for an order placed by an Admin -> Customers -> Test User account
    // - see CLAUDE.md: never decrements real stock, excluded from finance reports.
    public bool $isTestOrder = false;
    // The language the customer was browsing in when they checked out -
    // used so the invoice/confirmation email are generated in that language
    // even if the site's default language later changes.
    public string $language = 'en';
    // Sum of all line items' totals, before shipping/tax (NET, tax-exclusive).
    public float $subtotal = 0.0;
    public float $shippingCost = 0.0;
    public ?int $shippingMethodId = null;
    // Snapshot of the shipping method's NAME at order time - stored
    // separately from shippingMethodId so the order still shows a sensible
    // method name even if that shipping method is later renamed or deleted.
    public ?string $shippingMethodName = null;
    public float $taxTotal = 0.0;
    // Grand total actually charged: subtotal + shippingCost + taxTotal.
    public float $total = 0.0;
    public ?string $shippingName = null;
    public ?string $shippingAddress1 = null;
    public ?string $shippingAddress2 = null;
    public ?string $shippingCity = null;
    public ?string $shippingState = null;
    public ?string $shippingPostalCode = null;
    public ?string $shippingCountry = null;
    // Whether the billing address fields below should be ignored in favor
    // of the shipping address - true is the common case (same address for
    // both); the billing_* fields only matter when this is false.
    public bool $billingSameAsShipping = true;
    public ?string $billingName = null;
    public ?string $billingAddress1 = null;
    public ?string $billingAddress2 = null;
    public ?string $billingCity = null;
    public ?string $billingState = null;
    public ?string $billingPostalCode = null;
    public ?string $billingCountry = null;
    // Notes the customer left at checkout (e.g. delivery instructions).
    public ?string $customerNotes = null;
    // Internal notes an admin can add - never shown to the customer.
    public ?string $adminNotes = null;
    // v2.00 - stamped the first time an admin transitions this order to
    // 'shipped' (Phase 8); used by WithdrawalRequest::calculateDeadline().
    public ?string $shippedAt = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    // Not a real column - joined in via COALESCE(c.email, o.guest_email),
    // same as fetchOrderByNumber()'s original query.
    public ?string $customerEmail = null;

    /** Looks up an order by its human-facing order number (not the numeric id) - this is how every URL/email/confirmation page finds "the order", since the order number is the only order identifier ever exposed outside the database. */
    public static function findByNumber(string $orderNumber): ?self
    {
        $stmt = static::pdo()->prepare(
            "SELECT o.*, COALESCE(c.email, o.guest_email) AS customer_email
             FROM orders o LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.order_number = ?"
        );
        $stmt->execute([$orderNumber]);
        $row = $stmt->fetch();
        return $row ? (new self())->fill($row) : null;
    }

    /** @return array<int, array<string, mixed>> Every line item (product, quantity, price snapshot) belonging to this order - fetched fresh from order_items rather than cached on the object, since callers usually only need this once per request. */
    public function items(): array
    {
        $stmt = static::pdo()->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $stmt->execute([$this->id]);
        return $stmt->fetchAll();
    }

    /**
     * Three-way access grant - direct port of the identical check that used
     * to be duplicated in order_confirmation.php and invoice_download.php:
     * the order's own customer, any active admin, or the session that just
     * placed this exact order (guest checkout, set at checkout_process.php
     * time). Order numbers alone are not a secret worth trusting (only
     * ~24 bits of randomness with a predictable date prefix) - see
     * docs/SECURITY_AUDIT.md finding #4.
     */
    public function isAccessibleBy(?array $customer, bool $isAdmin, bool $isJustPlacedThisSession): bool
    {
        // "Owns" this order only if BOTH a customer is logged in AND this
        // order actually has a customer_id (not a guest order) AND the two
        // ids match - a logged-in customer viewing someone else's order
        // number, or anyone viewing a guest order they don't own, fails this.
        $isOwner = $customer && $this->customerId !== null && (int)$this->customerId === (int)$customer['id'];
        // Any ONE of the three is enough: the actual owner, an admin, or -
        // the guest-checkout case - the same browser session that placed
        // this exact order moments ago (see class docblock: order numbers
        // alone aren't secret enough to trust as the only gate).
        return (bool)$isOwner || $isAdmin || $isJustPlacedThisSession;
    }

    /**
     * Direct port of markOrderPaid() - idempotency guard preserved
     * verbatim (see docs/SECURITY_AUDIT.md finding #3): a gateway return
     * URL can legitimately be hit more than once (browser back/forward, a
     * retried request), so without this guard every extra hit would
     * insert another "sale" row into the transactions ledger for the same
     * payment, double-counting revenue.
     */
    public function markPaid(?string $transactionId, string $gatewayResponse, \PDO $pdo): void
    {
        // THE idempotency guard: if this order is already marked paid,
        // do nothing and return immediately. Without this, re-hitting a
        // gateway return URL (browser back/forward, a retried request, or
        // even a deliberate replay) would re-run everything below and
        // insert ANOTHER "sale" row into the transactions ledger for the
        // same payment every time - see docs/SECURITY_AUDIT.md finding #3.
        if ($this->paymentStatus === 'paid') {
            return;
        }

        $pdo->prepare('UPDATE orders SET status = "processing", payment_status = "paid" WHERE id = ?')->execute([$this->id]);
        // COALESCE(?, transaction_id) keeps the existing transaction_id if
        // this call didn't provide a new one (e.g. a gateway path that
        // confirms payment without returning a fresh id), rather than
        // overwriting a real id with NULL.
        $pdo->prepare(
            'UPDATE payments SET status = "completed", transaction_id = COALESCE(?, transaction_id), gateway_response = ? WHERE order_id = ?'
        )->execute([$transactionId, $gatewayResponse, $this->id]);

        // Keeps this in-memory object's fields in sync with what was just
        // written to the database, so the caller can immediately use
        // $order->status/$order->paymentStatus without re-fetching.
        $this->status = 'processing';
        $this->paymentStatus = 'paid';

        // Test orders never touch the financial ledger.
        if (!$this->isTestOrder) {
            $pdo->prepare('INSERT INTO transactions (order_id, type, amount, note) VALUES (?, "sale", ?, "Payment captured")')
                ->execute([$this->id, $this->total]);
        }
    }
}
