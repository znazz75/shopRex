<?php

namespace ShopRex\Services;

/**
 * Direct port of getPerPage()/getPerPageInt() from includes/functions.php -
 * a ?per_page= override is applied to the session so the choice persists
 * across the visit without repeating it in every link.
 */
final class PerPageResolver
{
    // Every value a "results per page" picker is allowed to use; 'all' means
    // no LIMIT/pagination at all. Kept as strings since it's compared directly
    // against raw $_GET/$_SESSION values below.
    public const OPTIONS = ['20', '50', '200', 'all'];

    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    /** Works out the current "items per page" choice for this visitor and remembers it in their session for next time. */
    public function current(): string
    {
        // A ?per_page= link (e.g. from a list page's page-size picker) updates
        // the session so the choice sticks across subsequent pages without
        // needing to repeat the query string on every link.
        if (isset($_GET['per_page']) && in_array($_GET['per_page'], self::OPTIONS, true)) {
            $_SESSION['per_page'] = $_GET['per_page'];
        }
        // Fallback chain: session choice, else the site-wide admin default,
        // else '20' hardcoded as a last resort.
        $value = $_SESSION['per_page'] ?? $this->settings->get('items_per_page_default', '20');
        // Guards against a stale/tampered session value that's no longer one
        // of the allowed OPTIONS (e.g. an admin changed the allowed list).
        return in_array($value, self::OPTIONS, true) ? $value : '20';
    }

    /** Same as current() but as an int, or null for "all" (no LIMIT). */
    public function currentInt(): ?int
    {
        $value = $this->current();
        return $value === 'all' ? null : (int)$value;
    }
}
