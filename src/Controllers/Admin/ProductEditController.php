<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Services\CategoryTreeService;
use ShopRex\Services\I18n;
use ShopRex\Services\SettingsRepository;
use ShopRex\Services\TaxCalculator;
use ShopRex\Services\TranslationOverlay;
use ShopRex\Support\Slugger;

/**
 * Powers the "create/edit product" admin screen - the single most complex
 * page in the app. One product here isn't just a name/price/stock row: it
 * can also have per-language translations (name/description, and every
 * option group/value's translation), option groups with a free-typed
 * comma-separated value list (e.g. "Size" -> "S, M, L"), and a full
 * variant matrix tracking stock per exact option combination (e.g.
 * "M||Red" has its own stock count, separate from "M||Blue"). Every save
 * deletes and fully rebuilds a product's options/values/variants rather
 * than diffing them - see CLAUDE.md's Architecture section ("Because
 * product_options/product_option_values are fully deleted and recreated
 * on every product save...") for why that's the deliberate design here,
 * not an oversight: it's simpler and safer than trying to diff an
 * arbitrary add/remove/rename of option rows against existing ids, at the
 * cost of every option/value getting a brand new database id on every
 * save (which is exactly why the "combo signature" matching described
 * below exists, instead of matching by id).
 *
 * Direct, line-cited port of admin/product_edit.php - the largest and
 * most complex file in the app (delete-and-recreate options/variants
 * every save, combo-signature stock carry-forward, positional translation
 * matching). See the architecture plan's risk list: ported method-for-
 * method against the original's exact line ranges rather than rewritten
 * from memory.
 */
