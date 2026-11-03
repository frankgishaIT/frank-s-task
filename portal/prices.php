<?php
/**
 * NEW FILE: portal/prices.php — Services & Products Pricing (JSON for the pop-up).
 * Read DIRECTLY from MY MOTIVE's registered products and services every time (no copy, no cache), so the portal
 * always shows the latest approved selling prices. Only ACTIVE items; sub-units (e.g. Box = 10 Pieces) are priced
 * from the same record. Signed-in customers only. No cost price, stock figure or supplier is ever sent.
 */
require_once __DIR__ . '/../includes/portal_helpers.php';
$conn = portal_boot();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!portal_is_open($conn) || !portal_customer($conn)) { http_response_code(401); echo json_encode(['error' => 'Please sign in.']); exit; }
$col = function (string $t, string $c) use ($conn): bool {
    $s = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    mysqli_stmt_bind_param($s, 'ss', $t, $c); mysqli_stmt_execute($s);
    return (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] > 0;
};
$desc = $col('products', 'description') ? 'p.description' : 'NULL';
$cat = $col('products', 'category') ? 'p.category' : 'NULL';
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name, p.item_type, p.unit, p.selling_price, $cat AS category, $desc AS description
    FROM products p WHERE p.is_active = 1 AND p.selling_price > 0 ORDER BY p.product_name"), MYSQLI_ASSOC);
$packs = [];
if ($col('product_units', 'conversion_rate')) {
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT pu.product_id, u.name AS unit_name, pu.conversion_rate FROM product_units pu JOIN units u ON u.id = pu.unit_id WHERE pu.conversion_rate > 1 ORDER BY pu.conversion_rate'), MYSQLI_ASSOC) as $u) {
        $packs[(int) $u['product_id']][] = $u;
    }
}
$items = [];
foreach ($rows as $r) {
    $price = round((float) $r['selling_price'], 2);
    $items[] = [
        'name' => $r['product_name'], 'type' => $r['item_type'] === 'Service' ? 'Service' : 'Product',
        'category' => $r['category'] ?: null, 'description' => $r['description'] ? mb_strimwidth((string) $r['description'], 0, 120, '…') : null,
        'unit' => $r['unit'] ?: 'unit', 'price' => $price,
        'packs' => array_map(function ($u) use ($price) { return ['unit' => $u['unit_name'], 'qty' => (int) $u['conversion_rate'], 'price' => round($price * (int) $u['conversion_rate'], 2)]; }, $packs[(int) $r['id']] ?? []),
    ];
}
echo json_encode(['updated' => date('d M Y, H:i'), 'items' => $items], JSON_UNESCAPED_UNICODE);