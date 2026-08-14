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

    public string $orderNumber = '';
    public ?int $customerId = null;
    public ?string $guestEmail = null;
    public string $status = 'pending';
    public string $paymentMethod = '';
    public string $paymentStatus = 'pending';
    public bool $isTestOrder = false;
    public string $language = 'en';
    public float $subtotal = 0.0;
    public float $shippingCost = 0.0;
    public ?int $shippingMethodId = null;
    public ?string $shippingMethodName = null;
    public float $taxTotal = 0.0;
    public float $total = 0.0;
    public ?string $shippingName = null;
    public ?string $shippingAddress1 = null;
    public ?string $shippingAddress2 = null;
    public ?string $shippingCity = null;
    public ?string $shippingState = null;
    public ?string $shippingPostalCode = null;
    public ?string $shippingCountry = null;
    public bool $billingSameAsShipping = true;
    public ?string $billingName = null;
    public ?string $billingAddress1 = null;
    public ?string $billingAddress2 = null;
    public ?string $billingCity = null;
    public ?string $billingState = null;
    public ?string $billingPostalCode = null;
    public ?string $billingCountry = null;
    public ?string $customerNotes = null;
    public ?string $adminNotes = null;
    // v2.00 - stamped the first time an admin transitions this order to
    // 'shipped' (Phase 8); used by WithdrawalRequest::calculateDeadline().
    public ?string $shippedAt = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    // Not a real column - joined in via COALESCE(c.email, o.guest_email),
    // same as fetchOrderByNumber()'s original query.
    public ?string $customerEmail = null;

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

    /** @return array<int, array<string, mixed>> */
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
        $isOwner = $customer && $this->customerId !== null && (int)$this->customerId === (int)$customer['id'];
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
        if ($this->paymentStatus === 'paid') {
            return;
        }

        $pdo->prepare('UPDATE orders SET status = "processing", payment_status = "paid" WHERE id = ?')->execute([$this->id]);
        $pdo->prepare(
            'UPDATE payments SET status = "completed", transaction_id = COALESCE(?, transaction_id), gateway_response = ? WHERE order_id = ?'
        )->execute([$transactionId, $gatewayResponse, $this->id]);

        $this->status = 'processing';
        $this->paymentStatus = 'paid';

        // Test orders never touch the financial ledger.
        if (!$this->isTestOrder) {
            $pdo->prepare('INSERT INTO transactions (order_id, type, amount, note) VALUES (?, "sale", ?, "Payment captured")')
                ->execute([$this->id, $this->total]);
        }
    }
}
