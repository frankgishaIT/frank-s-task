<?php
/**
 * NEW FILE: includes/asset_capital_helpers.php
 * Asset Management <-> RM Capital Fund (task "Integrate Asset Management with RM Capital Fund").
 *
 *  1. Acquisition: an asset is PAID from the RM Capital Fund (money out, linked to the asset) and its
 *     value enters the fund's asset part. Blocked when the fund's MONEY (never stock or asset values,
 *     never the Balance Sheet cash) does not cover the full acquisition value.
 *  2. Opening reconciliation: amounts supported by records are posted once, with an Admin note.
 *  3. Every asset records its funding (Capital Fund purchase + payment, or Opening balance).
 *  4. Returned to seller: refund received now (money back into the fund) or receivable (later).
 *  5. Valuation: depreciation / amortization / none; impairment and revaluation with evidence.
 *     None of these moves money: they only change the asset part of the Capital Fund.
 */
require_once __DIR__ . '/fund_helpers.php';
require_once __DIR__ . '/asset_fund_helpers.php';

const ASSET_CLASSES = ['Tangible', 'Purchased software', 'Internally developed software', 'Capitalized development cost', 'Other intangible'];
const ASSET_VALUATION_METHODS = ['Depreciation', 'Amortization', 'No depreciation'];
const ASSET_EVIDENCE_DIR = __DIR__ . '/../storage/asset_valuations';

/* ------------------------------------------------------------------------------------------
 * 1. Acquisition
 * ------------------------------------------------------------------------------------------ */

/** NEW: true when migration_asset_capital.sql has been run on this database. */
function asset_capital_installed(mysqli $conn): bool
{
    try {
        return (bool) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'capital_reconciliations'"))[0];
    } catch (Throwable $e) {
        return false;
    }
}

/** True once the opening reconciliation has been posted (the purchase rule is then active). */
function asset_capital_reconciled(mysqli $conn): bool
{
    try {
        return (bool) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM capital_reconciliations'))[0];
    } catch (Throwable $e) {
        return false;
    }
}

/** Can the RM Capital Fund pay this amount? ['ok', 'available', 'required', 'shortfall']. */
function asset_capital_funding_check(mysqli $conn, float $required): array
{
    $fund = fund_by_code($conn, 'CAPITAL');
    $available = $fund ? round(fund_available($conn, (int) $fund['id']), 2) : 0.0;
    $required = round($required, 2);
    return ['ok' => $available + 0.001 >= $required, 'available' => $available, 'required' => $required,
            'shortfall' => round(max(0, $required - $available), 2)];
}

function asset_funding_message(array $c): string
{
    return 'The RM Capital Fund cannot pay for this asset. Available: RWF ' . number_format($c['available'], 2)
        . ' | Required: RWF ' . number_format($c['required'], 2) . ' | Shortfall: RWF ' . number_format($c['shortfall'], 2) . '.';
}

/**
 * Pays an asset's acquisition value from the RM Capital Fund (inside the caller's transaction) and links
 * the payment to the asset. Safe to call twice: an asset already paid is never paid again.
 * Throws InsufficientFundException when the money is not there.
 */
function asset_pay_from_capital(mysqli $conn, int $assetId, float $amount, string $date, string $label, ?int $userId): int
{
    $s = mysqli_prepare($conn, 'SELECT funding_transaction_id FROM assets WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($s, 'i', $assetId);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if ($row && !empty($row['funding_transaction_id'])) {
        return (int) $row['funding_transaction_id']; // already paid: never deducted twice
    }
    $txId = fund_post_automatic_expense($conn, 'CAPITAL', 'Asset Purchase', round($amount, 2), $date,
        mb_substr($label, 0, 240), $userId, null, 'ASSET_PAY', $assetId);
    $type = 'Capital Fund purchase';
    $u = mysqli_prepare($conn, 'UPDATE assets SET funding_type = ?, funding_transaction_id = ? WHERE id = ?');
    mysqli_stmt_bind_param($u, 'sii', $type, $txId, $assetId);
    mysqli_stmt_execute($u);
    return $txId;
}

/* ------------------------------------------------------------------------------------------
 * 5. Impairment and revaluation (no cash)
 * ------------------------------------------------------------------------------------------ */

function asset_store_valuation_evidence(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { throw new RuntimeException('The evidence file could not be uploaded.'); }
    if ($file['size'] > 5 * 1024 * 1024) { throw new RuntimeException('The evidence file is too large (maximum 5 MB).'); }
    $types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset($types[$mime])) { throw new RuntimeException('Evidence must be a PDF or a photo (JPG, PNG).'); }
    if (!is_dir(ASSET_EVIDENCE_DIR) && !@mkdir(ASSET_EVIDENCE_DIR, 0775, true)) { throw new RuntimeException('The evidence folder could not be created.'); }
    $name = bin2hex(random_bytes(16)) . '.' . $types[$mime];
    if (!move_uploaded_file($file['tmp_name'], ASSET_EVIDENCE_DIR . '/' . $name)) { throw new RuntimeException('The evidence file could not be saved.'); }
    return $name;
}

