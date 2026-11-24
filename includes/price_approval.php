<?php
/**
 * NEW FILE: includes/price_approval.php — the EXISTING "sell below the set price" approval of the Sales module,
 * made visible and complete (Task 3.5). No second approval module: a sale needing it still waits in
 * "Pending Discount Approval" and is approved / rejected on sales/approve_discount.php by the same people as before.
 *
 * Added:
 *   - the set price of each line is kept (sale_items.set_price = RM Offerings selling price × pack size), so a lower
 *     unit price is visible and counted, not only the discount field (before, an Employee could type a lower unit
 *     price and the sale went through without approval);
 *   - the reason for the request, the decision (Approved / Rejected), who decided, when and why.
 */

function pa_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        $has = function ($t, $c) use ($conn) { $r = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '$t' AND column_name = '$c'")); return $r && (int) $r[0] > 0; };
        if (!$has('sales', 'discount_reason')) {
            mysqli_query($conn, 'ALTER TABLE sales ADD COLUMN discount_reason VARCHAR(255) NULL, ADD COLUMN price_decision VARCHAR(10) NULL, ADD COLUMN price_decided_by INT(11) NULL, ADD COLUMN price_decided_at DATETIME NULL, ADD COLUMN price_decision_reason VARCHAR(255) NULL');
        }
        if (!$has('sale_items', 'set_price')) { mysqli_query($conn, 'ALTER TABLE sale_items ADD COLUMN set_price DECIMAL(14,2) NULL'); }
    } catch (Throwable $e) { error_log('pa_ensure: ' . $e->getMessage()); }
}

/** Lines of a sale with set price, proposed price, difference and — when the cost is known — the margin. */
function pa_lines(mysqli $conn, int $saleId): array
{
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT si.*, p.product_name, p.selling_price, p.buying_price, p.item_type ptype FROM sale_items si LEFT JOIN products p ON p.id = si.product_id WHERE si.sale_id = $saleId ORDER BY si.id"), MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $pack = max(1, (int) ($r['pack_size'] ?: 1));
        $r['set'] = $r['set_price'] !== null ? (float) $r['set_price'] : ($r['selling_price'] !== null ? (float) $r['selling_price'] * $pack : null);
        $r['diff'] = $r['set'] !== null ? ((float) $r['unit_price'] - $r['set']) * (int) $r['quantity'] : null;
        $r['cost'] = ($r['ptype'] ?? '') === 'Item' && (float) $r['buying_price'] > 0 ? (float) $r['buying_price'] * $pack * (int) $r['quantity'] : null;
        $r['margin'] = $r['cost'] !== null ? (float) $r['line_total'] - $r['cost'] : null;
    }
    return $rows;
}

/** Summary of a price request: set total, proposed total, below-set amount, discount, cost, margin (null when unknown). */
function pa_summary(array $sale, array $lines): array
{
    $set = 0; $known = true; $cost = 0; $costKnown = true;
    foreach ($lines as $l) { if ($l['set'] === null) { $known = false; } else { $set += $l['set'] * (int) $l['quantity']; } if ($l['cost'] === null) { $costKnown = false; } else { $cost += $l['cost']; } }
    $total = (float) $sale['total_amount'];
    return ['set_total' => $known ? $set : null, 'proposed' => $total, 'difference' => $known ? $total - $set : null, 'discount' => (float) $sale['discount_amount'],
            'below_lines' => count(array_filter($lines, function ($l) { return $l['diff'] !== null && $l['diff'] < -0.004; })),
            'cost' => $costKnown ? $cost : null, 'margin' => $costKnown ? $total - $cost : null, 'loss' => $costKnown && $total < $cost];
}

/** Status of the price approval of a sale, for display. */
function pa_status(array $sale): array
{
    if (($sale['price_decision'] ?? '') === 'Approved' || (!empty($sale['discount_approved_by']) && $sale['status'] !== 'Pending Discount Approval')) { return ['Approved', 'success']; }
    if (($sale['price_decision'] ?? '') === 'Rejected') { return ['Rejected', 'danger']; }
    if ($sale['status'] === 'Pending Discount Approval') { return ['Waiting for approval', 'warning']; }
    return ['', ''];
}