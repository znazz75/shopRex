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
        if ($type === 'none' || empty($product['discount_value'])) {
            return null;
        }

        $now = new \DateTimeImmutable();
        if (!empty($product['discount_starts_at']) && $now < new \DateTimeImmutable($product['discount_starts_at'])) {
            return null;
        }
        if (!empty($product['discount_ends_at']) && $now > new \DateTimeImmutable($product['discount_ends_at'])) {
            return null;
        }

        $price = (float)$product['price'];
        $value = (float)$product['discount_value'];
        $discounted = $type === 'percent'
            ? $price * (1 - min($value, 100) / 100)
            : max(0, $price - $value);

        return [
            'type'      => $type,
            'value'     => $value,
            'price'     => round($discounted, 2),
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

    public function dateRangeLabel(array $discount): ?string
    {
        $starts = $discount['starts_at'] ?? null;
        $ends = $discount['ends_at'] ?? null;
        if (!$starts && !$ends) {
            return null;
        }
        if ($starts && $ends) {
            return __('discount.valid_range', ['start' => \ShopRex\Services\I18n::formatLocalDate($starts), 'end' => \ShopRex\Services\I18n::formatLocalDate($ends)]);
        }
        if ($ends) {
            return __('discount.ends', ['date' => \ShopRex\Services\I18n::formatLocalDate($ends)]);
        }
        return __('discount.starts', ['date' => \ShopRex\Services\I18n::formatLocalDate($starts)]);
    }
}
