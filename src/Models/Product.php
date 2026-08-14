<?php

namespace ShopRex\Models;

use ShopRex\Core\Model;
use ShopRex\Services\DiscountCalculator;
use ShopRex\Services\TaxCalculator;

/**
 * A real, typed product object - used by the newly-written product page
 * (Controllers\Storefront\ProductController) and, later, cart/checkout/
 * admin. Listing pages (index.php's home.php slot, search.php) instead
 * keep working on plain arrays through DiscountCalculator/TaxCalculator/
 * the view-helpers shim, because their templates are deliberately NOT
 * rewritten (theme-package fidelity) - see those services' docblocks.
 * toArray() bridges the two: it produces the exact same associative-array
 * shape those services already expect, so a Product's price/tax/discount
 * can be computed with the identical, single implementation either way.
 */
class Product extends Model
{
    protected static string $table = 'products';

    public ?int $categoryId = null;
    public string $sku = '';
    public string $name = '';
    public string $slug = '';
    public ?string $shortDescription = null;
    public ?string $description = null;
    public float $price = 0.0;
    public string $discountType = 'none';
    public ?float $discountValue = null;
    public ?string $discountStartsAt = null;
    public ?string $discountEndsAt = null;
    public ?string $availableFrom = null;
    public ?string $availableUntil = null;
    public ?int $taxRateId = null;
    public string $priceEntryMode = 'net';
    public int $stockQuantity = 0;
    public int $stockThreshold = 5;
    public ?int $maxOrderQuantity = null;
    public ?float $weightKg = null;
    public string $status = 'active';
    // v2.00 - warranty/battery/hygiene disclosure fields, see Phase 6.
    public int $statutoryWarrantyMonths = 24;
    public ?int $manufacturerWarrantyMonths = null;
    public ?string $manufacturerWarrantyNotes = null;
    public bool $containsBattery = false;
    public bool $isHygieneProduct = false;
    public ?string $createdAt = null;
    public ?string $updatedAt = null;

    // Not real columns - populated by whichever query subquery-joined them
    // (see Product::findBySlug()), kept as plain properties so toArray()
    // reproduces the exact shape DiscountCalculator/TaxCalculator expect.
    public ?string $primaryImage = null;
    public ?string $primaryCroppedImage = null;
    public ?float $taxRatePercent = null;

    /** Single active product by slug - direct port of product.php's initial SELECT. */
    public static function findBySlug(string $slug): ?self
    {
        $stmt = static::pdo()->prepare(
            "SELECT p.*, (SELECT rate FROM tax_rates WHERE id = p.tax_rate_id) AS tax_rate_percent
             FROM products p WHERE p.slug = ? AND p.status = 'active'"
        );
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        return $row ? (new self())->fill($row) : null;
    }

    /** Outside its available_from/available_until window, a product is treated exactly like it doesn't exist. */
    public function isCurrentlyAvailable(): bool
    {
        return self::isRowCurrentlyAvailable(['available_from' => $this->availableFrom, 'available_until' => $this->availableUntil]);
    }

    /**
     * Same check as isCurrentlyAvailable(), for callers holding a raw
     * fetchAll() row rather than a hydrated Product (admin product list -
     * see Controllers\Admin\ProductAdminController - deliberately queries
     * plain arrays rather than a Model per entity, matching every other
     * admin CRUD list in this codebase). Direct port of
     * isProductCurrentlyAvailable() from includes/functions.php.
     */
    public static function isRowCurrentlyAvailable(array $product): bool
    {
        $now = new \DateTimeImmutable();
        if (!empty($product['available_from']) && $now < new \DateTimeImmutable($product['available_from'])) {
            return false;
        }
        if (!empty($product['available_until']) && $now > new \DateTimeImmutable($product['available_until'])) {
            return false;
        }
        return true;
    }

    /** SQL fragment (no leading AND) restricting a `p`-aliased query to rows inside their availability window. */
    public static function availabilityWindowSql(): string
    {
        return "(p.available_from IS NULL OR p.available_from <= NOW()) AND (p.available_until IS NULL OR p.available_until >= NOW())";
    }

    public function primaryImageUrl(): string
    {
        if (!empty($this->primaryCroppedImage)) {
            return UPLOAD_URL . $this->primaryCroppedImage;
        }
        if (!empty($this->primaryImage)) {
            return UPLOAD_URL . $this->primaryImage;
        }
        return rtrim(SITE_URL, '/') . '/assets/img/placeholder.svg';
    }

    /** Display URL for a single product_images row - direct port of getImageUrl() in includes/functions.php. */
    public static function imageUrl(array $image): string
    {
        return UPLOAD_URL . ($image['cropped_path'] ?: $image['image_path']);
    }

    public function activeDiscount(DiscountCalculator $discounts): ?array
    {
        return $discounts->activeFor($this->toArray());
    }

    public function effectivePrice(DiscountCalculator $discounts): float
    {
        return $discounts->effectivePrice($this->toArray());
    }

    public function taxRatePercentValue(TaxCalculator $tax): float
    {
        return $tax->percentFor($this->toArray());
    }

    public function grossPrice(TaxCalculator $tax): float
    {
        return $tax->grossPrice($this->toArray());
    }

    /** Same associative-array shape the pre-OOP code (and its still-untouched templates) expect. */
    public function toArray(): array
    {
        return $this->toRow();
    }
}
