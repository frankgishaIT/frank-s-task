<?php
/**
 * NEW FILE: includes/loan_intel.php — Company Loans & Investment Intelligence: DATA (tables, settings, audit,
 * the company's financial position from existing MY MOTIVE records, financing needs, loan performance).
 *
 * COMPANY LOANS ONLY (money RISE MOTIVE borrows = loans PAYABLE, tables loans / loan_schedule / loan_payments).
 * RM Credit Hub (money lent TO customers = loans RECEIVABLE) is never read as borrowing and never changed here.
 *
 * Figures come from the existing calculations (no second ledger, no second forecasting engine):
 *  - Profit & Loss per month: fin_pnl() (Financial Reports)      - cash today: fin_position()
 *  - forecasts, stock demand, cash flow: forecast_helpers.php     - funds & commitments: fund_helpers / budget_helpers
 */
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/loan_intel_engine.php';
require_once __DIR__ . '/fund_helpers.php';
require_once __DIR__ . '/financial_report_queries.php';
require_once __DIR__ . '/forecast_helpers.php';
require_once __DIR__ . '/branch_helpers.php';

const LI_SCHEMA = '1';
const LI_CATEGORIES = ['Inventory / stock', 'Equipment & machinery', 'Vehicles', 'Digital infrastructure', 'Service expansion', 'Branch expansion',
                       'Premises & renovation', 'Working capital', 'Marketing & growth', 'Other'];
const LI_PROPOSAL_STATUSES = ['Draft', 'Submitted', 'Approved', 'Rejected', 'Funded', 'Completed', 'Cancelled'];
const LI_DECISIONS = ['BORROW' => ['BORROW', '#0FA968'], 'LESS' => ['BORROW LESS', '#1E2FE0'], 'INTERNAL' => ['USE INTERNAL FUNDS', '#0E7C66'],
                      'POSTPONE' => ['POSTPONE', '#B7791F'], 'DO_NOT' => ['DO NOT BORROW', '#E24B4A'], 'MORE_DATA' => ['MORE DATA REQUIRED', '#6B7280']];
const LI_DEFAULTS = [
    'min_dscr' => '1.25',          // base case, every 12-month window
    'min_dscr_stress' => '1.00',   // pessimistic case
    'max_debt_share' => '60',      // debt service may use at most this % of CFADS (shown as Debt-to-Cash-Flow)
    'reserve_months' => '1',       // months of operating costs kept as a cash reserve
    'discount_rate' => '15',       // % a year, for NPV
    'min_roi' => '20',             // % over the evaluation horizon
    'max_payback' => '24',         // months
    'min_history' => '6',          // months with sales before a repayment analysis is trusted
    'history_window' => '12',      // months of history read
    'min_margin' => '15',          // % gross margin for "healthy margin" products
    'horizon' => '24',             // default evaluation horizon (months)
    'investable_funds' => 'CAPITAL,FUTURE_PLANS',
    'weights' => '{"roi":25,"payback":15,"cash_flow":15,"risk":15,"evidence":15,"confidence":10,"npv":5}',
    'scenarios' => '',
];

/* ------------------------------------------------------------------ schema, settings, audit */

