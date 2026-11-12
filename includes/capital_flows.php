<?php
/**
 * NEW FILE: includes/capital_flows.php — RM Credit Hub and RM Investors Hub money in Transactions and the funds.
 *
 *   RM Capital Fund is the lending / investment pool: its AVAILABLE balance limits new customer loans.
 *
 *   Customer loan disbursed   Expense "Customer Loan Disbursed" (automatic: not an operating cost), Capital Fund OUT
 *   Principal repaid          Income "Loan Principal Recovered" (automatic: NOT income),            Capital Fund IN
 *   Interest + fees received  Income "Customer Loan Interest" (real income), shared by the allocation % (build_allocation)
 *   Investor money as a LOAN  Income "Loan Received" (borrowed money, a liability; never income),   Capital Fund IN
 *   Investor CAPITAL          Income "Investor Capital Contribution" (automatic: not income),       Capital Fund IN
 *   Investor principal back   Expense (automatic), Capital Fund OUT
 *   Investor return / profit  Expense "Investor Return Paid" (a real cost), Operating Fund OUT
 *
 * Every posting is stored ONCE in cf_postings (kind + reference): calling twice never counts money twice.
 * Interest uses the same allocation rule as sales profit (build_allocation), so the percentages stay in one place.
 */
require_once __DIR__ . '/fund_helpers.php';

const CF_CL_LIVE = ['Disbursed', 'Active', 'Overdue', 'Defaulted', 'Fully Repaid'];

