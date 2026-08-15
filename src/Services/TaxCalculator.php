<?php

namespace ShopRex\Services;

/**
 * Direct port of the VAT/tax functions in includes/functions.php
 * (vatIsEnabled/getAllTaxRates/getDefaultTaxRate/getTaxRatePercent/
 * getGrossPrice/formatTaxRateNumber). Depends on DiscountCalculator for
 * getGrossPrice()'s "net = effective (discounted) price" step, matching
 * the original getGrossPrice() -> getEffectivePrice() call chain.
 */
final class TaxCalculator
{
    // Memoizes allRates() for the rest of the request so repeated calls (e.g.
    // once per product row on a listing page) don't each hit the database.
    private ?array $ratesCache = null;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly SettingsRepository $settings,
        private readonly DiscountCalculator $discounts,
    ) {
    }

    /** Whether VAT/tax should be applied and shown at all - a shop-wide admin toggle for stores that don't need to charge tax. */
    public function vatEnabled(): bool
    {
        return $this->settings->get('vat_enabled', '1') === '1';
    }

    /** All configured tax rates, highest first. */
    public function allRates(): array
    {
        if ($this->ratesCache === null) {
            // ORDER BY rate DESC just controls display order in admin
            // dropdowns/lists - it has no effect on which rate applies to a
            // given product (that's decided by product.tax_rate_id).
            $this->ratesCache = $this->pdo->query('SELECT * FROM tax_rates ORDER BY rate DESC')->fetchAll();
        }
        return $this->ratesCache;
    }

    /** The tax rate new products/categories should use unless told otherwise - falls back to a synthetic "no tax" rate if the admin never marked any rate as default. */
    public function defaultRate(): array
    {
        foreach ($this->allRates() as $rate) {
            if ($rate['is_default']) {
                return $rate;
            }
        }
        return ['id' => null, 'name' => 'None', 'rate' => 0.00];
    }

    /** Expects a tax_rate_percent column on $product (see index.php's subquery pattern). */
    public function percentFor(array $product): float
    {
        // VAT disabled shop-wide overrides any per-product rate - always 0%.
        if (!$this->vatEnabled()) {
            return 0.0;
        }
        return isset($product['tax_rate_percent']) ? (float)$product['tax_rate_percent'] : 0.0;
    }

    /** Gross (tax-included) price - what the storefront always displays. */
    public function grossPrice(array $product): float
    {
        // Tax is calculated on top of the already-discounted price, not the
        // original list price - matches the original getGrossPrice() ->
        // getEffectivePrice() call chain (see class docblock).
        $net = $this->discounts->effectivePrice($product);
        $rate = $this->percentFor($product);
        return $rate > 0 ? round($net * (1 + $rate / 100), 2) : $net;
    }

    /** Formats a tax rate for display without trailing zeros, e.g. 19.00 -> "19", 7.50 -> "7.5". */
    public function formatRateNumber(float $rate): string
    {
        // number_format() always pads to 2 decimals; the two rtrim() calls
        // strip trailing zeros and then a now-trailing decimal point.
        return rtrim(rtrim(number_format($rate, 2), '0'), '.');
    }
}
