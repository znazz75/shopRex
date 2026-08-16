<?php

namespace ShopRex\Models;

use ShopRex\Services\NumberSequenceService;
use ShopRex\Services\SettingsRepository;

/**
 * Right of withdrawal (Widerrufsrecht) - functional self-service flow, not
 * just the disclosure text on the 'right-of-withdrawal' CMS page. Covers a
 * subset of an order's items (withdrawal_request_items), since
 * hygiene-flagged items (Product::isHygieneProduct) must be excludable
 * per item, not per order - see Controllers\Storefront\WithdrawalController.
 */
class WithdrawalRequest extends CustomerRequest
{
    protected static string $table = 'withdrawal_requests';

    // Admin-configurable via Admin -> Numbering (see Services\NumberSequenceService);
    // null for any request submitted before that feature existed.
    public ?string $withdrawalNumber = null;
    // Free-text reason the customer gave, if any - EU/German withdrawal law
    // doesn't require a reason to be given at all, so this is optional context only.
    public ?string $reason = null;
    // The computed cutoff date/time after which this order can no longer be
    // withdrawn from - snapshotted once at creation (see calculateDeadline()'s
    // docblock) rather than recalculated on every check.
    public ?string $deadlineAt = null;

    /** Finds the (at most one) withdrawal request already filed for a given order - since an order can only have a single withdrawal request in this model, unlike RmaTicket which is per line item and can have several. */
    public static function findByOrder(int $orderId): ?self
    {
        $stmt = static::pdo()->prepare('SELECT * FROM withdrawal_requests WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch();
        return $row ? (new self())->fill($row) : null;
    }

    /**
     * (shippedAt ?? createdAt) + assumed_delivery_days_after_shipment +
     * withdrawal_period_days. A pragmatic proxy, not a claim of exact
     * legal precision: German law (§ 355 BGB) starts the clock on the
     * consumer's actual *receipt* of goods, which this app doesn't track
     * (no carrier/delivery-confirmation integration) - see the
     * withdrawal_requests.deadline_at column comment in sql/schema.sql for
     * why this is snapshotted once, not recomputed from current settings.
     */
    public static function calculateDeadline(Order $order, SettingsRepository $settings): \DateTimeImmutable
    {
        // Starting point: when the order actually shipped, or - if it
        // hasn't shipped yet somehow - when it was placed, or (last resort)
        // right now. shippedAt is preferred since the legal clock is meant
        // to track delivery, not purchase.
        $base = new \DateTimeImmutable($order->shippedAt ?? $order->createdAt ?? 'now');
        $deliveryDays = (int)$settings->get('assumed_delivery_days_after_shipment', '3');
        $withdrawalDays = (int)$settings->get('withdrawal_period_days', '14');
        // Two separate ->modify() calls (rather than adding the days
        // together first) so it's clear these are two distinct legal
        // periods being stacked: an assumed transit time, then the
        // statutory withdrawal window on top of that.
        return $base->modify("+{$deliveryDays} days")->modify("+{$withdrawalDays} days");
    }

    /** Whether the withdrawal deadline has already passed - a request with no deadline recorded (shouldn't normally happen once created via createFor()) is treated as NOT past deadline rather than blocking it. */
    public function isPastDeadline(): bool
    {
        if (!$this->deadlineAt) {
            return false;
        }
        return new \DateTimeImmutable() > new \DateTimeImmutable($this->deadlineAt);
    }

    /**
     * Creates the request + its withdrawal_request_items rows in one
     * transaction. $orderItemIds must already be validated by the caller
     * (Controllers\Storefront\WithdrawalController) to actually belong to
     * $order and not be hygiene-excluded - this method trusts its input,
     * it doesn't re-derive eligibility itself.
     */
    public static function createFor(Order $order, ?array $customer, string $reason, array $orderItemIds, \PDO $pdo, SettingsRepository $settings, NumberSequenceService $sequences): self
    {
        // The deadline is computed and stored once here, at submission time
        // - not recalculated later from live settings - so a subsequent
        // change to the withdrawal_period_days/assumed_delivery_days
        // setting can't retroactively move an already-filed request's deadline.
        $deadline = self::calculateDeadline($order, $settings);

        // Allocated BEFORE the transaction below opens, not inside it -
        // NumberSequenceService::next() runs its own internal
        // beginTransaction()/commit(), and PDO doesn't support real nested
        // transactions. If the insert below then fails, this number is
        // simply left unused (a gap, not a collision - see
        // NumberSequenceService's docblock).
        $withdrawalNumber = $sequences->next('withdrawal_request');

        // Both the parent request row and its per-item rows must be written
        // together - a transaction guarantees a request is never left with
        // zero items (or items without a parent request) if something fails midway.
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO withdrawal_requests (withdrawal_number, order_id, customer_id, reason, status, deadline_at) VALUES (?, ?, ?, ?, "submitted", ?)'
            );
            $stmt->execute([$withdrawalNumber, $order->id, $customer['id'] ?? null, $reason !== '' ? $reason : null, $deadline->format('Y-m-d H:i:s')]);
            $id = (int)$pdo->lastInsertId();

            // One row per covered order item - this is what lets a
            // withdrawal cover only SOME of an order's items (e.g.
            // excluding a hygiene-flagged product) rather than being all-or-nothing.
            $itemStmt = $pdo->prepare('INSERT INTO withdrawal_request_items (withdrawal_request_id, order_item_id) VALUES (?, ?)');
            foreach ($orderItemIds as $orderItemId) {
                $itemStmt->execute([$id, $orderItemId]);
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return self::find($id);
    }

    /** @return array<int, array<string,mixed>> order_items rows this request covers. */
    public function items(): array
    {
        // Joins through the withdrawal_request_items link table to get the
        // actual order_items rows this request applies to (see createFor()
        // above for how that link table gets populated).
        $stmt = static::pdo()->prepare(
            'SELECT oi.* FROM order_items oi
             JOIN withdrawal_request_items wri ON wri.order_item_id = oi.id
             WHERE wri.withdrawal_request_id = ?'
        );
        $stmt->execute([$this->id]);
        return $stmt->fetchAll();
    }
}