function cf_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cf_postings (id INT(11) NOT NULL AUTO_INCREMENT, kind VARCHAR(20) NOT NULL, ref_id INT(11) NOT NULL,
        inv_type VARCHAR(8) NULL, amount DECIMAL(16,2) NOT NULL, settled DECIMAL(16,2) NULL, principal DECIMAL(16,2) NULL, interest DECIMAL(16,2) NULL,
        transaction_id INT(11) NULL, transaction2_id INT(11) NULL, posted_for DATE NOT NULL, backfilled TINYINT(1) NOT NULL DEFAULT 0, created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY once (kind, ref_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // FIX: transactions.category / expense_category must hold the new category names (e.g. "Investor Capital Contribution",
    // 29 characters): widened to 100 when shorter, keeping the values, NULL / NOT NULL and the default.
    foreach (['category', 'expense_category'] as $col) {
        $ci = mysqli_fetch_assoc(mysqli_query($conn, "SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = '$col'"));
        if ($ci && in_array(strtolower($ci['DATA_TYPE']), ['varchar', 'char'], true) && (int) $ci['CHARACTER_MAXIMUM_LENGTH'] < 100) {
            $d = $ci['COLUMN_DEFAULT'];
            $def = ($d !== null && strtoupper($d) !== 'NULL') ? " DEFAULT '" . mysqli_real_escape_string($conn, trim($d, "'")) . "'" : '';
            mysqli_query($conn, "ALTER TABLE transactions MODIFY `$col` VARCHAR(100) " . ($ci['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL') . $def);
        }
    }
    // Investor money can be a LOAN (liability) or a CAPITAL contribution: set per project.
    $c = mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'inv_projects'");
    if ((int) mysqli_fetch_row($c)[0]) {
        $k = mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'inv_projects' AND column_name = 'contribution_type'");
        if ((int) mysqli_fetch_row($k)[0] === 0) { mysqli_query($conn, "ALTER TABLE inv_projects ADD COLUMN contribution_type VARCHAR(8) NOT NULL DEFAULT 'capital'"); }
    }
}

function cf_posted(mysqli $conn, string $kind, int $refId): ?array
{
    $s = mysqli_prepare($conn, 'SELECT * FROM cf_postings WHERE kind = ? AND ref_id = ?');
    mysqli_stmt_bind_param($s, 'si', $kind, $refId); mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

/** One fund movement, dated (backfills keep their original date). */
function cf_move(mysqli $conn, int $fundId, string $type, string $dir, float $amount, string $date, ?int $txId, ?int $uid, string $desc, string $refType, int $refId): void
{
    $period = substr($date, 0, 7); $at = $date . ' ' . date('H:i:s'); $desc = mb_substr($desc, 0, 255); $src = 'Automatic';
    $s = mysqli_prepare($conn, 'INSERT INTO fund_movements (fund_id, period, movement_type, direction, amount, transaction_id, source_type, description, created_by, ref_type, ref_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isssdississs', $fundId, $period, $type, $dir, $amount, $txId, $src, $desc, $uid, $refType, $refId, $at);
    mysqli_stmt_execute($s);
}

/** One transaction (approved). */
function cf_tx(mysqli $conn, string $type, string $category, float $amount, string $date, string $desc, ?int $uid, ?int $fundId, bool $automatic, ?string $expenseCategory = null): int
{
    $auto = $automatic ? 1 : 0; $desc = mb_substr($desc, 0, 255);
    $s = mysqli_prepare($conn, "INSERT INTO transactions (category, transaction_type, amount, transaction_date, description, recorded_by, status, fund_id, expense_category, is_automatic) VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, ?, ?)");
    mysqli_stmt_bind_param($s, 'ssdssiisi', $category, $type, $amount, $date, $desc, $uid, $fundId, $expenseCategory, $auto);
    mysqli_stmt_execute($s);
    return (int) mysqli_insert_id($conn);
}

function cf_fund(mysqli $conn, string $code): array
{
    $f = fund_by_code($conn, $code);
    if (!$f) { throw new RuntimeException('Fund ' . $code . ' was not found.'); }
    return $f;
}

/** Money leaving a fund: checked against the AVAILABLE balance (except when recording the past). */
function cf_out(mysqli $conn, string $code, string $category, float $amount, string $date, string $desc, ?int $uid, bool $automatic, ?string $expenseCategory, string $refType, int $refId, bool $check): int
{
    $f = cf_fund($conn, $code); $fid = (int) $f['id'];
    fund_lock($conn, $fid);
    if ($check && $amount > fund_available($conn, $fid) + 0.001) {
        throw new InsufficientFundException($f['name'] . ' has only RWF ' . number_format(max(0, fund_available($conn, $fid)), 2) . ' available for RWF ' . number_format($amount, 2) . '. Money is never taken from another fund.');
    }
    $tx = cf_tx($conn, 'Expense', $category, $amount, $date, $desc, $uid, $fid, $automatic, $expenseCategory);
    cf_move($conn, $fid, 'EXPENSE', 'OUT', $amount, $date, $tx, $uid, $desc, $refType, $refId);
    return $tx;
}

function cf_record(mysqli $conn, string $kind, int $refId, float $amount, string $date, ?int $uid, array $extra = []): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO cf_postings (kind, ref_id, inv_type, amount, settled, principal, interest, transaction_id, transaction2_id, posted_for, backfilled, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $it = $extra['inv_type'] ?? null; $se = $extra['settled'] ?? null; $pr = $extra['principal'] ?? null; $in = $extra['interest'] ?? null;
    $t1 = $extra['tx'] ?? null; $t2 = $extra['tx2'] ?? null; $bf = !empty($extra['backfill']) ? 1 : 0;
    mysqli_stmt_bind_param($s, 'sisddddiisii', $kind, $refId, $it, $amount, $se, $pr, $in, $t1, $t2, $date, $bf, $uid);
    mysqli_stmt_execute($s);
}

/* ================================================================== RM CREDIT HUB */

/**
 * Principal / interest split of every VERIFIED payment of a customer loan, by replaying the payments in order on the
 * schedule: oldest instalment first; within an instalment, interest and fees first, then principal.
 */
function cf_cl_splits(mysqli $conn, int $appId): array
{
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT no, principal, interest, fee, amount_due FROM cl_schedule WHERE application_id = $appId ORDER BY no"), MYSQLI_ASSOC);
    $paid = array_fill(0, count($rows), 0.0); $out = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, amount FROM cl_payments WHERE application_id = $appId AND status = 'Verified' ORDER BY COALESCE(verified_at, created_at), id"), MYSQLI_ASSOC) as $p) {
        $left = (float) $p['amount']; $pr = 0.0; $in = 0.0;
        foreach ($rows as $i => $r) {
            if ($left <= 0.004) { break; }
            $room = (float) $r['amount_due'] - $paid[$i]; if ($room <= 0.004) { continue; }
            $take = min($left, $room); $charges = (float) $r['interest'] + (float) $r['fee'];
            $chargesLeft = max(0, $charges - $paid[$i]); $toCharges = min($take, $chargesLeft);
            $in += $toCharges; $pr += $take - $toCharges; $paid[$i] += $take; $left -= $take;
        }
        $out[(int) $p['id']] = ['principal' => round($pr, 2), 'interest' => round($in + max(0, $left), 2)]; // anything beyond the schedule: income
    }
    return $out;
}

/** The disbursement of a customer loan: Capital Fund OUT (only within its available balance). Returns the transaction id. */
function cf_cl_disburse(mysqli $conn, array $app, ?int $uid, string $date, bool $backfill = false): ?int
{
    cf_ensure($conn); $id = (int) $app['id'];
    if (cf_posted($conn, 'CL_DISBURSE', $id)) { return null; }
    $amount = round((float) $app['approved_amount'], 2);
    $ref = 'CL-' . date('Y', strtotime($app['created_at'])) . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
    $tx = cf_out($conn, 'CAPITAL', 'Customer Loan Disbursed', $amount, $date, 'Customer loan ' . $ref . ' disbursed (principal)', $uid, true, 'Customer loans', 'CL_DISBURSE', $id, !$backfill);
    cf_record($conn, 'CL_DISBURSE', $id, $amount, $date, $uid, ['tx' => $tx, 'backfill' => $backfill]);
    return $tx;
}

/** A verified repayment: principal back to the Capital Fund; interest / fees as income, shared by the allocation %. */
function cf_cl_repayment(mysqli $conn, int $appId, int $paymentId, ?int $uid, string $date, bool $backfill = false): ?array
{
    cf_ensure($conn);
    if (cf_posted($conn, 'CL_PAYMENT', $paymentId)) { return null; }
    $split = cf_cl_splits($conn, $appId)[$paymentId] ?? null;
    if (!$split) { return null; }
    $app = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, created_at FROM cl_applications WHERE id = $appId"));
    $ref = 'CL-' . date('Y', strtotime($app['created_at'])) . '-' . str_pad((string) $appId, 5, '0', STR_PAD_LEFT);
    $t1 = null; $t2 = null;
    if ($split['principal'] > 0) {
        $cap = cf_fund($conn, 'CAPITAL');
        $t1 = cf_tx($conn, 'Income', 'Loan Principal Recovered', $split['principal'], $date, 'Principal repaid on customer loan ' . $ref . ' (not income)', $uid, (int) $cap['id'], true);
        cf_move($conn, (int) $cap['id'], 'CAPITAL_INFLOW', 'IN', $split['principal'], $date, $t1, $uid, 'Principal recovered: customer loan ' . $ref, 'CL_PRINCIPAL', $paymentId);
    }
    if ($split['interest'] > 0) {
        $t2 = cf_tx($conn, 'Income', 'Customer Loan Interest', $split['interest'], $date, 'Interest and fees on customer loan ' . $ref, $uid, null, false);
        foreach (build_allocation(funds_all($conn), $split['interest']) as $row) {
            if (abs($row['amount']) < 0.005) { continue; }
            cf_move($conn, (int) $row['fund']['id'], 'ALLOCATION', $row['amount'] >= 0 ? 'IN' : 'OUT', abs($row['amount']), $date, $t2, $uid, 'Customer loan interest ' . $ref . ' (allocation)', 'CL_INTEREST', $paymentId);
        }
    }
    cf_record($conn, 'CL_PAYMENT', $paymentId, $split['principal'] + $split['interest'], $date, $uid, ['principal' => $split['principal'], 'interest' => $split['interest'], 'tx' => $t1, 'tx2' => $t2, 'backfill' => $backfill]);
    return $split;
}

/* ================================================================== RM INVESTORS HUB */

function cf_inv_type(mysqli $conn, int $projectId): string
{
    $r = mysqli_fetch_row(mysqli_query($conn, "SELECT contribution_type FROM inv_projects WHERE id = $projectId"));
    return ($r[0] ?? 'capital') === 'loan' ? 'loan' : 'capital';
}

/** A verified investor contribution: Capital Fund IN, as a LOAN (liability) or a CAPITAL contribution. */
function cf_inv_contribution(mysqli $conn, array $k, ?int $uid, string $date, bool $backfill = false): ?int
{
    cf_ensure($conn); $id = (int) $k['id'];
    if (cf_posted($conn, 'INV_CONTRIB', $id)) { return null; }
    $type = cf_inv_type($conn, (int) $k['project_id']); $amount = round((float) $k['amount'], 2); $cap = cf_fund($conn, 'CAPITAL');
    if ($type === 'loan') {
        $tx = cf_tx($conn, 'Income', 'Loan Received', $amount, $date, 'Investor loan received: contribution ' . $k['contribution_ref'] . ' (borrowed capital, not income)', $uid, (int) $cap['id'], false);
        cf_move($conn, (int) $cap['id'], 'CAPITAL_INFLOW', 'IN', $amount, $date, $tx, $uid, 'Investor loan ' . $k['contribution_ref'], 'LOAN_TX', $tx);
    } else {
        $tx = cf_tx($conn, 'Income', 'Investor Capital Contribution', $amount, $date, 'Investor capital contribution ' . $k['contribution_ref'] . ' (not income)', $uid, (int) $cap['id'], true);
        cf_move($conn, (int) $cap['id'], 'CAPITAL_INFLOW', 'IN', $amount, $date, $tx, $uid, 'Investor capital ' . $k['contribution_ref'], 'INV_CAPITAL', $id);
    }
    cf_record($conn, 'INV_CONTRIB', $id, $amount, $date, $uid, ['inv_type' => $type, 'tx' => $tx, 'backfill' => $backfill]);
    return $tx;
}

/**
 * A paid distribution: the investor's principal from the Capital Fund, their return from the Operating Fund
 * (two separate transactions, both linked). The whole contribution is settled (a loss reduces what comes back).
 */
function cf_inv_payout(mysqli $conn, array $d, ?int $uid, string $date, bool $backfill = false): ?array
{
    cf_ensure($conn); $id = (int) $d['id'];
    if (cf_posted($conn, 'INV_PAYOUT', $id)) { return null; }
    $type = cf_inv_type($conn, (int) $d['project_id']); $principal = round((float) $d['capital_returned'], 2); $ret = round((float) $d['profit_share'], 2);
    $who = 'distribution #' . $id . ' (project #' . (int) $d['project_id'] . ')';
    $t1 = $principal > 0 ? cf_out($conn, 'CAPITAL', $type === 'loan' ? 'Investor Loan Principal Repaid' : 'Investor Capital Returned', $principal, $date, 'Investor principal paid back: ' . $who, $uid, true, 'Investor principal', 'INV_PRINCIPAL', $id, !$backfill) : null;
    $t2 = $ret > 0 ? cf_out($conn, 'OPERATING', 'Investor Return Paid', $ret, $date, 'Investor return / profit paid: ' . $who, $uid, false, 'Investor returns', 'INV_RETURN', $id, !$backfill) : null;
    cf_record($conn, 'INV_PAYOUT', $id, $principal + $ret, $date, $uid, ['inv_type' => $type, 'settled' => round((float) $d['contribution'], 2), 'principal' => $principal, 'interest' => $ret, 'tx' => $t1, 'tx2' => $t2, 'backfill' => $backfill]);
    return ['principal' => $principal, 'return' => $ret];
}

/** A refunded application: the verified contribution goes back from the Capital Fund. */
function cf_inv_refund(mysqli $conn, array $k, ?int $uid, string $date, bool $backfill = false): ?int
{
    cf_ensure($conn); $id = (int) $k['id'];
    if (cf_posted($conn, 'INV_REFUND', $id) || !cf_posted($conn, 'INV_CONTRIB', $id)) { return null; } // only money that was recorded as received
    $type = cf_inv_type($conn, (int) $k['project_id']); $amount = round((float) $k['amount'], 2);
    $tx = cf_out($conn, 'CAPITAL', $type === 'loan' ? 'Investor Loan Principal Repaid' : 'Investor Capital Returned', $amount, $date, 'Investor contribution refunded: ' . $k['contribution_ref'], $uid, true, 'Investor principal', 'INV_REFUND', $id, !$backfill);
    cf_record($conn, 'INV_REFUND', $id, $amount, $date, $uid, ['inv_type' => $type, 'settled' => $amount, 'principal' => $amount, 'tx' => $tx, 'backfill' => $backfill]);
    return $tx;
}

/* ================================================================== past movements and overview */

/** Money that already moved in the hubs but was never recorded in the funds (before this integration). */
function cf_pending(mysqli $conn): array
{
    cf_ensure($conn); $out = [];
    $has = function ($t) use ($conn) { return (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '$t'"))[0] > 0; };
    if ($has('cl_applications')) {
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT a.* FROM cl_applications a WHERE a.status IN ('" . implode("','", CF_CL_LIVE) . "') AND a.approved_amount > 0
            AND NOT EXISTS (SELECT 1 FROM cf_postings p WHERE p.kind = 'CL_DISBURSE' AND p.ref_id = a.id) ORDER BY a.id"), MYSQLI_ASSOC) as $a) {
            $out[] = ['kind' => 'CL_DISBURSE', 'row' => $a, 'date' => substr($a['disbursed_at'] ?: $a['updated_at'] ?: $a['created_at'], 0, 10), 'amount' => (float) $a['approved_amount'], 'label' => 'Customer loan disbursed #' . (int) $a['id']]; }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT p.* FROM cl_payments p WHERE p.status = 'Verified' AND NOT EXISTS (SELECT 1 FROM cf_postings c WHERE c.kind = 'CL_PAYMENT' AND c.ref_id = p.id) ORDER BY p.id"), MYSQLI_ASSOC) as $p) {
            $out[] = ['kind' => 'CL_PAYMENT', 'row' => $p, 'date' => substr($p['paid_on'] ?: $p['verified_at'] ?: $p['created_at'], 0, 10), 'amount' => (float) $p['amount'], 'label' => 'Customer loan repayment #' . (int) $p['id']]; }
    }
    if ($has('inv_contributions')) {
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT k.* FROM inv_contributions k WHERE k.status IN ('Verified', 'Refunded') AND NOT EXISTS (SELECT 1 FROM cf_postings c WHERE c.kind = 'INV_CONTRIB' AND c.ref_id = k.id) ORDER BY k.id"), MYSQLI_ASSOC) as $k) {
            $out[] = ['kind' => 'INV_CONTRIB', 'row' => $k, 'date' => substr($k['verified_at'] ?: $k['paid_on'] ?: $k['created_at'], 0, 10), 'amount' => (float) $k['amount'], 'label' => 'Investor contribution ' . $k['contribution_ref']]; }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT d.* FROM inv_distributions d WHERE d.status = 'Paid' AND NOT EXISTS (SELECT 1 FROM cf_postings c WHERE c.kind = 'INV_PAYOUT' AND c.ref_id = d.id) ORDER BY d.id"), MYSQLI_ASSOC) as $d) {
            $out[] = ['kind' => 'INV_PAYOUT', 'row' => $d, 'date' => substr($d['paid_at'] ?: $d['created_at'], 0, 10), 'amount' => (float) $d['total_payout'], 'label' => 'Investor payout #' . (int) $d['id']]; }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT k.* FROM inv_contributions k WHERE k.status = 'Refunded' AND NOT EXISTS (SELECT 1 FROM cf_postings c WHERE c.kind = 'INV_REFUND' AND c.ref_id = k.id) ORDER BY k.id"), MYSQLI_ASSOC) as $k) {
            $out[] = ['kind' => 'INV_REFUND', 'row' => $k, 'date' => substr($k['verified_at'] ?: $k['created_at'], 0, 10), 'amount' => (float) $k['amount'], 'label' => 'Investor refund ' . $k['contribution_ref']]; }
    }
    usort($out, function ($a, $b) { $o = ['INV_CONTRIB' => 0, 'CL_DISBURSE' => 1, 'CL_PAYMENT' => 2, 'INV_PAYOUT' => 3, 'INV_REFUND' => 4]; return [$a['date'], $o[$a['kind']]] <=> [$b['date'], $o[$b['kind']]]; });
    return $out;
}

