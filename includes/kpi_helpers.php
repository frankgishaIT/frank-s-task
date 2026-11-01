<?php
/**
 * NEW FILE: includes/kpi_helpers.php
 * Task 4: PERFORMANCE & KPI MANAGEMENT.
 *
 *   Levels        business / department / team / employee
 *   Actual value  AUTOMATIC from MY MOTIVE (sales, customers, expenses, tasks, attendance, customer requests) for the
 *                 KPI's scope and period, or MANUAL (entered per period) when the system has no such data.
 *   Achievement   higher-is-better: actual / target;  lower-is-better: target / actual;  range: 100% inside the range,
 *                 less the further outside it is. Capped at 120% so one KPI cannot hide the others.
 *   Score         weighted average of the achievements (weights = importance); rating from configurable levels.
 */
require_once __DIR__ . '/rm_org.php';
require_once __DIR__ . '/access_control.php';

const KPI_LEVELS = ['business' => 'Business', 'department' => 'Department', 'team' => 'Team', 'employee' => 'Employee'];
const KPI_TYPES = ['financial' => 'Financial', 'quantity' => 'Quantity', 'percentage' => 'Percentage', 'time' => 'Time-based',
                   'quality' => 'Quality', 'completion' => 'Completion', 'growth' => 'Growth', 'compliance' => 'Compliance'];
const KPI_DIRECTIONS = ['higher' => 'Higher is better', 'lower' => 'Lower is better', 'range' => 'Within a range'];
const KPI_FREQUENCIES = ['daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annual'];
const KPI_STATUSES = ['Draft', 'Active', 'Completed', 'Cancelled'];
const KPI_CAP = 120.0;
/** Automatic data sources: key => [label, unit, suggested type, suggested direction]. */
const KPI_SOURCES = [
    'manual'            => ['Manual entry (no automatic data)', '', '', ''],
    'sales_revenue'     => ['Sales revenue (Sales)', 'RWF', 'financial', 'higher'],
    'sales_count'       => ['Number of sales (Sales)', 'sales', 'quantity', 'higher'],
    'products_sold'     => ['Products sold, base units (Sales)', 'units', 'quantity', 'higher'],
    'new_customers'     => ['New customers (Customers)', 'customers', 'growth', 'higher'],
    'operating_expenses'=> ['Operating expenses (Transactions)', 'RWF', 'financial', 'lower'],
    'tasks_completed'   => ['Tasks completed and approved (Tasks)', 'tasks', 'completion', 'higher'],
    'tasks_on_time'     => ['Tasks submitted on time, % (Tasks)', '%', 'compliance', 'higher'],
    'attendance_rate'   => ['Attendance rate, % (Attendance)', '%', 'percentage', 'higher'],
    'late_arrivals'     => ['Late arrivals (Attendance)', 'times', 'quality', 'lower'],
    'requests_resolved' => ['Customer requests resolved (Customer Portal)', 'requests', 'quantity', 'higher'],
];
const KPI_DEFAULT_RATINGS = [['Excellent', 90], ['Very Good', 80], ['Good', 70], ['Needs Improvement', 50], ['Poor', 0]];

function kpi_guard(mysqli $conn, string $perm = 'kpi.view'): void
{
    if (!rm_feature_enabled('kpi') || !access_can($perm, $conn)) {
        http_response_code(403);
        include __DIR__ . '/access_denied.php';
        exit;
    }
}

