<?php
/**
 * NEW FILE: includes/forecast_helpers.php
 * Task 3: RM BUSINESS FORECASTING — forward-looking projections from the data already in MY MOTIVE.
 *
 * Method (kept simple and explainable):
 *   - History is grouped into periods (day / week / month / quarter / year). Only COMPLETED periods are used
 *     to fit a straight-line trend (least squares); the projection continues that trend, never below zero.
 *   - Confidence range: +/- 1.28 x the typical deviation from the trend (about 80% of past periods fell inside).
 *   - Confidence label: from the number of periods and how much they jump around.
 *   - Too little history (fewer than FC_MIN_POINTS periods with activity): no projection, and the page says so.
 * Every figure says whether it is ACTUAL or PROJECTED.
 */
require_once __DIR__ . '/rm_org.php';
require_once __DIR__ . '/access_control.php';

const FC_GRANULARITIES = ['day' => 'Daily', 'week' => 'Weekly', 'month' => 'Monthly', 'quarter' => 'Quarterly', 'year' => 'Annual'];
const FC_HISTORY = ['day' => 60, 'week' => 26, 'month' => 18, 'quarter' => 8, 'year' => 5];
const FC_AHEAD = ['day' => 14, 'week' => 8, 'month' => 6, 'quarter' => 4, 'year' => 2];
const FC_MIN_POINTS = ['day' => 14, 'week' => 6, 'month' => 4, 'quarter' => 3, 'year' => 2];
const FC_LEAD_DAYS = 14;   // supplier lead time assumed for stock recommendations
const FC_DEFAULT_SCENARIOS = [
    'optimistic'  => ['label' => 'Optimistic',  'sales' => 15,  'expenses' => -5, 'cost' => 0,  'delay' => 0],
    'base'        => ['label' => 'Base case',   'sales' => 0,   'expenses' => 0,  'cost' => 0,  'delay' => 0],
    'pessimistic' => ['label' => 'Pessimistic', 'sales' => -15, 'expenses' => 10, 'cost' => 10, 'delay' => 30],
];

function fc_guard(mysqli $conn, string $perm = 'forecasting.view'): void
{
    if (!rm_feature_enabled('forecasting') || !access_can($perm, $conn)) {
        http_response_code(403);
        include __DIR__ . '/access_denied.php';
        exit;
    }
}

function fc_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS fc_snapshots (id INT(11) NOT NULL AUTO_INCREMENT, metric VARCHAR(20) NOT NULL,
        period_start DATE NOT NULL, period_end DATE NOT NULL, projected DECIMAL(16,2) NOT NULL, low DECIMAL(16,2) NULL, high DECIMAL(16,2) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY one (metric, period_start, created_at)) ENGINE=InnoDB');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS fc_settings (name VARCHAR(40) NOT NULL, value TEXT NULL, PRIMARY KEY (name)) ENGINE=InnoDB');
}

function fc_has(mysqli $conn, string $table, ?string $column = null): bool
{
    static $c = [];
    $k = $table . '.' . $column;
    if (isset($c[$k])) { return $c[$k]; }
    $sql = $column === null
        ? "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '" . mysqli_real_escape_string($conn, $table) . "'"
        : "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '" . mysqli_real_escape_string($conn, $table) . "' AND column_name = '" . mysqli_real_escape_string($conn, $column) . "'";
    return $c[$k] = (int) mysqli_fetch_row(mysqli_query($conn, $sql))[0] > 0;
}

/* ------------------------------------------------------------------ periods */

function fc_period_start(string $date, string $g): string
{
    $t = strtotime($date);
    switch ($g) {
        case 'day': return date('Y-m-d', $t);
        case 'week': return date('Y-m-d', strtotime('monday this week', $t));
        case 'quarter': return date('Y', $t) . '-' . str_pad((string) (intdiv((int) date('n', $t) - 1, 3) * 3 + 1), 2, '0', STR_PAD_LEFT) . '-01';
        case 'year': return date('Y-01-01', $t);
        default: return date('Y-m-01', $t);
    }
}

