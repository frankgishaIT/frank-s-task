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

/**
 * NEW: loan figures for the Capital Fund Check.
 * The Loans module (table `loans`) is the record of what was borrowed, repaid and is still owed.
 * Cancelled loans are left out.
 *   received        total borrowed (loan_amount)
 *   principal_repaid / interest_paid / outstanding (still owed: Active and Defaulted loans)
 *   in_fund         loan money the RM Capital Fund received through the Loans module
 *                   (movements with ref_type 'loan', including corrections and reversals)
 *   manual          'Business Loan' entries typed on the Add Capital page (not linked to any loan)
 */
function capital_loan_summary(mysqli $conn): array {
    $l = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
            COUNT(*) AS loans,
            COALESCE(SUM(loan_amount), 0) AS received,
            COALESCE(SUM(principal_repaid), 0) AS principal_repaid,
            COALESCE(SUM(interest_paid), 0) AS interest_paid,
            COALESCE(SUM(CASE WHEN status IN ('Active', 'Defaulted') THEN outstanding_balance ELSE 0 END), 0) AS outstanding,
            COALESCE(SUM(CASE WHEN status IN ('Active', 'Defaulted') THEN 1 ELSE 0 END), 0) AS open_loans
        FROM loans WHERE status <> 'Cancelled'"));

    $inFund = mysqli_fetch_assoc(mysqli_query($conn, "SELECT
            COALESCE(SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END), 0) AS v
        FROM fund_movements m JOIN funds f ON f.id = m.fund_id AND f.code = 'CAPITAL'
        WHERE m.ref_type = 'loan'"));

    // Manual "Business Loan" entries still active (not deleted or corrected away).
    $manual = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS n, COALESCE(SUM(m.amount), 0) AS v
        FROM fund_movements m
        JOIN funds f ON f.id = m.fund_id AND f.code = 'CAPITAL'
        LEFT JOIN fund_movements r ON r.reverses_movement_id = m.id
        WHERE m.movement_type = 'CAPITAL_INFLOW' AND (m.ref_type IS NULL OR m.ref_type = 'CAPITAL_EDIT')
          AND m.source_type = 'Business Loan' AND r.id IS NULL"));

    // NEW: per-loan detail. Loans taken BEFORE the RM Capital Fund started never went through it:
    // their money (and what it bought) is already inside the opening balances, so they are not
    // compared with the fund. They still count in Outstanding loans (the business still owes them).
    $startRow = mysqli_fetch_row(mysqli_query($conn, "SELECT DATE(MIN(m.created_at)) FROM fund_movements m
        JOIN funds f ON f.id = m.fund_id AND f.code = 'CAPITAL'"));
    $fundStart = $startRow[0] ?? null;

    $details = mysqli_fetch_all(mysqli_query($conn, "SELECT l.id, l.lender, l.loan_type, l.loan_amount, l.loan_start_date, l.status,
            l.outstanding_balance,
            COALESCE((SELECT SUM(CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END)
                      FROM fund_movements m WHERE m.ref_type = 'loan' AND m.ref_id = l.id), 0) AS in_fund
        FROM loans l WHERE l.status <> 'Cancelled' ORDER BY l.loan_start_date, l.id"), MYSQLI_ASSOC);

    $expected = 0.0; $expectedInFund = 0.0; $beforeCount = 0; $beforeAmount = 0.0; $missingCount = 0;
    foreach ($details as &$d) {
        $amount = round((float) $d['loan_amount'], 2);
        $in = round((float) $d['in_fund'], 2);
        if ($in >= $amount - 0.01) {
            $d['check'] = 'ok';
        } elseif ($fundStart && $d['loan_start_date'] < $fundStart) {
            $d['check'] = 'before';
        } else {
            $d['check'] = 'missing';
            $missingCount++;
        }
        if ($d['check'] === 'before') {
            $beforeCount++;
            $beforeAmount += $amount;
        } else {
            $expected += $amount;
            $expectedInFund += $in;
        }
    }
    unset($d);

    return [
        'fund_start' => $fundStart,
        'details' => $details,
        'before_count' => $beforeCount,
        'before_amount' => round($beforeAmount, 2),
        'missing_count' => $missingCount,
        'expected' => round($expected, 2),               // loans that must be in the fund
        'expected_in_fund' => round($expectedInFund, 2), // what the fund received for them
        'loans' => (int) $l['loans'],
        'open_loans' => (int) $l['open_loans'],
        'received' => round((float) $l['received'], 2),
        'principal_repaid' => round((float) $l['principal_repaid'], 2),
        'interest_paid' => round((float) $l['interest_paid'], 2),
        'outstanding' => round((float) $l['outstanding'], 2),
        'in_fund' => round((float) $inFund['v'], 2),
        'manual_count' => (int) $manual['n'],
        'manual_amount' => round((float) $manual['v'], 2),
    ];
}