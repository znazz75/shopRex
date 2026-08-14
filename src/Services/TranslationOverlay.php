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
        if (empty($product['id']) || $lang === $this->settings->get('default_language', 'en')) {
            return $product;
        }

        $stmt = $this->pdo->prepare(
            'SELECT name, short_description, description FROM product_translations WHERE product_id = ? AND language = ?'
        );
        $stmt->execute([$product['id'], $lang]);
        $translation = $stmt->fetch();
        if ($translation) {
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

        $optionIds = array_column($options, 'id');
        $placeholders = implode(',', array_fill(0, count($optionIds), '?'));
        $nameStmt = $this->pdo->prepare(
            "SELECT product_option_id, name FROM product_option_translations WHERE language = ? AND product_option_id IN ($placeholders)"
        );
        $nameStmt->execute([$lang, ...$optionIds]);
        $namesByOptionId = array_column($nameStmt->fetchAll(), 'name', 'product_option_id');

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
     * $options, pre-joined and keyed by [$groupIndex][$language] - see
     * includes/functions.php's getOptionTranslationsForForm() docblock for
     * the full positional-alignment rationale this preserves verbatim.
     */
    public function optionTranslationsForForm(array $options): array
    {
        $result = ['names' => [], 'values' => []];
        if (!$options) {
            return $result;
        }

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

        foreach (array_values($options) as $groupIndex => $opt) {
            $result['names'][$groupIndex] = $namesByOptionId[$opt['id']] ?? [];

            $valueCount = count($opt['values']);
            $perLangPositional = [];
            foreach (array_values($opt['values']) as $valueIndex => $val) {
                foreach (($valuesByValueId[$val['id']] ?? []) as $lang => $text) {
                    $perLangPositional[$lang][$valueIndex] = $text;
                }
            }
            foreach ($perLangPositional as $lang => $positional) {
                $joined = [];
                for ($k = 0; $k < $valueCount; $k++) {
                    $joined[] = $positional[$k] ?? '';
                }
                $result['values'][$groupIndex][$lang] = implode(', ', $joined);
            }
        }

        return $result;
    }
}