function kpi_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $q = function ($sql) use ($conn) { mysqli_query($conn, $sql); };
    $q('CREATE TABLE IF NOT EXISTS kpi_teams (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(120) NOT NULL, department_id INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS kpi_team_members (team_id INT(11) NOT NULL, user_id INT(11) NOT NULL, PRIMARY KEY (team_id, user_id)) ENGINE=InnoDB');
    $q("CREATE TABLE IF NOT EXISTS kpis (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(160) NOT NULL, description TEXT NULL,
        level VARCHAR(12) NOT NULL, department_id INT(11) NULL, team_id INT(11) NULL, user_id INT(11) NULL,
        measure_type VARCHAR(12) NOT NULL, direction VARCHAR(8) NOT NULL DEFAULT 'higher', target DECIMAL(16,2) NOT NULL DEFAULT 0,
        range_min DECIMAL(16,2) NULL, range_max DECIMAL(16,2) NULL, unit VARCHAR(30) NULL, weight DECIMAL(6,2) NOT NULL DEFAULT 1,
        start_date DATE NOT NULL, end_date DATE NULL, frequency VARCHAR(10) NOT NULL DEFAULT 'monthly', manager_id INT(11) NULL,
        data_source VARCHAR(30) NOT NULL DEFAULT 'manual', status VARCHAR(12) NOT NULL DEFAULT 'Active', created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY level (level), KEY user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q('CREATE TABLE IF NOT EXISTS kpi_entries (id INT(11) NOT NULL AUTO_INCREMENT, kpi_id INT(11) NOT NULL, period_start DATE NOT NULL,
        actual DECIMAL(16,2) NOT NULL, note VARCHAR(500) NULL, entered_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY one (kpi_id, period_start)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q("CREATE TABLE IF NOT EXISTS kpi_comments (id INT(11) NOT NULL AUTO_INCREMENT, kpi_id INT(11) NOT NULL, period_start DATE NULL,
        user_id INT(11) NULL, kind VARCHAR(20) NOT NULL DEFAULT 'comment', body TEXT NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY kpi_id (kpi_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS kpi_reviews (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, review_type VARCHAR(12) NOT NULL,
        period_start DATE NOT NULL, period_end DATE NOT NULL, score DECIMAL(6,1) NULL, rating VARCHAR(40) NULL,
        self_assessment TEXT NULL, self_at DATETIME NULL, manager_assessment TEXT NULL, manager_rating VARCHAR(40) NULL, manager_id INT(11) NULL, manager_at DATETIME NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Open', created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS kpi_pips (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, title VARCHAR(160) NOT NULL, reason TEXT NOT NULL,
        goals TEXT NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'Active', outcome TEXT NULL,
        employee_comment TEXT NULL, manager_id INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q('CREATE TABLE IF NOT EXISTS kpi_recognitions (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, title VARCHAR(160) NOT NULL,
        reason TEXT NOT NULL, reward VARCHAR(160) NULL, awarded_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS kpi_ratings (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(40) NOT NULL, min_score DECIMAL(5,1) NOT NULL,
        PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    if ((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM kpi_ratings'))[0] === 0) {
        foreach (KPI_DEFAULT_RATINGS as [$n, $min]) { $q("INSERT INTO kpi_ratings (name, min_score) VALUES ('$n', $min)"); }
    }
}

/* ------------------------------------------------------------------ periods */

function kpi_period_start(string $date, string $freq): string
{
    $t = strtotime($date);
    switch ($freq) {
        case 'daily': return date('Y-m-d', $t);
        case 'weekly': return date('Y-m-d', strtotime('monday this week', $t));
        case 'quarterly': return date('Y', $t) . '-' . str_pad((string) (intdiv((int) date('n', $t) - 1, 3) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01';
        case 'annual': return date('Y-01-01', $t);
        default: return date('Y-m-01', $t);
    }
}

function kpi_period_end(string $start, string $freq): string
{
    $step = ['daily' => '+1 day', 'weekly' => '+1 week', 'monthly' => '+1 month', 'quarterly' => '+3 months', 'annual' => '+1 year'][$freq] ?? '+1 month';
    return date('Y-m-d', strtotime($start . ' ' . $step . ' -1 day'));
}

function kpi_period_label(string $start, string $freq): string
{
    $t = strtotime($start);
    switch ($freq) {
        case 'daily': return date('d M Y', $t);
        case 'weekly': return 'Week of ' . date('d M Y', $t);
        case 'quarterly': return 'Q' . (intdiv((int) date('n', $t) - 1, 3) + 1) . ' ' . date('Y', $t);
        case 'annual': return date('Y', $t);
        default: return date('F Y', $t);
    }
}

/** The KPI's periods from its start (or the last $max) up to today, newest first. */
function kpi_periods(array $k, int $max = 12): array
{
    $out = []; $p = kpi_period_start(date('Y-m-d'), $k['frequency']); $first = kpi_period_start($k['start_date'], $k['frequency']);
    $last = $k['end_date'] ? kpi_period_start(min($k['end_date'], date('Y-m-d')), $k['frequency']) : $p;
    $p = min($p, $last);
    while ($p >= $first && count($out) < $max) {
        $out[] = $p;
        $p = kpi_period_start(date('Y-m-d', strtotime($p . ' -1 day')), $k['frequency']);
    }
    return $out;
}

/* ------------------------------------------------------------------ scope: who the KPI is about */

/** User ids the KPI is about (null = the whole business). */
function kpi_scope_users(mysqli $conn, array $k): ?array
{
    if ($k['level'] === 'employee') { return [(int) $k['user_id']]; }
    if ($k['level'] === 'department') {
        return array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id FROM users WHERE department_id = ' . (int) $k['department_id']), MYSQLI_ASSOC), 'id'));
    }
    if ($k['level'] === 'team') {
        return array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT user_id FROM kpi_team_members WHERE team_id = ' . (int) $k['team_id']), MYSQLI_ASSOC), 'user_id'));
    }
    return null;
}

function kpi_col_exists(mysqli $conn, string $t, string $c): bool
{
    static $cache = [];
    $key = "$t.$c";
    if (!isset($cache[$key])) {
        $s = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        mysqli_stmt_bind_param($s, 'ss', $t, $c);
        mysqli_stmt_execute($s);
        $cache[$key] = (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] > 0;
    }
    return $cache[$key];
}

/**
 * The automatic actual value of a KPI for one period, or null when it cannot be measured automatically
 * (manual KPI, data not recorded in this database, or the source does not apply to this level).
 */
function kpi_auto_actual(mysqli $conn, array $k, string $start, string $end): ?float
{
    $src = $k['data_source'];
    if ($src === 'manual' || !isset(KPI_SOURCES[$src])) { return null; }
    $users = kpi_scope_users($conn, $k);
    if (is_array($users) && !$users) { return 0.0; }
    $in = function (string $col) use ($users) { return $users === null ? '' : " AND $col IN (" . implode(',', $users) . ')'; };
    $one = function (string $sql) use ($conn, $start, $end) {
        $s = mysqli_prepare($conn, $sql);
        mysqli_stmt_bind_param($s, 'ss', $start, $end);
        mysqli_stmt_execute($s);
        $v = mysqli_fetch_row(mysqli_stmt_get_result($s))[0];
        return $v === null ? 0.0 : round((float) $v, 2);
    };
    try {
        switch ($src) {
            case 'sales_revenue':
                return $one("SELECT SUM(total_amount) FROM sales WHERE status <> 'Cancelled' AND sale_date BETWEEN ? AND ?" . $in('recorded_by'));
            case 'sales_count':
                return $one("SELECT COUNT(*) FROM sales WHERE status <> 'Cancelled' AND sale_date BETWEEN ? AND ?" . $in('recorded_by'));
            case 'products_sold':
                return $one("SELECT SUM(si.quantity * GREATEST(COALESCE(si.pack_size, 1), 1)) FROM sale_items si JOIN sales s ON s.id = si.sale_id
                    WHERE s.status <> 'Cancelled' AND (si.item_type <> 'Service' OR si.item_type IS NULL) AND s.sale_date BETWEEN ? AND ?" . $in('s.recorded_by'));
            case 'new_customers':
                if (!kpi_col_exists($conn, 'customers', 'created_at')) { return null; }
                $by = kpi_col_exists($conn, 'customers', 'created_by') ? $in('created_by') : ($users === null ? '' : null);
                if ($by === null) { return null; } // customers are not linked to who added them
                return $one('SELECT COUNT(*) FROM customers WHERE DATE(created_at) BETWEEN ? AND ?' . $by);
            case 'operating_expenses':
                return $one("SELECT SUM(amount) FROM transactions WHERE transaction_type = 'Expense' AND status = 'approved'
                    AND category NOT IN ('Purchase (Re-stock)', 'Asset Purchase', 'Asset Loss') AND NOT (category = 'Loan Repayment' AND description LIKE 'Loan principal%')
                    AND transaction_date BETWEEN ? AND ?" . $in('recorded_by'));
            case 'tasks_completed':
                return $one("SELECT COUNT(*) FROM tasks WHERE status IN ('Approved', 'Completed') AND DATE(COALESCE(reviewed_at, submitted_at)) BETWEEN ? AND ?" . $in('assigned_to'));
            case 'tasks_on_time':
                $s = mysqli_prepare($conn, "SELECT COUNT(*), SUM(DATE(submitted_at) <= due_date) FROM tasks WHERE submitted_at IS NOT NULL AND due_date IS NOT NULL
                    AND DATE(submitted_at) BETWEEN ? AND ?" . $in('assigned_to'));
                mysqli_stmt_bind_param($s, 'ss', $start, $end); mysqli_stmt_execute($s);
                $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
                return (int) $r[0] > 0 ? round(100 * (float) $r[1] / (int) $r[0], 1) : null;
            case 'attendance_rate':
                $s = mysqli_prepare($conn, "SELECT COUNT(*), SUM(status IN ('Present', 'Late')) FROM attendance WHERE attendance_date BETWEEN ? AND ?
                    AND status IN ('Present', 'Late', 'Absent')" . $in('user_id'));
                mysqli_stmt_bind_param($s, 'ss', $start, $end); mysqli_stmt_execute($s);
                $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
                return (int) $r[0] > 0 ? round(100 * (float) $r[1] / (int) $r[0], 1) : null;
            case 'late_arrivals':
                return $one("SELECT COUNT(*) FROM attendance WHERE status = 'Late' AND attendance_date BETWEEN ? AND ?" . $in('user_id'));
            case 'requests_resolved':
                if ($users !== null) { return null; } // requests are handled by the business as a whole
                return $one("SELECT COUNT(*) FROM portal_requests WHERE status IN ('Resolved', 'Closed') AND DATE(updated_at) BETWEEN ? AND ?");
        }
    } catch (Throwable $e) {
        error_log('kpi auto ' . $src . ': ' . $e->getMessage());
    }
    return null;
}

/** Actual value of a KPI for a period: automatic if available, else the manual entry, else null. */
function kpi_actual(mysqli $conn, array $k, string $start): array
{
    $end = kpi_period_end($start, $k['frequency']);
    if ($k['data_source'] !== 'manual') {
        $v = kpi_auto_actual($conn, $k, $start, min($end, date('Y-m-d')));
        if ($v !== null) { return ['value' => $v, 'source' => 'automatic']; }
    }
    $s = mysqli_prepare($conn, 'SELECT actual FROM kpi_entries WHERE kpi_id = ? AND period_start = ?');
    $id = (int) $k['id'];
    mysqli_stmt_bind_param($s, 'is', $id, $start);
    mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
    return $r ? ['value' => (float) $r[0], 'source' => 'manual'] : ['value' => null, 'source' => null];
}

/** Achievement % (0 .. KPI_CAP) according to the KPI's direction; null without an actual value. */
function kpi_achievement(array $k, ?float $actual): ?float
{
    if ($actual === null) { return null; }
    $t = (float) $k['target'];
    switch ($k['direction']) {
        case 'lower':
            if ($actual <= 0) { return KPI_CAP; }
            return round(min(KPI_CAP, 100 * $t / $actual), 1);
        case 'range':
            $lo = (float) ($k['range_min'] ?? $t); $hi = (float) ($k['range_max'] ?? $t);
            if ($actual >= $lo && $actual <= $hi) { return 100.0; }
            $width = max(abs($hi - $lo), abs($t) * 0.1, 1);
            $dist = $actual < $lo ? $lo - $actual : $actual - $hi;
            return round(max(0, 100 - 100 * $dist / $width), 1);
        default:
            if ($t <= 0) { return $actual > 0 ? KPI_CAP : 100.0; }
            return round(min(KPI_CAP, 100 * $actual / $t), 1);
    }
}

function kpi_ratings(mysqli $conn): array
{
    return mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM kpi_ratings ORDER BY min_score DESC'), MYSQLI_ASSOC);
}

function kpi_rating(mysqli $conn, ?float $score): ?string
{
    if ($score === null) { return null; }
    foreach (kpi_ratings($conn) as $r) { if ($score >= (float) $r['min_score']) { return $r['name']; } }
    return null;
}

/** The current (or chosen) result of each KPI: [kpi + period, actual, source, achievement]. */
function kpi_results(mysqli $conn, array $kpis, ?string $on = null): array
{
    $out = [];
    foreach ($kpis as $k) {
        $start = kpi_period_start($on ?? date('Y-m-d'), $k['frequency']);
        if ($start < kpi_period_start($k['start_date'], $k['frequency']) || ($k['end_date'] && $start > $k['end_date'])) { continue; }
        $a = kpi_actual($conn, $k, $start);
        $out[] = $k + ['period_start' => $start, 'period_label' => kpi_period_label($start, $k['frequency']),
            'actual' => $a['value'], 'source' => $a['source'], 'achievement' => kpi_achievement($k, $a['value'])];
    }
    return $out;
}

/** Weighted score of a list of results (only KPIs that have an actual value count). */
function kpi_weighted(array $results): ?float
{
    $w = 0.0; $sum = 0.0;
    foreach ($results as $r) { if ($r['achievement'] === null) { continue; } $w += (float) $r['weight']; $sum += (float) $r['weight'] * $r['achievement']; }
    return $w > 0 ? round($sum / $w, 1) : null;
}

/** Active KPIs, optionally filtered: ['level', 'department_id', 'team_id', 'user_id']. */
function kpi_list(mysqli $conn, array $f = [], bool $activeOnly = true): array
{
    $w = [$activeOnly ? "k.status = 'Active'" : '1 = 1'];
    foreach (['level' => 's', 'department_id' => 'i', 'team_id' => 'i', 'user_id' => 'i'] as $c => $t) {
        if (isset($f[$c]) && $f[$c] !== '' && $f[$c] !== null) { $w[] = "k.$c = " . ($t === 'i' ? (int) $f[$c] : "'" . mysqli_real_escape_string($conn, (string) $f[$c]) . "'"); }
    }
    return mysqli_fetch_all(mysqli_query($conn, 'SELECT k.*, u.names AS employee, d.name AS department, t.name AS team, m.names AS manager
        FROM kpis k LEFT JOIN users u ON u.id = k.user_id LEFT JOIN departments d ON d.id = k.department_id
        LEFT JOIN kpi_teams t ON t.id = k.team_id LEFT JOIN users m ON m.id = k.manager_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY FIELD(k.level, "business", "department", "team", "employee"), k.name'), MYSQLI_ASSOC);
}

function kpi_scope_label(array $k): string
{
    switch ($k['level']) {
        case 'employee': return $k['employee'] ?? 'Employee';
        case 'department': return $k['department'] ?? 'Department';
        case 'team': return $k['team'] ?? 'Team';
        default: return 'RISE MOTIVE';
    }
}

function kpi_fmt(?float $v, ?string $unit): string
{
    if ($v === null) { return '—'; }
    $n = abs($v - round($v)) < 0.005 ? number_format($v) : number_format($v, 2);
    return $unit === 'RWF' ? 'RWF ' . $n : $n . ($unit ? ' ' . $unit : '');
}

/** May the signed-in employee see this KPI? (KPI viewers: all; others: their own, their team's, their department's). */
function kpi_can_see(mysqli $conn, array $k): bool
{
    if (access_can('kpi.view', $conn)) { return true; }
    $uid = (int) current_user_id();
    if ($k['level'] === 'employee') { return (int) $k['user_id'] === $uid; }
    if ($k['level'] === 'team') { return (bool) mysqli_fetch_row(mysqli_query($conn, 'SELECT 1 FROM kpi_team_members WHERE team_id = ' . (int) $k['team_id'] . ' AND user_id = ' . $uid)); }
    if ($k['level'] === 'department') { return (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT department_id FROM users WHERE id = ' . $uid))[0] ?? 0) === (int) $k['department_id']; }
    return false;
}