<?php

namespace ShopRex\Services;

/**
 * Direct port of the product/option translation overlay functions in
 * includes/functions.php (applyProductTranslation/applyOptionTranslations/
 * getProductTranslationsByLanguage/getOptionTranslationsForForm). See
 * sql/schema.sql's docs on product_translations/product_option_translations/
 * product_option_value_translations for why this is a separate mechanism
 * from the UI-chrome i18n (Services\I18n) - a product's own row keeps
 * holding the site's default-language content; these tables only ever
 * hold OTHER languages, one row per (entity, language).
 */
final class TranslationOverlay
{
    public function __construct(private readonly \PDO $pdo, private readonly SettingsRepository $settings)
    {
    }

    /** Overlays $lang onto name/short_description/description, per field, falling back to the base value. */
    public function applyToProduct(array $product, ?string $lang = null): array
    {
        $lang = $lang ?? I18n::current();
        // Nothing to overlay for a not-yet-saved product, or when the
        // requested language IS the default language - the product's own
        // row already holds the default-language content (see class docblock).
        if (empty($product['id']) || $lang === $this->settings->get('default_language', 'en')) {
            return $product;
        }

        $stmt = $this->pdo->prepare(
            'SELECT name, short_description, description FROM product_translations WHERE product_id = ? AND language = ?'
        );
        $stmt->execute([$product['id'], $lang]);
        $translation = $stmt->fetch();
        if ($translation) {
            // Per-field fallback: only overwrite a field if the translation
            // row actually has non-empty text for it, so a partially
            // translated product still shows the original text for
            // whichever fields were left blank in that language.
            foreach (['name', 'short_description', 'description'] as $field) {
                if (!empty($translation[$field])) {
                    $product[$field] = $translation[$field];
                }
            }
        }
        return $product;
    }

    /** Same idea for an option-group tree (each row's values[] sub-array already attached). */
    public function applyToOptions(array $options, ?string $lang = null): array
    {
        $lang = $lang ?? I18n::current();
        if (!$options || $lang === $this->settings->get('default_language', 'en')) {
            return $options;
        }

        // Pulls out just the option-group IDs to look up their translated
        // names in one query, instead of one query per group.
        $optionIds = array_column($options, 'id');
        // Builds a "?,?,?,..." placeholder list matching the number of IDs,
        // since PDO can't bind an array directly into an IN (...) clause.
        $placeholders = implode(',', array_fill(0, count($optionIds), '?'));
        $nameStmt = $this->pdo->prepare(
            "SELECT product_option_id, name FROM product_option_translations WHERE language = ? AND product_option_id IN ($placeholders)"
        );
        $nameStmt->execute([$lang, ...$optionIds]);
        // array_column(..., 'name', 'product_option_id') turns the result
        // rows straight into a [product_option_id => name] lookup map.
        $namesByOptionId = array_column($nameStmt->fetchAll(), 'name', 'product_option_id');

        // Same idea one level down: collect every option VALUE id across
        // every group so their translations can also be fetched in one query.
        $valueIds = [];
        foreach ($options as $opt) {
            foreach ($opt['values'] as $val) {
                $valueIds[] = $val['id'];
            }
        }
        $valuesByValueId = [];
        if ($valueIds) {
            $vPlaceholders = implode(',', array_fill(0, count($valueIds), '?'));
            $valStmt = $this->pdo->prepare(
                "SELECT product_option_value_id, value FROM product_option_value_translations WHERE language = ? AND product_option_value_id IN ($vPlaceholders)"
            );
            $valStmt->execute([$lang, ...$valueIds]);
            $valuesByValueId = array_column($valStmt->fetchAll(), 'value', 'product_option_value_id');
        }

        // Walk the tree by reference (&$opt, &$val) so the translated text
        // is written straight into the array being returned, rather than
        // building a separate copy.
        foreach ($options as &$opt) {
            if (!empty($namesByOptionId[$opt['id']])) {
                $opt['name'] = $namesByOptionId[$opt['id']];
            }
            foreach ($opt['values'] as &$val) {
                if (!empty($valuesByValueId[$val['id']])) {
                    $val['value'] = $valuesByValueId[$val['id']];
                }
            }
            unset($val);
        }
        unset($opt);

        return $options;
    }

