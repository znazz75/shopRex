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
    // Which order this request relates to - a WithdrawalRequest applies to
    // the whole order; an RmaTicket is item-level but still records the
    // order it came from.
    public ?int $orderId = null;
    // Who submitted the request - the logged-in customer's ID (a guest
    // checkout customer would need an account to open one of these).
    public ?int $customerId = null;
    // Workflow state - starts 'submitted', moves to 'approved'/'rejected'
    // (see transitionTo()) plus whatever further subtype-specific states
    // each concrete class's own status vocabulary adds.
    public string $status = 'submitted';
    public ?string $requestedAt = null;
    // Admin-facing notes about this request - not shown to the customer
    // unless a subclass/controller explicitly surfaces them.
    public ?string $adminNotes = null;
    // Which admin user last changed the status, for accountability/audit.
    public ?int $processedBy = null;
    public ?string $processedAt = null;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    /** Ownership check: true only if this request was made against the given order - used by storefront controllers to make sure a customer can only view/act on their OWN requests, not someone else's by guessing/incrementing an ID in the URL. */
    public function belongsToOrder(int $orderId): bool
    {
        return $this->orderId === $orderId;
    }

    /** Generic status transition - works for either subtype since both tables share these columns. Records who made the change and when, alongside the new status, so every approve/reject is auditable. */
    public function transitionTo(string $status, \PDO $pdo, ?int $adminId = null, ?string $notes = null): void
    {
        $this->status = $status;
        $this->processedBy = $adminId;
        $this->processedAt = date('Y-m-d H:i:s');
        // Only overwrite admin_notes if new notes were actually passed -
        // an approve/reject with no notes shouldn't wipe out notes left by
        // an earlier review step.
        if ($notes !== null) {
            $this->adminNotes = $notes;
        }

        // static::$table resolves to whichever concrete subclass's table
        // this instance actually belongs to (WithdrawalRequest's or
        // RmaTicket's) - that's why this shared method works for both
        // without needing to know which one it's operating on.
        $stmt = $pdo->prepare(
            'UPDATE ' . static::$table . ' SET status = ?, processed_by = ?, processed_at = ?, admin_notes = COALESCE(?, admin_notes) WHERE id = ?'
        );
        // COALESCE(?, admin_notes) in SQL mirrors the same "don't overwrite
        // with nothing" behavior at the database level, in case this row is
        // updated by SQL directly elsewhere without going through this method.
        $stmt->execute([$status, $adminId, $this->processedAt, $notes, $this->id]);
    }

    /** Admin action: marks this request approved (e.g. a withdrawal/return is accepted) - a thin, readable wrapper over transitionTo() so call sites read as intent ("approve") rather than a raw status string. */
    public function approve(\PDO $pdo, ?int $adminId = null, ?string $notes = null): void
    {
        $this->transitionTo('approved', $pdo, $adminId, $notes);
    }

    /** Admin action: marks this request rejected - see approve(). */
    public function reject(\PDO $pdo, ?int $adminId = null, ?string $notes = null): void
    {
        $this->transitionTo('rejected', $pdo, $adminId, $notes);
    }
}