function fc_next(string $start, string $g, int $n = 1): string
{
    $step = ['day' => 'day', 'week' => 'week', 'month' => 'month', 'quarter' => 'month', 'year' => 'year'][$g];
    $k = $g === 'quarter' ? 3 * $n : $n;
    return date('Y-m-d', strtotime($start . ' ' . ($k >= 0 ? '+' : '') . $k . ' ' . $step)); // works backwards too
}

function fc_label(string $start, string $g): string
{
    $t = strtotime($start);
    switch ($g) {
        case 'day': return date('d M', $t);
        case 'week': return 'Wk ' . date('d M', $t);
        case 'quarter': return 'Q' . (intdiv((int) date('n', $t) - 1, 3) + 1) . ' ' . date('Y', $t);
        case 'year': return date('Y', $t);
        default: return date('M Y', $t);
    }
}

/* ------------------------------------------------------------------ actual history */

/** SQL (value, date column) for a metric; null when the data does not exist in this database. */
function fc_metric_sql(mysqli $conn, string $metric): ?array
{
    switch ($metric) {
        case 'revenue':   // recorded sales (cancelled ones excluded)
            return ["SELECT DATE(sale_date) d, SUM(total_amount) v FROM sales WHERE status <> 'Cancelled' AND sale_date BETWEEN ? AND ? GROUP BY d"];
        case 'expenses':  // operating expenses: not stock purchases, assets, loan principal or one-off asset losses
            return ["SELECT transaction_date d, SUM(amount) v FROM transactions WHERE transaction_type = 'Expense' AND status = 'approved'
                AND category NOT IN ('Purchase (Re-stock)', 'Asset Purchase', 'Asset Loss')
                AND NOT (category = 'Loan Repayment' AND description LIKE 'Loan principal%') AND transaction_date BETWEEN ? AND ? GROUP BY d"];
        case 'customers':
            return fc_has($conn, 'customers', 'created_at') ? ['SELECT DATE(created_at) d, COUNT(*) v FROM customers WHERE created_at BETWEEN ? AND DATE_ADD(?, INTERVAL 1 DAY) GROUP BY d'] : null;
        case 'restock':
            return ["SELECT transaction_date d, SUM(amount) v FROM transactions WHERE transaction_type = 'Expense' AND status = 'approved'
                AND category = 'Purchase (Re-stock)' AND transaction_date BETWEEN ? AND ? GROUP BY d"];
    }
    return null;
}

/** Completed periods (oldest first): [start => value], plus the current period so far. */
function fc_history(mysqli $conn, string $metric, string $g): array
{
    $sqlArr = fc_metric_sql($conn, $metric);
    $cur = fc_period_start(date('Y-m-d'), $g);
    $first = fc_next($cur, $g, -FC_HISTORY[$g]);
    $out = [];
    for ($p = $first; $p < $cur; $p = fc_next($p, $g)) { $out[$p] = 0.0; }
    $current = 0.0;
    if ($sqlArr) {
        $s = mysqli_prepare($conn, $sqlArr[0]);
        $to = date('Y-m-d');
        mysqli_stmt_bind_param($s, 'ss', $first, $to);
        mysqli_stmt_execute($s);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) {
            $p = fc_period_start($r['d'], $g);
            if ($p === $cur) { $current += (float) $r['v']; } elseif (isset($out[$p])) { $out[$p] += (float) $r['v']; }
        }
    }
    // Drop leading empty periods (before the business used MY MOTIVE): they are not "zero sales".
    while ($out && reset($out) == 0.0) { array_shift($out); }
    return ['periods' => $out, 'current_start' => $cur, 'current_so_far' => round($current, 2), 'available' => $sqlArr !== null];
}

/* ------------------------------------------------------------------ projection */

/**
 * Straight-line trend on the completed periods, projected $ahead periods.
 * Returns ['ok', 'reason', 'points' => [start => [value, low, high]], 'confidence', 'slope_pct'].
 */
function fc_project(array $history, string $g, ?int $ahead = null): array
{
    $ahead = $ahead ?? FC_AHEAD[$g];
    $vals = array_values($history['periods']);
    $n = count($vals);
    $active = count(array_filter($vals, function ($v) { return $v > 0; }));
    if (!$history['available']) { return ['ok' => false, 'reason' => 'This information is not recorded in this database.']; }
    if ($n < FC_MIN_POINTS[$g] || $active < FC_MIN_POINTS[$g]) {
        return ['ok' => false, 'reason' => 'Not enough history for a reliable ' . strtolower(FC_GRANULARITIES[$g]) . ' forecast: '
            . $active . ' period(s) with activity, at least ' . FC_MIN_POINTS[$g] . ' needed. Try a longer period (e.g. weekly or monthly), or wait for more data.'];
    }
    $mx = ($n - 1) / 2; $my = array_sum($vals) / $n; $sxy = 0; $sxx = 0;
    foreach ($vals as $i => $v) { $sxy += ($i - $mx) * ($v - $my); $sxx += ($i - $mx) ** 2; }
    $b = $sxx > 0 ? $sxy / $sxx : 0; $a = $my - $b * $mx;
    $res = 0; foreach ($vals as $i => $v) { $res += ($v - ($a + $b * $i)) ** 2; }
    $sd = sqrt($res / max(1, $n - 2));
    $points = [];
    $p = $history['current_start'];
    for ($k = 0; $k < $ahead; $k++) {
        $x = $n + $k;
        $v = max(0, $a + $b * $x);
        $points[$p] = [round($v, 2), round(max(0, $v - 1.28 * $sd), 2), round($v + 1.28 * $sd, 2)];
        $p = fc_next($p, $g);
    }
    $cv = $my > 0 ? $sd / $my : 1;
    $confidence = ($n >= 2 * FC_MIN_POINTS[$g] && $cv < 0.25) ? 'High' : (($cv < 0.6) ? 'Medium' : 'Low');
    return ['ok' => true, 'reason' => '', 'points' => $points, 'confidence' => $confidence,
            'slope_pct' => $my > 0 ? round(100 * $b / $my, 1) : 0.0, 'n' => $n];
}

/* ------------------------------------------------------------------ margins, receivables, obligations */

/** Gross margin of the last 90 days from the RECORDED costs (products: cost at sale; services: the profit rule). */
function fc_gross_margin(mysqli $conn): array
{
    $rate = 0.8;
    if (is_file(__DIR__ . '/profit_rules.php')) { require_once __DIR__ . '/profit_rules.php'; if (defined('SALES_SERVICE_PROFIT_RATE')) { $rate = (float) SALES_SERVICE_PROFIT_RATE; } }
    $net = "(si.line_total * CASE WHEN s.subtotal > 0 THEN (1 - COALESCE(s.discount_amount, 0) / s.subtotal) ELSE 1 END)";
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM($net) rev,
        SUM(CASE WHEN si.item_type = 'Service' OR p.item_type = 'Service' THEN $net * " . (1 - $rate) . "
                 ELSE si.quantity * GREATEST(COALESCE(si.pack_size, 1), 1) * COALESCE(si.unit_cost, p.buying_price, 0) END) cost
        FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN products p ON p.id = si.product_id
        WHERE s.status <> 'Cancelled' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)"));
    $rev = (float) ($r['rev'] ?? 0);
    return ['margin' => $rev > 0 ? max(0, min(1, ($rev - (float) $r['cost']) / $rev)) : null, 'revenue_90' => $rev];
}

