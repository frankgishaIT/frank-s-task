<?php
/**
 * NEW FILE: includes/budget_helpers.php
 * BUDGET MANAGEMENT, FUND ALLOCATION & BUDGET VS ACTUAL — built ON the existing RM Funds (no second accounting system).
 *
 *   Funds        balances and history come from the existing ledger (fund_movements). Nothing is copied.
 *   Reserved     pending expense transactions (already) + APPROVED commitments not yet paid (bdg_commitments).
 *                fund_available() everywhere in MY MOTIVE subtracts both, so no module can spend reserved money.
 *   Payment      completing a commitment creates ONE approved expense transaction + ONE ledger entry, and ends the
 *                reservation in the same database transaction: the amount is deducted exactly once.
 *   Transfers    request -> approval -> two linked ledger entries (TRANSFER_OUT / TRANSFER_IN). Never automatic.
 *   Budgets      actual = APPROVED transactions in the budget's period and scope; pending commitments shown apart.
 *   Audit        every action: user, action, date and time, previous value, new value, reason, reference.
 */
require_once __DIR__ . '/fund_helpers.php';
require_once __DIR__ . '/rm_org.php';

const BDG_PERIODS = ['annual' => 'Annual', 'quarterly' => 'Quarterly', 'monthly' => 'Monthly', 'custom' => 'Custom period'];
const BDG_SCOPES = ['company' => 'Company-wide', 'department' => 'Department', 'project' => 'Project', 'category' => 'Expense category', 'revenue' => 'Revenue / target'];
const BDG_STATUSES = ['Draft', 'Pending Approval', 'Approved', 'Rejected', 'Closed'];
const BDG_ALERT_DEFAULTS = ['warn' => 70, 'critical' => 90, 'exhausted' => 100];

