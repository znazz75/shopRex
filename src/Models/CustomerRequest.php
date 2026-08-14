<?php

namespace ShopRex\Models;

use ShopRex\Core\Model;

/**
 * Shared base for the two "customer opens a post-purchase request tied to
 * an order, an admin reviews it, a status change can trigger an email"
 * flows added in v2.00: WithdrawalRequest (14-day, no-reason-needed) and
 * RmaTicket (defect/warranty-based, much longer window). Table-per-
 * concrete-class (no shared physical table - each subclass declares its
 * own $table) since the two have genuinely different extra columns
 * (order-level vs item-level, different status vocabularies), but the
 * common shape - who requested it, its current status, how an admin
 * resolves it - is real shared behavior, not just naming convention. This
 * is the concrete use of inheritance for "modular design" the OOP rewrite
 * was asked for.
 */
abstract class CustomerRequest extends Model
{
    public ?int $orderId = null;
    public ?int $customerId = null;
    public string $status = 'submitted';
    public ?string $requestedAt = null;
    public ?string $adminNotes = null;
    public ?int $processedBy = null;
    public ?string $processedAt = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    public function belongsToOrder(int $orderId): bool
    {
        return $this->orderId === $orderId;
    }

    /** Generic status transition - works for either subtype since both tables share these columns. */
    public function transitionTo(string $status, \PDO $pdo, ?int $adminId = null, ?string $notes = null): void
    {
        $this->status = $status;
        $this->processedBy = $adminId;
        $this->processedAt = date('Y-m-d H:i:s');
        if ($notes !== null) {
            $this->adminNotes = $notes;
        }

        $stmt = $pdo->prepare(
            'UPDATE ' . static::$table . ' SET status = ?, processed_by = ?, processed_at = ?, admin_notes = COALESCE(?, admin_notes) WHERE id = ?'
        );
        $stmt->execute([$status, $adminId, $this->processedAt, $notes, $this->id]);
    }

    public function approve(\PDO $pdo, ?int $adminId = null, ?string $notes = null): void
    {
        $this->transitionTo('approved', $pdo, $adminId, $notes);
    }

    public function reject(\PDO $pdo, ?int $adminId = null, ?string $notes = null): void
    {
        $this->transitionTo('rejected', $pdo, $adminId, $notes);
    }
}
