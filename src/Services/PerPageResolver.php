<?php

namespace ShopRex\Services;

/**
 * Direct port of getPerPage()/getPerPageInt() from includes/functions.php -
 * a ?per_page= override is applied to the session so the choice persists
 * across the visit without repeating it in every link.
 */
final class PerPageResolver
{
    public const OPTIONS = ['20', '50', '200', 'all'];

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function current(): string
    {
        if (isset($_GET['per_page']) && in_array($_GET['per_page'], self::OPTIONS, true)) {
            $_SESSION['per_page'] = $_GET['per_page'];
        }
        $value = $_SESSION['per_page'] ?? $this->settings->get('items_per_page_default', '20');
        return in_array($value, self::OPTIONS, true) ? $value : '20';
    }

    /** Same as current() but as an int, or null for "all" (no LIMIT). */
    public function currentInt(): ?int
    {
        $value = $this->current();
        return $value === 'all' ? null : (int)$value;
    }
}
