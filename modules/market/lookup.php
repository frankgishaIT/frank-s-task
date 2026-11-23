<?php
/**
 * NEW FILE: modules/market/lookup.php — live search for the request form (JSON):
 *   ?q=...&kind=product|service  → matching RM Offerings items (with stock) and existing opportunities (with their requests)
 *   ?customer=...                → customers by name, phone or Customer ID
 */
require __DIR__ . '/common.php';
if (!mic($conn, 'request') && !mic($conn, 'view')) { http_response_code(403); exit('[]'); }
header('Content-Type: application/json; charset=utf-8');
if (isset($_GET['customer'])) {
    $q = trim((string) $_GET['customer']);
    if (mb_strlen($q) < 2) { echo '[]'; exit; }
    $id = (int) preg_replace('/\D/', '', $q); $like = '%' . $q . '%';
    $s = mysqli_prepare($conn, 'SELECT id, name, phone FROM customers WHERE id = ? OR name LIKE ? OR phone LIKE ? ORDER BY (id = ?) DESC, name LIMIT 10');
    mysqli_stmt_bind_param($s, 'issi', $id, $like, $like, $id); mysqli_stmt_execute($s);
    echo json_encode(array_map(function ($r) { return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'phone' => (string) $r['phone'], 'code' => 'RMC' . str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT)]; }, mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC)));
    exit;
}
$kind = ($_GET['kind'] ?? '') === 'service' ? 'service' : 'product';
$r = mi_lookup($conn, (string) ($_GET['q'] ?? ''), $kind);
$out = ['catalogue' => [], 'opportunities' => []];
foreach ($r['catalogue'] as $p) {
    [$code, $label] = mi_situation(['kind' => $kind, 'origin' => 'request'], $p);
    $out['catalogue'][] = ['id' => (int) $p['id'], 'name' => $p['product_name'], 'code' => $p['product_code'], 'category' => $p['category'], 'unit' => $p['unit'],
        'stock' => $kind === 'product' ? mi_num($p['quantity']) . ' ' . ($p['unit'] ?: '') : null, 'situation' => $label, 'sit' => $code, 'score' => $p['score']];
}
foreach ($r['opportunities'] as $o) {
    $out['opportunities'][] = ['id' => (int) $o['id'], 'name' => $o['name'], 'category' => $o['category'], 'unit' => $o['unit'], 'status' => $o['status'], 'requests' => (int) $o['requests'],
        'qty' => mi_num($o['qty']), 'product_id' => $o['product_id'] ? (int) $o['product_id'] : null, 'score' => $o['score']];
}
echo json_encode($out);