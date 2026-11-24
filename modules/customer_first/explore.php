<?php
/** NEW FILE: modules/customer_first/explore.php — JSON for the guided request: catalogue matches (stock / service), alternatives, capable employees, customers. */
require __DIR__ . '/common.php';
cf_require($conn, 'record');
header('Content-Type: application/json');
if (isset($_GET['customer'])) {
    $q = '%' . trim((string) $_GET['customer']) . '%';
    $s = mysqli_prepare($conn, 'SELECT id, name, phone FROM customers WHERE is_active = 1 AND (name LIKE ? OR phone LIKE ? OR CONCAT(\'RMC\', LPAD(id, 6, \'0\')) LIKE ?) ORDER BY name LIMIT 8');
    mysqli_stmt_bind_param($s, 'sss', $q, $q, $q); mysqli_stmt_execute($s);
    echo json_encode(array_map(function ($c) { return ['id' => (int) $c['id'], 'name' => $c['name'], 'phone' => $c['phone'], 'code' => 'RMC' . str_pad((string) $c['id'], 6, '0', STR_PAD_LEFT)]; }, mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC)));
    exit;
}
$kind = ($_GET['kind'] ?? '') === 'service' ? 'service' : 'product';
$x = cf_explore($conn, (string) ($_GET['q'] ?? ''), $kind, (int) ($_GET['product_id'] ?? 0));
$map = function ($p) { return ['id' => (int) $p['id'], 'name' => $p['product_name'], 'code' => $p['product_code'], 'status' => cf_stock_text($p), 'in_stock' => $p['item_type'] === 'Service' ? (bool) (int) $p['is_active'] : ((int) $p['is_active'] && (float) $p['quantity'] > 0), 'price' => (float) $p['selling_price']]; };
echo json_encode(['matches' => array_map($map, $x['matches']), 'alternatives' => array_map($map, $x['alternatives']), 'employees' => $x['employees']]);