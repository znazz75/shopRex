<?php

namespace ShopRex\Services;

/**
 * Allocates admin-configurable, formatted sequential numbers (Admin ->
 * Numbering) for customer/invoice/RMA-ticket/withdrawal-request records -
 * backed by the `number_sequences` table (one row per document type, all
 * seeded in sql/schema.sql since `type` is a fixed, code-defined set, not
 * something an admin creates). Deliberately NOT used for order numbers -
 * see sql/schema.sql's comment on this table for why those stay
 * date+random.
 */
final class NumberSequenceService
{
    public function __construct(private readonly \PDO $pdo)
    {
    }

    /**
     * Atomically allocates and returns the next formatted number for
     * $type (e.g. 'customer', 'invoice', 'rma_ticket', 'withdrawal_request').
     * Format is always prefix + date component (if configured) + the
     * zero-padded running number + suffix - a fixed, simple layout rather
     * than a template mini-language, matching this project's "no framework
     * magic" style.
     *
     * A number allocated here but never actually used by the caller (e.g.
     * the following INSERT fails validation) leaves a gap in the
     * sequence, not a collision - that's standard, accepted behavior for
     * sequence generators (real invoicing systems tolerate voided/skipped
     * numbers too), not something this method tries to prevent.
     */
    public function next(string $type): string
    {
        $this->pdo->beginTransaction();
        try {
            // SELECT ... FOR UPDATE row-locks this sequence for the rest of
            // the transaction, so two concurrent callers (e.g. two
            // simultaneous registrations) can never both read the same
            // next_value - the second caller blocks here until the first
            // commits, then sees the already-advanced value. Same pattern
            // this project already uses for stock decrements
            // (Services\CheckoutService) and payment-capture idempotency
            // (Models\Order::markPaid()).
            $stmt = $this->pdo->prepare('SELECT * FROM number_sequences WHERE type = ? FOR UPDATE');
            $stmt->execute([$type]);
            $seq = $stmt->fetch();
            if (!$seq) {
                // Every valid $type has a seeded row (see schema.sql) - an
                // unknown type here is a programming error, not user input,
                // so this fails loud rather than silently returning
                // something made-up.
                throw new \RuntimeException("No number sequence configured for type '{$type}'.");
            }

            $datePart = $seq['date_format'] !== '' ? date($seq['date_format']) : '';
            $issued = (int)$seq['next_value'];

            // Period rollover: only relevant when a date component is in
            // use AND reset_on_date_change is on. current_period_key is
            // NULL before this sequence's very first number is ever
            // issued, which also (correctly) counts as "the period
            // changed" - the first number issued always starts at
            // start_value rather than whatever next_value happened to be
            // seeded to.
            if ($seq['reset_on_date_change'] && $datePart !== '' && $datePart !== $seq['current_period_key']) {
                $issued = (int)$seq['start_value'];
            }

            $next = $issued + (int)$seq['increment'];
            $this->pdo->prepare('UPDATE number_sequences SET next_value = ?, current_period_key = ? WHERE type = ?')
                ->execute([$next, $datePart, $type]);
            $this->pdo->commit();

            return $seq['prefix'] . $datePart . str_pad((string)$issued, (int)$seq['padding'], '0', STR_PAD_LEFT) . $seq['suffix'];
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }
    }
}
