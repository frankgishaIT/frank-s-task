<?php
/**
 * Shared helpers for the RM Business Parties module.
 */

function business_party_display_name($party) {
    if (!$party) { return null; }
    if ($party['type'] === 'Supplier') {
        return $party['business_name'] ?: ('Supplier #' . $party['id']);
    }
    return $party['name'] ?: ($party['type'] . ' #' . $party['id']);
}

function business_party_type_badge($type) {
    $map = ['Supplier' => 'primary', 'Payee' => 'warning text-dark', 'Partner' => 'info text-dark'];
    $class = $map[$type] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . htmlspecialchars($type, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Active parties of a given type, for populating filtered dropdowns
 * (Purchase Order → Supplier, Expense → Payee, Income → Partner).
 */
function business_parties_of_type($conn, $type) {
    $statement = mysqli_prepare($conn, 'SELECT * FROM business_parties WHERE type = ? AND is_active = 1 ORDER BY COALESCE(business_name, name)');
    mysqli_stmt_bind_param($statement, 's', $type);
    mysqli_stmt_execute($statement);
    $result = mysqli_stmt_get_result($statement);
    $list = [];
    while ($row = mysqli_fetch_assoc($result)) { $list[] = $row; }
    return $list;
}

function business_party_by_id($conn, $id) {
    if (!$id) { return null; }
    $statement = mysqli_prepare($conn, 'SELECT * FROM business_parties WHERE id = ?');
    mysqli_stmt_bind_param($statement, 'i', $id);
    mysqli_stmt_execute($statement);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
}