/** What recording the past movements would do to each fund: [code => [name, now, change, after]]. */
function cf_pending_effect(mysqli $conn, array $items): array
{
    $chg = [];
    $add = function ($code, $v) use (&$chg) { $chg[$code] = ($chg[$code] ?? 0) + $v; };
    foreach ($items as $it) {
        $r = $it['row'];
        switch ($it['kind']) {
            case 'INV_CONTRIB': $add('CAPITAL', (float) $r['amount']); break;
            case 'CL_DISBURSE': $add('CAPITAL', -(float) $r['approved_amount']); break;
            case 'CL_PAYMENT':
                $sp = cf_cl_splits($conn, (int) $r['application_id'])[(int) $r['id']] ?? ['principal' => 0, 'interest' => 0];
                $add('CAPITAL', $sp['principal']);
                foreach (build_allocation(funds_all($conn), $sp['interest']) as $row) { $add($row['fund']['code'], $row['amount']); }
                break;
            case 'INV_PAYOUT': $add('CAPITAL', -(float) $r['capital_returned']); $add('OPERATING', -(float) $r['profit_share']); break;
            default: $add('CAPITAL', -(float) $r['amount']);
        }
    }
    $out = [];
    foreach (funds_all($conn) as $f) {
        if (!isset($chg[$f['code']])) { continue; }
        $now = round(fund_cash_balance($conn, (int) $f['id']), 2);
        $out[$f['code']] = ['name' => $f['name'], 'now' => $now, 'change' => round($chg[$f['code']], 2), 'after' => round($now + $chg[$f['code']], 2)];
    }
    return $out;
}

