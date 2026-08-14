<?php

namespace ShopRex\Support;

/**
 * Direct port of renderPagination() from includes/functions.php. Presentation-
 * only (echoes Bootstrap pagination markup directly, same as the original)
 * so it lives under Support rather than Services.
 */
final class Pagination
{
    public static function render(int $currentPage, int $totalPages, array $queryParams): void
    {
        if ($totalPages <= 1) {
            return;
        }
        $link = function (int $page) use ($queryParams): string {
            return '?' . http_build_query(array_merge($queryParams, ['page' => $page]));
        };
        echo '<nav aria-label="Pagination"><ul class="pagination justify-content-center">';
        echo '<li class="page-item' . ($currentPage <= 1 ? ' disabled' : '') . '"><a class="page-link" href="' . e($link(max(1, $currentPage - 1))) . '">&laquo;</a></li>';

        $start = max(1, $currentPage - 2);
        $end = min($totalPages, $currentPage + 2);
        if ($start > 1) {
            echo '<li class="page-item"><a class="page-link" href="' . e($link(1)) . '">1</a></li>';
            if ($start > 2) {
                echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
        }
        for ($p = $start; $p <= $end; $p++) {
            echo '<li class="page-item' . ($p === $currentPage ? ' active' : '') . '"><a class="page-link" href="' . e($link($p)) . '">' . $p . '</a></li>';
        }
        if ($end < $totalPages) {
            if ($end < $totalPages - 1) {
                echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
            }
            echo '<li class="page-item"><a class="page-link" href="' . e($link($totalPages)) . '">' . $totalPages . '</a></li>';
        }

        echo '<li class="page-item' . ($currentPage >= $totalPages ? ' disabled' : '') . '"><a class="page-link" href="' . e($link(min($totalPages, $currentPage + 1))) . '">&raquo;</a></li>';
        echo '</ul></nav>';
    }
}
