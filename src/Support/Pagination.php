<?php

namespace ShopRex\Support;

/**
 * Direct port of renderPagination() from includes/functions.php. Presentation-
 * only (echoes Bootstrap pagination markup directly, same as the original)
 * so it lives under Support rather than Services.
 *
 * In plain terms: this class draws the "« 1 2 3 ... 10 »" page-number
 * links you see at the bottom of a product listing or order list. It's
 * "presentation-only" because it just echoes HTML straight to the browser
 * (like a view template would) rather than returning data for something
 * else to use - that's why it belongs with the other Support classes
 * (StorefrontMenuRenderer, MenuAdminTreeRenderer) instead of Services,
 * which is reserved for classes with real business logic.
 */
final class Pagination
{
    /**
     * Echoes a full Bootstrap pagination control for a list currently on
     * $currentPage out of $totalPages, reusing whatever query-string
     * parameters ($queryParams, e.g. a search term or filter) the current
     * listing page was already using so page links don't lose them.
     */
    public static function render(int $currentPage, int $totalPages, array $queryParams): void
    {
        // Nothing to paginate - a single page (or none) needs no controls at all.
        if ($totalPages <= 1) {
            return;
        }
        // Small local helper that builds one page's link URL by merging the
        // requested $page number into whatever other query params
        // (search/filter/sort) the caller already had - so switching pages
        // never loses the user's current filters.
        $link = function (int $page) use ($queryParams): string {
            return '?' . http_build_query(array_merge($queryParams, ['page' => $page]));
        };
        echo '<nav aria-label="Pagination"><ul class="pagination justify-content-center">';
        // "Previous" arrow - disabled (still rendered, but not a real link
        // visually) when already on page 1; max(1, ...) keeps the target
        // page number from ever going below 1 even though the link is
        // disabled anyway.
        echo '<li class="page-item' . ($currentPage <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . e($link(max(1, $currentPage - 1))) . '">&laquo;</a></li>';

        // Only show a window of up to 5 page numbers centered on the
        // current page (2 before, 2 after), rather than every page number -
        // important when there are e.g. 200 pages of products.
        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);
        if ($start > 1) {
            // The window doesn't reach page 1 - show it explicitly so users
            // can always jump straight back to the start.
            echo '<li class="page-item"><a class="page-link" href="' . e($link(1)) . '">1</a></li>';
            if ($start > 2) {
                // There's a gap between page 1 and the window - show an
                // ellipsis to indicate skipped pages.
                echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
        }
        for ($p = $start; $p <= $end; $p++) {
            echo '<li class="page-item' . ($p === $currentPage ? ' active' : '') . '"><a class="page-link" href="' . e($link($p)) . '">' . $p . '</a></li>';
        }
        if ($end < $totalPages) {
            // Same idea as above, mirrored for the tail end: show a gap
            // indicator and the final page number if the window doesn't
            // reach the last page.
            if ($end < $totalPages - 1) {
                echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            echo '<li class="page-item"><a class="page-link" href="' . e($link($totalPages)) . '">' . $totalPages . '</a></li>';
        }

        // "Next" arrow - same disabled/clamped logic as "Previous" above,
        // mirrored for the end of the list.
        echo '<li class="page-item' . ($currentPage >= $totalPages ? ' disabled' : '') . '"><a class="page-link" href="' . e($link(min($totalPages, $currentPage + 1))) . '">&raquo;</a></li>';
        echo '</ul></nav>';
    }
}
