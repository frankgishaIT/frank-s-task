<?php
/**
 * NEW FILE: includes/customer_analytics.php — data for Customers > Analytics (Task 2).
 *
 * Rules (the same as the Customers & Sales reports):
 *  - A PURCHASE = a sale with status Paid, Partially Paid or Credit (confirmed invoices). Pending Discount Approval
 *    and Cancelled sales never count. Value = the invoice total (after discount).
 *  - Best customer = the highest total value of purchases in the month / year. Ties: more purchases, then the
 *    customer registered first. Loyalty points and number of purchases are shown as extra indicators only.
 *  - Customer segments are MUTUALLY EXCLUSIVE (each customer is in exactly one), for the chosen period:
 *      New        registered during the period
 *      Returning  registered before; bought during the period AND had bought before it
 *      Active     registered before; bought during the period for the FIRST time
 *      Inactive   registered before; did not buy during the period
 *  - Purchase frequency in the period: one-time = 1 purchase, repeat = 2 to 4, frequent = 5 or more.
 *  - Everything is calculated from the live records, so rankings change as soon as a sale is recorded, paid or cancelled.
 */
require_once __DIR__ . '/analytics_ui.php';
require_once __DIR__ . '/customer_report_queries.php';

const CA_PURCHASE = "s.status IN ('Paid', 'Partially Paid', 'Credit')";
const CA_FREQUENT_MIN = 5;
const CA_REPEAT_MIN = 2;
const CA_SEGMENTS = [
    'new' => ['New', 'Registered during the period', '#1E2FE0'],
    'active' => ['Active (first purchase)', 'Registered before; first purchase during the period', '#0FA968'],
    'returning' => ['Returning', 'Registered before; bought during the period and before it', '#7C5CE0'],
    'inactive' => ['Inactive', 'Registered before; no purchase during the period', '#C9CEDD'],
];
const CA_FREQ = ['one' => 'One-time (1 purchase)', 'repeat' => 'Repeat (2–4 purchases)', 'frequent' => 'Frequent (5 or more)'];

function ca_code(mysqli $conn, int $id): string
{
    return function_exists('sms_customer_code') ? sms_customer_code($conn, $id) : customer_report_code($id);
}

/** Period for the location / growth / segment charts. All History: from the first customer. */
function ca_bounds(mysqli $conn, array $period): array
{
    $end = min($period['end'] ?? date('Y-m-d'), date('Y-m-d')); // never in the future (This Year ends today, not 31 Dec)
    $start = $period['start'];
    if ($start === null) {
        $first = mysqli_fetch_row(mysqli_query($conn, 'SELECT DATE(MIN(created_at)) FROM customers'))[0] ?? null;
        $start = $first ?: date('Y-01-01');
    }
    return [$start, $end];
}

/* ------------------------------------------------------------------ location */

/** Customers registered in the period, by province / district / sector. Returns [['label', 'value', 'n', 'pct']]. */
function ca_location(mysqli $conn, array $period, string $level): array
{
    [$from, $to] = ca_bounds($conn, $period);
    $col = ['province' => 'province', 'district' => 'district', 'sector' => 'sector'][$level] ?? 'province';
    $label = $col === 'province' ? "COALESCE(NULLIF(TRIM(province), ''), '')"
        : ($col === 'district' ? "COALESCE(NULLIF(TRIM(district), ''), '')" : "COALESCE(NULLIF(TRIM(sector), ''), '')");
    $ctx = $col === 'province' ? "''" : ($col === 'district' ? "COALESCE(province, '')" : "CONCAT(COALESCE(district, ''), ', ', COALESCE(province, ''))");
    $rows = an_rows($conn, "SELECT $label AS v, MAX($ctx) AS ctx, COUNT(*) AS n FROM customers
        WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY v ORDER BY n DESC, v", 'ss', [$from, $to]);
    $total = array_sum(array_column($rows, 'n'));
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['value' => $r['v'], 'label' => $r['v'] === '' ? 'Not specified' : $r['v'] . ($r['ctx'] && trim($r['ctx'], ', ') !== '' ? ' (' . trim($r['ctx'], ', ') . ')' : ''),
            'n' => (int) $r['n'], 'pct' => $total ? round(100 * $r['n'] / $total, 1) : 0];
    }
    return $out;
}