/** Money customers owe now, and how much of credit sold in the last 6 months was paid. */
function fc_receivables(mysqli $conn): array
{
    $ch = fc_has($conn, 'sales', 'charges_accrued') ? ' + COALESCE(charges_accrued, 0) - COALESCE(charges_paid, 0)' : '';
    $open = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount - amount_paid$ch), 0) FROM sales WHERE status IN ('Credit', 'Partially Paid')"))[0];
    $r = mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount), 0), COALESCE(SUM(amount_paid), 0) FROM sales
        WHERE status <> 'Cancelled' AND payment_method = 'Credit' AND sale_date BETWEEN DATE_SUB(CURDATE(), INTERVAL 180 DAY) AND DATE_SUB(CURDATE(), INTERVAL 30 DAY)"));
    $rate = (float) $r[0] > 0 ? min(1, (float) $r[1] / (float) $r[0]) : 0.7;
    return ['open' => round($open, 2), 'collection_rate' => $rate];
}

/** Loan instalments still to pay, per day, in the next $days days. */
function fc_loan_dues(mysqli $conn, int $days): array
{
    if (!fc_has($conn, 'loan_schedule')) { return []; }
    $out = [];
    $res = mysqli_query($conn, "SELECT ls.due_date d, SUM(ls.amount_due - COALESCE(ls.paid_amount, 0)) v FROM loan_schedule ls JOIN loans l ON l.id = ls.loan_id
        WHERE l.status NOT IN ('Cancelled', 'Deleted', 'Fully Paid') AND ls.status <> 'Paid' AND ls.due_date <= DATE_ADD(CURDATE(), INTERVAL $days DAY) GROUP BY ls.due_date");
    foreach (mysqli_fetch_all($res, MYSQLI_ASSOC) as $r) { $d = max($r['d'], date('Y-m-d')); $out[$d] = ($out[$d] ?? 0) + (float) $r['v']; }
    return $out;
}

/** Average monthly payroll (last 3 months), for information. */
function fc_payroll_month(mysqli $conn): ?float
{
    if (!fc_has($conn, 'payroll') || !fc_has($conn, 'payroll', 'net_salary') || !fc_has($conn, 'payroll', 'pay_period')) { return null; }
    $v = mysqli_fetch_row(mysqli_query($conn, 'SELECT SUM(net_salary) / NULLIF(COUNT(DISTINCT DATE_FORMAT(pay_period, "%Y-%m")), 0) FROM payroll WHERE pay_period >= DATE_SUB(CURDATE(), INTERVAL 3 MONTH)'))[0];
    return $v !== null ? round((float) $v, 2) : null;
}

/** Cash today (money in the RM Funds; falls back to the Balance Sheet cash). */
function fc_cash_now(mysqli $conn): ?float
{
    if (is_file(__DIR__ . '/financial_report_queries.php')) {
        try { require_once __DIR__ . '/financial_report_queries.php'; if (function_exists('fin_position')) { return round((float) fin_position($conn, date('Y-m-d'))['cash'], 2); } }
        catch (Throwable $e) { error_log('fc cash: ' . $e->getMessage()); }
    }
    return null;
}

/**
 * Day-by-day cash for the next $days days: today's cash + sales paid (85% of projected revenue, paid in the month)
 * + receivables collected over 30 days - operating expenses - stock purchases - loan instalments on their due dates.
 * Returns ['days' => [date => balance], 'first_shortage' => date|null, 'lowest' => [date, value]].
 */
function fc_cash_flow(?float $cash, array $revFc, array $expFc, array $recv, array $loanDues, float $restockMonth, int $days = 90): array
{
    if ($cash === null || empty($revFc['ok'])) { return ['days' => [], 'first_shortage' => null, 'lowest' => null]; }
    $rev = array_values($revFc['points']); $exp = array_values($expFc['points'] ?? []);
    $bal = $cash; $out = []; $first = null; $low = [date('Y-m-d'), $cash];
    $collectPerDay = $recv['open'] * $recv['collection_rate'] / 30;
    for ($d = 0; $d < $days; $d++) {
        $date = date('Y-m-d', strtotime('+' . $d . ' days'));
        $m = min(count($rev) - 1, intdiv($d, 30));
        $in = ($rev[$m][0] ?? 0) * 0.85 / 30 + ($d < 30 ? $collectPerDay : 0);
        $outflow = (($exp[$m][0] ?? 0) + $restockMonth) / 30 + ($loanDues[$date] ?? 0);
        $bal += $in - $outflow;
        $out[$date] = round($bal, 2);
        if ($bal < 0 && $first === null) { $first = $date; }
        if ($bal < $low[1]) { $low = [$date, round($bal, 2)]; }
    }
    return ['days' => $out, 'first_shortage' => $first, 'lowest' => $low];
}

/* ------------------------------------------------------------------ scenarios */

function fc_scenarios(mysqli $conn): array
{
    $row = mysqli_fetch_row(mysqli_query($conn, "SELECT value FROM fc_settings WHERE name = 'scenarios'"));
    $saved = $row ? json_decode((string) $row[0], true) : null;
    $out = FC_DEFAULT_SCENARIOS;
    if (is_array($saved)) { foreach ($out as $k => $v) { foreach (['sales', 'expenses', 'cost', 'delay'] as $f) { if (isset($saved[$k][$f])) { $out[$k][$f] = (float) $saved[$k][$f]; } } } }
    return $out;
}

/**
 * Next 3 months for one set of assumptions:
 * revenue x (1 + sales%), gross margin reduced by cost%, expenses x (1 + expenses%),
 * receivables collected later by "delay" days (none collected within the window when delay >= the window).
 */
function fc_scenario_result(array $revenueFc, array $expenseFc, ?float $margin, array $recv, ?float $cash, float $loans90, float $restockMonth, array $a): array
{
    $months = array_slice($revenueFc['points'] ?? [], 0, 3, true);
    $exps = array_values(array_slice($expenseFc['points'] ?? [], 0, 3, true));
    $m = $margin ?? 0.3;
    $mAdj = max(0, $m - (1 - $m) * $a['cost'] / 100); // costs up by x% => margin down
    $rows = []; $endCash = $cash; $i = 0;
    $collected = $a['delay'] >= 90 ? 0 : $recv['open'] * $recv['collection_rate'] * (1 - $a['delay'] / 90);
    foreach ($months as $start => $p) {
        $rev = $p[0] * (1 + $a['sales'] / 100);
        $exp = ($exps[$i][0] ?? 0) * (1 + $a['expenses'] / 100);
        $gp = $rev * $mAdj;
        $net = $gp - $exp;
        $cashIn = $rev * 0.85 + ($i === 0 ? $collected : 0);          // most sales are paid in the month
        $cashOut = $exp + $loans90 / 3 + $restockMonth * (1 + $a['cost'] / 100) * (1 + $a['sales'] / 100);
        if ($endCash !== null) { $endCash += $cashIn - $cashOut; }
        $rows[] = ['month' => fc_label($start, 'month'), 'revenue' => $rev, 'gross' => $gp, 'expenses' => $exp, 'net' => $net, 'cash_end' => $endCash];
        $i++;
    }
    return $rows;
}

/* ------------------------------------------------------------------ inventory demand */

function fc_inventory(mysqli $conn): array
{
    $unitExpr = "si.quantity * GREATEST(COALESCE(si.pack_size, 1), 1)";
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name, p.unit, p.quantity, p.buying_price, COALESCE(p.reorder_level, 0) reorder_level,
        COALESCE((SELECT SUM($unitExpr) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.product_id = p.id AND s.status <> 'Cancelled' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)), 0) sold90,
        COALESCE((SELECT SUM($unitExpr) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.product_id = p.id AND s.status <> 'Cancelled' AND s.sale_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)), 0) sold30,
        (SELECT MAX(s.sale_date) FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE si.product_id = p.id AND s.status <> 'Cancelled') last_sale
        FROM products p WHERE p.item_type = 'Item' AND p.is_active = 1"), MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        // Recent demand weighs more: half last 30 days, half last 90 days.
        $daily = 0.5 * ((float) $r['sold30'] / 30) + 0.5 * ((float) $r['sold90'] / 90);
        $stock = (float) $r['quantity'];
        $r['daily'] = round($daily, 2);
        $r['days_left'] = $daily > 0 ? floor($stock / $daily) : null;
        $r['runs_out'] = $r['days_left'] !== null ? date('d M Y', strtotime('+' . (int) $r['days_left'] . ' days')) : null;
        $need = (int) ceil($daily * (FC_LEAD_DAYS + 30) - $stock);
        $r['recommend'] = max(0, $need);
        $r['recommend_cost'] = round($r['recommend'] * (float) $r['buying_price'], 2);
        if ($stock <= 0 && $daily > 0) { $r['status'] = 'Out of stock'; }
        elseif ($r['days_left'] !== null && $r['days_left'] <= FC_LEAD_DAYS) { $r['status'] = 'Will run out soon'; }
        elseif ($stock > 0 && (float) $r['sold90'] == 0.0) { $r['status'] = 'Slow / not moving'; }
        elseif ($r['days_left'] !== null && $r['days_left'] > 180) { $r['status'] = 'Overstocked'; }
        else { $r['status'] = 'OK'; }
    }
    unset($r);
    $order = ['Out of stock' => 0, 'Will run out soon' => 1, 'Slow / not moving' => 3, 'Overstocked' => 4, 'OK' => 2];
    usort($rows, function ($x, $y) use ($order) { return [$order[$x['status']], $x['days_left'] ?? 99999] <=> [$order[$y['status']], $y['days_left'] ?? 99999]; });
    return $rows;
}