final class ProductEditController extends AdminCrudController
{
    // Shared PDO connection - used directly (with an explicit transaction
    // around the whole save) rather than through a Model, since this save
    // touches half a dozen related tables atomically.
    private readonly \PDO $pdo;
    // Builds the category dropdown's tree/flattened list for the form.
    private readonly CategoryTreeService $categories;
    // Loads/overlays this product's (and its options'/values') per-language
    // translated text - see CLAUDE.md's Product/option translation section.
    private readonly TranslationOverlay $translations;
    // Supplies the list of tax rates and whether VAT/tax is enabled at all,
    // used for the price-entry (net vs gross) and tax-rate-dropdown logic.
    private readonly TaxCalculator $tax;
    // Used here just to read shop-wide settings like the default language.
    private readonly SettingsRepository $settings;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->categories = $container->make(CategoryTreeService::class);
        $this->translations = $container->make(TranslationOverlay::class);
        $this->tax = $container->make(TaxCalculator::class);
        $this->settings = $container->make(SettingsRepository::class);
    }

    /** Shows a blank "create new product" form. */
    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    /** Shows the "edit product" form, pre-filled with one existing product's current data. */
    public function edit(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        return $this->form($id);
    }

    /**
     * Builds every piece of data the create/edit form needs and renders
     * it. Shared by create()/edit() (id is null for create) and also by
     * save() when validation fails, in which case $errors and
     * $postedProduct let the form re-display exactly what the admin just
     * typed instead of reverting to the saved (or blank) values.
     */
    private function form(?int $id, array $errors = [], ?array $postedProduct = null): Response
    {
        $product = null;
        $options = [];

        if ($id) {
            $stmt = $this->pdo->prepare('SELECT * FROM products WHERE id = ?');
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            if (!$product) {
                $this->flash('error', __('admin.product_edit.not_found'));
                return $this->redirect('/admin/products');
            }
            $optStmt = $this->pdo->prepare('SELECT * FROM product_options WHERE product_id = ? ORDER BY sort_order');
            $optStmt->execute([$id]);
            $options = $optStmt->fetchAll();
            // Attach each option group's values under a 'values' key - by
            // reference (&$opt) so the assignment writes back into the $options
            // array itself instead of a throwaway copy of each row.
            foreach ($options as &$opt) {
                $valStmt = $this->pdo->prepare('SELECT * FROM product_option_values WHERE product_option_id = ? ORDER BY sort_order');
                $valStmt->execute([$opt['id']]);
                $opt['values'] = $valStmt->fetchAll();
            }
            // Breaks the reference after the loop - standard PHP hygiene after
            // a `foreach (... as &$opt)`: without this, $opt would keep
            // pointing at the last array element, and a later plain
            // `foreach (... as $opt)` reusing the same variable name could
            // silently overwrite that last element instead of iterating normally.
            unset($opt);
        }
        // A failed save() call re-invokes form() with what the admin actually
        // typed, so the re-rendered form shows their attempted edits instead of
        // silently reverting to the last-saved database values.
        if ($postedProduct) {
            $product = $postedProduct;
        }

        // Existing variant stock, keyed by a "value text per group, joined
        // with ||" combo signature (e.g. "M||Red") so the JS matrix can
        // carry stock forward across an edit even though every
        // product_option_values row (and therefore every id) gets
        // recreated on save - matching is done by what the admin actually
        // TYPED, not by id. Ported from product_edit.php:28-58.
        $variantStockByCombo = [];
        if ($id) {
            // Build a lookup from option-value id -> {which group it belongs
            // to, its raw text} so the variant loop below can translate each
            // variant's set of option_value_ids into the same "group => text"
            // shape the combo signature needs.
            $valueGroupAndText = [];
            foreach ($options as $groupIndex => $opt) {
                foreach ($opt['values'] as $val) {
                    $valueGroupAndText[$val['id']] = ['group' => $groupIndex, 'text' => $val['value']];
                }
            }
            $variantStmt = $this->pdo->prepare('SELECT id, stock_quantity FROM product_variants WHERE product_id = ?');
            $variantStmt->execute([$id]);
            foreach ($variantStmt->fetchAll() as $variant) {
                $vvStmt = $this->pdo->prepare('SELECT product_option_value_id FROM product_variant_values WHERE product_variant_id = ?');
                $vvStmt->execute([$variant['id']]);
                $comboParts = [];
                foreach ($vvStmt->fetchAll() as $vv) {
                    $info = $valueGroupAndText[$vv['product_option_value_id']] ?? null;
                    if ($info) {
                        // Keyed by group index (not appended in query order), so
                        // combos always assemble in a consistent group order
                        // below regardless of which order product_variant_values
                        // rows happen to come back in.
                        $comboParts[$info['group']] = $info['text'];
                    }
                }
                if ($comboParts) {
                    // ksort() puts the group-indexed parts back in group order
                    // (0, 1, 2, ...) before joining, so "M||Red" always means
                    // "group 0 = M, group 1 = Red" consistently, matching how
                    // the JS matrix builds the same signature client-side.
                    ksort($comboParts);
                    $variantStockByCombo[implode('||', $comboParts)] = (int)$variant['stock_quantity'];
                }
            }
        }

        $categories = $this->categories->flatten($this->categories->tree());
        $availableLangs = I18n::enabledLanguages();
        $defaultLang = $this->settings->get('default_language', 'en');
        // Every enabled language except the default one - the default
        // language's text lives directly on the product/option rows
        // themselves, so only the "other" languages need translation fields
        // in the form (see CLAUDE.md's Product/option translation section).
        $otherLanguages = array_diff(array_keys($availableLangs), [$defaultLang]);
        $productTranslations = $id ? $this->translations->translationsForProduct($id) : [];
        $optionTranslationsForForm = $this->translations->optionTranslationsForForm($options);
        $taxRates = $this->tax->allRates();
        $vatEnabled = $this->tax->vatEnabled();
        $pageTitle = $id ? __('admin.product_edit.edit_title') : __('admin.product_edit.add_title');

        return $this->render('products/edit', compact(
            'id', 'product', 'options', 'variantStockByCombo', 'categories', 'availableLangs', 'defaultLang',
            'otherLanguages', 'productTranslations', 'optionTranslationsForForm', 'taxRates', 'vatEnabled',
            'errors', 'pageTitle'
        ));
    }

    /**
     * Validates and persists the whole product form in one go: the
     * product row itself, its per-language translations, its option
     * groups/values (fully deleted and recreated - see class docblock),
     * and its variant stock matrix. Everything happens inside a single
     * database transaction so a mid-save failure can't leave the product
     * half-updated (e.g. new option groups saved but the variant matrix
     * missing).
     */
    public function save(Request $request): Response
    {
        // Blocks a forged product-save submission (CSRF) - this form can write
        // to half a dozen tables and upload no files itself, but still a
        // sensitive admin action - see Controller::requireCsrf().
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        // Present (even numeric 0) only when editing an existing product via
        // /admin/products/{id}/edit - absent entirely for the "create" route,
        // which is how the rest of this method tells create and edit apart.
        $id = $request->routeParam('id') !== null ? (int)$request->routeParam('id') : null;

        $availableLangs = I18n::enabledLanguages();
        $defaultLang = $this->settings->get('default_language', 'en');
        $otherLanguages = array_diff(array_keys($availableLangs), [$defaultLang]);

        $name = trim((string)$request->post('name', ''));
        $sku = trim((string)$request->post('sku', ''));
        $categoryId = $request->post('category_id', '') !== '' ? (int)$request->post('category_id') : null;
        $shortDescription = trim((string)$request->post('short_description', ''));
        $description = trim((string)$request->post('description', ''));
        $stockQuantity = (int)$request->post('stock_quantity', 0);
        $stockThreshold = (int)$request->post('stock_threshold', 5);
        // max(1, ...) - a max order quantity of 0 or negative would make the
        // product impossible to buy at all, so 1 is the effective floor;
        // null (not set) means "no per-order limit".
        $maxOrderQuantity = $request->post('max_order_quantity', '') !== '' ? max(1, (int)$request->post('max_order_quantity')) : null;
        $weight = $request->post('weight_kg', '') !== '' ? (float)$request->post('weight_kg') : null;
        // Whitelist check: unrecognized/tampered status values fall back to
        // 'draft' (the safest option - never publishes something unintended).
        $status = in_array($request->post('status', ''), ['active', 'draft', 'archived'], true) ? $request->post('status') : 'draft';

        // v2.00 - warranty/battery/hygiene fields. statutory_warranty_months
        // defaults to 24 (the EU statutory minimum) if not submitted;
        // manufacturer_warranty_months is optional (null = "manufacturer
        // offers no extra warranty beyond the statutory one"). contains_battery/
        // is_hygiene_product are simple checkboxes, coerced to 1/0 for storage.
        $statutoryWarrantyMonths = max(0, (int)$request->post('statutory_warranty_months', 24));
        $manufacturerWarrantyMonths = $request->post('manufacturer_warranty_months', '') !== '' ? max(0, (int)$request->post('manufacturer_warranty_months')) : null;
        $manufacturerWarrantyNotes = trim((string)$request->post('manufacturer_warranty_notes', '')) ?: null;
        $containsBattery = $request->post('contains_battery') ? 1 : 0;
        $isHygieneProduct = $request->post('is_hygiene_product') ? 1 : 0;

        // Price entry: admin can type either the net or the gross (tax-
        // included) amount - price is always STORED net, converted here
        // server-side (never trust a client-computed conversion).
        $priceEntryMode = $request->post('price_entry_mode') === 'gross' ? 'gross' : 'net';
        $enteredPrice = (float)$request->post('price_input', 0);
        // tax_rate_id is only honored at all if VAT/tax is enabled shop-wide -
        // otherwise it's forced to null regardless of what was submitted.
        $taxRateId = $this->tax->vatEnabled() && $request->post('tax_rate_id', '') !== '' ? (int)$request->post('tax_rate_id') : null;
        $taxRatePercent = 0.0;
        if ($taxRateId) {
            // Looks up the chosen tax rate's actual percentage from the
            // database (never trusts a client-submitted rate value) so the
            // gross-to-net conversion below uses the real, current rate.
            $rateStmt = $this->pdo->prepare('SELECT rate FROM tax_rates WHERE id = ?');
            $rateStmt->execute([$taxRateId]);
            $taxRatePercent = (float)($rateStmt->fetchColumn() ?: 0);
        }
        // Gross -> net conversion: if the admin typed a tax-included price,
        // divide out the tax rate to get the net price actually stored (e.g.
        // 119 gross at 19% tax -> 100 net). If entry mode is 'net', or there's
        // no tax rate, the entered amount is already the net price as-is.
        $price = ($priceEntryMode === 'gross' && $taxRatePercent > 0)
            ? round($enteredPrice / (1 + $taxRatePercent / 100), 2)
            : $enteredPrice;

        // Whitelist check: unrecognized discount_type falls back to 'none'.
        $discountType = in_array($request->post('discount_type', ''), ['none', 'percent', 'fixed'], true) ? $request->post('discount_type') : 'none';
        $discountValue = $request->post('discount_value', '') !== '' ? (float)$request->post('discount_value') : null;
        // Small helper closure to avoid repeating this conversion four times
        // below: an HTML datetime-local input posts "YYYY-MM-DDTHH:MM" (a
        // literal "T" separator, no seconds) - this swaps the "T" for a space
        // and appends ":00" seconds to get a MySQL-compatible DATETIME string,
        // or null if the field was left empty.
        $toDatetime = fn (string $key): ?string => $request->post($key, '') !== '' ? str_replace('T', ' ', $request->post($key)) . ':00' : null;
        $discountStartsAt = $toDatetime('discount_starts_at');
        $discountEndsAt = $toDatetime('discount_ends_at');
        $availableFrom = $toDatetime('available_from');
        $availableUntil = $toDatetime('available_until');

        $errors = [];
        if ($name === '') {
            $errors[] = __('admin.product_edit.name_required');
        }
        if ($sku === '') {
            $errors[] = __('admin.product_edit.sku_required');
        }
        if ($enteredPrice <= 0) {
            $errors[] = __('admin.product_edit.price_required');
        }
        if ($discountType !== 'none' && !$discountValue) {
            $errors[] = __('admin.product_edit.discount_value_required');
        }
        if ($discountStartsAt && $discountEndsAt && $discountStartsAt > $discountEndsAt) {
            $errors[] = __('admin.product_edit.discount_date_order');
        }
        if ($availableFrom && $availableUntil && $availableFrom > $availableUntil) {
            $errors[] = __('admin.product_edit.availability_date_order');
        }
        if ($discountType === 'none') {
            // Belt-and-suspenders: even if the admin left stray values in the
            // discount value/date fields while discount_type was set to
            // 'none', explicitly null them out here so a "no discount" product
            // never ends up with half a leftover discount configuration saved.
            $discountValue = null;
            $discountStartsAt = null;
            $discountEndsAt = null;
        }

        // Slug is always derived from the name server-side (see
        // Support\Slugger::slug()) rather than admin-editable - keeps every
        // product's URL in sync with its name and guarantees it's URL-safe.
        $slug = Slugger::slug($name);

        if (!$errors) {
            // Everything below - the product row, its translations, its option
            // groups/values, and its variant matrix - is wrapped in one
            // transaction: if anything throws partway through (e.g. a
            // duplicate SKU on the UPDATE/INSERT, or any other DB error), the
            // catch block below rolls back ALL of it, so a save can never leave
            // the product half-updated (e.g. new options saved but stale
            // variants left pointing at deleted option values).
            $this->pdo->beginTransaction();
            try {
                if ($id) {
                    // Full column-by-column UPDATE of the existing product row -
                    // every field is rewritten each save (not just changed ones),
                    // which is simple and safe since the whole form always
                    // submits the complete set of fields anyway.
                    $stmt = $this->pdo->prepare(
                        'UPDATE products SET category_id=?, sku=?, name=?, slug=?, short_description=?, description=?,
                         price=?, price_entry_mode=?, tax_rate_id=?, discount_type=?, discount_value=?, discount_starts_at=?, discount_ends_at=?,
                         available_from=?, available_until=?, stock_quantity=?, stock_threshold=?, max_order_quantity=?, weight_kg=?, status=?,
                         statutory_warranty_months=?, manufacturer_warranty_months=?, manufacturer_warranty_notes=?, contains_battery=?, is_hygiene_product=?
                         WHERE id=?'
                    );
                    $stmt->execute([
                        $categoryId, $sku, $name, $slug, $shortDescription, $description,
                        $price, $priceEntryMode, $taxRateId, $discountType, $discountValue, $discountStartsAt, $discountEndsAt,
                        $availableFrom, $availableUntil, $stockQuantity, $stockThreshold, $maxOrderQuantity, $weight, $status,
                        $statutoryWarrantyMonths, $manufacturerWarrantyMonths, $manufacturerWarrantyNotes, $containsBattery, $isHygieneProduct, $id,
                    ]);
                    $productId = $id;
                    // Reset options - simplest consistent approach for a basic
                    // admin UI (see class docblock and CLAUDE.md's Architecture
                    // section on the delete-and-recreate pattern). Deleting the
                    // product_options rows is expected to cascade-delete their
                    // child product_option_values rows too (a foreign key with
                    // ON DELETE CASCADE in sql/schema.sql) - this statement only
                    // needs to target the parent table.
                    $this->pdo->prepare('DELETE FROM product_options WHERE product_id = ?')->execute([$productId]);
                    // product_variants isn't cascade-deleted by the above
                    // (it's tied to products, not product_options) - clear
                    // it explicitly so it's rebuilt from scratch below.
                    $this->pdo->prepare('DELETE FROM product_variants WHERE product_id = ?')->execute([$productId]);
                } else {
                    $stmt = $this->pdo->prepare(
                        'INSERT INTO products (category_id, sku, name, slug, short_description, description, price,
                         price_entry_mode, tax_rate_id, discount_type, discount_value, discount_starts_at, discount_ends_at, available_from, available_until,
                         stock_quantity, stock_threshold, max_order_quantity, weight_kg, status,
                         statutory_warranty_months, manufacturer_warranty_months, manufacturer_warranty_notes, contains_battery, is_hygiene_product)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
                    );
                    $stmt->execute([
                        $categoryId, $sku, $name, $slug, $shortDescription, $description, $price,
                        $priceEntryMode, $taxRateId, $discountType, $discountValue, $discountStartsAt, $discountEndsAt, $availableFrom, $availableUntil,
                        $stockQuantity, $stockThreshold, $maxOrderQuantity, $weight, $status,
                        $statutoryWarrantyMonths, $manufacturerWarrantyMonths, $manufacturerWarrantyNotes, $containsBattery, $isHygieneProduct,
                    ]);
                    // lastInsertId() gets the auto-increment id MySQL just
                    // assigned to the new row - needed below since every
                    // following insert (translations, options, variants) has to
                    // reference this product by id, and a brand-new product has
                    // no id until right now.
                    $productId = (int)$this->pdo->lastInsertId();
                }

                // Translations for name/short_description/description - one
                // upsert (delete-then-insert, same transaction) per non-
                // default language, skipped entirely if the admin left all
                // three of that language's fields blank. Deleting first (rather
                // than an ON DUPLICATE KEY UPDATE) means "admin cleared out a
                // translation" naturally results in no row for that language,
                // instead of a leftover row with empty strings.
                foreach ($otherLanguages as $langCode) {
                    $tName = trim((string)($request->post('name_translations')[$langCode] ?? ''));
                    $tShort = trim((string)($request->post('short_description_translations')[$langCode] ?? ''));
                    $tDesc = trim((string)($request->post('description_translations')[$langCode] ?? ''));
                    $this->pdo->prepare('DELETE FROM product_translations WHERE product_id = ? AND language = ?')->execute([$productId, $langCode]);
                    if ($tName !== '' || $tShort !== '' || $tDesc !== '') {
                        $this->pdo->prepare(
                            'INSERT INTO product_translations (product_id, language, name, short_description, description) VALUES (?, ?, ?, ?, ?)'
                        )->execute([$productId, $langCode, $tName !== '' ? $tName : null, $tShort !== '' ? $tShort : null, $tDesc !== '' ? $tDesc : null]);
                    }
                }

                // Option groups: option_name[] / option_values[] (comma-
                // separated). $groupIndex is a fresh sequential counter over
                // only the non-empty groups (skipping a blank row entirely) -
                // this has to match how the variant-matrix JS numbers groups
                // (also sequential over non-empty rows only), since
                // variant_combo[] below is keyed by that same group order,
                // not by the original option_name[]/option_values[] array index.
                $optionNames = (array)$request->post('option_name', []);
                $optionValues = (array)$request->post('option_values', []);
                $nameTranslationsPosted = (array)$request->post('option_name_translations', []);
                $valueTranslationsPosted = (array)$request->post('option_values_translations', []);
                $newValueIdByGroupText = [];
                // Same value ids as $newValueIdByGroupText, but keyed by a
                // contiguous 0-based position within the group instead of by
                // text - what a translated values list is aligned against,
                // since it obviously can't be matched by the (untranslated)
                // base text.
                $newValueIdByGroupPosition = [];
                $groupIndex = 0;
                foreach ($optionNames as $i => $optName) {
                    $optName = trim($optName);
                    $valuesRaw = trim($optionValues[$i] ?? '');
                    if ($optName === '' || $valuesRaw === '') {
                        continue;
                    }

                    $optStmt = $this->pdo->prepare('INSERT INTO product_options (product_id, name, sort_order) VALUES (?, ?, ?)');
                    $optStmt->execute([$productId, $optName, $groupIndex]);
                    $optionId = (int)$this->pdo->lastInsertId();

                    // Splits the comma-separated value list (e.g. "S, M, L")
                    // into individual product_option_values rows. The
                    // stock_quantity column written here is legacy/unused once
                    // a product has variants (see sql/schema.sql's comment on
                    // product_option_values) - the real per-combination stock
                    // lives on product_variants below; this is just filled with
                    // the product-level $stockQuantity as a harmless placeholder.
                    $valStmt = $this->pdo->prepare('INSERT INTO product_option_values (product_option_id, value, stock_quantity, sort_order) VALUES (?, ?, ?, ?)');
                    $position = 0;
                    foreach (explode(',', $valuesRaw) as $j => $val) {
                        $val = trim($val);
                        if ($val === '') {
                            continue;
                        }
                        $valStmt->execute([$optionId, $val, $stockQuantity, $j]);
                        $valueId = (int)$this->pdo->lastInsertId();
                        // Records this brand-new value's id both by its text (for
                        // the variant-combo matching below, which works off what
                        // the admin typed) and by its position within the group
                        // (for matching translated values, which can't be looked
                        // up by the - untranslated - base text).
                        $newValueIdByGroupText[$groupIndex][$val] = $valueId;
                        $newValueIdByGroupPosition[$groupIndex][$position] = $valueId;
                        $position++;
                    }

                    // Translations for this option group's name and values,
                    // keyed by the raw form row index $i (NOT $groupIndex -
                    // a blank row earlier in the form would otherwise
                    // misalign them). Values are matched by position
                    // against $newValueIdByGroupPosition, same comma-
                    // separated convention as the base field.
                    foreach ($otherLanguages as $langCode) {
                        $translatedName = trim((string)($nameTranslationsPosted[$langCode][$i] ?? ''));
                        if ($translatedName !== '') {
                            $this->pdo->prepare('INSERT INTO product_option_translations (product_option_id, language, name) VALUES (?, ?, ?)')
                                ->execute([$optionId, $langCode, $translatedName]);
                        }
                        $translatedValuesRaw = trim((string)($valueTranslationsPosted[$langCode][$i] ?? ''));
                        if ($translatedValuesRaw !== '') {
                            foreach (explode(',', $translatedValuesRaw) as $valuePosition => $tVal) {
                                $tVal = trim($tVal);
                                if ($tVal === '' || !isset($newValueIdByGroupPosition[$groupIndex][$valuePosition])) {
                                    continue;
                                }
                                $this->pdo->prepare('INSERT INTO product_option_value_translations (product_option_value_id, language, value) VALUES (?, ?, ?)')
                                    ->execute([$newValueIdByGroupPosition[$groupIndex][$valuePosition], $langCode, $tVal]);
                            }
                        }
                    }

                    $groupIndex++;
                }

                // Variant matrix (one row per option combination, built
                // client-side and posted as parallel variant_combo[]/
                // variant_stock[] arrays) - only meaningful once at least
                // one option group was actually saved above.
                if ($newValueIdByGroupText) {
                    $variantStmt = $this->pdo->prepare('INSERT INTO product_variants (product_id, stock_quantity, sort_order) VALUES (?, ?, ?)');
                    $variantValueStmt = $this->pdo->prepare('INSERT INTO product_variant_values (product_variant_id, product_option_value_id) VALUES (?, ?)');
                    // variant_combo[$vi] is a "||"-joined combo signature exactly
                    // like $variantStockByCombo's keys in form() (e.g. "M||Red"),
                    // and variant_stock[$vi] is that combination's stock count -
                    // the two arrays are parallel, matched by the same $vi index.
                    $variantCombos = (array)$request->post('variant_combo', []);
                    $variantStocks = (array)$request->post('variant_stock', []);
                    $expectedGroups = count($newValueIdByGroupText);
                    foreach ($variantCombos as $vi => $combo) {
                        $parts = explode('||', (string)$combo);
                        $valueIds = [];
                        foreach ($parts as $gIdx => $text) {
                            $text = trim($text);
                            // Resolve each combo part's raw text back to the
                            // freshly-inserted option value id for that group
                            // (via $newValueIdByGroupText built above) - if the
                            // text doesn't match any current value in that group,
                            // the whole combo is unresolvable and abandoned.
                            if ($text === '' || !isset($newValueIdByGroupText[$gIdx][$text])) {
                                $valueIds = null;
                                break;
                            }
                            $valueIds[] = $newValueIdByGroupText[$gIdx][$text];
                        }
                        // Skip a row that doesn't resolve to exactly one
                        // value per saved group (stale combo referencing a
                        // renamed/removed option value).
                        if ($valueIds === null || count($valueIds) !== $expectedGroups) {
                            continue;
                        }
                        // Stock can never go negative even if a tampered/buggy
                        // client posted a negative number.
                        $stock = max(0, (int)($variantStocks[$vi] ?? 0));
                        $variantStmt->execute([$productId, $stock, $vi]);
                        $newVariantId = (int)$this->pdo->lastInsertId();
                        // One product_variant_values row per option group this
                        // variant belongs to (see sql/schema.sql's comment on
                        // that join table) - ties the new variant to every value
                        // in its combination.
                        foreach ($valueIds as $valueId) {
                            $variantValueStmt->execute([$newVariantId, $valueId]);
                        }
                    }
                }

                // Everything above succeeded - make it all permanent at once.
                $this->pdo->commit();
                $this->flash('success', __('admin.product_edit.flash_saved'));
                return $this->redirect('/admin/products/' . $productId . '/edit');
            } catch (\Throwable $e) {
                // Anything thrown anywhere in the try block (a DB constraint
                // violation, a logic error, etc.) undoes every change made
                // since beginTransaction() - the product keeps whatever state
                // it had before this save attempt.
                $this->pdo->rollBack();
                $errors[] = __('admin.product_edit.save_error', ['message' => $e->getMessage()]);
            }
        }

        // Either validation failed before the transaction even started, or the
        // save threw and was rolled back above - either way, re-render the form
        // with what the admin submitted (see form()'s $postedProduct parameter)
        // plus the error list, instead of losing their edits.
        $postedProduct = [
            'id' => $id, 'category_id' => $categoryId, 'sku' => $sku, 'name' => $name, 'short_description' => $shortDescription,
            'description' => $description, 'price' => $enteredPrice, 'price_entry_mode' => $priceEntryMode, 'tax_rate_id' => $taxRateId,
            'discount_type' => $discountType, 'discount_value' => $discountValue, 'discount_starts_at' => $discountStartsAt, 'discount_ends_at' => $discountEndsAt,
            'available_from' => $availableFrom, 'available_until' => $availableUntil, 'stock_quantity' => $stockQuantity,
            'stock_threshold' => $stockThreshold, 'max_order_quantity' => $maxOrderQuantity, 'weight_kg' => $weight, 'status' => $status,
            'statutory_warranty_months' => $statutoryWarrantyMonths, 'manufacturer_warranty_months' => $manufacturerWarrantyMonths,
            'manufacturer_warranty_notes' => $manufacturerWarrantyNotes, 'contains_battery' => $containsBattery, 'is_hygiene_product' => $isHygieneProduct,
        ];
        return $this->form($id, $errors, $postedProduct);
    }
}
