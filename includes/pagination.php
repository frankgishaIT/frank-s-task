<?php
/**
 * Shared pagination helpers used by every long list table.
 */

function get_current_page() {
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    return ($page && $page > 0) ? $page : 1;
}

function pagination_build_url($page, $queryParams) {
    $params = $queryParams;
    $params['page'] = $page;
    return '?' . http_build_query($params);
}

function render_pagination($currentPage, $totalPages, $queryParams = []) {
    if ($totalPages <= 1) {
        return;
    }

    echo '<nav class="mt-3"><ul class="pagination justify-content-center mb-0">';
    echo '<li class="page-item ' . ($currentPage <= 1 ? 'disabled' : '') . '">
    <a class="page-link" href="' . pagination_build_url(max(1, $currentPage - 1), $queryParams) . '">Previous</a></li>';

    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        echo '<li class="page-item">
        <a class="page-link" href="' . pagination_build_url(1, $queryParams) . '">1</a></li>';
        if ($start > 2) {
            echo '<li class="page-item disabled">
            <span class="page-link">&hellip;</span></li>';
        }
    }

    for ($p = $start; $p <= $end; $p++) {
        echo '<li class="page-item ' . ($p === $currentPage ? 'active' : '') . '">
        <a class="page-link" href="' . pagination_build_url($p, $queryParams) . '">' . $p . '</a></li>';
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            echo '<li class="page-item disabled"><span class="page-link">&hellip;</span></li>';
        }
        echo '<li class="page-item">
        <a class="page-link" href="' . pagination_build_url($totalPages, $queryParams) . '">' . $totalPages . '</a></li>';
    }

    echo '<li class="page-item ' . ($currentPage >= $totalPages ? 'disabled' : '') . '">
    <a class="page-link" href="' . pagination_build_url(min($totalPages, $currentPage + 1), $queryParams) . '">Next</a></li>';
    echo '</ul></nav>';
}
