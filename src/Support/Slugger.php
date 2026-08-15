<?php

namespace ShopRex\Support;

/**
 * Direct port of slugify() from includes/functions.php - shared by every
 * admin controller that derives a slug from a name.
 *
 * In plain terms: a "slug" is the URL-safe version of a name, like turning
 * "Classic T-Shirt (Blue)!" into "classic-t-shirt-blue" - lowercase,
 * hyphen-separated, no punctuation or accented characters. Used for
 * building clean product/category/page URLs (e.g. /product/classic-t-shirt)
 * instead of exposing raw numeric database IDs in links.
 */
final class Slugger
{
    /**
     * Converts arbitrary text (typically a product/category/page name) into
     * a URL-safe slug: strips accents/diacritics, replaces anything that
     * isn't a letter/digit with a hyphen, lowercases everything, and falls
     * back to "n-a" if the result would otherwise be empty (e.g. the input
     * was only punctuation/emoji) so callers never end up with a blank slug.
     */
    public static function slug(string $text): string
    {
        // Replace any run of characters that isn't a Unicode letter (\pL) or
        // digit with a single hyphen - the /u flag makes this Unicode-aware
        // so accented letters (é, ü, ...) are treated as letters here and
        // only transliterated in the next step, not stripped outright.
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        // Transliterate accented/non-ASCII letters to their closest plain
        // ASCII equivalent (e.g. "café" -> "cafe") - iconv() can fail on
        // some inputs, in which case the `?: $text` fallback keeps the
        // untransliterated string rather than losing it entirely. trim()
        // removes any leading/trailing hyphen left over from step one.
        $text = trim(iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text, '-');
        // Final cleanup pass: drop anything that still isn't a plain word
        // character or hyphen (iconv's transliteration can occasionally
        // leave stray punctuation), then lowercase the result.
        $text = strtolower((string)preg_replace('~[^-\w]+~', '', $text));
        // Guard against an entirely-empty slug (e.g. input was just emoji or
        // symbols) - "n-a" ("not applicable") is a harmless, still-unique-
        // enough placeholder rather than an empty URL segment.
        return $text !== '' ? $text : 'n-a';
    }
}
