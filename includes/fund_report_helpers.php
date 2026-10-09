<?php
/**
 * NEW FILE: includes/fund_report_helpers.php
 * Spec sections 13 and 14: fund figures for cards / reports, and the check that
 * the RM Capital Fund matches the real Stock + Assets.
 *
 * Every fund movement falls into ONE group, so the groups always add up to the balance:
 *   contrib  money put in: profit allocations, capital inflows, loans, sale proceeds, corrections
 *   used     money spent: expenses (purchase orders, re-stock, rent ...)
 *   stock    stock value: received stock, cost of stock sold, opening stock, stock corrections
 *   assets   asset value: registered assets, depreciation, revaluation, disposal
 * A REVERSAL row belongs to the same group as the row it reverses, so a cancelled sale
 * lowers "Contributed" instead of looking like money "Used".
 *
 *   balance = contrib - used + stock + assets
 */
require_once __DIR__ . '/fund_helpers.php';

// The group of movement "m" (with its reversed original joined as "o").
function fund_movement_category_sql(): string {
    $eff = "(CASE WHEN m.movement_type = 'REVERSAL' THEN o.movement_type ELSE m.movement_type END)";
    return "(CASE
        WHEN m.ref_type = 'ASSET' THEN 'assets'
        WHEN $eff IN ('STOCK_IN', 'STOCK_OUT')
          OR m.ref_type IN ('STOCK_OPENING', 'STOCK_RECONCILE')
          OR ($eff = 'CAPITAL_INFLOW' AND m.ref_type = 'PO') THEN 'stock'
        WHEN $eff = 'EXPENSE' THEN 'used'
        WHEN m.movement_type = 'REVERSAL' AND m.reverses_movement_id IS NULL AND m.transaction_id IS NOT NULL THEN 'used'
        ELSE 'contrib' END)";
}

/**
 * Figures per fund: [fund_id => [contrib, used, stock, assets, period_contrib, last_date]].
 * "used" is returned as a positive number. $period is 'YYYY-MM' for "This period".
 */
function fund_breakdown(mysqli $conn, string $period): array {
    $cat = fund_movement_category_sql();
    $signed = "(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END)";
    $s = mysqli_prepare($conn, "SELECT m.fund_id, $cat AS cat,
            SUM($signed) AS total,
            SUM(CASE WHEN m.period = ? THEN $signed ELSE 0 END) AS period_total,
            MAX(m.created_at) AS last_date
        FROM fund_movements m
        LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id
        GROUP BY m.fund_id, cat");
    mysqli_stmt_bind_param($s, 's', $period);
    mysqli_stmt_execute($s);

    $out = [];
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) {
        $fid = (int) $r['fund_id'];
        if (!isset($out[$fid])) {
            $out[$fid] = ['contrib' => 0.0, 'used' => 0.0, 'stock' => 0.0, 'assets' => 0.0, 'period_contrib' => 0.0, 'last_date' => null];
        }
        $total = round((float) $r['total'], 2);
        if ($r['cat'] === 'used') {
            $out[$fid]['used'] += -$total;
        } else {
            $out[$fid][$r['cat']] += $total;
        }
        if ($r['cat'] === 'contrib') {
            $out[$fid]['period_contrib'] += round((float) $r['period_total'], 2);
        }
        if ($r['last_date'] && (!$out[$fid]['last_date'] || $r['last_date'] > $out[$fid]['last_date'])) {
            $out[$fid]['last_date'] = $r['last_date'];
        }
    }
    return $out;
}

// Real value of the stock on hand: quantity x (average) buying price of every physical item.
function capital_actual_stock_value(mysqli $conn): float {
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(quantity * buying_price), 0) AS v
        FROM products WHERE item_type = 'Item' AND quantity > 0"));
    return round((float) $row['v'], 2);
}

// Real value of the assets the business still has (same rule as the Capital Fund).
function capital_actual_asset_value(mysqli $conn): float {
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(current_value), 0) AS v
        FROM assets WHERE status IN ('Active', 'Under Maintenance')"));
    return round((float) $row['v'], 2);
}

// True once the opening stock value has been posted.
function capital_has_opening_stock(mysqli $conn): bool {
    return (bool) mysqli_fetch_assoc(mysqli_query($conn, "SELECT id FROM fund_movements WHERE ref_type = 'STOCK_OPENING' LIMIT 1"));
}

/**
 * Brings the stock part of the RM Capital Fund in line with the real stock value (own transaction).
 * The first time it is the OPENING BALANCE (the stock that was on the shelves before the
 * Capital Fund integration). After that it is a correction (ADJUSTMENT), e.g. after stock was
 * counted, edited by hand or written off. Returns ['ok' => bool, 'amount' => float, 'error' => string].
 */
function capital_post_stock_difference(mysqli $conn, ?int $userId, string $reason): array {
    mysqli_begin_transaction($conn);
    try {
        $fund = fund_by_code($conn, 'CAPITAL');
        if (!$fund) { throw new RuntimeException('RM Capital Fund was not found.'); }
        fund_lock($conn, (int) $fund['id']);

        $breakdown = fund_breakdown($conn, date('Y-m'));
        $inFund = round($breakdown[(int) $fund['id']]['stock'] ?? 0.0, 2);
        $actual = capital_actual_stock_value($conn);
        $diff = round($actual - $inFund, 2);
        if (abs($diff) < 0.01) {
            mysqli_rollback($conn);
            return ['ok' => true, 'amount' => 0.0, 'error' => ''];
        }

        $opening = !capital_has_opening_stock($conn);
        $type = $opening ? 'OPENING_BALANCE' : 'ADJUSTMENT';
        $refType = $opening ? 'STOCK_OPENING' : 'STOCK_RECONCILE';
        $desc = $opening
            ? 'Opening balance: value of stock on hand (RWF ' . number_format($actual, 2) . ')'
            : 'Stock value correction' . ($reason !== '' ? ': ' . $reason : '');

        fund_record_movement($conn, (int) $fund['id'], $type, $diff > 0 ? 'IN' : 'OUT', abs($diff), date('Y-m'),
            null, $userId, mb_substr($desc, 0, 255), $opening ? 'Opening stock' : 'Stock correction', $refType, null);

        mysqli_commit($conn);
        return ['ok' => true, 'amount' => $diff, 'error' => ''];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('capital_post_stock_difference failed: ' . $e->getMessage());
        return ['ok' => false, 'amount' => 0.0, 'error' => 'Unable to post the stock value. Nothing was changed.'];
    }
}