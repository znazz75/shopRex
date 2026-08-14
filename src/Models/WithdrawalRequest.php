<?php

namespace ShopRex\Models;

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

    public ?string $reason = null;
    public ?string $deadlineAt = null;

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
        $base = new \DateTimeImmutable($order->shippedAt ?? $order->createdAt ?? 'now');
        $deliveryDays = (int)$settings->get('assumed_delivery_days_after_shipment', '3');
        $withdrawalDays = (int)$settings->get('withdrawal_period_days', '14');
        return $base->modify("+{$deliveryDays} days")->modify("+{$withdrawalDays} days");
    }

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
    public static function createFor(Order $order, ?array $customer, string $reason, array $orderItemIds, \PDO $pdo, SettingsRepository $settings): self
    {
        $deadline = self::calculateDeadline($order, $settings);

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO withdrawal_requests (order_id, customer_id, reason, status, deadline_at) VALUES (?, ?, ?, "submitted", ?)'
            );
            $stmt->execute([$order->id, $customer['id'] ?? null, $reason !== '' ? $reason : null, $deadline->format('Y-m-d H:i:s')]);
            $id = (int)$pdo->lastInsertId();

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
        $stmt = static::pdo()->prepare(
            'SELECT oi.* FROM order_items oi
             JOIN withdrawal_request_items wri ON wri.order_item_id = oi.id
             WHERE wri.withdrawal_request_id = ?'
        );
        $stmt->execute([$this->id]);
        return $stmt->fetchAll();
    }
}