function bdg_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $q = function ($s) use ($conn) { mysqli_query($conn, $s); };
    $q("CREATE TABLE IF NOT EXISTS budgets (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(160) NOT NULL, financial_year INT(11) NOT NULL,
        period_type VARCHAR(10) NOT NULL, period_start DATE NOT NULL, period_end DATE NOT NULL, scope VARCHAR(12) NOT NULL DEFAULT 'company',
        kind VARCHAR(8) NOT NULL DEFAULT 'expense', department_id INT(11) NULL, project_id INT(11) NULL, category VARCHAR(80) NULL, fund_id INT(11) NULL,
        original_amount DECIMAL(16,2) NOT NULL, amount DECIMAL(16,2) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Draft', notes TEXT NULL,
        created_by INT(11) NULL, approved_by INT(11) NULL, approved_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY fy (financial_year), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS budget_revisions (id INT(11) NOT NULL AUTO_INCREMENT, budget_id INT(11) NOT NULL, old_amount DECIMAL(16,2) NOT NULL,
        new_amount DECIMAL(16,2) NOT NULL, reason TEXT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'Pending', requested_by INT(11) NULL, decided_by INT(11) NULL,
        decision_note VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL, PRIMARY KEY (id), KEY budget_id (budget_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS bdg_commitments (id INT(11) NOT NULL AUTO_INCREMENT, fund_id INT(11) NOT NULL, amount DECIMAL(16,2) NOT NULL,
        description VARCHAR(255) NOT NULL, expense_category VARCHAR(80) NULL, department_id INT(11) NULL, project_id INT(11) NULL, party_id INT(11) NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'Pending', requested_by INT(11) NULL, approved_by INT(11) NULL, approved_at DATETIME NULL,
        transaction_id INT(11) NULL, completed_by INT(11) NULL, completed_at DATETIME NULL, note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY fund_status (fund_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS fund_transfers (id INT(11) NOT NULL AUTO_INCREMENT, from_fund_id INT(11) NOT NULL, to_fund_id INT(11) NOT NULL,
        amount DECIMAL(16,2) NOT NULL, reason TEXT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'Pending', requested_by INT(11) NULL, decided_by INT(11) NULL,
        decision_note VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS fund_alloc_revisions (id INT(11) NOT NULL AUTO_INCREMENT, old_values TEXT NOT NULL, new_values TEXT NOT NULL, reason TEXT NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'Pending', requested_by INT(11) NULL, decided_by INT(11) NULL, decision_note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, decided_at DATETIME NULL, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS bdg_audit (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NULL, action VARCHAR(255) NOT NULL, reference VARCHAR(40) NULL,
        previous_value TEXT NULL, new_value TEXT NULL, reason TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY reference (reference)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS bdg_alerts (budget_id INT(11) NOT NULL, level VARCHAR(12) NOT NULL, sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (budget_id, level)) ENGINE=InnoDB");
    $q('ALTER TABLE bdg_audit MODIFY action VARCHAR(255) NOT NULL'); // tables created before it was widened
    $q('CREATE TABLE IF NOT EXISTS bdg_settings (name VARCHAR(40) NOT NULL, value VARCHAR(255) NULL, PRIMARY KEY (name)) ENGINE=InnoDB');
    // Expenses can be attributed to a department and a project (departmental / project budgets).
    foreach (['department_id', 'project_id'] as $c) {
        $s = mysqli_prepare($conn, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'transactions' AND column_name = ?");
        mysqli_stmt_bind_param($s, 's', $c); mysqli_stmt_execute($s);
        if ((int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] === 0) { $q("ALTER TABLE transactions ADD COLUMN $c INT(11) NULL"); }
    }
}

function bdg_setting(mysqli $conn, string $k, string $default): string
{
    $s = mysqli_prepare($conn, 'SELECT value FROM bdg_settings WHERE name = ?');
    mysqli_stmt_bind_param($s, 's', $k); mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
    return $r ? (string) $r[0] : $default;
}

function bdg_audit(mysqli $conn, string $action, ?string $ref, $prev, $new, ?string $reason, ?int $userId = null): void
{
    $uid = $userId ?? (int) (function_exists('current_user_id') ? current_user_id() : 0);
    $p = $prev === null ? null : (is_scalar($prev) ? (string) $prev : json_encode($prev, JSON_UNESCAPED_UNICODE));
    $n = $new === null ? null : (is_scalar($new) ? (string) $new : json_encode($new, JSON_UNESCAPED_UNICODE));
    $action = mb_substr($action, 0, 255);
    $s = mysqli_prepare($conn, 'INSERT INTO bdg_audit (user_id, action, reference, previous_value, new_value, reason) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isssss', $uid, $action, $ref, $p, $n, $reason); mysqli_stmt_execute($s);
}

function bdg_ref(string $type, int $id): string
{
    return ['budget' => 'BUD', 'revision' => 'REV', 'commitment' => 'COM', 'transfer' => 'FTR', 'allocation' => 'ALC'][$type] . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ funds */

/** Money committed (approved, not yet paid) against a fund. */
function bdg_committed_total(mysqli $conn, int $fundId, ?int $exceptCommitment = null): float
{
    static $has = null;
    if ($has === null) { $has = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'bdg_commitments'"))[0] > 0; }
    if (!$has) { return 0.0; }
    $x = $exceptCommitment ? ' AND id <> ' . (int) $exceptCommitment : '';
    return (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) FROM bdg_commitments WHERE fund_id = $fundId AND status = 'Approved'$x"))[0];
}

/** The SQL condition used by fund_cash_balance(): money only, never the value of stock or assets. */
function bdg_cash_condition(): string
{
    $eff = "(CASE WHEN m.movement_type = 'REVERSAL' THEN o.movement_type ELSE m.movement_type END)";
    return "NOT (COALESCE(m.ref_type, '') = 'ASSET' OR COALESCE($eff, '') IN ('STOCK_IN', 'STOCK_OUT')
            OR COALESCE(m.ref_type, '') IN ('STOCK_OPENING', 'STOCK_RECONCILE') OR (COALESCE($eff, '') = 'CAPITAL_INFLOW' AND COALESCE(m.ref_type, '') = 'PO'))";
}

/**
 * Fund statement for a period, from the ledger: opening, inflows, transfers in, expenses, transfers out, closing,
 * reserved (pending + committed, now) and available. A reversal is counted with the entry it reverses (net).
 */
function bdg_fund_statement(mysqli $conn, int $fundId, string $from, string $to): array
{
    $cash = bdg_cash_condition();
    $effType = "(CASE WHEN m.movement_type = 'REVERSAL' THEN o.movement_type ELSE m.movement_type END)";
    $effDir = "(CASE WHEN m.movement_type = 'REVERSAL' THEN o.direction ELSE m.direction END)";
    $signed = "(CASE WHEN m.direction = $effDir THEN m.amount ELSE -m.amount END)";
    $s = mysqli_prepare($conn, "SELECT
        COALESCE(SUM(CASE WHEN DATE(m.created_at) < ? THEN (CASE WHEN m.direction = 'IN' THEN m.amount ELSE -m.amount END) END), 0) opening,
        COALESCE(SUM(CASE WHEN DATE(m.created_at) BETWEEN ? AND ? AND $effDir = 'IN' AND COALESCE($effType, '') <> 'TRANSFER_IN' THEN $signed END), 0) inflows,
        COALESCE(SUM(CASE WHEN DATE(m.created_at) BETWEEN ? AND ? AND COALESCE($effType, '') = 'TRANSFER_IN' THEN $signed END), 0) t_in,
        COALESCE(SUM(CASE WHEN DATE(m.created_at) BETWEEN ? AND ? AND $effDir = 'OUT' AND COALESCE($effType, '') <> 'TRANSFER_OUT' THEN $signed END), 0) expenses,
        COALESCE(SUM(CASE WHEN DATE(m.created_at) BETWEEN ? AND ? AND COALESCE($effType, '') = 'TRANSFER_OUT' THEN $signed END), 0) t_out
        FROM fund_movements m LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id WHERE m.fund_id = ? AND $cash");
    mysqli_stmt_bind_param($s, 'sssssssssi', $from, $from, $to, $from, $to, $from, $to, $from, $to, $fundId);
    mysqli_stmt_execute($s);
    $r = array_map('floatval', mysqli_fetch_assoc(mysqli_stmt_get_result($s)));
    $closing = round($r['opening'] + $r['inflows'] + $r['t_in'] - $r['expenses'] - $r['t_out'], 2);
    $pending = round(fund_pending_total($conn, $fundId) - bdg_committed_total($conn, $fundId), 2); // pending expense transactions
    $committed = round(bdg_committed_total($conn, $fundId), 2);
    return ['opening' => round($r['opening'], 2), 'inflows' => round($r['inflows'], 2), 'transfers_in' => round($r['t_in'], 2),
            'expenses' => round($r['expenses'], 2), 'transfers_out' => round($r['t_out'], 2), 'closing' => $closing,
            'pending' => $pending, 'committed' => $committed, 'reserved' => round($pending + $committed, 2),
            'available' => round($closing - $pending - $committed, 2)];
}

/** Everything the expense form shows before submission. */
function bdg_fund_status(mysqli $conn, int $fundId, float $amount = 0.0): ?array
{
    $f = fund_get($conn, $fundId);
    if (!$f) { return null; }
    $balance = round(fund_cash_balance($conn, $fundId), 2);
    $reserved = round(fund_pending_total($conn, $fundId), 2);
    $available = round($balance - $reserved, 2);
    $after = round($available - $amount, 2);
    $status = $available <= 0 ? 'No available money' : ($after < 0 ? 'Insufficient for this amount' : ($after < 0.1 * max($balance, 1) ? 'Low after this expense' : 'Sufficient'));
    return ['fund' => $f['name'], 'balance' => $balance, 'reserved' => $reserved, 'available' => $available, 'amount' => $amount, 'after' => $after, 'status' => $status, 'ok' => $after >= -0.001 && $available > 0];
}

/* ------------------------------------------------------------------ budgets: actual vs budget */

function bdg_period_label(array $b): string
{
    $s = strtotime($b['period_start']); $e = strtotime($b['period_end']);
    switch ($b['period_type']) {
        case 'annual': return 'FY ' . $b['financial_year'];
        case 'quarterly': return 'Q' . (intdiv((int) date('n', $s) - 1, 3) + 1) . ' ' . date('Y', $s);
        case 'monthly': return date('F Y', $s);
        default: return date('d M Y', $s) . ' – ' . date('d M Y', $e);
    }
}

/** SQL conditions on transactions t for a budget's scope (department / project / category). */
function bdg_scope_sql(mysqli $conn, array $b, string $t = 't'): string
{
    $w = [];
    if (!empty($b['department_id'])) { $d = (int) $b['department_id'];
        $w[] = "($t.department_id = $d OR ($t.department_id IS NULL AND $t.recorded_by IN (SELECT id FROM users WHERE department_id = $d)))"; }
    if (!empty($b['project_id'])) { $w[] = "$t.project_id = " . (int) $b['project_id']; }
    if (!empty($b['category'])) {
        $c = mysqli_real_escape_string($conn, $b['category']);
        $w[] = $b['kind'] === 'revenue' ? "$t.category = '$c'" : "COALESCE(NULLIF($t.expense_category, ''), $t.category) = '$c'";
    }
    if (!empty($b['fund_id']) && $b['kind'] !== 'revenue') { $w[] = "$t.fund_id = " . (int) $b['fund_id']; }
    return $w ? ' AND ' . implode(' AND ', $w) : '';
}

/** Budget vs actual for one budget (actual = APPROVED records only; commitments apart). */
function bdg_figures(mysqli $conn, array $b): array
{
    $from = $b['period_start']; $to = $b['period_end']; $scope = bdg_scope_sql($conn, $b);
    $type = $b['kind'] === 'revenue' ? 'Income' : 'Expense';
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN t.status = 'approved' THEN t.amount END), 0) actual, COALESCE(SUM(CASE WHEN t.status = 'pending' THEN t.amount END), 0) pending
        FROM transactions t WHERE t.transaction_type = '$type' AND t.status IN ('approved', 'pending') AND t.transaction_date BETWEEN ? AND ?$scope");
    mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
    $r = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    $actual = round((float) $r['actual'], 2); $pending = round((float) $r['pending'], 2);
    if ($b['kind'] !== 'revenue') { // approved commitments not yet paid, in scope
        $w = [];
        if (!empty($b['department_id'])) { $w[] = 'c.department_id = ' . (int) $b['department_id']; }
        if (!empty($b['project_id'])) { $w[] = 'c.project_id = ' . (int) $b['project_id']; }
        if (!empty($b['category'])) { $w[] = "c.expense_category = '" . mysqli_real_escape_string($conn, $b['category']) . "'"; }
        if (!empty($b['fund_id'])) { $w[] = 'c.fund_id = ' . (int) $b['fund_id']; }
        $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(c.amount), 0) FROM bdg_commitments c WHERE c.status = 'Approved' AND DATE(c.created_at) BETWEEN ? AND ?" . ($w ? ' AND ' . implode(' AND ', $w) : ''));
        mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
        $pending = round($pending + (float) mysqli_fetch_row(mysqli_stmt_get_result($s))[0], 2);
    }
    $budget = (float) $b['amount'];
    $util = $budget > 0 ? round(100 * $actual / $budget, 1) : null;
    $level = bdg_level($conn, $util, $b['kind']);
    return ['budget' => $budget, 'actual' => $actual, 'variance' => round($budget - $actual, 2),
            'variance_pct' => $budget > 0 ? round(100 * ($budget - $actual) / $budget, 1) : null, 'remaining' => round($budget - $actual, 2),
            'utilization' => $util, 'pending' => $pending, 'available' => round($budget - $actual - $pending, 2), 'level' => $level,
            'over' => $b['kind'] !== 'revenue' ? max(0, round($actual - $budget, 2)) : 0.0];
}

/** OK / Warning / Critical / Exhausted / Overspent (revenue: Achieved / Below target). */
function bdg_level(mysqli $conn, ?float $util, string $kind = 'expense'): string
{
    if ($util === null) { return 'No budget amount'; }
    if ($kind === 'revenue') { return $util >= 100 ? 'Target achieved' : 'Below target'; }
    $w = (float) bdg_setting($conn, 'alert_warn', (string) BDG_ALERT_DEFAULTS['warn']); $c = (float) bdg_setting($conn, 'alert_critical', (string) BDG_ALERT_DEFAULTS['critical']);
    if ($util > 100) { return 'Overspent'; } if ($util >= 100) { return 'Exhausted'; } if ($util >= $c) { return 'Critical'; } if ($util >= $w) { return 'Warning'; }
    return 'Within budget';
}

function bdg_badge(string $level): string
{
    $c = ['Overspent' => 'bg-danger', 'Exhausted' => 'bg-danger', 'Critical' => 'bg-danger', 'Warning' => 'bg-primary', 'Within budget' => 'bg-success', 'Target achieved' => 'bg-success', 'Below target' => 'bg-primary',
          'Approved' => 'bg-success', 'Pending' => 'bg-primary', 'Pending Approval' => 'bg-primary', 'Rejected' => 'bg-danger', 'Reserved' => 'bg-dark', 'Completed' => 'bg-success', 'Cancelled' => 'bg-secondary',
          'Draft' => 'bg-secondary', 'Closed' => 'bg-secondary', 'Executed' => 'bg-success'][$level] ?? 'bg-secondary';
    return '<span class="badge ' . $c . '">' . htmlspecialchars($level, ENT_QUOTES, 'UTF-8') . '</span>';
}

/** Approved budgets matching the dashboard / report filters. */
function bdg_budgets(mysqli $conn, array $f = [], array $statuses = ['Approved']): array
{
    $w = ["b.status IN ('" . implode("','", array_map(function ($x) use ($conn) { return mysqli_real_escape_string($conn, $x); }, $statuses)) . "')"];
    if (!empty($f['year'])) { $w[] = 'b.financial_year = ' . (int) $f['year']; }
    if (!empty($f['period_type'])) { $w[] = "b.period_type = '" . mysqli_real_escape_string($conn, $f['period_type']) . "'"; }
    if (!empty($f['scope'])) { $w[] = "b.scope = '" . mysqli_real_escape_string($conn, $f['scope']) . "'"; }
    if (!empty($f['department_id'])) { $w[] = 'b.department_id = ' . (int) $f['department_id']; }
    if (!empty($f['project_id'])) { $w[] = 'b.project_id = ' . (int) $f['project_id']; }
    if (!empty($f['category'])) { $w[] = "b.category = '" . mysqli_real_escape_string($conn, $f['category']) . "'"; }
    if (!empty($f['fund_id'])) { $w[] = 'b.fund_id = ' . (int) $f['fund_id']; }
    if (!empty($f['month'])) { $m = mysqli_real_escape_string($conn, $f['month']); $w[] = "b.period_start <= LAST_DAY('$m-01') AND b.period_end >= '$m-01'"; }
    if (!empty($f['quarter']) && !empty($f['year'])) { $q = (int) $f['quarter']; $y = (int) $f['year']; $qs = sprintf('%d-%02d-01', $y, 3 * $q - 2);
        $w[] = "b.period_start <= LAST_DAY(DATE_ADD('$qs', INTERVAL 2 MONTH)) AND b.period_end >= '$qs'"; }
    return mysqli_fetch_all(mysqli_query($conn, 'SELECT b.*, d.name AS department, p.' . bdg_project_name_col($conn) . ' AS project, f.name AS fund
        FROM budgets b LEFT JOIN departments d ON d.id = b.department_id LEFT JOIN projects p ON p.id = b.project_id LEFT JOIN funds f ON f.id = b.fund_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY b.financial_year DESC, b.period_start, b.name'), MYSQLI_ASSOC);
}

/** The name column of RM Targets (projects). */
function bdg_project_name_col(mysqli $conn): string
{
    static $c = null;
    if ($c !== null) { return $c; }
    foreach (['name', 'project_name', 'title'] as $x) {
        $s = mysqli_prepare($conn, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'projects' AND column_name = ?");
        mysqli_stmt_bind_param($s, 's', $x); mysqli_stmt_execute($s);
        if ((int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] > 0) { return $c = $x; }
    }
    return $c = 'id';
}

function bdg_scope_label(array $b): string
{
    $parts = [];
    if (!empty($b['department'])) { $parts[] = $b['department']; }
    if (!empty($b['project'])) { $parts[] = 'Project: ' . $b['project']; }
    if (!empty($b['category'])) { $parts[] = $b['category']; }
    if (!empty($b['fund'])) { $parts[] = $b['fund']; }
    return $parts ? implode(' · ', $parts) : ($b['kind'] === 'revenue' ? 'All revenue' : 'Whole company');
}

/* ------------------------------------------------------------------ alerts */

/** Sends each threshold alert once per budget (Managing Director = Administrators; the department's managers). */
function bdg_check_alerts(mysqli $conn): int
{
    require_once __DIR__ . '/notification_helper.php';
    $sent = 0;
    $levels = ['Warning' => 'Budget Warning', 'Critical' => 'Critical Warning', 'Exhausted' => 'Budget Exhausted', 'Overspent' => 'Overspending Alert'];
    $order = array_keys($levels);
    foreach (bdg_budgets($conn) as $b) {
        if ($b['kind'] === 'revenue' || $b['period_end'] < date('Y-m-d', strtotime('-31 days'))) { continue; }
        $fig = bdg_figures($conn, $b);
        if (!isset($levels[$fig['level']])) { continue; }
        // Send the reached level (and any lower level skipped in between, once each).
        foreach (array_slice($order, 0, array_search($fig['level'], $order, true) + 1) as $lvl) {
            $s = mysqli_prepare($conn, 'INSERT IGNORE INTO bdg_alerts (budget_id, level) VALUES (?, ?)');
            $bid = (int) $b['id']; mysqli_stmt_bind_param($s, 'is', $bid, $lvl); mysqli_stmt_execute($s);
            if (mysqli_stmt_affected_rows($s) !== 1 || $lvl !== $fig['level']) { continue; }
            $who = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND (LOWER(role) = 'admin'"
                . (!empty($b['department_id']) ? ' OR (LOWER(role) = \'manager\' AND department_id = ' . (int) $b['department_id'] . ')' : '') . ')'), MYSQLI_ASSOC), 'id');
            foreach ($who as $u) {
                try { notifyUser($conn, (int) $u, $levels[$lvl] . ': ' . $b['name'], 'Used ' . $fig['utilization'] . '% (RWF ' . number_format($fig['actual']) . ' of ' . number_format($fig['budget']) . ', ' . bdg_period_label($b) . ').'); } catch (Throwable $e) { }
            }
            bdg_audit($conn, 'Budget alert sent: ' . $levels[$lvl], bdg_ref('budget', $bid), null, $fig['utilization'] . '%', null, 0);
            $sent++;
        }
    }
    return $sent;
}

/* ------------------------------------------------------------------ commitments (reserved funds) */

function bdg_commitment(mysqli $conn, int $id, bool $lock = false): ?array
{
    return mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM bdg_commitments WHERE id = $id" . ($lock ? ' FOR UPDATE' : ''))) ?: null;
}

/** Approve: the amount becomes RESERVED in its fund (only if the fund has it available). */
function bdg_commitment_approve(mysqli $conn, int $id, int $uid, string $note = ''): void
{
    mysqli_begin_transaction($conn);
    try {
        $c = bdg_commitment($conn, $id, true);
        if (!$c || $c['status'] !== 'Pending') { throw new RuntimeException('This request is no longer pending.'); }
        fund_lock($conn, (int) $c['fund_id']);
        $f = fund_get($conn, (int) $c['fund_id']);
        $avail = fund_available($conn, (int) $c['fund_id']);
        if ((float) $c['amount'] > $avail + 0.001) {
            throw new InsufficientFundException('Cannot approve: ' . $f['name'] . ' has only RWF ' . number_format(max(0, $avail), 2) . ' available for RWF ' . number_format((float) $c['amount'], 2)
                . '. Money is never taken from another fund; request a fund transfer first if this is justified.');
        }
        mysqli_query($conn, "UPDATE bdg_commitments SET status = 'Approved', approved_by = $uid, approved_at = NOW() WHERE id = $id");
        bdg_audit($conn, 'Commitment approved: RWF ' . number_format((float) $c['amount'], 2) . ' reserved in ' . $f['name'], bdg_ref('commitment', $id), 'Pending', 'Approved (reserved)', $note ?: null, $uid);
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

/**
 * Complete the payment: ONE approved expense transaction + ONE ledger entry, and the reservation ends in the same
 * database transaction, so the amount is deducted exactly once. Returns the transaction id.
 */
function bdg_commitment_complete(mysqli $conn, int $id, int $uid, string $date, ?float $paid = null): int
{
    mysqli_begin_transaction($conn);
    try {
        $c = bdg_commitment($conn, $id, true);
        if (!$c || $c['status'] !== 'Approved') { throw new RuntimeException('Only an approved (reserved) commitment can be paid, and only once.'); }
        $amount = round($paid ?? (float) $c['amount'], 2);
        if ($amount <= 0 || $amount > (float) $c['amount'] + 0.001) { throw new RuntimeException('The amount paid must be between 1 and the reserved amount (RWF ' . number_format((float) $c['amount'], 2) . ').'); }
        $fid = (int) $c['fund_id'];
        fund_lock($conn, $fid);
        // The fund must cover it (its own reservation is about to be released, so it is left out of "reserved").
        $availForThis = fund_cash_balance($conn, $fid) - (fund_pending_total($conn, $fid) - (float) $c['amount']);
        if ($amount > $availForThis + 0.001) { throw new InsufficientFundException(fund_insufficient_message(fund_get($conn, $fid)['name'])); }
        $desc = mb_substr($c['description'] . ' (' . bdg_ref('commitment', $id) . ')', 0, 255);
        $cat = 'Service';
        $s = mysqli_prepare($conn, "INSERT INTO transactions (category, transaction_type, amount, transaction_date, description, recorded_by, status, fund_id, expense_category, party_id, department_id, project_id)
            VALUES (?, 'Expense', ?, ?, ?, ?, 'approved', ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($s, 'sdssiisiii', $cat, $amount, $date, $desc, $uid, $fid, $c['expense_category'], $c['party_id'], $c['department_id'], $c['project_id']);
        mysqli_stmt_execute($s);
        $txId = (int) mysqli_insert_id($conn);
        fund_record_movement($conn, $fid, 'EXPENSE', 'OUT', $amount, substr($date, 0, 7), $txId, $uid, $desc, null, 'COMMITMENT', $id);
        $s = mysqli_prepare($conn, "UPDATE bdg_commitments SET status = 'Completed', transaction_id = ?, completed_by = ?, completed_at = NOW(), amount = ? WHERE id = ? AND status = 'Approved'");
        mysqli_stmt_bind_param($s, 'iidi', $txId, $uid, $amount, $id); mysqli_stmt_execute($s);
        if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('This commitment was already paid.'); }
        bdg_audit($conn, 'Payment completed: RWF ' . number_format($amount, 2) . ' deducted once; reservation released', bdg_ref('commitment', $id), 'Approved (reserved RWF ' . number_format((float) $c['amount'], 2) . ')', 'Completed, transaction TX-' . str_pad((string) $txId, 6, '0', STR_PAD_LEFT), null, $uid);
        mysqli_commit($conn);
        return $txId;
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

/** Cancel / reject: the reservation (if any) is released. */
function bdg_commitment_cancel(mysqli $conn, int $id, int $uid, string $reason, bool $reject = false): void
{
    $c = bdg_commitment($conn, $id);
    if (!$c || !in_array($c['status'], ['Pending', 'Approved'], true)) { throw new RuntimeException('Only a pending or approved commitment can be ' . ($reject ? 'rejected' : 'cancelled') . '.'); }
    $to = $reject ? 'Rejected' : 'Cancelled';
    $s = mysqli_prepare($conn, "UPDATE bdg_commitments SET status = ?, note = ? WHERE id = ? AND status IN ('Pending', 'Approved')");
    mysqli_stmt_bind_param($s, 'ssi', $to, $reason, $id); mysqli_stmt_execute($s);
    bdg_audit($conn, 'Commitment ' . strtolower($to) . ($c['status'] === 'Approved' ? ': reservation of RWF ' . number_format((float) $c['amount'], 2) . ' released' : ''), bdg_ref('commitment', $id), $c['status'], $to, $reason, $uid);
}

/* ------------------------------------------------------------------ fund transfers */

/** Executes an APPROVED transfer: two linked ledger entries; never beyond the source's available balance. */
function bdg_transfer_approve(mysqli $conn, int $id, int $uid, string $note, bool $isAdmin = false): void
{
    mysqli_begin_transaction($conn);
    try {
        $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fund_transfers WHERE id = $id FOR UPDATE"));
        if (!$t || $t['status'] !== 'Pending') { throw new RuntimeException('This transfer is no longer pending.'); }
        // CHANGED: Administrators (Managing Director) may approve their own request, with a note; others may not.
        $self = (int) $t['requested_by'] === $uid;
        if ($self && !$isAdmin) { throw new RuntimeException('A transfer must be approved by someone other than the person who requested it (or by an Administrator).'); }
        if ($self && mb_strlen(trim($note)) < 5) { throw new RuntimeException('You requested this transfer yourself: write a short approval note (it is kept in the audit trail).'); }
        $from = (int) $t['from_fund_id']; $to = (int) $t['to_fund_id'];
        foreach ([min($from, $to), max($from, $to)] as $fid) { fund_lock($conn, $fid); } // always the same order: no deadlock
        $src = fund_get($conn, $from); $dst = fund_get($conn, $to);
        $avail = fund_available($conn, $from);
        if ((float) $t['amount'] > $avail + 0.001) { throw new InsufficientFundException('Cannot transfer: ' . $src['name'] . ' has only RWF ' . number_format(max(0, $avail), 2) . ' available.'); }
        $ref = bdg_ref('transfer', $id); $per = date('Y-m');
        fund_record_movement($conn, $from, 'TRANSFER_OUT', 'OUT', (float) $t['amount'], $per, null, $uid, $ref . ' to ' . $dst['name'] . ': ' . mb_substr($t['reason'], 0, 150), null, 'FUND_TRANSFER', $id);
        fund_record_movement($conn, $to, 'TRANSFER_IN', 'IN', (float) $t['amount'], $per, null, $uid, $ref . ' from ' . $src['name'] . ': ' . mb_substr($t['reason'], 0, 150), null, 'FUND_TRANSFER', $id);
        $s = mysqli_prepare($conn, "UPDATE fund_transfers SET status = 'Executed', decided_by = ?, decision_note = ?, decided_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($s, 'isi', $uid, $note, $id); mysqli_stmt_execute($s);
        bdg_audit($conn, 'Fund transfer approved and executed' . ($self ? ' (self-approved by Administrator)' : '') . ': RWF ' . number_format((float) $t['amount'], 2) . ' ' . $src['name'] . ' → ' . $dst['name'], $ref, 'Pending', 'Executed', $note ?: $t['reason'], $uid);
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}