<?php

function get_status_filter($default = 'active') {
    $status = $_GET['status'] ?? $default;
    return in_array($status, ['active', 'inactive', 'all'], true) ? $status : $default;
}

function status_where_clause($status, $column = 'is_active') {
    if ($status === 'active') return "WHERE $column = 1";
    if ($status === 'inactive') return "WHERE $column = 0";
    return '';
}