<?php

namespace ShopRex\Models;

/**
 * RMA / defect ticket - warranty-based, item-level (a defect claim is
 * always about one specific product), distinct from the no-reason-needed,
 * order-level WithdrawalRequest above despite sharing CustomerRequest.
 * Eligibility is driven by whichever warranty the customer is claiming
 * under (Product::statutoryWarrantyMonths vs manufacturerWarrantyMonths),
 * not a fixed short window.
 */
class RmaTicket extends CustomerRequest
{
    protected static string $table = 'rma_tickets';

    // Which specific line item within the order this defect claim is
    // about - unlike WithdrawalRequest, an RMA is always tied to one item,
    // never the whole order.
    public ?int $orderItemId = null;
    // The customer's free-text description of what's wrong with the product.
    public string $defectDescription = '';
    // Which warranty the customer is claiming under - 'statutory' (the
    // legally mandated minimum) or 'manufacturer' (a longer, product-specific
    // warranty the seller/manufacturer optionally offers) - see isEligible().
    public string $warrantyClaimType = 'statutory';
    // Admin-written notes on how the claim was resolved (e.g. "replacement
    // sent", "refund issued") - distinct from the shared adminNotes field,
    // this is specifically the outcome/resolution record.
    public ?string $resolutionNotes = null;

    /**
     * True while $orderCreatedAt + the relevant warranty period (statutory
     * or manufacturer, per $claimType) hasn't yet elapsed. A manufacturer
     * claim against a product with no manufacturer warranty configured
     * (manufacturerWarrantyMonths === null) is never eligible.
     */
    public static function isEligible(Product $product, string $claimType, \DateTimeInterface $orderCreatedAt): bool
    {
        // Picks whichever warranty length applies to the type of claim
        // being made - the two are independent (a product can have a
        // manufacturer warranty longer, shorter, or the same length as its
        // statutory one).
        $months = $claimType === 'manufacturer'
            ? $product->manufacturerWarrantyMonths
            : $product->statutoryWarrantyMonths;

        // No warranty period configured for this claim type at all (most
        // commonly: a product with no manufacturer warranty being claimed
        // as 'manufacturer') - can never be eligible, regardless of dates.
        if ($months === null) {
            return false;
        }

        // Eligibility window runs from when the order was placed, not from
        // delivery - a simpler, more conservative proxy than
        // WithdrawalRequest's shipped-date-based calculation, appropriate
        // since warranty periods are typically much longer than the
        // shipping-time margin would matter for.
        $expiry = \DateTimeImmutable::createFromInterface($orderCreatedAt)->modify("+{$months} months");
        return new \DateTimeImmutable() <= $expiry;
    }

    /** Opens a new RMA/defect ticket for one order item - the storefront controller is expected to have already checked isEligible() before calling this; this method itself doesn't re-verify warranty eligibility. */
    public static function createFor(int $orderId, int $orderItemId, ?array $customer, string $claimType, string $description, \PDO $pdo): self
    {
        $stmt = $pdo->prepare(
            'INSERT INTO rma_tickets (order_id, order_item_id, customer_id, defect_description, warranty_claim_type, status)
             VALUES (?, ?, ?, ?, ?, "submitted")'
        );
        $stmt->execute([$orderId, $orderItemId, $customer['id'] ?? null, $description, $claimType]);
        return self::find((int)$pdo->lastInsertId());
    }

    /**
     * Persists an already-validated (extension + real content checked),
     * already-moved attachment file. The validation itself (rejecting
     * disguised/non-image files) must happen in the controller BEFORE this
     * is called - see docs/SECURITY_AUDIT.md finding #6 on why extension-only
     * checks aren't sufficient for uploads; this method just records the
     * path of a file that's already been through that check and saved to disk.
     */
    public function addAttachment(string $relativePath, \PDO $pdo): void
    {
        $pdo->prepare('INSERT INTO rma_ticket_attachments (rma_ticket_id, file_path) VALUES (?, ?)')->execute([$this->id, $relativePath]);
    }

    /** @return array<int, array{id:int, file_path:string, uploaded_at:string}> Every photo attached to this ticket (up to 5, enforced by the controller - see class docblock), for rendering thumbnails/links in the admin and customer views. */
    public function attachments(): array
    {
        $stmt = static::pdo()->prepare('SELECT * FROM rma_ticket_attachments WHERE rma_ticket_id = ?');
        $stmt->execute([$this->id]);
        return $stmt->fetchAll();
    }

    /** How many photos are already attached - used to enforce the "up to 5 attachments" limit before accepting another upload, without needing to fetch and count the full attachments() list. */
    public function attachmentCount(): int
    {
        $stmt = static::pdo()->prepare('SELECT COUNT(*) FROM rma_ticket_attachments WHERE rma_ticket_id = ?');
        $stmt->execute([$this->id]);
        return (int)$stmt->fetchColumn();
    }
}
