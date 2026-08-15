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
    // Stock keeping unit - the merchant's own inventory code, distinct from
    // the database id and from $slug (the URL identifier).
    public string $sku = '';
    public string $name = '';
    // URL-friendly identifier, e.g. "classic-t-shirt" - what /product/{slug} matches on.
    public string $slug = '';
    public ?string $shortDescription = null;
    public ?string $description = null;
    // Base price in the default language/currency - always the DEFAULT
    // language's content (product name/description translations live in a
    // separate table via TranslationOverlay, but price is never translated,
    // just currency-formatted - see CLAUDE.md).
    public float $price = 0.0;
    // 'none' | 'percent' | 'amount' - see Services\DiscountCalculator for
    // how this combines with discountValue/discountStartsAt/discountEndsAt.
    public string $discountType = 'none';
    public ?float $discountValue = null;
    public ?string $discountStartsAt = null;
    public ?string $discountEndsAt = null;
    // Optional scheduling window during which this product is actually
    // orderable/visible - see isCurrentlyAvailable().
    public ?string $availableFrom = null;
    public ?string $availableUntil = null;
    public ?int $taxRateId = null;
    // Whether the admin-entered $price was typed as 'net' (tax-exclusive) or
    // 'gross' (tax-inclusive) - affects how the admin edit form displays/
    // interprets the price field, not how it's stored (price is always
    // stored net internally).
    public string $priceEntryMode = 'net';
    public int $stockQuantity = 0;
    // Below this quantity, the admin UI flags the product as "low stock" -
    // purely a display threshold, doesn't block sales by itself.
    public int $stockThreshold = 5;
    // Optional cap on how many units one customer can buy in a single order.
    public ?int $maxOrderQuantity = null;
    // Used to compute cart shipping weight (see Models\Cart::getWeightKg()).
    public ?float $weightKg = null;
    // 'active' | other statuses (e.g. draft/archived) - only 'active'
    // products are ever shown/purchasable on the storefront.
    public string $status = 'active';
    // v2.00 - warranty/battery/hygiene disclosure fields, see Phase 6.
    // Legally-required minimum warranty length in months (see RmaTicket::isEligible()).
    public int $statutoryWarrantyMonths = 24;
    // Optional longer warranty the seller/manufacturer voluntarily offers on
    // top of the statutory minimum - null means none is offered.
    public ?int $manufacturerWarrantyMonths = null;
    public ?string $manufacturerWarrantyNotes = null;
    // Disclosure flags used to show required legal notices/handling warnings
    // at checkout and on the product page (batteries, hygiene items).
    public bool $containsBattery = false;
    // Hygiene-flagged products are excluded from the right-of-withdrawal
    // flow per item (see Models\WithdrawalRequest's class docblock).
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
        // Only 'active' products are ever resolvable by slug - a
        // draft/archived product's page effectively doesn't exist to a visitor.
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
        // Not available yet - scheduled to go on sale in the future.
        if (!empty($product['available_from']) && $now < new \DateTimeImmutable($product['available_from'])) {
            return false;
        }
        // No longer available - the sale window has already ended.
        if (!empty($product['available_until']) && $now > new \DateTimeImmutable($product['available_until'])) {
            return false;
        }
        return true;
    }

    /** SQL fragment (no leading AND) restricting a `p`-aliased query to rows inside their availability window. */
    public static function availabilityWindowSql(): string
    {
        // Mirrors isRowCurrentlyAvailable()'s logic but expressed as SQL, so
        // listing queries (category/search pages) can filter unavailable
        // products out directly in the database instead of fetching every
        // row and filtering in PHP afterward.
        return "(p.available_from IS NULL OR p.available_from <= NOW()) AND (p.available_until IS NULL OR p.available_until >= NOW())";
    }

    /** The actual image URL to show for this product's main/thumbnail image, falling back gracefully if no image has been uploaded yet. */
    public function primaryImageUrl(): string
    {
        // A cropped version (made via the admin's image-crop tool) is
        // preferred over the original upload when one exists, since it's
        // been sized/framed specifically for display.
        if (!empty($this->primaryCroppedImage)) {
            return UPLOAD_URL . $this->primaryCroppedImage;
        }
        if (!empty($this->primaryImage)) {
            return UPLOAD_URL . $this->primaryImage;
        }
        // No image uploaded at all - a generic placeholder graphic rather
        // than a broken image link.
        return rtrim(SITE_URL, '/') . '/assets/img/placeholder.svg';
    }

    /** Display URL for a single product_images row - direct port of getImageUrl() in includes/functions.php. */
    public static function imageUrl(array $image): string
    {
        return UPLOAD_URL . ($image['cropped_path'] ?: $image['image_path']);
    }

    /** This product's currently-active discount details (if any), or null - a thin object-oriented wrapper around DiscountCalculator, which does the actual work on the array shape (see class docblock on why toArray() bridges the two). */
    public function activeDiscount(DiscountCalculator $discounts): ?array
    {
        return $discounts->activeFor($this->toArray());
    }

    /** NET price after any active discount is applied - see effectivePrice() on DiscountCalculator for the underlying calculation. */
    public function effectivePrice(DiscountCalculator $discounts): float
    {
        return $discounts->effectivePrice($this->toArray());
    }

    /** The tax percentage that applies to this product (0 if VAT is disabled shop-wide). */
    public function taxRatePercentValue(TaxCalculator $tax): float
    {
        return $tax->percentFor($this->toArray());
    }

    /** GROSS (tax-included) price after discount - what should actually be shown to a shopper as "the price". */
    public function grossPrice(TaxCalculator $tax): float
    {
        return $tax->grossPrice($this->toArray());
    }

    /** Same associative-array shape the pre-OOP code (and its still-untouched templates) expect - lets this typed Product object be handed to DiscountCalculator/TaxCalculator (which only know how to work with plain arrays) without those services needing a second, object-aware code path. */
    public function toArray(): array
    {
        return $this->toRow();
    }
}
