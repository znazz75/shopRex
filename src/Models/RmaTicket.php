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

    public ?int $orderItemId = null;
    public string $defectDescription = '';
    public string $warrantyClaimType = 'statutory';
    public ?string $resolutionNotes = null;

    /**
     * True while $orderCreatedAt + the relevant warranty period (statutory
     * or manufacturer, per $claimType) hasn't yet elapsed. A manufacturer
     * claim against a product with no manufacturer warranty configured
     * (manufacturerWarrantyMonths === null) is never eligible.
     */
    public static function isEligible(Product $product, string $claimType, \DateTimeInterface $orderCreatedAt): bool
    {
        $months = $claimType === 'manufacturer'
            ? $product->manufacturerWarrantyMonths
            : $product->statutoryWarrantyMonths;

        if ($months === null) {
            return false;
        }

        $expiry = \DateTimeImmutable::createFromInterface($orderCreatedAt)->modify("+{$months} months");
        return new \DateTimeImmutable() <= $expiry;
    }

    public static function createFor(int $orderId, int $orderItemId, ?array $customer, string $claimType, string $description, \PDO $pdo): self
    {
        $stmt = $pdo->prepare(
            'INSERT INTO rma_tickets (order_id, order_item_id, customer_id, defect_description, warranty_claim_type, status)
             VALUES (?, ?, ?, ?, ?, "submitted")'
        );
        $stmt->execute([$orderId, $orderItemId, $customer['id'] ?? null, $description, $claimType]);
        return self::find((int)$pdo->lastInsertId());
    }

    /** Persists an already-validated (extension + real content checked), already-moved attachment file. */
    public function addAttachment(string $relativePath, \PDO $pdo): void
    {
        $pdo->prepare('INSERT INTO rma_ticket_attachments (rma_ticket_id, file_path) VALUES (?, ?)')->execute([$this->id, $relativePath]);
    }

    /** @return array<int, array{id:int, file_path:string, uploaded_at:string}> */
    public function attachments(): array
    {
        $stmt = static::pdo()->prepare('SELECT * FROM rma_ticket_attachments WHERE rma_ticket_id = ?');
        $stmt->execute([$this->id]);
        return $stmt->fetchAll();
    }

    public function attachmentCount(): int
    {
        $stmt = static::pdo()->prepare('SELECT COUNT(*) FROM rma_ticket_attachments WHERE rma_ticket_id = ?');
        $stmt->execute([$this->id]);
        return (int)$stmt->fetchColumn();
    }
}
