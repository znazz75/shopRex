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
    private ?array $ratesCache = null;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly SettingsRepository $settings,
        private readonly DiscountCalculator $discounts,
    ) {
    }

    public function vatEnabled(): bool
    {
        return $this->settings->get('vat_enabled', '1') === '1';
    }

    /** All configured tax rates, highest first. */
    public function allRates(): array
    {
        if ($this->ratesCache === null) {
            $this->ratesCache = $this->pdo->query('SELECT * FROM tax_rates ORDER BY rate DESC')->fetchAll();
        }
        return $this->ratesCache;
    }

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
        if (!$this->vatEnabled()) {
            return 0.0;
        }
        return isset($product['tax_rate_percent']) ? (float)$product['tax_rate_percent'] : 0.0;
    }

    /** Gross (tax-included) price - what the storefront always displays. */
    public function grossPrice(array $product): float
    {
        $net = $this->discounts->effectivePrice($product);
        $rate = $this->percentFor($product);
        return $rate > 0 ? round($net * (1 + $rate / 100), 2) : $net;
    }

    /** "19" not "19.00". */
    public function formatRateNumber(float $rate): string
    {
        return rtrim(rtrim(number_format($rate, 2), '0'), '.');
    }
}