/* ------------------------------------------------------------------ best customers */

/** Top customers by confirmed purchase value between two dates (first = the best). */
function ca_best(mysqli $conn, string $from, string $to, int $limit = 5): array
{
    $loyalty = an_has_column($conn, 'customers', 'loyalty_points') ? 'c.loyalty_points' : 'NULL';
    $photo = null;
    foreach (['photo', 'photo_path', 'image', 'avatar'] as $p) { if (an_has_column($conn, 'customers', $p)) { $photo = 'c.' . $p; break; } }
    $rows = an_rows($conn, "SELECT c.id, MAX(c.name) AS name, MAX(c.phone) AS phone, MAX(c.province) AS province, MAX(c.district) AS district,
            MAX($loyalty) AS loyalty_points, MAX(" . ($photo ?: 'NULL') . ") AS photo,
            SUM(s.total_amount) AS total, COUNT(*) AS purchases, MIN(c.created_at) AS registered
        FROM sales s JOIN customers c ON c.id = s.customer_id
        WHERE " . CA_PURCHASE . " AND s.sale_date BETWEEN ? AND ?
        GROUP BY c.id
        ORDER BY total DESC, purchases DESC, c.id ASC LIMIT " . (int) $limit, 'ss', [$from, $to]);
    foreach ($rows as &$r) { $r['code'] = ca_code($conn, (int) $r['id']); $r['total'] = (float) $r['total']; $r['purchases'] = (int) $r['purchases']; }
    return $rows;
}

/* ------------------------------------------------------------------ growth & revenue (monthly) */

/** Months shown in the trend charts: the period's months, at least the last 6. */
function ca_trend_months(mysqli $conn, array $period): array
{
    [$from, $to] = ca_bounds($conn, $period);
    $minFrom = date('Y-m-01', strtotime(date('Y-m-01', strtotime($to)) . ' -5 months'));
    return an_months(min($from, $minFrom), $to);
}

function ca_growth(mysqli $conn, array $period): array
{
    $months = ca_trend_months($conn, $period);
    $first = array_key_first($months) . '-01';
    $last = date('Y-m-t', strtotime(array_key_last($months) . '-01'));
    $new = [];
    foreach (an_rows($conn, "SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) n FROM customers WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY m", 'ss', [$first, $last]) as $r) { $new[$r['m']] = (int) $r['n']; }
    $before = (int) (an_rows($conn, 'SELECT COUNT(*) n FROM customers WHERE DATE(created_at) < ?', 's', [$first])[0]['n'] ?? 0);
    $out = []; $total = $before;
    foreach ($months as $m => $label) {
        $n = $new[$m] ?? 0;
        $rate = $total > 0 ? round(100 * $n / $total, 1) : null;
        $total += $n;
        $out[] = ['month' => $m, 'label' => $label, 'new' => $n, 'total' => $total, 'rate' => $rate];
    }
    return $out;
}

function ca_revenue(mysqli $conn, array $period): array
{
    $months = ca_trend_months($conn, $period);
    $first = array_key_first($months) . '-01';
    $last = date('Y-m-t', strtotime(array_key_last($months) . '-01'));
    $rev = [];
    foreach (an_rows($conn, "SELECT DATE_FORMAT(s.sale_date, '%Y-%m') m, SUM(CASE WHEN s.customer_id IS NOT NULL THEN s.total_amount ELSE 0 END) reg,
            SUM(CASE WHEN s.customer_id IS NULL THEN s.total_amount ELSE 0 END) walkin, COUNT(DISTINCT s.customer_id) buyers
            FROM sales s WHERE " . CA_PURCHASE . " AND s.sale_date BETWEEN ? AND ? GROUP BY m", 'ss', [$first, $last]) as $r) { $rev[$r['m']] = $r; }
    $out = []; $prev = null;
    foreach ($months as $m => $label) {
        $reg = (float) ($rev[$m]['reg'] ?? 0); $walk = (float) ($rev[$m]['walkin'] ?? 0);
        $out[] = ['month' => $m, 'label' => $label, 'registered' => $reg, 'walkin' => $walk, 'total' => $reg + $walk, 'buyers' => (int) ($rev[$m]['buyers'] ?? 0),
            'growth' => $prev !== null && $prev > 0 ? round(100 * ($reg - $prev) / $prev, 1) : null];
        $prev = $reg;
    }
    return $out;
}

/* ------------------------------------------------------------------ segments & frequency */

/** Customer id => segment key (registered up to the end of the period). */
function ca_segment_map(mysqli $conn, array $period): array
{
    [$from, $to] = ca_bounds($conn, $period);
    if ($period['start'] === null) { $from = date('Y-01-01', strtotime($to)); } // All History: this year's activity
    $boughtIn = array_flip(array_map('intval', array_column(an_rows($conn, "SELECT DISTINCT s.customer_id id FROM sales s WHERE " . CA_PURCHASE . " AND s.customer_id IS NOT NULL AND s.sale_date BETWEEN ? AND ?", 'ss', [$from, $to]), 'id')));
    $boughtBefore = array_flip(array_map('intval', array_column(an_rows($conn, "SELECT DISTINCT s.customer_id id FROM sales s WHERE " . CA_PURCHASE . " AND s.customer_id IS NOT NULL AND s.sale_date < ?", 's', [$from]), 'id')));
    $map = [];
    foreach (an_rows($conn, 'SELECT id, DATE(created_at) d FROM customers WHERE DATE(created_at) <= ?', 's', [$to]) as $c) {
        $id = (int) $c['id'];
        if ($c['d'] >= $from) { $map[$id] = 'new'; }
        elseif (isset($boughtIn[$id])) { $map[$id] = isset($boughtBefore[$id]) ? 'returning' : 'active'; }
        else { $map[$id] = 'inactive'; }
    }
    return $map;
}

/** Customer id => number of purchases in the period. */
function ca_purchase_counts(mysqli $conn, array $period): array
{
    [$from, $to] = ca_bounds($conn, $period);
    $out = [];
    foreach (an_rows($conn, "SELECT s.customer_id id, COUNT(*) n FROM sales s WHERE " . CA_PURCHASE . " AND s.customer_id IS NOT NULL AND s.sale_date BETWEEN ? AND ? GROUP BY s.customer_id", 'ss', [$from, $to]) as $r) {
        $out[(int) $r['id']] = (int) $r['n'];
    }
    return $out;
}

function ca_freq_key(int $n): string { return $n >= CA_FREQUENT_MIN ? 'frequent' : ($n >= CA_REPEAT_MIN ? 'repeat' : 'one'); }

/* ------------------------------------------------------------------ services */

/** Services requested in the period: [['id', 'name', 'requests', 'quantity', 'customers', 'pct']]. */
function ca_services(mysqli $conn, array $period): array
{
    [$from, $to] = ca_bounds($conn, $period);
    $rows = an_rows($conn, "SELECT p.id, MAX(p.product_name) name, COUNT(*) requests, SUM(si.quantity) quantity, COUNT(DISTINCT s.customer_id) customers
        FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id
        WHERE " . CA_PURCHASE . " AND p.item_type = 'Service' AND s.sale_date BETWEEN ? AND ?
        GROUP BY p.id ORDER BY requests DESC, quantity DESC", 'ss', [$from, $to]);
    $total = array_sum(array_column($rows, 'requests'));
    foreach ($rows as &$r) { $r['requests'] = (int) $r['requests']; $r['pct'] = $total ? round(100 * $r['requests'] / $total, 1) : 0; }
    return $rows;
}

/* ------------------------------------------------------------------ drill-down lists */

/** The customer records behind a chart bar / slice. Returns [title, rows]. */
function ca_drill(mysqli $conn, array $period, array $q): array
{
    [$from, $to] = ca_bounds($conn, $period);
    $type = (string) ($q['drill'] ?? '');
    $ids = null; $title = '';
    switch ($type) {
        case 'location':
            $level = in_array($q['level'] ?? '', ['province', 'district', 'sector'], true) ? $q['level'] : 'province';
            $val = (string) ($q['value'] ?? '');
            $rows = an_rows($conn, "SELECT id FROM customers WHERE DATE(created_at) BETWEEN ? AND ? AND COALESCE(NULLIF(TRIM($level), ''), '') = ?", 'sss', [$from, $to, $val]);
            $ids = array_column($rows, 'id');
            $title = 'Customers in ' . ($val === '' ? '(no ' . $level . ' recorded)' : $val) . ' · registered in the period';
            break;
        case 'segment':
            $key = (string) ($q['key'] ?? '');
            $ids = array_keys(array_filter(ca_segment_map($conn, $period), function ($s) use ($key) { return $s === $key; }));
            $title = (CA_SEGMENTS[$key][0] ?? 'Segment') . ' customers · ' . (CA_SEGMENTS[$key][1] ?? '');
            break;
        case 'frequency':
            $key = (string) ($q['key'] ?? '');
            $ids = array_keys(array_filter(ca_purchase_counts($conn, $period), function ($n) use ($key) { return ca_freq_key($n) === $key; }));
            $title = (CA_FREQ[$key] ?? 'Customers') . ' in the period';
            break;
        case 'service':
            $pid = (int) ($q['id'] ?? 0);
            $ids = array_column(an_rows($conn, "SELECT DISTINCT s.customer_id id FROM sale_items si JOIN sales s ON s.id = si.sale_id WHERE " . CA_PURCHASE . " AND si.product_id = ? AND s.customer_id IS NOT NULL AND s.sale_date BETWEEN ? AND ?", 'iss', [$pid, $from, $to]), 'id');
            $name = an_rows($conn, 'SELECT product_name n FROM products WHERE id = ?', 'i', [$pid])[0]['n'] ?? 'the service';
            $title = 'Customers who requested ' . $name;
            break;
        case 'new_month':
            $m = preg_match('/^\d{4}-\d{2}$/', (string) ($q['month'] ?? '')) ? $q['month'] : date('Y-m');
            $ids = array_column(an_rows($conn, "SELECT id FROM customers WHERE DATE_FORMAT(created_at, '%Y-%m') = ?", 's', [$m]), 'id');
            $title = 'New customers in ' . date('F Y', strtotime($m . '-01'));
            $from = $m . '-01'; $to = date('Y-m-t', strtotime($from));
            break;
        case 'buyers_month':
            $m = preg_match('/^\d{4}-\d{2}$/', (string) ($q['month'] ?? '')) ? $q['month'] : date('Y-m');
            $from = $m . '-01'; $to = date('Y-m-t', strtotime($from));
            $ids = array_column(an_rows($conn, "SELECT DISTINCT s.customer_id id FROM sales s WHERE " . CA_PURCHASE . " AND s.customer_id IS NOT NULL AND s.sale_date BETWEEN ? AND ?", 'ss', [$from, $to]), 'id');
            $title = 'Customers who bought in ' . date('F Y', strtotime($from));
            break;
        default:
            return ['', []];
    }
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids) { return [$title, []]; }
    $loyalty = an_has_column($conn, 'customers', 'loyalty_points') ? 'c.loyalty_points' : 'NULL';
    $rows = an_rows($conn, "SELECT c.id, c.name, c.phone, c.province, c.district, c.sector, DATE(c.created_at) registered, $loyalty loyalty_points,
            COALESCE(p.n, 0) purchases, COALESCE(p.total, 0) total
        FROM customers c LEFT JOIN (SELECT s.customer_id, COUNT(*) n, SUM(s.total_amount) total FROM sales s WHERE " . CA_PURCHASE . " AND s.sale_date BETWEEN ? AND ? GROUP BY s.customer_id) p ON p.customer_id = c.id
        WHERE c.id IN (" . implode(',', $ids) . ") ORDER BY total DESC, c.name LIMIT 500", 'ss', [$from, $to]);
    foreach ($rows as &$r) { $r['code'] = ca_code($conn, (int) $r['id']); }
    return [$title, $rows];
}