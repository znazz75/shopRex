<?php

namespace ShopRex\Support;

/** Direct port of slugify() from includes/functions.php - shared by every admin controller that derives a slug from a name. */
final class Slugger
{
    public static function slug(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = trim(iconv('UTF-8', 'ASCII//TRANSLIT', $text) ?: $text, '-');
        $text = strtolower((string)preg_replace('~[^-\w]+~', '', $text));
        return $text !== '' ? $text : 'n-a';
    }
}