/**
 * Records an impairment or a revaluation (inside the caller's transaction).
 *   Impairment: value down to the recoverable amount; the loss goes to profit & loss.
 *   Revaluation up: first reverses earlier losses of this asset in profit & loss, the rest goes to the
 *                   Revaluation Reserve (equity), never to profit.
 *   Revaluation down: first uses this asset's Revaluation Reserve, the rest is a loss.
 * The asset part of the RM Capital Fund follows the new value (ADJUSTMENT); no money moves.
 * Later depreciation / amortization spreads the new value over the remaining useful life.
 */
function asset_record_valuation(mysqli $conn, int $assetId, string $type, float $newValue, string $date,
                                ?string $valuer, ?string $evidence, ?string $notes, ?int $userId): array
{
    asset_run_depreciation_catchup($conn, $assetId);
    $s = mysqli_prepare($conn, 'SELECT * FROM assets WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($s, 'i', $assetId);
    mysqli_stmt_execute($s);
    $a = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$a || !in_array($a['status'], ASSET_COUNTED_STATUSES, true)) {
        throw new RuntimeException('Only an asset in use can be impaired or revalued.');
    }
    $before = round((float) $a['current_value'], 2);
    $newValue = round($newValue, 2);
    $change = round($newValue - $before, 2);
    if (abs($change) < 0.01) { throw new RuntimeException('The new value is the same as the current value.'); }
    if ($type === 'Impairment' && $change > 0) { throw new RuntimeException('An impairment can only lower the value. Use a revaluation for an increase.'); }

    $h = mysqli_prepare($conn, 'SELECT COALESCE(SUM(to_reserve), 0) AS reserve, COALESCE(SUM(to_profit_loss), 0) AS pl FROM asset_valuations WHERE asset_id = ?');
    mysqli_stmt_bind_param($h, 'i', $assetId);
    mysqli_stmt_execute($h);
    $hist = mysqli_fetch_assoc(mysqli_stmt_get_result($h));
    $reserve = round((float) $hist['reserve'], 2);       // reserve this asset still holds
    $pastLosses = round(-min(0, (float) $hist['pl']), 2); // losses not yet reversed

    if ($type === 'Impairment') {
        $toReserve = -min($reserve, -$change);            // an impairment first uses this asset's reserve
        $toPl = round($change - $toReserve, 2);
    } elseif ($change > 0) {
        $toPl = min($pastLosses, $change);                // reverse earlier losses first
        $toReserve = round($change - $toPl, 2);
    } else {
        $toReserve = -min($reserve, -$change);
        $toPl = round($change - $toReserve, 2);
    }

    $ins = mysqli_prepare($conn, 'INSERT INTO asset_valuations (asset_id, valuation_type, valuation_date, value_before, value_after, change_amount,
        to_reserve, to_profit_loss, valuer, evidence_file, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($ins, 'issdddddsssi', $assetId, $type, $date, $before, $newValue, $change, $toReserve, $toPl, $valuer, $evidence, $notes, $userId);
    mysqli_stmt_execute($ins);

    // New value, and the daily depreciation / amortization over the remaining life.
    $usedDays = (int) floor((strtotime(date('Y-m-d')) - strtotime($a['acquisition_date'])) / 86400);
    $remaining = max(0, (int) $a['useful_life_days'] - $usedDays);
    $override = $remaining > 0 ? round(max(0, $newValue - (float) $a['residual_value']) / $remaining, 4) : 0.0;
    $u = mysqli_prepare($conn, 'UPDATE assets SET current_value = ?, daily_depreciation_override = ? WHERE id = ?');
    mysqli_stmt_bind_param($u, 'ddi', $newValue, $override, $assetId);
    mysqli_stmt_execute($u);

    // Today's valuation history row carries the new value, so the daily run continues from it.
    $today = date('Y-m-d');
    $hu = mysqli_prepare($conn, 'UPDATE asset_valuation_history SET value = ? WHERE asset_id = ? AND valuation_date = ?');
    mysqli_stmt_bind_param($hu, 'dis', $newValue, $assetId, $today);
    mysqli_stmt_execute($hu);
    if (mysqli_stmt_affected_rows($hu) === 0) {
        $zero = 0.0;
        $hi = mysqli_prepare($conn, 'INSERT INTO asset_valuation_history (asset_id, valuation_date, daily_depreciation, value) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($hi, 'isdd', $assetId, $today, $zero, $newValue);
        mysqli_stmt_execute($hi);
    }

    asset_sync_capital_fund($conn, $assetId, 'ADJUSTMENT', $userId,
        $type . ' of asset ' . $a['asset_code'] . ': RWF ' . number_format($before, 2) . ' -> RWF ' . number_format($newValue, 2) . ' (no money moved)');

    return ['before' => $before, 'after' => $newValue, 'to_reserve' => $toReserve, 'to_pl' => $toPl];
}

/* ------------------------------------------------------------------------------------------
 * 4. Refund from the seller, received after the return
 * ------------------------------------------------------------------------------------------ */

function asset_refund_receive(mysqli $conn, int $disposalId, string $date, ?int $userId): float
{
    $s = mysqli_prepare($conn, "SELECT d.*, a.asset_code, a.asset_name FROM asset_disposals d JOIN assets a ON a.id = d.asset_id
        WHERE d.id = ? FOR UPDATE");
    mysqli_stmt_bind_param($s, 'i', $disposalId);
    mysqli_stmt_execute($s);
    $d = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$d || $d['refund_status'] !== 'Receivable') {
        throw new RuntimeException('There is no refund waiting for this asset.');
    }
    fund_record_capital_inflow($conn, (float) $d['proceeds'], $date, 'Asset refund',
        'Refund received for asset ' . $d['asset_code'] . ' (' . $d['asset_name'] . ') returned to the seller', $userId, 'ASSET_REFUND', $disposalId);
    $u = mysqli_prepare($conn, "UPDATE asset_disposals SET refund_status = 'Received', refund_received_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($u, 'i', $disposalId);
    mysqli_stmt_execute($u);
    return (float) $d['proceeds'];
}

/* ------------------------------------------------------------------------------------------
 * 2. Opening reconciliation: amounts supported by records, posted once
 * ------------------------------------------------------------------------------------------ */

/** What the opening reconciliation would post, line by line (nothing is posted here). */
function capital_reconciliation_items(mysqli $conn): array
{
    $items = ['sales' => [], 'loans' => [], 'restocks' => [], 'repayments' => []];

    // a) Paid sales whose cost of goods has not returned to the Capital Fund.
    $res = mysqli_query($conn, "SELECT s.id,
            COALESCE(-SUM(CASE WHEN m.ref_type = 'SALE' AND (m.movement_type = 'STOCK_OUT' OR o.movement_type = 'STOCK_OUT')
                               THEN (CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END) END), 0)
          - COALESCE(SUM(CASE WHEN m.ref_type = 'SALE_COST' THEN (CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END) END), 0) AS due
        FROM sales s JOIN fund_movements m ON m.ref_id = s.id AND m.ref_type IN ('SALE', 'SALE_COST')
        LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id
        WHERE s.status = 'Paid' GROUP BY s.id HAVING due > 0.009");
    foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $r) { $items['sales'][] = ['id' => (int) $r['id'], 'amount' => round((float) $r['due'], 2)]; }

    // b) Loans received ("Loan Received" transactions) whose money never entered the Capital Fund.
    $res = mysqli_query($conn, "SELECT t.id, t.amount, t.transaction_date, t.description, l.id AS loan_id
        FROM transactions t LEFT JOIN loans l ON l.received_transaction_id = t.id
        WHERE t.transaction_type = 'Income' AND t.status = 'approved' AND t.category = 'Loan Received'
          AND NOT EXISTS (SELECT 1 FROM fund_movements m WHERE (m.ref_type = 'LOAN_TX' AND m.ref_id = t.id)
                          OR (l.id IS NOT NULL AND m.ref_type = 'loan' AND m.ref_id = l.id AND m.direction = 'IN'))");
    foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $r) { $items['loans'][] = $r; }

    // c) Stock purchases paid outside any fund (the stock they bought is already in the Capital Fund).
    $res = mysqli_query($conn, "SELECT id, amount, transaction_date, description FROM transactions
        WHERE transaction_type = 'Expense' AND status = 'approved' AND category = 'Purchase (Re-stock)' AND fund_id IS NULL");
    $items['restocks'] = mysqli_fetch_all($res, MYSQLI_ASSOC);

    // d) Loan repayments paid outside any fund: principal from the Capital Fund, interest from Operating.
    $res = mysqli_query($conn, "SELECT id, amount, transaction_date, description FROM transactions
        WHERE transaction_type = 'Expense' AND status = 'approved' AND category = 'Loan Repayment' AND fund_id IS NULL");
    foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $r) {
        $principal = (float) $r['amount']; $interest = 0.0;
        if (preg_match('/Principal RWF ([\d,.]+).*Interest RWF ([\d,.]+)/i', (string) $r['description'], $mm)) {
            $p = (float) str_replace(',', '', $mm[1]); $i = (float) str_replace(',', '', $mm[2]);
            if (abs($p + $i - (float) $r['amount']) < 1) { $principal = $p; $interest = $i; }
        }
        $r['principal'] = round($principal, 2); $r['interest'] = round($interest, 2);
        $items['repayments'][] = $r;
    }
    return $items;
}

function capital_reconciliation_totals(array $items): array
{
    $t = [
        'sales' => round(array_sum(array_column($items['sales'], 'amount')), 2),
        'loans' => round(array_sum(array_map('floatval', array_column($items['loans'], 'amount'))), 2),
        'restocks' => round(array_sum(array_map('floatval', array_column($items['restocks'], 'amount'))), 2),
        'principal' => round(array_sum(array_column($items['repayments'], 'principal')), 2),
        'interest' => round(array_sum(array_column($items['repayments'], 'interest')), 2),
    ];
    $t['capital_net'] = round($t['sales'] + $t['loans'] - $t['restocks'] - $t['principal'], 2);
    return $t;
}

/** Posts the opening reconciliation (own transaction). Only once. Returns the summary text. */
function capital_post_reconciliation(mysqli $conn, string $note, int $userId): string
{
    mysqli_begin_transaction($conn);
    try {
        if ((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM capital_reconciliations FOR UPDATE'))[0] > 0) {
            throw new RuntimeException('The opening reconciliation has already been posted.');
        }
        $capital = fund_by_code($conn, 'CAPITAL');
        $operating = fund_by_code($conn, 'OPERATING');
        if (!$capital || !$operating) { throw new RuntimeException('The RM Funds are not set up.'); }
        $items = capital_reconciliation_items($conn);
        $t = capital_reconciliation_totals($items);
        $period = date('Y-m');
        $tag = 'Opening reconciliation';

        foreach ($items['sales'] as $s) { capital_recover_sale_cost($conn, $s['id'], $userId); }
        foreach ($items['loans'] as $l) {
            $refType = $l['loan_id'] ? 'loan' : 'LOAN_TX';
            $refId = $l['loan_id'] ? (int) $l['loan_id'] : (int) $l['id'];
            fund_record_movement($conn, (int) $capital['id'], 'CAPITAL_INFLOW', 'IN', (float) $l['amount'], $period, null, $userId,
                $tag . ': loan received (transaction #' . $l['id'] . ') put into the RM Capital Fund', 'Business Loan', $refType, $refId);
        }
        $link = mysqli_prepare($conn, 'UPDATE transactions SET fund_id = ? WHERE id = ? AND fund_id IS NULL');
        $capId = (int) $capital['id'];
        foreach ($items['restocks'] as $r) {
            $tid = (int) $r['id'];
            mysqli_stmt_bind_param($link, 'ii', $capId, $tid);
            mysqli_stmt_execute($link);
            fund_record_movement($conn, $capId, 'EXPENSE', 'OUT', (float) $r['amount'], $period, $tid, $userId,
                $tag . ': stock purchase paid outside the funds (transaction #' . $tid . ')', null, 'RECON', $tid);
        }
        foreach ($items['repayments'] as $r) {
            $tid = (int) $r['id'];
            mysqli_stmt_bind_param($link, 'ii', $capId, $tid);
            mysqli_stmt_execute($link);
            if ($r['principal'] > 0) {
                fund_record_movement($conn, $capId, 'EXPENSE', 'OUT', (float) $r['principal'], $period, $tid, $userId,
                    $tag . ': loan principal repaid outside the funds (transaction #' . $tid . ')', null, 'RECON', $tid);
            }
            if ($r['interest'] > 0) {
                fund_record_movement($conn, (int) $operating['id'], 'EXPENSE', 'OUT', (float) $r['interest'], $period, $tid, $userId,
                    $tag . ': loan interest paid outside the funds (transaction #' . $tid . ')', null, 'RECON', $tid);
            }
        }

        $summary = 'Cost of goods recovered on ' . count($items['sales']) . ' paid sale(s): +RWF ' . number_format($t['sales'], 2) . "\n"
            . 'Loans received outside the fund (' . count($items['loans']) . '): +RWF ' . number_format($t['loans'], 2) . "\n"
            . 'Stock purchases paid outside the funds (' . count($items['restocks']) . '): -RWF ' . number_format($t['restocks'], 2) . "\n"
            . 'Loan principal repaid outside the funds: -RWF ' . number_format($t['principal'], 2) . "\n"
            . 'Loan interest repaid outside the funds (Operating Fund): -RWF ' . number_format($t['interest'], 2) . "\n"
            . 'Net into the RM Capital Fund: RWF ' . number_format($t['capital_net'], 2);
        $ins = mysqli_prepare($conn, 'INSERT INTO capital_reconciliations (posted_by, note, summary) VALUES (?, ?, ?)');
        mysqli_stmt_bind_param($ins, 'iss', $userId, $note, $summary);
        mysqli_stmt_execute($ins);

        mysqli_commit($conn);
        return $summary;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
}