    /** Every language's product_translations row for $productId, keyed by language - for the admin edit form. */
    public function translationsForProduct(int $productId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT language, name, short_description, description FROM product_translations WHERE product_id = ?'
        );
        $stmt->execute([$productId]);
        $byLang = [];
        foreach ($stmt->fetchAll() as $row) {
            $byLang[$row['language']] = $row;
        }
        return $byLang;
    }

    /**
     * Option-group-name and option-value translations for every group in
     * $options, pre-joined and keyed by [$groupIndex][$language]. Keyed
     * positionally (by index, not by option/value id) because
     * Controllers\Admin\ProductEditController deletes and recreates every
     * product_options/product_option_values row on every save - the ids
     * are never stable across saves, but the *position* in $options (Size
     * is always group 0, Color always group 1, etc., for a given product)
     * is, so that's what the edit form's translation tabs key against.
     */
    public function optionTranslationsForForm(array $options): array
    {
        $result = ['names' => [], 'values' => []];
        if (!$options) {
            return $result;
        }

        // Fetch every language's translated group name for every option
        // group in one query (not per-group, not per-language).
        $optionIds = array_column($options, 'id');
        $placeholders = implode(',', array_fill(0, count($optionIds), '?'));
        $nameStmt = $this->pdo->prepare(
            "SELECT product_option_id, language, name FROM product_option_translations WHERE product_option_id IN ($placeholders)"
        );
        $nameStmt->execute($optionIds);
        $namesByOptionId = [];
        foreach ($nameStmt->fetchAll() as $row) {
            $namesByOptionId[$row['product_option_id']][$row['language']] = $row['name'];
        }

        // Same for every option VALUE's translated text, across every group.
        $valueIds = [];
        foreach ($options as $opt) {
            foreach ($opt['values'] as $val) {
                $valueIds[] = $val['id'];
            }
        }
        $valuesByValueId = [];
        if ($valueIds) {
            $vPlaceholders = implode(',', array_fill(0, count($valueIds), '?'));
            $valStmt = $this->pdo->prepare(
                "SELECT product_option_value_id, language, value FROM product_option_value_translations WHERE product_option_value_id IN ($vPlaceholders)"
            );
            $valStmt->execute($valueIds);
            foreach ($valStmt->fetchAll() as $row) {
                $valuesByValueId[$row['product_option_value_id']][$row['language']] = $row['value'];
            }
        }

        // The admin edit form shows each option group's values as one
        // comma-separated text field per language (e.g. "Small, Medium,
        // Large"), matching how the default-language values are entered.
        // So each language's translated values need to be joined back into
        // one comma-separated string, IN THE SAME ORDER as the
        // default-language values list - that's what the positional
        // alignment below (indexing by $valueIndex instead of by ID) is for.
        foreach (array_values($options) as $groupIndex => $opt) {
            $result['names'][$groupIndex] = $namesByOptionId[$opt['id']] ?? [];

            $valueCount = count($opt['values']);
            // Re-key each language's translated values by their POSITION
            // within this group (0, 1, 2, ...) rather than by value ID, so
            // they can be joined back together in the original left-to-right
            // order the admin typed the default-language values in.
            $perLangPositional = [];
            foreach (array_values($opt['values']) as $valueIndex => $val) {
                foreach (($valuesByValueId[$val['id']] ?? []) as $lang => $text) {
                    $perLangPositional[$lang][$valueIndex] = $text;
                }
            }
            foreach ($perLangPositional as $lang => $positional) {
                $joined = [];
                // Walks every position 0..valueCount-1 rather than just the
                // ones present in $positional, so a value with no
                // translation yet still gets an empty placeholder slot
                // instead of shifting the later values out of alignment.
                for ($k = 0; $k < $valueCount; $k++) {
                    $joined[] = $positional[$k] ?? '';
                }
                $result['values'][$groupIndex][$lang] = implode(', ', $joined);
            }
        }

        return $result;
    }
}