function li_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $v = null;
    try { $v = mysqli_fetch_row(mysqli_query($conn, "SELECT value FROM li_settings WHERE name = 'schema'"))[0] ?? null; } catch (Throwable $e) { $v = null; }
    if ($v === LI_SCHEMA) { return; }
    $q = function (string $sql) use ($conn) { mysqli_query($conn, $sql); };
    $q('CREATE TABLE IF NOT EXISTS li_settings (name VARCHAR(40) NOT NULL, value TEXT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS li_audit (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NULL, action VARCHAR(40) NOT NULL, ref_type VARCHAR(20) NULL,
        ref_id INT(11) NULL, prev_json TEXT NULL, new_json TEXT NULL, reason VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY ref (ref_type, ref_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS li_loan_details (loan_id INT(11) NOT NULL, loan_product VARCHAR(100) NULL, rate_type VARCHAR(10) NOT NULL DEFAULT \'Fixed\',
        grace_periods INT(11) NOT NULL DEFAULT 0, processing_fee DECIMAL(16,2) NOT NULL DEFAULT 0, other_charges DECIMAL(16,2) NOT NULL DEFAULT 0,
        fees_transaction_id INT(11) NULL, approved_amount DECIMAL(16,2) NULL, received_amount DECIMAL(16,2) NULL, purpose_category VARCHAR(40) NULL,
        department_id INT(11) NULL, reference_no VARCHAR(60) NULL, lender_contact VARCHAR(150) NULL, updated_by INT(11) NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, PRIMARY KEY (loan_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS li_proposals (id INT(11) NOT NULL AUTO_INCREMENT, code VARCHAR(20) NULL, name VARCHAR(150) NOT NULL, category VARCHAR(40) NOT NULL,
        justification TEXT NULL, branch_id INT(11) NULL, department_id INT(11) NULL, investment_cost DECIMAL(16,2) NOT NULL DEFAULT 0,
        working_capital DECIMAL(16,2) NOT NULL DEFAULT 0, impl_months INT(11) NOT NULL DEFAULT 0, horizon_months INT(11) NOT NULL DEFAULT 24,
        revenue DECIMAL(16,2) NOT NULL DEFAULT 0, cost_of_sales_pct DECIMAL(6,2) NOT NULL DEFAULT 0, opex DECIMAL(16,2) NOT NULL DEFAULT 0,
        ramp_months INT(11) NOT NULL DEFAULT 0, risk_level VARCHAR(10) NOT NULL DEFAULT \'Medium\', risks TEXT NULL, assumptions TEXT NULL,
        demand_evidence TEXT NULL, evidence_level VARCHAR(12) NOT NULL DEFAULT \'assumption\', source VARCHAR(10) NOT NULL DEFAULT \'manual\',
        detected_key VARCHAR(60) NULL, product_id INT(11) NULL, measure VARCHAR(10) NOT NULL DEFAULT \'manual\', status VARCHAR(12) NOT NULL DEFAULT \'Draft\',
        approved_funding DECIMAL(16,2) NULL, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, submitted_at DATETIME NULL,
        decided_by INT(11) NULL, decided_at DATETIME NULL, decision_note VARCHAR(500) NULL, started_on DATE NULL,
        PRIMARY KEY (id), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS li_allocations (id INT(11) NOT NULL AUTO_INCREMENT, loan_id INT(11) NOT NULL, proposal_id INT(11) NOT NULL,
        amount DECIMAL(16,2) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT \'Proposed\', note VARCHAR(500) NULL, created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_by INT(11) NULL, decided_at DATETIME NULL,
        PRIMARY KEY (id), KEY loan (loan_id), KEY proposal (proposal_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS li_investments (id INT(11) NOT NULL AUTO_INCREMENT, allocation_id INT(11) NOT NULL, amount DECIMAL(16,2) NOT NULL,
        invest_date DATE NOT NULL, description VARCHAR(255) NOT NULL, reference VARCHAR(100) NULL, recorded_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY alloc (allocation_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS li_actuals (id INT(11) NOT NULL AUTO_INCREMENT, proposal_id INT(11) NOT NULL, period DATE NOT NULL,
        revenue DECIMAL(16,2) NOT NULL DEFAULT 0, cost_of_sales DECIMAL(16,2) NOT NULL DEFAULT 0, opex DECIMAL(16,2) NOT NULL DEFAULT 0,
        note VARCHAR(500) NULL, recorded_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY one (proposal_id, period)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS li_scenarios (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(120) NOT NULL, proposal_id INT(11) NULL, lender VARCHAR(150) NULL,
        principal DECIMAL(16,2) NOT NULL, rate DECIMAL(7,3) NOT NULL, rate_type VARCHAR(10) NOT NULL DEFAULT \'Fixed\', n INT(11) NOT NULL,
        freq VARCHAR(10) NOT NULL DEFAULT \'Monthly\', grace INT(11) NOT NULL DEFAULT 0, structure VARCHAR(10) NOT NULL DEFAULT \'emi\',
        fees DECIMAL(16,2) NOT NULL DEFAULT 0, other DECIMAL(16,2) NOT NULL DEFAULT 0, created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS li_recommendations (id INT(11) NOT NULL AUTO_INCREMENT, decision VARCHAR(10) NOT NULL, amount DECIMAL(16,2) NOT NULL DEFAULT 0,
        proposal_id INT(11) NULL, scenario_id INT(11) NULL, snapshot MEDIUMTEXT NOT NULL, status VARCHAR(16) NOT NULL DEFAULT \'Awaiting review\',
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, reviewed_by INT(11) NULL, reviewed_at DATETIME NULL,
        review_note VARCHAR(500) NULL, PRIMARY KEY (id), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS li_cache (k VARCHAR(60) NOT NULL, v MEDIUMTEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (k)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q("INSERT INTO li_settings (name, value) VALUES ('schema', '" . LI_SCHEMA . "') ON DUPLICATE KEY UPDATE value = VALUES(value)");
}

function li_setting(mysqli $conn, string $k): string
{
    static $c = null;
    if ($c === null) {
        $c = [];
        try { foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT name, value FROM li_settings'), MYSQLI_ASSOC) as $r) { $c[$r['name']] = (string) $r['value']; } } catch (Throwable $e) { }
    }
    return ($c[$k] ?? '') !== '' ? $c[$k] : (LI_DEFAULTS[$k] ?? '');
}

function li_setting_set(mysqli $conn, string $k, string $v): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO li_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    mysqli_stmt_bind_param($s, 'ss', $k, $v);
    mysqli_stmt_execute($s);
}

function li_num(mysqli $conn, string $k): float { return (float) li_setting($conn, $k); }

function li_weights(mysqli $conn): array
{
    $w = json_decode(li_setting($conn, 'weights'), true);
    return is_array($w) ? $w + json_decode(LI_DEFAULTS['weights'], true) : json_decode(LI_DEFAULTS['weights'], true);
}

function li_scenarios(mysqli $conn): array
{
    $out = li_default_scenarios();
    $saved = json_decode(li_setting($conn, 'scenarios'), true);
    if (is_array($saved)) { foreach ($out as $k => $v) { foreach ($v as $f => $x) { if ($f !== 'label' && isset($saved[$k][$f])) { $out[$k][$f] = (float) $saved[$k][$f]; } } } }
    return $out;
}

/** Who did what, previous and new values, and why. */
function li_audit(mysqli $conn, string $action, ?string $refType, ?int $refId, $prev = null, $new = null, ?string $reason = null): void
{
    try {
        $uid = function_exists('current_user_id') ? (current_user_id() ?: null) : null;
        $p = $prev === null ? null : json_encode($prev, JSON_UNESCAPED_UNICODE);
        $n = $new === null ? null : json_encode($new, JSON_UNESCAPED_UNICODE);
        $reason = $reason !== null ? mb_substr($reason, 0, 500) : null;
        $s = mysqli_prepare($conn, 'INSERT INTO li_audit (user_id, action, ref_type, ref_id, prev_json, new_json, reason) VALUES (?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'ississs', $uid, $action, $refType, $refId, $p, $n, $reason);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) { error_log('li_audit: ' . $e->getMessage()); }
}

/** Values that changed between two rows (for the audit trail). */
function li_diff(array $before, array $after): array
{
    $p = []; $n = [];
    foreach ($after as $k => $v) { if (array_key_exists($k, $before) && (string) $before[$k] !== (string) $v) { $p[$k] = $before[$k]; $n[$k] = $v; } }
    return [$p, $n];
}

/* ------------------------------------------------------------------ permissions */

/** view, propose, finance (register loans, payments, actuals), approve, configure. Managers keep their branch limits. */
function li_can(mysqli $conn, string $action): bool
{
    return access_can('loan_intel.' . $action, $conn);
}

/** Proposal visible to the user: company-wide users all; others their branches, or their own proposals. */
function li_proposal_visible(mysqli $conn, array $p): bool
{
    $a = br_enabled($conn) ? br_allowed_ids($conn) : null;
    if ($a === null) { return true; }
    if ((int) $p['created_by'] === (int) current_user_id()) { return true; }
    return in_array((int) ($p['branch_id'] ?: br_main_id($conn)), $a, true);
}

function li_proposal_scope_sql(mysqli $conn, string $alias = 'p'): string
{
    if (!br_enabled($conn)) { return '1=1'; }
    $a = br_allowed_ids($conn);
    if ($a === null) { return '1=1'; }
    $main = br_main_id($conn);
    return "(COALESCE($alias.branch_id, $main) IN (" . implode(',', array_map('intval', $a ?: [0])) . ") OR $alias.created_by = " . (int) current_user_id() . ')';
}

/**
 * Company loans the user may see. Loans are COMPANY liabilities: company-wide users see all of them whatever branch is
 * selected in the top bar; a branch manager sees the loans recorded for his branch(es). (Filter by branch on the page.)
 */
function li_loan_scope_sql(mysqli $conn, string $col = 'l.branch_id'): string
{
    if (!br_enabled($conn)) { return '1=1'; }
    $a = br_allowed_ids($conn);
    return $a === null ? '1=1' : 'COALESCE(' . $col . ', ' . br_main_id($conn) . ') IN (' . implode(',', array_map('intval', $a ?: [0])) . ')';
}

/* ------------------------------------------------------------------ the company's position (actual data) */

function li_cache_get(mysqli $conn, string $k, int $ttl)
{
    try {
        $r = mysqli_fetch_row(mysqli_query($conn, "SELECT v FROM li_cache WHERE k = '" . mysqli_real_escape_string($conn, $k) . "' AND created_at >= '" . date('Y-m-d H:i:s', time() - $ttl) . "'"));
        return $r ? json_decode($r[0], true) : null;
    } catch (Throwable $e) { return null; }
}

function li_cache_put(mysqli $conn, string $k, $v): void
{
    try {
        $j = json_encode($v);
        $now = date('Y-m-d H:i:s'); // PHP time on both sides (the database clock may use another time zone)
        $s = mysqli_prepare($conn, 'INSERT INTO li_cache (k, v, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), created_at = VALUES(created_at)');
        mysqli_stmt_bind_param($s, 'sss', $k, $j, $now);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) { }
}

function li_cache_clear(mysqli $conn): void { try { mysqli_query($conn, 'DELETE FROM li_cache'); } catch (Throwable $e) { } }

/** Profit & Loss figures for each COMPLETED month of the history window (oldest first). Cached 30 minutes. */
function li_monthly_history(mysqli $conn): array
{
    $win = max(3, min(36, (int) li_num($conn, 'history_window')));
    $key = 'hist_' . $win . '_' . date('Y-m');
    if (($c = li_cache_get($conn, $key, 1800)) !== null) { return $c; }
    $out = [];
    for ($i = $win; $i >= 1; $i--) {
        $s = date('Y-m-01', strtotime("first day of -$i month"));
        $e = date('Y-m-t', strtotime($s));
        $p = fin_pnl($conn, $s, $e);
        $nonCash = (float) ($p['opex']['Depreciation and amortization of assets'] ?? 0) + (float) ($p['opex']['Impairment and revaluation losses'] ?? 0)
                 + (float) ($p['opex']['Loss on assets removed'] ?? 0);
        $out[] = ['month' => substr($s, 0, 7), 'revenue' => $p['revenue'], 'gross' => $p['gross'], 'other' => $p['other_total'], 'opex' => $p['opex_total'],
                  'non_cash' => round($nonCash, 2), 'operating' => $p['operating'], 'interest' => $p['interest'], 'net' => $p['net'],
                  'cfads' => round($p['operating'] + $nonCash, 2)];
    }
    li_cache_put($conn, $key, $out);
    return $out;
}

/** Existing company-loan debt service still to pay, per month from now (1 = this month; overdue goes to month 1). */
function li_existing_debt(mysqli $conn, int $months = 120): array
{
    $out = array_fill(1, $months, 0.0);
    $res = mysqli_query($conn, "SELECT ls.due_date, ls.amount_due - COALESCE(ls.paid_amount, 0) v FROM loan_schedule ls JOIN loans l ON l.id = ls.loan_id
        WHERE l.status IN ('Active', 'Defaulted') AND ls.status <> 'Paid'");
    $now = (int) date('Y') * 12 + (int) date('n');
    foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $r) {
        $t = strtotime($r['due_date']);
        $m = max(1, (int) date('Y', $t) * 12 + (int) date('n', $t) - $now + 1);
        if ($m <= $months) { $out[$m] += (float) $r['v']; }
    }
    return $out;
}

/** Debt service actually paid per calendar month (YYYY-MM => amount), company loans only. */
function li_debt_paid_by_month(mysqli $conn): array
{
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT DATE_FORMAT(p.payment_date, '%Y-%m') ym, SUM(p.amount) v FROM loan_payments p JOIN loans l ON l.id = p.loan_id
        WHERE l.status <> 'Deleted' GROUP BY ym"), MYSQLI_ASSOC) as $r) { $o[$r['ym']] = (float) $r['v']; }
    return $o;
}

/** Data completeness and validation checks (each: label, value, ok, severity). */
function li_data_checks(mysqli $conn, array $hist, int $minHistory): array
{
    $v = function (string $sql) use ($conn) { try { return (float) mysqli_fetch_row(mysqli_query($conn, $sql))[0]; } catch (Throwable $e) { return null; } };
    $months = count(array_filter($hist, function ($h) { return $h['revenue'] > 0; }));
    $c = [];
    $c[] = ['Months with recorded sales (of the last ' . count($hist) . ')', $months, $months >= $minHistory, 'critical'];
    $n = $v("SELECT COUNT(*) FROM products WHERE item_type = 'Item' AND is_active = 1 AND COALESCE(buying_price, 0) <= 0");
    if ($n !== null) { $c[] = ['Active products without a buying price (margins unreliable)', (int) $n, $n == 0, 'warning']; }
    $n = $v("SELECT COUNT(*) FROM transactions WHERE status = 'pending'");
    if ($n !== null) { $c[] = ['Transactions still waiting for approval (not counted)', (int) $n, $n == 0, 'info']; }
    $n = $v("SELECT COUNT(*) FROM transactions WHERE transaction_type = 'Expense' AND status = 'approved' AND is_automatic = 0 AND COALESCE(expense_category, '') = '' AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)");
    if ($n !== null) { $c[] = ['Expenses without a category in the last 12 months', (int) $n, $n == 0, 'warning']; }
    $n = $v("SELECT COUNT(*) FROM loans l WHERE l.status IN ('Active', 'Defaulted') AND NOT EXISTS (SELECT 1 FROM loan_schedule s WHERE s.loan_id = l.id)");
    if ($n !== null) { $c[] = ['Active company loans without a repayment schedule', (int) $n, $n == 0, 'critical']; }
    $n = $v("SELECT COUNT(*) FROM payroll WHERE pay_period >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)");
    if ($n !== null) { $c[] = ['Payroll recorded in the last 3 months', (int) $n, $n > 0, 'warning']; }
    return $c;
}

/**
 * The company's financial position for borrowing decisions (company-wide: loans are company liabilities).
 * Every figure is labelled ACTUAL (recorded), ESTIMATED (calculated from records) or FORECAST.
 */
function li_company(mysqli $conn): array
{
    static $memo = null;
    if ($memo !== null) { return $memo; }
    $hist = li_monthly_history($conn);
    $minH = (int) li_num($conn, 'min_history');
    $active = array_values(array_filter($hist, function ($h) { return $h['revenue'] > 0; }));
    $recent = array_slice($active, -6);
    $avg = function (array $rows, string $k) { return $rows ? array_sum(array_column($rows, $k)) / count($rows) : 0.0; };
    $cfads = $avg($recent, 'cfads'); $revenue = $avg($recent, 'revenue'); $gross = $avg($recent, 'gross');
    $opexCash = $avg($recent, 'opex') - $avg($recent, 'non_cash');
    $vals = array_column($recent, 'cfads');
    $sd = 0.0; if (count($vals) > 1) { $m = array_sum($vals) / count($vals); foreach ($vals as $x) { $sd += ($x - $m) ** 2; } $sd = sqrt($sd / (count($vals) - 1)); }
    $cv = $cfads > 0 ? $sd / $cfads : null;
    $lowest = $active ? min(array_column($active, 'cfads')) : 0.0;

    // Cash, funds, commitments
    $cash = null;
    try { $cash = (float) fin_position($conn, date('Y-m-d'))['cash']; } catch (Throwable $e) { error_log('li cash: ' . $e->getMessage()); }
    $investable = array_map('trim', explode(',', li_setting($conn, 'investable_funds')));
    $funds = []; $internalFunds = 0.0;
    try {
        require_once __DIR__ . '/budget_helpers.php';
        foreach (funds_all($conn) as $f) {
            $avail = fund_available($conn, (int) $f['id']);
            $committed = bdg_committed_total($conn, (int) $f['id']);
            $free = max(0, $avail - $committed);
            $inv = in_array($f['code'], $investable, true);
            $funds[] = ['name' => $f['name'], 'code' => $f['code'], 'available' => round($avail, 2), 'committed' => round($committed, 2), 'free' => round($free, 2), 'investable' => $inv];
            if ($inv) { $internalFunds += $free; }
        }
    } catch (Throwable $e) { error_log('li funds: ' . $e->getMessage()); }

    $existing = li_existing_debt($conn);
    $next12 = array_sum(array_slice($existing, 0, 12, true));
    $next3 = array_sum(array_slice($existing, 0, 3, true));
    $reserve = max(0, li_num($conn, 'reserve_months') * max(0, $revenue - $cfads));
    $freeCash = $cash === null ? null : max(0, $cash - $reserve - $next3);
    $internal = $freeCash === null ? $internalFunds : min($internalFunds, $freeCash);

    // Historical DSCR (last 12 completed months): CFADS / debt service actually paid in the same months
    $paid = li_debt_paid_by_month($conn);
    $hC = 0.0; $hD = 0.0;
    foreach (array_slice($hist, -12) as $h) { $hC += $h['cfads']; $hD += $paid[$h['month']] ?? 0; }
    $loans = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(loan_amount), 0) borrowed,
        COALESCE(SUM(CASE WHEN status IN ('Active', 'Defaulted') THEN GREATEST(0, loan_amount - principal_repaid) ELSE 0 END), 0) outstanding,
        COALESCE(SUM(interest_paid), 0) interest_paid, COALESCE(SUM(principal_repaid), 0) principal_repaid,
        SUM(status IN ('Active', 'Defaulted')) open_loans FROM loans WHERE status NOT IN ('Cancelled', 'Deleted')"));
    $fees = 0.0;
    try { $fees = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(d.processing_fee + d.other_charges), 0) FROM li_loan_details d JOIN loans l ON l.id = d.loan_id WHERE l.status NOT IN ('Cancelled', 'Deleted')"))[0]; } catch (Throwable $e) { }
    $overdue = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) n, COALESCE(SUM(ls.amount_due - ls.paid_amount), 0) v FROM loan_schedule ls JOIN loans l ON l.id = ls.loan_id
        WHERE l.status IN ('Active', 'Defaulted') AND ls.status <> 'Paid' AND ls.due_date < CURDATE()"));
    $up30 = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(ls.amount_due - ls.paid_amount), 0) FROM loan_schedule ls JOIN loans l ON l.id = ls.loan_id
        WHERE l.status IN ('Active', 'Defaulted') AND ls.status <> 'Paid' AND ls.due_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)"))[0];

    // Forecast (existing RM Business Forecasting engine): revenue trend, monthly
    $trend = null; $fcRev = null;
    try { $fcRev = fc_project(fc_history($conn, 'revenue', 'month'), 'month', 6); if (!empty($fcRev['ok'])) { $trend = $fcRev['slope_pct']; } } catch (Throwable $e) { }

    $checks = li_data_checks($conn, $hist, $minH);
    $critical = count(array_filter($checks, function ($c) { return !$c[2] && $c[3] === 'critical'; }));
    $warnings = count(array_filter($checks, function ($c) { return !$c[2] && $c[3] === 'warning'; }));
    $confidence = (count($active) >= 12 && $critical === 0 && $cv !== null && $cv < 0.35 && $warnings <= 1) ? 'High'
        : ((count($active) >= $minH && $critical === 0) ? 'Medium' : 'Low');

    return $memo = [
        'history' => $hist, 'active_months' => count($active), 'enough_history' => count($active) >= $minH, 'min_history' => $minH,
        'cfads' => round($cfads, 2), 'revenue' => round($revenue, 2), 'margin' => $revenue > 0 ? round($gross / $revenue, 4) : 0.0,
        'opex_cash' => round($opexCash, 2), 'cfads_cv' => $cv, 'cfads_lowest' => round($lowest, 2),
        'cash' => $cash, 'reserve' => round($reserve, 2), 'free_cash' => $freeCash, 'funds' => $funds, 'internal_funds' => round($internalFunds, 2),
        'internal' => round(max(0, $internal), 2),
        'existing' => $existing, 'debt_next12' => round($next12, 2), 'debt_month' => round($next12 / 12, 2), 'debt_next3' => round($next3, 2), 'due_30' => round($up30, 2),
        'dscr_hist' => $hD > 0 ? round($hC / $hD, 2) : null, 'dscr_forward' => $next12 > 0 ? round($cfads * 12 / $next12, 2) : null,
        'debt_share' => $cfads > 0 ? round($next12 / 12 / $cfads * 100, 1) : null,
        'borrowed' => (float) $loans['borrowed'], 'outstanding' => (float) $loans['outstanding'], 'interest_paid' => (float) $loans['interest_paid'],
        'principal_repaid' => (float) $loans['principal_repaid'], 'open_loans' => (int) $loans['open_loans'], 'fees' => $fees,
        'overdue_n' => (int) $overdue['n'], 'overdue_v' => (float) $overdue['v'],
        'trend' => $trend, 'forecast' => $fcRev, 'checks' => $checks, 'confidence' => $confidence,
    ];
}

/** Monthly figures used by the simulator (base case). */
function li_sim_base(array $co): array
{
    return ['cfads' => $co['cfads'], 'revenue' => $co['revenue'], 'margin' => $co['margin'], 'opex' => $co['opex_cash'],
            'cash' => (float) ($co['cash'] ?? 0), 'reserve' => $co['reserve'], 'existing' => $co['existing']];
}

/* ------------------------------------------------------------------ financing needs (detected from records) */

/**
 * Situations found in the records. Each need: key, type, title, need, evidence[], funding (null = unknown), internal, gap, months,
 * add_revenue, add_opex, add_profit, cash_impact, roi, class, basis ('Actual' / 'Estimated' / 'Forecast'), proposal (prefill).
 */
function li_needs(mysqli $conn, array $co): array
{
    $needs = [];
    $internalLeft = $co['internal'];
    $minMargin = li_num($conn, 'min_margin') / 100;
    $classify = function (?float $funding, ?float $profit, bool $temporary = false) use (&$internalLeft) {
        if ($temporary) { return ['Temporary cash flow shortage', 0.0]; }
        if ($funding === null || $profit === null) { return ['Potential investment opportunity', 0.0]; }
        $use = min($internalLeft, $funding);
        if ($funding <= $internalLeft) { $internalLeft -= $use; return ['Unnecessary borrowing — internal funds cover it', $use]; }
        $internalLeft -= $use;
        return [$profit > 0 ? 'Genuine financing need' : 'Unnecessary borrowing — no return', $use];
    };

    // 1. Products with demand that run out (stock), healthy margin
    try {
        $inv = fc_inventory($conn);
        $ids = array_map(function ($r) { return (int) $r['id']; }, $inv);
        $price = [];
        if ($ids) { foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, selling_price FROM products WHERE id IN (' . implode(',', $ids) . ')'), MYSQLI_ASSOC) as $r) { $price[(int) $r['id']] = (float) $r['selling_price']; } }
        $items = []; $fund = 0.0; $rev = 0.0; $gp = 0.0;
        foreach ($inv as $r) {
            if (!in_array($r['status'], ['Out of stock', 'Will run out soon'], true) || $r['daily'] <= 0 || $r['recommend'] <= 0) { continue; }
            $sp = $price[(int) $r['id']] ?? 0; $bp = (float) $r['buying_price'];
            if ($sp <= 0 || $bp <= 0) { continue; }
            $mg = ($sp - $bp) / $sp;
            if ($mg < $minMargin) { continue; }
            $lostDays = $r['status'] === 'Out of stock' ? 30 : max(0, 30 - (int) $r['days_left'] - FC_LEAD_DAYS);
            $lost = $r['daily'] * $lostDays * $sp;
            $items[] = [$r['product_name'], $r['status'], (float) $r['quantity'], $r['daily'], $r['recommend'], $r['recommend_cost'], round($mg * 100, 1), round($lost, 0)];
            $fund += $r['recommend_cost']; $rev += $lost; $gp += $lost * $mg;
        }
        if ($items) {
            $months = round((FC_LEAD_DAYS + 30) / 30, 1);
            [$class, $use] = $classify($fund, $gp);
            $needs[] = ['key' => 'stock', 'type' => 'Inventory', 'title' => 'High-demand products running out of stock',
                'need' => count($items) . ' product(s) with healthy margins (>= ' . (int) ($minMargin * 100) . '%) are out of stock or will run out before a new delivery arrives.',
                'evidence_head' => ['Product', 'Status', 'In stock', 'Sold per day', 'Units to buy', 'Cost (RWF)', 'Margin %', 'Sales lost per month (RWF)'], 'evidence' => $items,
                'funding' => round($fund, 2), 'internal' => $use, 'gap' => round(max(0, $fund - $use), 2), 'months' => $months,
                'add_revenue' => round($rev, 2), 'add_opex' => 0.0, 'add_profit' => round($gp, 2), 'cash_impact' => round($gp, 2),
                'roi' => $fund > 0 ? round($gp / $fund * 100, 1) : null, 'class' => $class, 'basis' => 'Estimated from the last 90 days of sales and the recorded prices',
                'proposal' => ['name' => 'Restock high-demand products', 'category' => 'Inventory / stock', 'investment_cost' => 0, 'working_capital' => round($fund, 0),
                    'impl_months' => 1, 'revenue' => round($rev, 0), 'cost_of_sales_pct' => $rev > 0 ? round(100 - $gp / $rev * 100, 1) : 0, 'opex' => 0, 'evidence_level' => 'data',
                    'demand_evidence' => 'Detected by MY MOTIVE: ' . implode('; ', array_map(function ($i) { return $i[0] . ' (' . $i[1] . ', ' . $i[3] . '/day)'; }, array_slice($items, 0, 8)))]];
        }
    } catch (Throwable $e) { error_log('li needs stock: ' . $e->getMessage()); }

    // 2. Cash flow gap in the next 90 days (existing forecasting engine)
    try {
        $revFc = fc_project(fc_history($conn, 'revenue', 'month'), 'month', 4);
        $expFc = fc_project(fc_history($conn, 'expenses', 'month'), 'month', 4);
        $restock = fc_history($conn, 'restock', 'month');
        $restockMonth = $restock['periods'] ? array_sum(array_slice($restock['periods'], -3)) / min(3, count($restock['periods'])) : 0.0;
        $flow = fc_cash_flow(fc_cash_now($conn), $revFc, $expFc, fc_receivables($conn), fc_loan_dues($conn, 90), $restockMonth, 90);
        if ($flow['first_shortage']) {
            $end = end($flow['days']);
            $gapAmt = round(-$flow['lowest'][1] + $co['reserve'], 2);
            $temp = $end >= 0;
            [$class] = $classify($gapAmt, null, $temp);
            if (!$temp) { $class = 'Working capital shortage'; }
            $needs[] = ['key' => 'cashgap', 'type' => 'Cash flow', 'title' => $temp ? 'Temporary cash shortage expected' : 'Cash shortage expected (does not recover in 90 days)',
                'need' => 'The cash forecast goes below zero on ' . date('d M Y', strtotime($flow['first_shortage'])) . ' (lowest RWF ' . number_format($flow['lowest'][1], 0) . ' on ' . date('d M Y', strtotime($flow['lowest'][0])) . ').',
                'evidence_head' => ['Item', 'Value'], 'evidence' => [['Cash today', number_format((float) fc_cash_now($conn), 0)], ['Lowest forecast balance', number_format($flow['lowest'][1], 0)],
                    ['Balance in 90 days', number_format($end, 0)], ['Restocking per month (average of 3 months)', number_format($restockMonth, 0)]],
                'funding' => $gapAmt, 'internal' => 0.0, 'gap' => $gapAmt, 'months' => 3, 'add_revenue' => 0.0, 'add_opex' => 0.0, 'add_profit' => 0.0,
                'cash_impact' => $gapAmt, 'roi' => null, 'class' => $class, 'basis' => 'Forecast (RM Business Forecasting)',
                'proposal' => ['name' => 'Working capital for the cash gap', 'category' => 'Working capital', 'working_capital' => $gapAmt, 'impl_months' => 0, 'evidence_level' => 'data']];
        }
    } catch (Throwable $e) { error_log('li needs cash: ' . $e->getMessage()); }

    // 3. Upcoming debt repayments the cash cannot cover
    if ($co['cash'] !== null && $co['debt_next3'] > 0 && $co['debt_next3'] > $co['cash'] + 3 * max(0, $co['cfads'])) {
        $short = round($co['debt_next3'] - $co['cash'] - 3 * max(0, $co['cfads']), 2);
        $needs[] = ['key' => 'debt', 'type' => 'Debt obligations', 'title' => 'Upcoming loan repayments larger than the cash available',
            'need' => 'Instalments of RWF ' . number_format($co['debt_next3'], 0) . ' fall due in the next 3 months.',
            'evidence_head' => ['Item', 'Value'], 'evidence' => [['Due in 3 months', number_format($co['debt_next3'], 0)], ['Cash today', number_format($co['cash'], 0)], ['CFADS per month (average)', number_format($co['cfads'], 0)]],
            'funding' => $short, 'internal' => 0.0, 'gap' => $short, 'months' => 3, 'add_revenue' => 0.0, 'add_opex' => 0.0, 'add_profit' => 0.0, 'cash_impact' => -$short,
            'roi' => null, 'class' => 'Genuine financing need (refinancing — borrowing to repay debt is a warning sign)', 'basis' => 'Actual (repayment schedules)', 'proposal' => null];
    }

    // 4. Services with growing demand (RM Offerings)
    try {
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name,
            SUM(CASE WHEN s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN si.line_total ELSE 0 END) r_now,
            SUM(CASE WHEN s.sale_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN si.line_total ELSE 0 END) r_before,
            COUNT(DISTINCT CASE WHEN s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN s.id END) n_now
            FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id
            WHERE p.item_type = 'Service' AND s.status <> 'Cancelled' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
            GROUP BY p.id, p.product_name HAVING r_before > 0 AND r_now >= r_before * 1.25 ORDER BY r_now - r_before DESC LIMIT 5"), MYSQLI_ASSOC);
        if ($rows) {
            $ev = array_map(function ($r) { return [$r['product_name'], number_format($r['r_before'], 0), number_format($r['r_now'], 0), round(($r['r_now'] / $r['r_before'] - 1) * 100, 0) . '%', (int) $r['n_now']]; }, $rows);
            $needs[] = ['key' => 'services', 'type' => 'Services', 'title' => 'Services with fast-growing demand',
                'need' => 'Revenue of these services grew by 25% or more (last 90 days compared with the 90 days before). If capacity (staff, equipment) is the limit, an investment may pay.',
                'evidence_head' => ['Service', 'Revenue, previous 90 days', 'Revenue, last 90 days', 'Growth', 'Sales (90 days)'], 'evidence' => $ev,
                'funding' => null, 'internal' => 0.0, 'gap' => null, 'months' => null, 'add_revenue' => round(array_sum(array_map(function ($r) { return ($r['r_now'] - $r['r_before']) / 3; }, $rows)), 2),
                'add_opex' => null, 'add_profit' => null, 'cash_impact' => null, 'roi' => null, 'class' => 'Potential investment opportunity',
                'basis' => 'Actual (sales); the cost of extra capacity must be entered in a proposal',
                'proposal' => ['name' => 'Expand ' . $rows[0]['product_name'], 'category' => 'Service expansion', 'evidence_level' => 'partial',
                    'demand_evidence' => 'Detected by MY MOTIVE: ' . implode('; ', array_map(function ($e) { return $e[0] . ' +' . $e[3]; }, $ev))]];
        }
    } catch (Throwable $e) { error_log('li needs services: ' . $e->getMessage()); }

    // 5. Productive branches (multi-branch)
    try {
        if (br_enabled($conn) && count(br_all($conn)) > 1) {
            $now = br_figures($conn, date('Y-m-d', strtotime('-90 days')), date('Y-m-d'));
            $before = br_figures($conn, date('Y-m-d', strtotime('-180 days')), date('Y-m-d', strtotime('-91 days')));
            $ev = [];
            foreach (br_all($conn) as $id => $b) {
                if ($b['status'] !== 'Active') { continue; }
                $rn = (float) ($now[$id]['revenue'] ?? 0); $rb = (float) ($before[$id]['revenue'] ?? 0); $ex = (float) ($now[$id]['expenses'] ?? 0);
                if ($rb > 0 && $rn >= $rb * 1.2 && $rn > $ex) { $ev[] = [$b['name'], number_format($rb, 0), number_format($rn, 0), round(($rn / $rb - 1) * 100) . '%', number_format($rn - $ex, 0)]; }
            }
            if ($ev) {
                $needs[] = ['key' => 'branches', 'type' => 'Branches', 'title' => 'Productive branches growing fast',
                    'need' => 'These branches grew their revenue by 20% or more and cover their running expenses. Extra capacity there may pay back quickly.',
                    'evidence_head' => ['Branch', 'Revenue, previous 90 days', 'Revenue, last 90 days', 'Growth', 'Revenue - expenses (90 days)'], 'evidence' => $ev,
                    'funding' => null, 'internal' => 0.0, 'gap' => null, 'months' => null, 'add_revenue' => null, 'add_opex' => null, 'add_profit' => null, 'cash_impact' => null,
                    'roi' => null, 'class' => 'Potential investment opportunity', 'basis' => 'Actual (branch records)',
                    'proposal' => ['name' => 'Increase capacity of ' . $ev[0][0], 'category' => 'Branch expansion', 'evidence_level' => 'partial', 'demand_evidence' => 'Detected by MY MOTIVE: branch revenue +' . $ev[0][3]]];
            }
        }
    } catch (Throwable $e) { error_log('li needs branches: ' . $e->getMessage()); }

    // 6. Equipment needing replacement
    try {
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT asset_name, asset_type, status, acquisition_value, current_value FROM assets
            WHERE status = 'Under Maintenance' OR (status = 'Active' AND acquisition_value > 0 AND current_value <= acquisition_value * 0.1) ORDER BY acquisition_value DESC LIMIT 10"), MYSQLI_ASSOC);
        if ($rows) {
            $f = array_sum(array_column($rows, 'acquisition_value'));
            [$class, $use] = $classify($f, null);
            $needs[] = ['key' => 'equipment', 'type' => 'Equipment', 'title' => 'Equipment under maintenance or at the end of its life',
                'need' => count($rows) . ' asset(s) may need replacing. Replacement cost is estimated from the original purchase price.',
                'evidence_head' => ['Asset', 'Type', 'Status', 'Purchase price', 'Value now'],
                'evidence' => array_map(function ($r) { return [$r['asset_name'], $r['asset_type'], $r['status'], number_format($r['acquisition_value'], 0), number_format($r['current_value'], 0)]; }, $rows),
                'funding' => round($f, 2), 'internal' => $use, 'gap' => round(max(0, $f - $use), 2), 'months' => 1, 'add_revenue' => null, 'add_opex' => null, 'add_profit' => null,
                'cash_impact' => null, 'roi' => null, 'class' => $class, 'basis' => 'Estimated (purchase price of the assets)',
                'proposal' => ['name' => 'Replace ' . $rows[0]['asset_name'], 'category' => 'Equipment & machinery', 'investment_cost' => round((float) $rows[0]['acquisition_value'], 0), 'evidence_level' => 'partial']];
        }
    } catch (Throwable $e) { error_log('li needs assets: ' . $e->getMessage()); }

    // 7. Operational improvements: money tied in slow stock, costs growing faster than sales (internal sources first)
    try {
        $slow = 0.0; $ev = [];
        foreach (fc_inventory($conn) as $r) {
            if (in_array($r['status'], ['Slow / not moving', 'Overstocked'], true) && (float) $r['quantity'] > 0) {
                $excess = $r['status'] === 'Overstocked' ? max(0, (float) $r['quantity'] - $r['daily'] * 90) : (float) $r['quantity'];
                $val = $excess * (float) $r['buying_price'];
                if ($val > 0) { $slow += $val; if (count($ev) < 8) { $ev[] = [$r['product_name'], $r['status'], (float) $r['quantity'], number_format($val, 0)]; } }
            }
        }
        $h = $co['history'];
        if (count($h) >= 6) {
            $a = array_slice($h, -3); $b = array_slice($h, -6, 3);
            $ra = array_sum(array_column($a, 'revenue')); $rb = array_sum(array_column($b, 'revenue'));
            $oa = array_sum(array_column($a, 'opex')); $ob = array_sum(array_column($b, 'opex'));
            if ($rb > 0 && $ob > 0 && ($oa / $ob - 1) > ($ra / $rb - 1) + 0.10) {
                $ev[] = ['Operating expenses grew ' . round(($oa / $ob - 1) * 100) . '% while revenue changed ' . round(($ra / $rb - 1) * 100) . '% (last 3 months vs 3 months before)', 'Costs', '', number_format($oa - $ob, 0)];
            }
        }
        if ($ev) {
            $needs[] = ['key' => 'efficiency', 'type' => 'Operations', 'title' => 'Money that can be freed inside the company (before borrowing)',
                'need' => $slow > 0 ? 'About RWF ' . number_format($slow, 0) . ' is tied up in stock that sells slowly or is more than 90 days of demand.' : 'Costs are growing faster than sales.',
                'evidence_head' => ['Item', 'Status', 'Quantity', 'Value (RWF)'], 'evidence' => $ev, 'funding' => 0.0, 'internal' => 0.0, 'gap' => 0.0, 'months' => 3,
                'add_revenue' => 0.0, 'add_opex' => 0.0, 'add_profit' => 0.0, 'cash_impact' => round($slow, 2), 'roi' => null,
                'class' => 'Operational improvement — no borrowing needed', 'basis' => 'Estimated (stock at buying price; P&L)', 'proposal' => null];
        }
    } catch (Throwable $e) { error_log('li needs efficiency: ' . $e->getMessage()); }

    return $needs;
}

/* ------------------------------------------------------------------ proposals */

function li_proposal(mysqli $conn, int $id): ?array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT p.*, u.names creator, d.names decider FROM li_proposals p LEFT JOIN users u ON u.id = p.created_by LEFT JOIN users d ON d.id = p.decided_by WHERE p.id = ' . (int) $id));
    return $r ?: null;
}

function li_proposal_input(array $p): array
{
    return ['investment_cost' => (float) $p['investment_cost'], 'working_capital' => (float) $p['working_capital'], 'impl_months' => (int) $p['impl_months'],
            'horizon_months' => (int) $p['horizon_months'], 'revenue' => (float) $p['revenue'], 'cost_of_sales_pct' => (float) $p['cost_of_sales_pct'],
            'opex' => (float) $p['opex'], 'ramp_months' => (int) $p['ramp_months']];
}

function li_evidence_score(array $p): float { return ['data' => 1.0, 'partial' => 0.6, 'assumption' => 0.3][$p['evidence_level']] ?? 0.3; }

/** Data confidence of a proposal: its evidence + the company's data. */
function li_proposal_confidence(array $p, string $company): string
{
    $e = $p['evidence_level'];
    if ($e === 'data' && $company !== 'Low') { return $company; }
    if ($e === 'assumption' || $company === 'Low') { return 'Low'; }
    return 'Medium';
}

/** Metrics + ranking score for a proposal. */
function li_proposal_eval(mysqli $conn, array $p, string $companyConfidence): array
{
    $m = li_proposal_metrics(li_proposal_input($p), li_num($conn, 'discount_rate'));
    $conf = li_proposal_confidence($p, $companyConfidence);
    $sc = li_score($m, $p['risk_level'], li_evidence_score($p), $conf, li_weights($conn), li_num($conn, 'min_roi'), li_num($conn, 'max_payback'));
    $missing = [];
    if ((float) $p['investment_cost'] + (float) $p['working_capital'] <= 0) { $missing[] = 'cost'; }
    if ((float) $p['revenue'] <= 0) { $missing[] = 'expected revenue'; }
    return ['m' => $m, 'confidence' => $conf, 'score' => $sc['score'], 'parts' => $sc['parts'], 'missing' => $missing];
}

/* ------------------------------------------------------------------ performance (after the money was received) */

/** Actual figures of a funded proposal per month since it started: manual entries, else measured from sales of the linked product. */
function li_proposal_actuals(mysqli $conn, array $p): array
{
    $start = $p['started_on'] ?: null;
    $out = ['months' => 0, 'revenue' => null, 'profit' => null, 'source' => 'none', 'rows' => []];
    if (!$start) { return $out; }
    $out['months'] = max(0, (int) floor((strtotime(date('Y-m-d')) - strtotime($start)) / (86400 * 30.44)));
    $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM li_actuals WHERE proposal_id = ' . (int) $p['id'] . ' ORDER BY period'), MYSQLI_ASSOC);
    if ($rows) {
        $rev = array_sum(array_column($rows, 'revenue'));
        $out['revenue'] = round($rev, 2);
        $out['profit'] = round($rev - array_sum(array_column($rows, 'cost_of_sales')) - array_sum(array_column($rows, 'opex')), 2);
        $out['source'] = 'Actual (entered by finance)'; $out['rows'] = $rows;
        return $out;
    }
    if ($p['measure'] === 'product' && (int) $p['product_id'] > 0) {
        $pid = (int) $p['product_id']; $s = mysqli_real_escape_string($conn, $start);
        $net = "si.line_total * CASE WHEN s.subtotal > 0 THEN (1 - COALESCE(s.discount_amount, 0) / s.subtotal) ELSE 1 END";
        $cost = "si.quantity * GREATEST(COALESCE(si.pack_size, 1), 1) * COALESCE(si.unit_cost, 0)";
        $after = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM($net), 0) r, COALESCE(SUM($cost), 0) c FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE si.product_id = $pid AND s.status <> 'Cancelled' AND s.sale_date >= '$s'"));
        $before = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM($net), 0) r, COALESCE(SUM($cost), 0) c FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE si.product_id = $pid AND s.status <> 'Cancelled' AND s.sale_date >= DATE_SUB('$s', INTERVAL 3 MONTH) AND s.sale_date < '$s'"));
        $m = max(1, $out['months']);
        $incR = (float) $after['r'] - (float) $before['r'] / 3 * $m;
        $incP = ((float) $after['r'] - (float) $after['c']) - ((float) $before['r'] - (float) $before['c']) / 3 * $m;
        $out['revenue'] = round($incR, 2); $out['profit'] = round($incP - (float) $p['opex'] * $m, 2);
        $out['source'] = 'Measured: extra sales of the product compared with the 3 months before (minus the planned extra expenses)';
    }
    return $out;
}

/** Everything about one company loan: money, allocations, investments, repayments, expected vs actual, assessment. */
function li_loan_performance(mysqli $conn, array $loan): array
{
    $id = (int) $loan['id'];
    $d = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM li_loan_details WHERE loan_id = $id")) ?: [];
    $fees = (float) ($d['processing_fee'] ?? 0) + (float) ($d['other_charges'] ?? 0);
    $approved = (float) ($d['approved_amount'] ?? 0) ?: (float) $loan['loan_amount'];
    $received = isset($d['received_amount']) && $d['received_amount'] !== null ? (float) $d['received_amount'] : (float) $loan['loan_amount'] - $fees;
    $allocs = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, p.name, p.code, p.status pstatus, p.started_on,
        (SELECT COALESCE(SUM(i.amount), 0) FROM li_investments i WHERE i.allocation_id = a.id) invested
        FROM li_allocations a JOIN li_proposals p ON p.id = a.proposal_id WHERE a.loan_id = $id ORDER BY a.id"), MYSQLI_ASSOC);
    $alloc = 0.0; $inv = 0.0; $expP = 0.0; $actP = 0.0; $expR = 0.0; $actR = 0.0; $measurable = false; $expRoi = []; $parts = [];
    $disc = li_num($conn, 'discount_rate');
    foreach ($allocs as &$a) {
        if ($a['status'] !== 'Approved') { continue; }
        $alloc += (float) $a['amount']; $inv += (float) $a['invested'];
        $p = li_proposal($conn, (int) $a['proposal_id']);
        $m = li_proposal_metrics(li_proposal_input($p), $disc);
        $act = li_proposal_actuals($conn, $p);
        $share = $m['total_cost'] > 0 ? min(1, (float) $a['amount'] / $m['total_cost']) : 1;
        $e = ['revenue' => 0.0, 'profit' => 0.0];
        for ($k = 1; $k <= min($act['months'], $m['horizon']); $k++) { $e['revenue'] += $m['monthly'][$k]['revenue']; $e['profit'] += $m['monthly'][$k]['net']; }
        $a['expected'] = $e; $a['actual'] = $act; $a['metrics'] = $m; $a['share'] = $share;
        $expP += $e['profit'] * $share; $expR += $e['revenue'] * $share;
        if ($act['profit'] !== null) { $actP += $act['profit'] * $share; $actR += $act['revenue'] * $share; $measurable = $measurable || $act['months'] > $m['impl_months']; }
        $expRoi[] = $m['roi'];
    }
    unset($a);
    $ov = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) n, COALESCE(SUM(amount_due - paid_amount), 0) v FROM loan_schedule WHERE loan_id = $id AND status <> 'Paid' AND due_date < CURDATE()"));
    $due = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount_due), 0) FROM loan_schedule WHERE loan_id = $id AND due_date <= CURDATE()"))[0];
    $completed = $loan['status'] === 'Fully Paid';
    $costs = (float) $loan['interest_paid'] + $fees;
    $costShare = $received > 0 ? min(1, $alloc / $received) : 1; // loan costs in proportion to the part of the loan given to these investments
    $actRoi = $inv > 0 && $measurable ? round(($actP - $costs * $costShare) / $inv * 100, 1) : null;
    return [
        'details' => $d, 'fees' => $fees, 'approved' => $approved, 'received' => round($received, 2), 'costs' => round($costs, 2),
        'allocated' => round($alloc, 2), 'invested' => round($inv, 2), 'unallocated' => round(max(0, $received - $alloc), 2), 'not_invested' => round(max(0, $alloc - $inv), 2),
        'allocs' => $allocs, 'expected_profit' => round($expP, 2), 'actual_profit' => $measurable ? round($actP, 2) : null,
        'expected_revenue' => round($expR, 2), 'actual_revenue' => $measurable ? round($actR, 2) : null,
        'expected_roi' => $expRoi ? round(array_sum($expRoi) / count($expRoi), 1) : null, 'actual_roi' => $actRoi,
        'principal_repaid' => (float) $loan['principal_repaid'], 'interest_paid' => (float) $loan['interest_paid'],
        'outstanding' => max(0, (float) $loan['loan_amount'] - (float) $loan['principal_repaid']),
        'overdue_n' => (int) $ov['n'], 'overdue_v' => (float) $ov['v'], 'due_to_date' => $due, 'paid_to_date' => (float) $loan['total_paid'],
        'assessment' => li_assess($expP ?: null, $measurable ? $actP : null, (int) $ov['n'], $completed, $measurable),
    ];
}

/* ------------------------------------------------------------------ recommendation (advisory) */

/**
 * Builds the full recommendation for a proposal (and optionally a saved loan scenario): the decision, the reason,
 * the data used, the amount, the allocation, the repayment, stress tests, risk, confidence, assumptions, alternatives.
 */
function li_build_recommendation(mysqli $conn, ?array $p, ?array $sc): array
{
    $co = li_company($conn);
    $disc = li_num($conn, 'discount_rate');
    $ev = $p ? li_proposal_eval($conn, $p, $co['confidence']) : null;
    $m = $ev['m'] ?? null;
    $need = $m ? $m['total_cost'] : (float) ($sc['principal'] ?? 0);
    $loan = $sc ? ['principal' => (float) $sc['principal'], 'rate' => (float) $sc['rate'], 'rate_type' => $sc['rate_type'], 'n' => (int) $sc['n'], 'freq' => $sc['freq'],
                   'grace' => (int) $sc['grace'], 'structure' => $sc['structure'], 'fees' => (float) $sc['fees'], 'other' => (float) $sc['other']]
                : ['principal' => max(0, $need - $co['internal']), 'rate' => 18.0, 'rate_type' => 'Fixed', 'n' => 24, 'freq' => 'Monthly', 'grace' => 0, 'structure' => 'emi', 'fees' => 0, 'other' => 0];
    $base = li_sim_base($co);
    $existingMonth = $co['debt_month'];
    $minD = li_num($conn, 'min_dscr');
    $aff = li_affordable_principal($co['cfads'], $existingMonth, $minD, $loan['rate'], $loan['n'], $loan['freq'], $loan['grace'], $loan['structure']);
    $proposalIn = $p ? li_proposal_input($p) : null;
    $stress = [];
    foreach (li_scenarios($conn) as $k => $adj) {
        $stress[$k] = ['label' => $adj['label'], 'adj' => $adj, 'sim' => $loan['principal'] > 0 ? li_simulate($base, $loan, $proposalIn, $disc, $adj) : null];
    }
    $gap = max(0, $need - $co['internal']);
    $temporary = false;
    $dec = li_decide([
        'enough_history' => $co['enough_history'], 'history_months' => $co['active_months'], 'min_history' => $co['min_history'], 'confidence' => $ev['confidence'] ?? $co['confidence'],
        'need' => $need, 'internal' => $co['internal'], 'requested' => $loan['principal'], 'affordable' => $aff, 'roi' => $m['roi'] ?? null, 'npv' => $m['npv'] ?? 0,
        'min_roi' => li_num($conn, 'min_roi'), 'dscr_base' => $stress['base']['sim']['dscr_min'] ?? null, 'dscr_pess' => $stress['pessimistic']['sim']['dscr_min'] ?? null,
        'min_dscr' => $minD, 'min_dscr_stress' => li_num($conn, 'min_dscr_stress'), 'temporary' => $temporary,
    ]);
    // When the amount differs from the scenario, recalculate the repayment at the recommended amount.
    $amount = (float) $dec[3];
    $atAmount = $amount > 0 ? li_simulate($base, ['principal' => $amount] + $loan, $proposalIn, $disc, li_scenarios($conn)['base']) : null;
    $risk = 'Low'; $riskWhy = [];
    $pess = $stress['pessimistic']['sim']['dscr_min'] ?? null;
    if ($pess !== null && $pess < li_num($conn, 'min_dscr_stress')) { $riskWhy[] = 'repayments not covered in the pessimistic case'; }
    if (($p['risk_level'] ?? '') === 'High') { $riskWhy[] = 'the investment itself is rated high risk'; }
    if ($co['overdue_n'] > 0) { $riskWhy[] = $co['overdue_n'] . ' instalment(s) of existing loans are overdue'; }
    if (($co['debt_share'] ?? 0) > li_num($conn, 'max_debt_share')) { $riskWhy[] = 'existing repayments already take ' . $co['debt_share'] . '% of the cash flow'; }
    if ($riskWhy) { $risk = 'High'; }
    else {
        if (($p['risk_level'] ?? 'Medium') === 'Medium') { $riskWhy[] = 'the investment is rated medium risk'; }
        if ($pess !== null && $pess < $minD) { $riskWhy[] = 'the pessimistic DSCR is below ' . $minD; }
        if (($loan['rate_type'] ?? '') === 'Variable') { $riskWhy[] = 'variable interest rate'; }
        if ($riskWhy) { $risk = 'Medium'; }
    }
    $alts = [];
    if ($co['internal'] > 0) { $alts[] = 'Use internal funds first: RWF ' . number_format($co['internal'], 0) . ' is free in the investable funds after commitments, the cash reserve and the next 3 months of repayments.'; }
    $alts[] = 'Phase the investment: start with the part that pays back fastest and borrow later if results confirm the expected returns.';
    $alts[] = 'Free cash inside the company (slow stock, collecting money customers owe) before borrowing — see Financing Needs.';
    if ($loan['n'] < 36) { $alts[] = 'A longer term or a grace period lowers the instalment and raises the DSCR (compare in Loan Simulation).'; }
    $alts[] = 'Negotiate the interest rate and fees: compare lenders side by side in Loan Simulation.';
    $assume = ['Base CFADS = average of the last ' . min(6, $co['active_months']) . ' completed month(s) with sales (RWF ' . number_format($co['cfads'], 0) . ' a month), kept flat (no growth assumed).',
        'Existing company loans: repayment schedules as recorded. RM Credit Hub (customer loans) is not company borrowing and is excluded.',
        'Cash reserve kept: ' . li_setting($conn, 'reserve_months') . ' month(s) of operating costs (RWF ' . number_format($co['reserve'], 0) . ').',
        'Discount rate for NPV: ' . li_setting($conn, 'discount_rate') . '% a year.'];
    if ($p) { $assume[] = 'Investment figures were entered in proposal ' . ($p['code'] ?: '#' . $p['id']) . ' (' . ['data' => 'based on MY MOTIVE records', 'partial' => 'partly based on records', 'assumption' => "management's assumptions"][$p['evidence_level']] . ').'; }
    if (!$sc) { $assume[] = 'No loan offer was entered: the repayment is estimated at 18% a year, 24 monthly equal instalments, no fees (enter real offers in Loan Simulation).'; }
    if ($p && trim((string) $p['assumptions']) !== '') { $assume[] = 'Proposal assumptions: ' . trim($p['assumptions']); }
    return [
        'generated_at' => date('Y-m-d H:i'), 'decision' => $dec[0], 'label' => $dec[1], 'reasons' => $dec[2], 'amount' => $amount,
        'need' => round($need, 2), 'internal' => $co['internal'], 'gap' => round($gap, 2), 'affordable' => $aff, 'loan' => $loan,
        'proposal' => $p ? ['id' => (int) $p['id'], 'code' => $p['code'], 'name' => $p['name'], 'category' => $p['category'], 'branch_id' => $p['branch_id']] : null,
        'metrics' => $m ? array_diff_key($m, ['flows' => 1, 'monthly' => 1]) : null, 'confidence' => $ev['confidence'] ?? $co['confidence'],
        'company' => ['cfads' => $co['cfads'], 'revenue' => $co['revenue'], 'margin' => $co['margin'], 'cash' => $co['cash'], 'reserve' => $co['reserve'],
                      'debt_month' => $co['debt_month'], 'dscr_hist' => $co['dscr_hist'], 'dscr_forward' => $co['dscr_forward'], 'active_months' => $co['active_months'],
                      'internal' => $co['internal'], 'overdue_n' => $co['overdue_n'], 'trend' => $co['trend']],
        'stress' => array_map(function ($s) { return ['label' => $s['label'], 'adj' => $s['adj'], 'dscr_min' => $s['sim']['dscr_min'] ?? null, 'cash_low' => $s['sim']['cash_low'] ?? null,
            'monthly_after' => $s['sim']['monthly_after'] ?? null, 'roi' => $s['sim']['investment']['roi'] ?? null, 'first_below_reserve' => $s['sim']['first_below_reserve'] ?? null]; }, $stress),
        'at_amount' => $atAmount ? ['instalment' => $atAmount['instalment'], 'total_interest' => $atAmount['total_interest'], 'total_repayment' => $atAmount['total_repayment'],
            'total_cost' => $atAmount['total_cost'], 'monthly_debt_new' => $atAmount['monthly_debt_new'], 'monthly_after' => $atAmount['monthly_after'],
            'dscr_min' => $atAmount['dscr_min'], 'months' => $atAmount['schedule']['months']] : null,
        'allocation' => $p ? [['Investment cost (equipment / setup)', (float) $p['investment_cost']], ['Working capital', (float) $p['working_capital']],
            ['From internal funds', min($co['internal'], $need)], ['From the loan', $amount]] : [],
        'risk' => $risk, 'risk_reasons' => $riskWhy, 'assumptions' => $assume, 'alternatives' => $alts,
        'formulas' => [
            'CFADS' => 'Operating profit (Profit & Loss) + depreciation + impairment + assets written off, per completed month',
            'DSCR' => 'CFADS of 12 months / (principal + interest due in the same 12 months); the lowest loan year is used',
            'Affordable amount' => 'Largest principal whose debt service, added to existing loans, keeps DSCR >= ' . $minD . ' (base case)',
            'ROI' => '(operating profit over ' . ($m['horizon'] ?? li_setting($conn, 'horizon')) . ' months + working capital released - investment cost) / investment cost x 100',
            'Payback' => 'months until the cumulative cash flow of the investment is >= 0',
            'NPV / IRR' => 'monthly cash flows discounted at ' . li_setting($conn, 'discount_rate') . '% a year / the annual rate where NPV = 0',
            'Internal funds' => 'free balance of the investable funds (' . li_setting($conn, 'investable_funds') . ') after approved commitments, limited to cash minus the reserve and 3 months of repayments',
        ],
    ];
}