/* ------------------------------------------------------------------ accuracy */

/** Saves this month's projections for the next 3 months (once a month), to compare with the actual results later. */
function fc_save_snapshots(mysqli $conn, array $fcs): void
{
    $already = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM fc_snapshots WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')"))[0];
    if ($already > 0) { return; }
    $s = mysqli_prepare($conn, 'INSERT IGNORE INTO fc_snapshots (metric, period_start, period_end, projected, low, high) VALUES (?, ?, ?, ?, ?, ?)');
    foreach ($fcs as $metric => $fc) {
        if (empty($fc['ok'])) { continue; }
        foreach (array_slice($fc['points'], 0, 3, true) as $start => [$v, $lo, $hi]) {
            $end = date('Y-m-d', strtotime(fc_next($start, 'month') . ' -1 day'));
            mysqli_stmt_bind_param($s, 'sssddd', $metric, $start, $end, $v, $lo, $hi);
            mysqli_stmt_execute($s);
        }
    }
}

/** Past projections whose month is over, with the actual result and the error. */
function fc_accuracy(mysqli $conn): array
{
    $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM fc_snapshots WHERE period_end < CURDATE() ORDER BY period_start DESC, metric LIMIT 48'), MYSQLI_ASSOC);
    foreach ($rows as &$r) {
        $sql = fc_metric_sql($conn, $r['metric']);
        $actual = 0.0;
        if ($sql) {
            $s = mysqli_prepare($conn, $sql[0]);
            mysqli_stmt_bind_param($s, 'ss', $r['period_start'], $r['period_end']);
            mysqli_stmt_execute($s);
            foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $x) { $actual += (float) $x['v']; }
        }
        $r['actual'] = round($actual, 2);
        $r['error_pct'] = $actual > 0 ? round(100 * ((float) $r['projected'] - $actual) / $actual, 1) : null;
        $r['inside'] = $actual >= (float) $r['low'] && $actual <= (float) $r['high'];
    }
    unset($r);
    return $rows;
}