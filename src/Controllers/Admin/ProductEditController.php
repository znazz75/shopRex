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
 * Direct, line-cited port of admin/product_edit.php - the largest and
 * most complex file in the app (delete-and-recreate options/variants
 * every save, combo-signature stock carry-forward, positional translation
 * matching). See the architecture plan's risk list: ported method-for-
 * method against the original's exact line ranges rather than rewritten
 * from memory.
 */
final class ProductEditController extends AdminCrudController
{
    private readonly \PDO $pdo;
    private readonly CategoryTreeService $categories;
    private readonly TranslationOverlay $translations;
    private readonly TaxCalculator $tax;
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

    public function create(Request $request): Response
    {
        return $this->form(null);
    }

    public function edit(Request $request): Response
    {
        $id = (int)$request->routeParam('id', 0);
        return $this->form($id);
    }

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
            foreach ($options as &$opt) {
                $valStmt = $this->pdo->prepare('SELECT * FROM product_option_values WHERE product_option_id = ? ORDER BY sort_order');
                $valStmt->execute([$opt['id']]);
                $opt['values'] = $valStmt->fetchAll();
            }
            unset($opt);
        }
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
                        $comboParts[$info['group']] = $info['text'];
                    }
                }
                if ($comboParts) {
                    ksort($comboParts);
                    $variantStockByCombo[implode('||', $comboParts)] = (int)$variant['stock_quantity'];
                }
            }
        }

        $categories = $this->categories->flatten($this->categories->tree());
        $availableLangs = I18n::enabledLanguages();
        $defaultLang = $this->settings->get('default_language', 'en');
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

    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
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
        $maxOrderQuantity = $request->post('max_order_quantity', '') !== '' ? max(1, (int)$request->post('max_order_quantity')) : null;
        $weight = $request->post('weight_kg', '') !== '' ? (float)$request->post('weight_kg') : null;
        $status = in_array($request->post('status', ''), ['active', 'draft', 'archived'], true) ? $request->post('status') : 'draft';

        // v2.00 - warranty/battery/hygiene fields.
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
        $taxRateId = $this->tax->vatEnabled() && $request->post('tax_rate_id', '') !== '' ? (int)$request->post('tax_rate_id') : null;
        $taxRatePercent = 0.0;
        if ($taxRateId) {
            $rateStmt = $this->pdo->prepare('SELECT rate FROM tax_rates WHERE id = ?');
            $rateStmt->execute([$taxRateId]);
            $taxRatePercent = (float)($rateStmt->fetchColumn() ?: 0);
        }
        $price = ($priceEntryMode === 'gross' && $taxRatePercent > 0)
            ? round($enteredPrice / (1 + $taxRatePercent / 100), 2)
            : $enteredPrice;

        $discountType = in_array($request->post('discount_type', ''), ['none', 'percent', 'fixed'], true) ? $request->post('discount_type') : 'none';
        $discountValue = $request->post('discount_value', '') !== '' ? (float)$request->post('discount_value') : null;
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
            $discountValue = null;
            $discountStartsAt = null;
            $discountEndsAt = null;
        }

        $slug = Slugger::slug($name);

        if (!$errors) {
            $this->pdo->beginTransaction();
            try {
                if ($id) {
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
                    // Reset options - simplest consistent approach for a basic admin UI.
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
                    $productId = (int)$this->pdo->lastInsertId();
                }

                // Translations for name/short_description/description - one
                // upsert (delete-then-insert, same transaction) per non-
                // default language, skipped entirely if the admin left all
                // three of that language's fields blank.
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

                    $valStmt = $this->pdo->prepare('INSERT INTO product_option_values (product_option_id, value, stock_quantity, sort_order) VALUES (?, ?, ?, ?)');
                    $position = 0;
                    foreach (explode(',', $valuesRaw) as $j => $val) {
                        $val = trim($val);
                        if ($val === '') {
                            continue;
                        }
                        $valStmt->execute([$optionId, $val, $stockQuantity, $j]);
                        $valueId = (int)$this->pdo->lastInsertId();
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
                    $variantCombos = (array)$request->post('variant_combo', []);
                    $variantStocks = (array)$request->post('variant_stock', []);
                    $expectedGroups = count($newValueIdByGroupText);
                    foreach ($variantCombos as $vi => $combo) {
                        $parts = explode('||', (string)$combo);
                        $valueIds = [];
                        foreach ($parts as $gIdx => $text) {
                            $text = trim($text);
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
                        $stock = max(0, (int)($variantStocks[$vi] ?? 0));
                        $variantStmt->execute([$productId, $stock, $vi]);
                        $newVariantId = (int)$this->pdo->lastInsertId();
                        foreach ($valueIds as $valueId) {
                            $variantValueStmt->execute([$newVariantId, $valueId]);
                        }
                    }
                }

                $this->pdo->commit();
                $this->flash('success', __('admin.product_edit.flash_saved'));
                return $this->redirect('/admin/products/' . $productId . '/edit');
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                $errors[] = __('admin.product_edit.save_error', ['message' => $e->getMessage()]);
            }
        }

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