/** Records the past movements, in date order, each once, in one database transaction. */
function cf_record_pending(mysqli $conn, int $uid): int
{
    $items = cf_pending($conn); $n = 0;
    mysqli_begin_transaction($conn);
    try {
        foreach ($items as $it) {
            $r = $it['row']; $d = $it['date'];
            switch ($it['kind']) {
                case 'CL_DISBURSE': $x = cf_cl_disburse($conn, $r, $uid, $d, true); break;
                case 'CL_PAYMENT': $x = cf_cl_repayment($conn, (int) $r['application_id'], (int) $r['id'], $uid, $d, true); break;
                case 'INV_CONTRIB': $x = cf_inv_contribution($conn, $r, $uid, $d, true); break;
                case 'INV_PAYOUT': $x = cf_inv_payout($conn, $r, $uid, $d, true); break;
                default: $x = cf_inv_refund($conn, $r, $uid, $d, true);
            }
            if ($x !== null) { $n++; }
        }
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
    return $n;
}

/** Visibility (spec D): capital, customer lending and investor capital, from the recorded money. */
function cf_overview(mysqli $conn): array
{
    cf_ensure($conn);
    $cap = fund_by_code($conn, 'CAPITAL'); $cid = $cap ? (int) $cap['id'] : 0;
    $sum = function ($sql) use ($conn) { return round((float) mysqli_fetch_row(mysqli_query($conn, $sql))[0], 2); };
    $disbursed = $sum("SELECT COALESCE(SUM(amount), 0) FROM cf_postings WHERE kind = 'CL_DISBURSE'");
    $recovered = $sum("SELECT COALESCE(SUM(principal), 0) FROM cf_postings WHERE kind = 'CL_PAYMENT'");
    $interest = $sum("SELECT COALESCE(SUM(interest), 0) FROM cf_postings WHERE kind = 'CL_PAYMENT'");
    $in = []; $owed = [];
    foreach (['loan', 'capital'] as $t) {
        $in[$t] = $sum("SELECT COALESCE(SUM(amount), 0) FROM cf_postings WHERE kind = 'INV_CONTRIB' AND inv_type = '$t'");
        $owed[$t] = max(0, round($in[$t] - $sum("SELECT COALESCE(SUM(settled), 0) FROM cf_postings WHERE kind IN ('INV_PAYOUT', 'INV_REFUND') AND inv_type = '$t'"), 2));
    }
    $returnsDue = 0.0;
    if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'inv_distributions'"))[0]) {
        $returnsDue = $sum("SELECT COALESCE(SUM(profit_share), 0) FROM inv_distributions WHERE status = 'Approved'");
    }
    return ['capital_balance' => $cid ? round(fund_cash_balance($conn, $cid), 2) : 0, 'capital_available' => $cid ? round(fund_available($conn, $cid), 2) : 0,
            'disbursed' => $disbursed, 'outstanding' => max(0, round($disbursed - $recovered, 2)), 'recovered' => $recovered, 'interest' => $interest,
            'inv_loan_in' => $in['loan'], 'inv_capital_in' => $in['capital'], 'inv_loan_owed' => $owed['loan'], 'inv_capital_owed' => $owed['capital'],
            'returns_due' => round($returnsDue, 2), 'pending' => count(cf_pending($conn))];
}