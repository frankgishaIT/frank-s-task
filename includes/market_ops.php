<?php
/**
 * NEW FILE: includes/market_ops.php — RM MARKET INSIGHTS, stage 3: implementation and follow-up.
 *   - Links to Purchase Orders, RM Offerings items and Tasks (mi_links); the opportunity status follows the purchase:
 *     Approved → In Implementation (purchase order created) → Launched/Active (received into stock).
 *   - Post-introduction performance: approved vs purchased quantity, actual cost, price, quantity sold, remaining
 *     stock, revenue, gross profit, time to sell, and estimated vs actual (accuracy). MY MOTIVE learns the real
 *     "requests → purchases" rate from launched products.
 *   - Sales patterns: products bought together, what requesting customers buy, categories with rising sales.
 *   - Automated insights: existing products frequently out of stock become "Suggested by MY MOTIVE" opportunities.
 *   - Smart alerts in the existing notification system, consolidated and never repeated for the same event.
 */
require_once __DIR__ . '/market_eval.php';

const MI_OPS_DEFAULTS = ['alerts_on' => '1', 'approval_wait_days' => '3', 'approve_order_days' => '7', 'oos_alert_count' => '3', 'fast_factor' => '1.25'];

function mi_ops_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mi_eval_ensure($conn);
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mi_links (id INT(11) NOT NULL AUTO_INCREMENT, opportunity_id INT(11) NOT NULL, kind VARCHAR(10) NOT NULL, ref_id INT(11) NOT NULL,
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY lk (kind, ref_id, opportunity_id), KEY opp (opportunity_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mi_alerts (id INT(11) NOT NULL AUTO_INCREMENT, alert_key VARCHAR(120) NOT NULL, opportunity_id INT(11) NULL, kind VARCHAR(30) NOT NULL,
        message VARCHAR(500) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY ak (alert_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    try { // launch / completion dates on the opportunity (added once)
        $c = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mi_opportunities' AND column_name = 'launched_at'"));
        if ($c && !(int) $c[0]) { mysqli_query($conn, 'ALTER TABLE mi_opportunities ADD COLUMN launched_at DATETIME NULL, ADD COLUMN completed_at DATETIME NULL'); }
    } catch (Throwable $e) { }
}

function mi_oset(mysqli $conn, string $k): float { $v = mi_setting($conn, $k); return (float) ($v !== '' ? $v : (MI_OPS_DEFAULTS[$k] ?? 0)); }

/* ---------------------------------------------------------------- links ---------------------------------------------------------------- */

function mi_link_add(mysqli $conn, int $oppId, string $kind, int $ref): void
{
    $u = (int) ($_SESSION['user_id'] ?? 0) ?: null;
    $s = mysqli_prepare($conn, 'INSERT IGNORE INTO mi_links (opportunity_id, kind, ref_id, created_by) VALUES (?, ?, ?, ?)'); mysqli_stmt_bind_param($s, 'isii', $oppId, $kind, $ref, $u); mysqli_stmt_execute($s);
}

/** Purchase orders, tasks of an opportunity (with their current status). */
function mi_links(mysqli $conn, int $oppId): array
{
    $out = ['po' => [], 'task' => []];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT kind, ref_id FROM mi_links WHERE opportunity_id = $oppId ORDER BY id"), MYSQLI_ASSOC) as $l) {
        if ($l['kind'] === 'po') { $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, status, total_amount, order_date, supplier, received_at FROM purchase_orders WHERE id = ' . (int) $l['ref_id'])); if ($r) { $out['po'][] = $r; } }
        if ($l['kind'] === 'task') { $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT t.id, t.title, t.status, t.due_date, u.names FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to WHERE t.id = ' . (int) $l['ref_id'])); if ($r) { $out['task'][] = $r; } }
    }
    return $out;
}

/** Purchase stage shown to managers: Approved for purchase / PO created / Ordered / Received into stock / Monitoring. */
function mi_purchase_stage(array $o, array $links): string
{
    $st = array_column($links['po'], 'status');
    if (in_array('Received', $st, true)) { return $o['status'] === 'Completed' ? 'Completed' : 'Received into stock · monitoring sales'; }
    if (in_array('Ordered', $st, true)) { return 'Ordered'; }
    if (in_array('Draft', $st, true)) { return 'Purchase order created (draft)'; }
    return $o['status'] === 'Approved' ? 'Approved for purchase' : '';
}

/** Moves the status along with the purchase (never backwards, never over a management decision). */
function mi_sync_status(mysqli $conn, array &$o): void
{
    if (!in_array($o['status'], ['Approved', 'In Implementation'], true)) { return; }
    $id = (int) $o['id']; $links = mi_links($conn, $id); $now = date('Y-m-d H:i:s');
    $received = array_filter($links['po'], function ($p) { return $p['status'] === 'Received'; });
    if ($received) {
        $at = min(array_map(function ($p) { return $p['received_at'] ?: $p['order_date']; }, $received));
        mysqli_query($conn, "UPDATE mi_opportunities SET status = 'Launched/Active', launched_at = COALESCE(launched_at, '" . mysqli_real_escape_string($conn, (string) $at) . "') WHERE id = $id");
        mi_audit($conn, $id, null, 'launched', 'Received into stock (purchase order #' . implode(', #', array_column($received, 'id')) . '): sales monitoring starts');
        $o['status'] = 'Launched/Active'; $o['launched_at'] = $at;
    } elseif ($o['status'] === 'Approved' && array_filter($links['po'], function ($p) { return $p['status'] !== 'Cancelled'; })) {
        mysqli_query($conn, "UPDATE mi_opportunities SET status = 'In Implementation' WHERE id = $id");
        mi_audit($conn, $id, null, 'implementation', 'Purchase order created');
        $o['status'] = 'In Implementation';
    }
}

/* ---------------------------------------------------------------- performance ---------------------------------------------------------------- */

/**
 * Post-introduction performance of a launched product (or service) against the approved estimate.
 * Returns null when the opportunity has not been launched.
 */
function mi_performance(mysqli $conn, array $o): ?array
{
    if (empty($o['launched_at']) || !$o['product_id']) { return null; }
    $id = (int) $o['id']; $pid = (int) $o['product_id'];
    $from = substr((string) $o['launched_at'], 0, 10); $to = $o['completed_at'] ? substr((string) $o['completed_at'], 0, 10) : date('Y-m-d');
    $dec = mi_last_decision($conn, $id, 'Approved');
    $snap = $dec ? (json_decode((string) $dec['snapshot'], true) ?: []) : [];
    $prod = mi_products($conn, [$pid])[$pid] ?? null;
    $links = mi_links($conn, $id);
    $poIds = array_map('intval', array_column(array_filter($links['po'], function ($p) { return $p['status'] === 'Received'; }), 'id'));
    $bought = 0.0; $cost = 0.0;
    if ($poIds) {
        $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COALESCE(SUM(quantity * COALESCE(NULLIF(pack_size, 0), 1)), 0) q, COALESCE(SUM(line_total), 0) c FROM purchase_order_items WHERE product_id = ' . $pid . ' AND purchase_order_id IN (' . implode(',', $poIds) . ')'));
        $bought = (float) $r['q']; $cost = (float) $r['c'];
    }
    $sold = mi_sales_between($conn, $pid, $from, $to);
    $unitCost = $bought > 0 ? $cost / $bought : (float) ($prod['buying_price'] ?? 0);
    $days = max(1, (int) floor((strtotime($to) - strtotime($from)) / 86400) + 1);
    $e = mi_eval_get($conn, $id);
    $period = (int) ($e['sales_period_days'] ?? 0) ?: (int) mi_eset($conn, 'sales_period_days');
    $expQty = (float) ($snap['qty'] ?? 0) ?: (float) ($dec['approved_qty'] ?? 0);
    $expPrice = isset($snap['price']) ? (float) $snap['price'] : null;
    $expGp = isset($snap['gross_profit']) ? (float) $snap['gross_profit'] : null;
    $p = ['from' => $from, 'to' => $to, 'days' => $days, 'period' => $period, 'requests' => mi_stats($conn, [$id])[$id] ?? null,
          'approved_qty' => $dec ? (float) $dec['approved_qty'] : null, 'bought' => $bought, 'cost' => $cost, 'unit_cost' => $unitCost,
          'sold' => $sold['q'], 'revenue' => $sold['v'], 'sales' => $sold['n'], 'price' => $sold['q'] > 0 ? $sold['v'] / $sold['q'] : null,
          'stock' => $prod ? (float) $prod['quantity'] : null, 'gross_profit' => $sold['v'] - $sold['q'] * $unitCost,
          'exp_qty' => $expQty, 'exp_price' => $expPrice, 'exp_gp' => $expGp, 'exp_revenue' => isset($snap['revenue']) ? (float) $snap['revenue'] : null];
    // pace: expected units sold by now (straight line over the sales period) vs actual
    $p['expected_by_now'] = $expQty > 0 ? min($expQty, $expQty * $days / max(1, $period)) : null;
    $p['pace'] = $p['expected_by_now'] ? $p['sold'] / $p['expected_by_now'] : null;
    $fast = mi_oset($conn, 'fast_factor');
    $p['verdict'] = $p['pace'] === null ? 'No estimate to compare' : ($p['pace'] >= $fast ? 'Selling faster than expected' : ($p['pace'] >= 0.8 ? 'On track' : ($days < 7 ? 'Too early to judge' : 'Selling slower than expected')));
    $p['sell_through'] = $bought > 0 ? min(100, $p['sold'] / $bought * 100) : null;
    $p['days_to_sell'] = $bought > 0 && $p['sold'] >= $bought ? $days : null;
    $p['days_left_est'] = $bought > 0 && $p['sold'] > 0 && $p['sold'] < $bought ? (int) ceil(($bought - $p['sold']) / ($p['sold'] / $days)) : null;
    $p['qty_accuracy'] = $expQty > 0 && $days >= $period ? min(200, $p['sold'] / $expQty * 100) : null;
    $p['gp_accuracy'] = $expGp ? $p['gross_profit'] / $expGp * 100 : null;
    return $p;
}

/**
 * What MY MOTIVE learned: across launched products, the share of the quantity customers requested (90 days before
 * approval) that was actually sold within the sales period. Compared with the conversion setting.
 */
function mi_learning(mysqli $conn): array
{
    $rates = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mi_opportunities WHERE kind = 'product' AND launched_at IS NOT NULL AND product_id IS NOT NULL AND merged_into IS NULL"), MYSQLI_ASSOC) as $o) {
        $p = mi_performance($conn, $o); if (!$p || $p['days'] < $p['period']) { continue; }
        $req = mi_stats($conn, [(int) $o['id']], date('Y-m-d', strtotime($p['from'] . ' -90 days')), $p['from'])[(int) $o['id']] ?? null;
        if (!$req || $req['quantity'] <= 0) { continue; }
        $soldInPeriod = mi_sales_between($conn, (int) $o['product_id'], $p['from'], date('Y-m-d', strtotime($p['from'] . ' +' . $p['period'] . ' days')))['q'];
        $rates[] = min(300, $soldInPeriod / $req['quantity'] * 100);
    }
    return ['n' => count($rates), 'rate' => $rates ? array_sum($rates) / count($rates) : null, 'setting' => mi_eset($conn, 'conversion_pct')];
}

/* ---------------------------------------------------------------- sales patterns ---------------------------------------------------------------- */

/** Products most often sold in the same sale as $productId (last 180 days). */
function mi_bought_together(mysqli $conn, int $productId, int $limit = 6): array
{
    try {
        return mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name, COUNT(DISTINCT a.sale_id) n FROM sale_items a JOIN sale_items b ON b.sale_id = a.sale_id AND b.product_id <> a.product_id JOIN products p ON p.id = b.product_id
            JOIN sales s ON s.id = a.sale_id WHERE a.product_id = $productId AND s.status IN ('Paid', 'Partially Paid', 'Credit') AND s.sale_date >= '" . date('Y-m-d', strtotime('-180 days')) . "'
            GROUP BY p.id, p.product_name ORDER BY n DESC LIMIT $limit"), MYSQLI_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** What the customers who asked for this opportunity usually buy from RISE MOTIVE (complementary products). */
function mi_requesters_buy(mysqli $conn, int $oppId, int $limit = 6): array
{
    $c = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT customer_id FROM mi_requests WHERE opportunity_id = $oppId AND customer_id IS NOT NULL"), MYSQLI_ASSOC), 'customer_id'));
    if (!$c) { return []; }
    try {
        return mysqli_fetch_all(mysqli_query($conn, 'SELECT p.id, p.product_name, COUNT(DISTINCT s.customer_id) customers, COUNT(DISTINCT s.id) n FROM sales s JOIN sale_items si ON si.sale_id = s.id JOIN products p ON p.id = si.product_id
            WHERE s.customer_id IN (' . implode(',', $c) . ") AND s.status IN ('Paid', 'Partially Paid', 'Credit') AND s.sale_date >= '" . date('Y-m-d', strtotime('-365 days')) . "'
            GROUP BY p.id, p.product_name ORDER BY customers DESC, n DESC LIMIT $limit"), MYSQLI_ASSOC);
    } catch (Throwable $e) { return []; }
}

/** Product categories whose sales grew: last 90 days vs the 90 days before (base units). */
function mi_category_trends(mysqli $conn, int $limit = 8): array
{
    $out = [];
    try {
        $a = date('Y-m-d', strtotime('-90 days')); $b = date('Y-m-d', strtotime('-180 days'));
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT COALESCE(NULLIF(p.category, ''), 'No category') cat, SUM(CASE WHEN s.sale_date >= '$a' THEN si.line_total ELSE 0 END) recent, SUM(CASE WHEN s.sale_date < '$a' THEN si.line_total ELSE 0 END) prev
            FROM sale_items si JOIN sales s ON s.id = si.sale_id JOIN products p ON p.id = si.product_id WHERE s.status IN ('Paid', 'Partially Paid', 'Credit') AND s.sale_date >= '$b' GROUP BY cat"), MYSQLI_ASSOC) as $r) {
            $r['recent'] = (float) $r['recent']; $r['prev'] = (float) $r['prev']; $r['growth'] = $r['prev'] > 0 ? ($r['recent'] - $r['prev']) / $r['prev'] * 100 : null;
            $out[] = $r;
        }
    } catch (Throwable $e) { }
    usort($out, function ($x, $y) { return [$y['growth'] === null ? -1 : 1, $y['growth'] ?? $y['recent']] <=> [$x['growth'] === null ? -1 : 1, $x['growth'] ?? $x['recent']]; });
    return array_slice($out, 0, $limit);
}

/* ---------------------------------------------------------------- automated insights ---------------------------------------------------------------- */

/**
 * Existing products that sell but are out of stock / at reorder level, without an open opportunity, become
 * "Suggested by MY MOTIVE" opportunities (one per product, never duplicated). Returns the number created.
 */
function mi_auto_insights(mysqli $conn): int
{
    $n = 0;
    try {
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name, p.category, p.unit, p.quantity, p.reorder_level FROM products p WHERE p.item_type = 'Item' AND p.is_active = 1
            AND p.quantity <= GREATEST(COALESCE(p.reorder_level, 0), 0) AND NOT EXISTS (SELECT 1 FROM mi_opportunities o WHERE o.product_id = p.id AND o.merged_into IS NULL AND o.status NOT IN ('Rejected', 'Completed'))"), MYSQLI_ASSOC);
    } catch (Throwable $e) { return 0; }
    foreach ($rows as $p) {
        $s = mi_sold($conn, (int) $p['id'], 60);
        if ($s['q'] <= 0 || $s['n'] < 2) { continue; } // only products that really sell
        $id = mi_create_opportunity($conn, ['kind' => 'product', 'name' => $p['product_name'], 'category' => $p['category'], 'unit' => $p['unit'], 'product_id' => (int) $p['id'], 'origin' => 'system',
            'notes' => 'Stock ' . mi_num($p['quantity']) . ' — sold ' . mi_num($s['q']) . ' in ' . $s['n'] . ' sale(s) over the last 60 days.']);
        mi_audit($conn, $id, null, 'insight', 'Found by MY MOTIVE: out of stock / at reorder level while still selling');
        $n++;
    }
    return $n;
}

/* ---------------------------------------------------------------- smart alerts ---------------------------------------------------------------- */

/** Records an alert once (by key) and sends it to Admins and Managers. Returns true when it is new. */
function mi_alert(mysqli $conn, string $key, ?int $oppId, string $kind, string $msg, bool $send = true): bool
{
    $s = mysqli_prepare($conn, 'INSERT IGNORE INTO mi_alerts (alert_key, opportunity_id, kind, message) VALUES (?, ?, ?, ?)');
    $m = mb_substr($msg, 0, 500); mysqli_stmt_bind_param($s, 'siss', $key, $oppId, $kind, $m); mysqli_stmt_execute($s);
    return mysqli_stmt_affected_rows($s) > 0;
}

/**
 * Checks every alert rule (at most once an hour) and sends ONE consolidated notification per run to Admins and
 * Managers. The same event is never notified twice (alert keys).
 */
function mi_alerts_run(mysqli $conn, bool $force = false): array
{
    if (!$force && (!mi_oset($conn, 'alerts_on') || (int) mi_setting($conn, 'alerts_last_run') > time() - 3600)) { return []; }
    mi_setting_set($conn, 'alerts_last_run', (string) time());
    $new = []; $wk = date('o-W'); $today = date('Y-m-d');
    $add = function (string $key, ?int $opp, string $kind, string $msg) use ($conn, &$new) { if (mi_alert($conn, $key, $opp, $kind, $msg)) { $new[] = $msg; } };
    try { if (($n = mi_auto_insights($conn)) > 0) { $add('insight:' . $today, null, 'insight', $n . ' product(s) that sell well are out of stock or at reorder level: added to Demand analysis as "Suggested by MY MOTIVE".'); } } catch (Throwable $e) { }
    $an = (int) mi_setting($conn, 'alert_repeat_count'); $ad = (int) mi_setting($conn, 'alert_repeat_days'); $min = (int) mi_setting($conn, 'analysis_min_requests'); $oosN = (int) mi_oset($conn, 'oos_alert_count');
    $since = date('Y-m-d H:i:s', time() - $ad * 86400);
    $opps = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mi_opportunities WHERE merged_into IS NULL AND status NOT IN ('Rejected', 'Completed')"), MYSQLI_ASSOC);
    $stats = mi_stats($conn, array_map('intval', array_column($opps, 'id')));
    foreach ($opps as $o) {
        $id = (int) $o['id']; $st = $stats[$id] ?? null; $nm = '"' . $o['name'] . '"'; $kind = $o['kind'] === 'service' ? 'service' : 'product';
        if ($st && in_array($o['status'], ['New', 'Under Review', 'More Information Required'], true)) {
            $rep = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mi_requests WHERE opportunity_id = $id AND requested_at >= '$since'"))[0];
            if ($rep >= $an) { $add("rep:$id:$wk", $id, 'repeated', ucfirst($kind) . " $nm was requested $rep times in $ad days" . ($kind === 'service' ? ' and is not available.' : '.')); }
            if ($st['requests'] >= $min) { $add("qual:$id", $id, 'qualified', "$nm has $st[requests] requests: it now qualifies for market analysis."); }
            [$lvl] = mi_classify($conn, $st); if ($lvl === 'Growing') { $add("grow:$id:" . date('Y-m'), $id, 'growing', "Demand for $nm is increasing."); }
            $oos = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mi_requests WHERE opportunity_id = $id AND reason = 'Out of stock' AND requested_at >= '$since'"))[0];
            if ($oos >= $oosN) { $add("oos:$id:$wk", $id, 'out_of_stock', "$nm was out of stock for $oos customers in $ad days."); }
        }
        if ($o['status'] === 'Recommended') {
            $sub = mi_last_decision($conn, $id, 'Submitted');
            if ($sub && strtotime($sub['created_at']) < time() - mi_oset($conn, 'approval_wait_days') * 86400) { $add('wait:' . $id . ':' . $sub['id'], $id, 'awaiting', "$nm has been awaiting management approval since " . date('d M', strtotime($sub['created_at'])) . '.'); }
        }
        if ($o['status'] === 'Approved' && $kind === 'product') {
            $ap = mi_last_decision($conn, $id, 'Approved');
            if ($ap && strtotime($ap['created_at']) < time() - mi_oset($conn, 'approve_order_days') * 86400) { $add('noorder:' . $id . ':' . $ap['id'], $id, 'not_ordered', "$nm was approved on " . date('d M', strtotime($ap['created_at'])) . ' but no purchase order has been made yet.'); }
        }
        if ($o['status'] === 'Launched/Active' && !empty($o['launched_at'])) {
            $p = mi_performance($conn, $o);
            if ($p && $p['verdict'] === 'Selling faster than expected') { $add("fast:$id", $id, 'fast', "$nm is selling faster than expected (" . mi_num($p['sold']) . ' sold in ' . $p['days'] . ' days).'); }
            if ($p && $p['verdict'] === 'Selling slower than expected' && $p['days'] >= $p['period']) { $add("slow:$id", $id, 'slow', "$nm is selling slower than expected (" . mi_num($p['sold']) . ' of ' . mi_num($p['exp_qty']) . ' sold in ' . $p['days'] . ' days).'); }
        }
    }
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT q.id, q.supplier_name, q.valid_until, o.id oid, o.name FROM mi_quotes q JOIN mi_opportunities o ON o.id = q.opportunity_id WHERE q.is_active = 1 AND q.valid_until IS NOT NULL AND q.valid_until < '$today' AND o.status IN ('New', 'Under Review', 'More Information Required', 'Recommended', 'Approved')"), MYSQLI_ASSOC) as $q) {
        $add('qexp:' . $q['id'], (int) $q['oid'], 'quote_expired', 'The quotation of ' . $q['supplier_name'] . ' for "' . $q['name'] . '" expired on ' . date('d M Y', strtotime($q['valid_until'])) . '.');
    }
    $pc = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mi_requests WHERE source = 'Customer Portal' AND requested_at >= '" . date('Y-m-d', strtotime('-1 day')) . " 00:00:00' AND requested_at < '$today 00:00:00'"))[0];
    if ($pc) { $add('portal:' . $today, null, 'portal', $pc . ' product / service suggestion(s) from the Customer Portal yesterday.'); }
    // capability: several employees with the same gap; recommendations needing a management decision
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT g.skill_key, MAX(g.skill) skill, COUNT(DISTINCT ge.user_id) n FROM mi_gaps g JOIN mi_gap_employees ge ON ge.gap_id = g.id WHERE g.status = 'Open' GROUP BY g.skill_key HAVING COUNT(DISTINCT ge.user_id) >= 2"), MYSQLI_ASSOC) as $g) {
        $add('skill:' . $g['skill_key'] . ':' . $g['n'], null, 'skill_gap', $g['n'] . ' employees need "' . $g['skill'] . '": a training opportunity.');
    }
    $recMsg = ['Recruit a qualified employee' => 'may justify recruiting a specialist', 'Outsource or partner' => 'can be delivered through a partner (outsourcing)', 'Purchase equipment' => 'requires an equipment investment', 'Acquire software / licence' => 'requires software or a licence'];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT g.id, g.recommendation, g.skill, o.name FROM mi_gaps g LEFT JOIN mi_opportunities o ON o.id = g.opportunity_id WHERE g.status = 'Open'"), MYSQLI_ASSOC) as $g) {
        if (isset($recMsg[$g['recommendation']])) { $add('gap:' . $g['id'] . ':' . md5($g['recommendation']), null, 'capability', 'Service "' . ($g['name'] ?: $g['skill']) . '" ' . $recMsg[$g['recommendation']] . '.'); }
    }
    if ($new) {
        require_once __DIR__ . '/notification_helper.php';
        $title = count($new) === 1 ? 'RM Market Insights' : 'RM Market Insights: ' . count($new) . ' new alerts';
        $body = count($new) === 1 ? $new[0] : '• ' . implode("\n• ", array_slice($new, 0, 8)) . (count($new) > 8 ? "\n… and " . (count($new) - 8) . ' more (RM Market Insights → Dashboard).' : '');
        try { notify_admins_and_managers($conn, $title, $body); } catch (Throwable $e) { error_log('mi alerts: ' . $e->getMessage()); }
    }
    return $new;
}