<?php

namespace ShopRex\Services;

/**
 * Direct port of getActiveDiscount()/getEffectivePrice()/
 * formatDiscountDateRange() from includes/functions.php.
 */
final class DiscountCalculator
{
    /** If $product currently has an active discount, details for rendering it; otherwise null. */
    public function activeFor(array $product): ?array
    {
        $type = $product['discount_type'] ?? 'none';
        // No discount configured at all, or a zero/blank value - nothing to apply.
        if ($type === 'none' || empty($product['discount_value'])) {
            return null;
        }

        // A discount can be scheduled for the future or already expired -
        // both are treated as "not active right now" even though the
        // discount fields are still set on the product.
        $now = new \DateTimeImmutable();
        if (!empty($product['discount_starts_at']) && $now < new \DateTimeImmutable($product['discount_starts_at'])) {
            return null;
        }
        if (!empty($product['discount_ends_at']) && $now > new \DateTimeImmutable($product['discount_ends_at'])) {
            return null;
        }

        $price = (float)$product['price'];
        $value = (float)$product['discount_value'];
        // 'percent' discounts are capped at 100% off (min($value, 100)) so a
        // misconfigured >100% discount can't make the price negative;
        // 'amount' (fixed) discounts are floored at 0 via max(0, ...) for the
        // same reason.
        $discounted = $type === 'percent'
            ? $price * (1 - min($value, 100) / 100)
            : max(0, $price - $value);

        return [
            'type'      => $type,
            'value'     => $value,
            'price'     => round($discounted, 2),
            // Human-readable badge text, e.g. "20% off" or "Save $5.00" -
            // built here so every place that shows a discount badge (product
            // listing, product page, cart) renders it identically.
            'label'     => $type === 'percent'
                ? __('discount.percent_off', ['value' => rtrim(rtrim(number_format($value, 2), '0'), '.')])
                : __('discount.save_amount', ['amount' => formatPrice($value)]),
            'starts_at' => $product['discount_starts_at'] ?? null,
            'ends_at'   => $product['discount_ends_at'] ?? null,
        ];
    }

    /** Always NET (before tax) - see TaxCalculator::grossPrice() for the tax-included price. */
    public function effectivePrice(array $product): float
    {
        $discount = $this->activeFor($product);
        return $discount ? $discount['price'] : (float)$product['price'];
    }

    /** Turns a discount's start/end dates into a human sentence like "Valid Jan 1 - Jan 31" for display near the discount badge; returns null if the discount has no date restrictions at all (an "always on" discount). */
    public function dateRangeLabel(array $discount): ?string
    {
        $starts = $discount['starts_at'] ?? null;
        $ends = $discount['ends_at'] ?? null;
        if (!$starts && !$ends) {
            return null;
        }
        // Three distinct phrasings depending on which bound(s) are set:
        // both start+end, only an end (open-ended discount that's ending),
        // or only a start (already-open discount with no end yet).
        if ($starts && $ends) {
            return __('discount.valid_range', ['start' => \ShopRex\Services\I18n::formatLocalDate($starts), 'end' => \ShopRex\Services\I18n::formatLocalDate($ends)]);
        }
        if ($ends) {
            return __('discount.ends', ['date' => \ShopRex\Services\I18n::formatLocalDate($ends)]);
        }
        return __('discount.starts', ['date' => \ShopRex\Services\I18n::formatLocalDate($starts)]);
    }
}
