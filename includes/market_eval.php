<?php
/**
 * NEW FILE: includes/market_eval.php — RM MARKET INSIGHTS, stage 2: supplier quotations and comparison, feasibility and
 * profitability (products and services), financial capacity (Capital Fund), employee capability assessment, the
 * recommendation engine (always with the reasons) and the management decision workflow.
 *
 * Rules kept here:
 *   - A missing supplier price is never a confirmed cost: a manual estimate is labelled "estimate".
 *   - Gross profit is never called net profit (operating expenses are not deducted).
 *   - Working capital = Capital Fund available (already net of pending expenses and approved commitments) minus the
 *     budgets of market opportunities approved but not yet ordered. Other funds are never counted.
 *   - Requests are signs of demand, not sales: suggested quantities use a conversion rate and never exceed demand.
 *   - Before an organisational capability gap is declared, other employees' skills and availability, the department,
 *     equipment, software and existing offerings are checked.
 */
require_once __DIR__ . '/market_helpers.php';

const MI_PRODUCT_RECS = ['Recommended to Introduce' => 'success', 'Increase Stock Quantity' => 'primary', 'Consider Introducing' => 'info', 'Monitor Demand' => 'warning',
                         'More Market Research Needed' => 'secondary', 'Not Recommended for Now' => 'danger'];
const MI_SERVICE_RECS = ['Introduce the service' => 'success', 'Assign a qualified colleague' => 'success', 'Train existing employees' => 'primary', 'Recruit a qualified employee' => 'primary',
                         'Outsource or partner' => 'info', 'Purchase equipment' => 'info', 'Acquire software / licence' => 'info', 'Review department capacity or scheduling' => 'warning',
                         'Continue monitoring demand' => 'warning', 'More market research needed' => 'secondary', 'Management review' => 'dark', 'Defer the opportunity' => 'danger'];
const MI_GAP_TYPES = ['none' => 'No gap: a qualified colleague can deliver', 'individual' => 'Individual employee skill gap', 'availability' => 'Employee availability problem',
                      'department' => 'Department capacity problem', 'equipment' => 'Missing equipment', 'software' => 'Missing software or licence', 'organization' => 'Organisation-wide capability gap'];
const MI_PROFICIENCY = [1 => 'Basic', 2 => 'Intermediate', 3 => 'Advanced', 4 => 'Expert'];
const MI_PAYMENT_TERMS = ['Cash on order', 'Cash on delivery', 'Credit'];
const MI_DECISION_REASONS = ['Insufficient demand', 'Low expected profit', 'Insufficient working capital', 'Supplier unavailable', 'High stock risk', 'Capability not available', 'Other'];
const MI_EVAL_DEFAULTS = ['target_margin' => '25', 'min_margin' => '15', 'conversion_pct' => '60', 'sales_period_days' => '60', 'busy_tasks' => '8', 'occasional_days' => '90'];

function mi_eval_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mi_ensure($conn);
    try { // stage 1 created est_demand as VARCHAR(20): widen it once
        $c = mysqli_fetch_row(mysqli_query($conn, "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mi_opportunities' AND column_name = 'est_demand'"));
        if ($c && (int) $c[0] < 80) { mysqli_query($conn, 'ALTER TABLE mi_opportunities MODIFY est_demand VARCHAR(80) NULL'); }
    } catch (Throwable $e) { }
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mi_quotes (id INT(11) NOT NULL AUTO_INCREMENT, opportunity_id INT(11) NOT NULL, supplier_party_id INT(11) NULL, supplier_name VARCHAR(160) NOT NULL,
        contact VARCHAR(120) NULL, location VARCHAR(120) NULL, unit_price DECIMAL(14,2) NOT NULL, moq DECIMAL(12,2) NULL, available_qty DECIMAL(12,2) NULL, delivery_cost DECIMAL(14,2) NOT NULL DEFAULT 0,
        other_costs DECIMAL(14,2) NOT NULL DEFAULT 0, delivery_days INT(11) NULL, payment_terms VARCHAR(20) NULL, credit_days INT(11) NULL, quality VARCHAR(20) NULL, quote_date DATE NULL, valid_until DATE NULL,
        notes TEXT NULL, attachment VARCHAR(120) NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY opp (opportunity_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS mi_evaluations (opportunity_id INT(11) NOT NULL, data TEXT NULL, updated_by INT(11) NULL, updated_at DATETIME NULL, PRIMARY KEY (opportunity_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mi_decisions (id INT(11) NOT NULL AUTO_INCREMENT, opportunity_id INT(11) NOT NULL, action VARCHAR(20) NOT NULL, recommendation VARCHAR(60) NULL,
        reason VARCHAR(80) NULL, comments TEXT NULL, approved_qty DECIMAL(12,2) NULL, quote_id INT(11) NULL, budget DECIMAL(14,2) NULL, responsible_id INT(11) NULL, next_action VARCHAR(255) NULL,
        assigned_to INT(11) NULL, follow_up DATE NULL, snapshot TEXT NULL, user_id INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY opp (opportunity_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mi_skills (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, skill VARCHAR(120) NOT NULL, skill_key VARCHAR(120) NOT NULL,
        proficiency TINYINT(1) NOT NULL DEFAULT 2, verified_by INT(11) NULL, updated_at DATETIME NULL, PRIMARY KEY (id), UNIQUE KEY us (user_id, skill_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mi_gaps (id INT(11) NOT NULL AUTO_INCREMENT, opportunity_id INT(11) NULL, skill VARCHAR(120) NOT NULL, skill_key VARCHAR(120) NOT NULL,
        gap_type VARCHAR(20) NOT NULL, required_level TINYINT(1) NOT NULL DEFAULT 2, department_id INT(11) NULL, equipment VARCHAR(160) NULL, software VARCHAR(160) NULL, checks TEXT NULL,
        recommendation VARCHAR(60) NULL, why TEXT NULL, status VARCHAR(12) NOT NULL DEFAULT 'Open', confirmed_by INT(11) NULL, confirmed_at DATETIME NULL, created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id), KEY sk (skill_key), KEY opp (opportunity_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS mi_gap_employees (gap_id INT(11) NOT NULL, user_id INT(11) NOT NULL, current_level TINYINT(1) NULL, PRIMARY KEY (gap_id, user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function mi_eset(mysqli $conn, string $k): float { $v = mi_setting($conn, $k); return (float) ($v !== '' ? $v : (MI_EVAL_DEFAULTS[$k] ?? 0)); }

/* ---------------------------------------------------------------- suppliers ---------------------------------------------------------------- */

function mi_quotes(mysqli $conn, int $oppId, bool $activeOnly = true): array
{
    return mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mi_quotes WHERE opportunity_id = $oppId" . ($activeOnly ? ' AND is_active = 1' : '') . ' ORDER BY id'), MYSQLI_ASSOC);
}

function mi_quote_expired(array $q): bool { return !empty($q['valid_until']) && $q['valid_until'] < date('Y-m-d'); }

/**
 * Compares quotations for a quantity. Each quote gets: qty (at least the MOQ), total, landed (cost per unit with delivery and
 * other costs), flags. Returns ['rows' => [...], 'cheapest', 'best', 'fastest', 'terms', 'suggested', 'why'] (ids or null).
 */
function mi_compare_quotes(array $quotes, float $qty): array
{
    $rows = []; $valid = [];
    foreach ($quotes as $q) {
        $n = max($qty > 0 ? $qty : 1, (float) ($q['moq'] ?: 0), 1);
        $total = (float) $q['unit_price'] * $n + (float) $q['delivery_cost'] + (float) $q['other_costs'];
        $q['qty'] = $n; $q['total'] = $total; $q['landed'] = $total / $n; $q['expired'] = mi_quote_expired($q);
        $q['short'] = $q['available_qty'] !== null && $q['available_qty'] !== '' && (float) $q['available_qty'] < $n;
        $q['terms_score'] = $q['payment_terms'] === 'Credit' ? 100 + (int) $q['credit_days'] : ($q['payment_terms'] === 'Cash on delivery' ? 50 : 0);
        $rows[(int) $q['id']] = $q;
        if (!$q['expired'] && $q['quality'] !== 'Poor') { $valid[] = $q; }
    }
    $pick = function (callable $key, bool $max = false) use ($valid) { $b = null; foreach ($valid as $q) { if ($b === null || ($max ? $key($q) > $key($b) : $key($q) < $key($b))) { $b = $q; } } return $b ? (int) $b['id'] : null; };
    $out = ['rows' => $rows, 'cheapest' => $pick(function ($q) { return (float) $q['unit_price']; }), 'best' => $pick(function ($q) { return $q['landed'] + ($q['short'] ? 1e12 : 0); }),
            'fastest' => $pick(function ($q) { return $q['delivery_days'] === null || $q['delivery_days'] === '' ? 1e9 : (int) $q['delivery_days']; }),
            'terms' => $pick(function ($q) { return $q['terms_score']; }, true), 'suggested' => null, 'why' => ''];
    if ($out['best']) {
        $b = $rows[$out['best']]; $out['suggested'] = $out['best'];
        $out['why'] = $b['supplier_name'] . ' has the lowest total cost per unit (' . mi_money($b['landed']) . ' with delivery and other costs for ' . mi_num($b['qty']) . ' units).';
        if ($out['cheapest'] && $out['cheapest'] !== $out['best']) { $c = $rows[$out['cheapest']]; $out['why'] .= ' ' . $c['supplier_name'] . ' has the lowest unit price (' . mi_money($c['unit_price']) . ') but costs ' . mi_money($c['landed']) . ' per unit once delivery and other costs are added.'; }
        if ($b['short']) { $out['why'] .= ' Note: this supplier does not have the full quantity available.'; }
    } elseif ($rows) { $out['why'] = 'No valid quotation: every quotation has expired or was marked poor quality.'; }
    $excluded = array_filter($rows, function ($q) { return $q['expired'] || $q['quality'] === 'Poor'; });
    if ($excluded) { $out['why'] .= ' Not considered: ' . implode(', ', array_map(function ($q) { return $q['supplier_name'] . ($q['expired'] ? ' (expired ' . date('d M Y', strtotime($q['valid_until'])) . ')' : ' (poor quality)'); }, $excluded)) . '.'; }
    return $out;
}

/* ---------------------------------------------------------------- money ---------------------------------------------------------------- */

/** Working capital for stock: Capital Fund available minus the budgets of approved opportunities not yet ordered. */
function mi_capital(mysqli $conn, ?int $exceptOpp = null): array
{
    $out = ['fund' => null, 'name' => 'Capital Fund', 'reserved' => 0.0, 'available' => null];
    try {
        require_once __DIR__ . '/fund_helpers.php';
        $f = fund_by_code($conn, 'CAPITAL');
        if ($f) { $out['fund'] = fund_available($conn, (int) $f['id']); $out['name'] = $f['name'] ?? 'Capital Fund'; }
    } catch (Throwable $e) { }
    $r = mysqli_query($conn, "SELECT o.id, (SELECT d.budget FROM mi_decisions d WHERE d.opportunity_id = o.id AND d.action = 'Approved' ORDER BY d.id DESC LIMIT 1) b FROM mi_opportunities o WHERE o.status = 'Approved' AND o.kind = 'product'" . ($exceptOpp ? ' AND o.id <> ' . (int) $exceptOpp : ''));
    while ($x = mysqli_fetch_assoc($r)) { $out['reserved'] += (float) $x['b']; }
    if ($out['fund'] !== null) { $out['available'] = max(0, $out['fund'] - $out['reserved']); }
    return $out;
}

function mi_eval_get(mysqli $conn, int $oppId): array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mi_evaluations WHERE opportunity_id = $oppId"));
    $d = $r ? (json_decode((string) $r['data'], true) ?: []) : [];
    return $d + ['_updated_by' => $r['updated_by'] ?? null, '_updated_at' => $r['updated_at'] ?? null];
}

function mi_eval_save(mysqli $conn, int $oppId, array $d): void
{
    $j = json_encode($d); $u = (int) ($_SESSION['user_id'] ?? 0); $now = date('Y-m-d H:i:s');
    mysqli_query($conn, "DELETE FROM mi_evaluations WHERE opportunity_id = $oppId");
    $s = mysqli_prepare($conn, 'INSERT INTO mi_evaluations (opportunity_id, data, updated_by, updated_at) VALUES (?, ?, ?, ?)'); mysqli_stmt_bind_param($s, 'isis', $oppId, $j, $u, $now); mysqli_stmt_execute($s);
}

/** Units sold of a catalogue item in the last $days days (cancelled sales excluded). */
function mi_sold(mysqli $conn, int $productId, int $days): array
{
    try {
        return mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(si.quantity), 0) q, COALESCE(SUM(si.quantity * si.unit_price), 0) v, COUNT(DISTINCT s.id) n FROM sale_items si JOIN sales s ON s.id = si.sale_id
            WHERE si.product_id = $productId AND s.status <> 'Cancelled' AND s.created_at >= '" . date('Y-m-d', strtotime("-$days days")) . "'")) ?: ['q' => 0, 'v' => 0, 'n' => 0];
    } catch (Throwable $e) { return ['q' => 0, 'v' => 0, 'n' => 0]; }
}

/* ---------------------------------------------------------------- products ---------------------------------------------------------------- */

/**
 * Feasibility of a PRODUCT opportunity and its recommendation. Returns every figure, the stock risk, the class and the
 * reasons ('why' list). Nothing is invented: missing inputs are reported in 'missing'.
 */
function mi_product_feasibility(mysqli $conn, array $o, ?array $prod = null): array
{
    $id = (int) $o['id']; $e = mi_eval_get($conn, $id);
    $st = mi_stats($conn, [$id])[$id] ?? null; [$lvl, $lvlWhy] = mi_classify($conn, $st);
    $recent = mi_stats($conn, [$id], date('Y-m-d', strtotime('-90 days')))[$id] ?? null;
    $sit = mi_situation($o, $prod);
    $period = (int) ($e['sales_period_days'] ?? 0) ?: (int) mi_eset($conn, 'sales_period_days');
    $sold = $prod ? mi_sold($conn, (int) $prod['id'], 90) : ['q' => 0, 'v' => 0, 'n' => 0];
    $quotes = mi_quotes($conn, $id);
    $qtyIn = (float) ($e['proposed_qty'] ?? 0);
    $cmp = mi_compare_quotes($quotes, $qtyIn ?: max(1, (float) ($recent['quantity'] ?? 0)));
    $qid = (int) ($e['quote_id'] ?? 0); if (!$qid || !isset($cmp['rows'][$qid]) || $cmp['rows'][$qid]['expired']) { $qid = (int) $cmp['suggested']; }
    $quote = $qid ? $cmp['rows'][$qid] : null;
    $r = ['e' => $e, 'stats' => $st, 'level' => $lvl, 'level_why' => $lvlWhy, 'sit' => $sit, 'sold90' => $sold, 'quote' => $quote, 'compare' => $cmp, 'missing' => [], 'why' => [], 'period' => $period];
    // unit cost: quotation, otherwise a clearly labelled estimate
    if ($quote) { $unit = (float) $quote['unit_price']; $r['cost_source'] = 'Quotation from ' . $quote['supplier_name']; }
    elseif (($e['unit_cost'] ?? '') !== '' && (float) $e['unit_cost'] > 0) { $unit = (float) $e['unit_cost']; $r['cost_source'] = 'Estimate (no supplier quotation yet)'; }
    elseif ($o['est_buy_price']) { $unit = (float) $o['est_buy_price']; $r['cost_source'] = 'Market research estimate (no supplier quotation yet)'; }
    elseif ($prod && (float) $prod['buying_price'] > 0) { $unit = (float) $prod['buying_price']; $r['cost_source'] = 'Last purchase price in RM Offerings'; }
    else { $unit = null; $r['missing'][] = 'purchase price (add a supplier quotation)'; $r['cost_source'] = '—'; }
    $r['estimated_cost'] = $unit !== null && stripos($r['cost_source'], 'estimate') !== false;
    $r['unit_cost'] = $unit;
    // suggested quantity (requests are not sales)
    $conv = mi_eset($conn, 'conversion_pct') / 100;
    $reqQty = (float) ($recent['quantity'] ?? 0);
    $salesRate = $period > 0 ? (float) $sold['q'] / 90 * $period : 0;
    $base = $reqQty * $conv + $salesRate;
    $moq = $quote && $quote['moq'] ? (float) $quote['moq'] : 0;
    $sugg = $base > 0 ? ceil($base) : 0;
    $capNote = '';
    if ($sugg > 0 && $moq > $sugg) { $capNote = 'The supplier minimum order (' . mi_num($moq) . ') is above the expected demand (' . mi_num($sugg) . ').'; $sugg = $moq; }
    $cap = mi_capital($conn, $id);
    $r['capital'] = $cap;
    $qty = $qtyIn > 0 ? $qtyIn : (float) $sugg;
    $r['suggested_qty'] = $sugg; $r['qty'] = $qty; $r['qty_source'] = $qtyIn > 0 ? 'proposed by management' : 'suggested';
    $r['sugg_why'] = $sugg > 0 ? (mi_num($reqQty) . ' requested in 90 days × ' . mi_num($conv * 100) . '% expected to buy' . ($salesRate > 0 ? ' + ' . mi_num(round($salesRate)) . ' expected sales over ' . $period . ' days (last 90 days: ' . mi_num($sold['q']) . ' sold)' : '') . '. ' . $capNote) : 'Not enough demand data to suggest a quantity.';
    // costs
    $transport = ($e['transport'] ?? '') !== '' ? (float) $e['transport'] : ($quote ? (float) $quote['delivery_cost'] : 0);
    $other = ($e['other_costs'] ?? '') !== '' ? (float) $e['other_costs'] : ($quote ? (float) $quote['other_costs'] : 0);
    $r['transport'] = $transport; $r['other'] = $other;
    if ($unit !== null && $qty > 0) {
        $r['investment'] = $unit * $qty + $transport + $other;
        $r['landed'] = $r['investment'] / $qty;
    } else { $r['investment'] = null; $r['landed'] = null; if ($qty <= 0) { $r['missing'][] = 'quantity'; } }
    // price
    $tm = (float) (($e['target_margin'] ?? '') !== '' ? $e['target_margin'] : mi_eset($conn, 'target_margin'));
    $sp = ($e['selling_price'] ?? '') !== '' ? (float) $e['selling_price'] : null;
    $r['price_suggestions'] = array_filter(['target margin ' . mi_num($tm) . '%' => $r['landed'] ? $r['landed'] / max(0.01, 1 - $tm / 100) : null, 'customers would pay (average)' => $st['avg_price'] ?? null,
        'current RM Offerings price' => $prod && (float) $prod['selling_price'] > 0 ? (float) $prod['selling_price'] : null, 'market research' => $o['est_sell_price'] ? (float) $o['est_sell_price'] : null]);
    if ($sp === null) { $sp = $prod && (float) $prod['selling_price'] > 0 ? (float) $prod['selling_price'] : ($o['est_sell_price'] ? (float) $o['est_sell_price'] : null); }
    $r['price'] = $sp; $r['price_source'] = ($e['selling_price'] ?? '') !== '' ? 'set by management' : ($sp !== null ? 'from ' . ($prod && (float) $prod['selling_price'] > 0 ? 'RM Offerings' : 'market research') : '');
    if ($sp === null) { $r['missing'][] = 'expected selling price'; }
    $esq = ($e['expected_sales_qty'] ?? '') !== '' ? (float) $e['expected_sales_qty'] : $qty;
    $r['sales_qty'] = $esq;
    if ($sp !== null && $r['landed'] !== null) {
        $r['unit_gp'] = $sp - $r['landed']; $r['margin'] = $sp > 0 ? $r['unit_gp'] / $sp * 100 : 0;
        $r['revenue'] = $esq * $sp; $r['gross_profit'] = $esq * $r['unit_gp'];
    } else { $r['unit_gp'] = $r['margin'] = $r['revenue'] = $r['gross_profit'] = null; }
    // financial capacity
    if ($r['investment'] !== null && $cap['available'] !== null) {
        $r['gap'] = max(0, $r['investment'] - $cap['available']); $r['coverage'] = $r['investment'] > 0 ? min(100, $cap['available'] / $r['investment'] * 100) : 100;
    } else { $r['gap'] = null; $r['coverage'] = null; }
    // stock risk
    $risk = 0; $rw = [];
    $demandInPeriod = $base;
    if ($qty > 0 && $demandInPeriod > 0 && $qty > $demandInPeriod * 1.3) { $risk += 2; $rw[] = 'quantity (' . mi_num($qty) . ') is well above expected demand (' . mi_num(round($demandInPeriod)) . ')'; }
    if ($moq && $demandInPeriod > 0 && $moq > $demandInPeriod) { $risk += 1; $rw[] = 'minimum order above expected demand'; }
    $shelf = (int) ($e['shelf_life_days'] ?? 0); if ($shelf > 0 && $shelf < $period) { $risk += 2; $rw[] = 'shelf life (' . $shelf . ' days) shorter than the sales period (' . $period . ' days)'; }
    if (in_array($lvl, ['Low', 'Insufficient data'], true)) { $risk += 2; $rw[] = 'demand is ' . strtolower($lvl); } elseif ($lvl === 'Moderate') { $risk += 1; $rw[] = 'demand is moderate'; }
    if ($r['investment'] !== null && $cap['available'] !== null && $r['investment'] > 0.5 * $cap['available']) { $risk += 1; $rw[] = 'uses more than half of the working capital'; }
    if (!empty($e['storage_notes'])) { $rw[] = 'storage: ' . $e['storage_notes']; }
    $r['risk'] = $risk >= 4 ? 'High' : ($risk >= 2 ? 'Medium' : 'Low'); $r['risk_why'] = $rw;
    // recommendation
    $minM = mi_eset($conn, 'min_margin'); $existing = in_array($sit[0], ['oos', 'low', 'in_stock'], true); $W = &$r['why'];
    $W[] = 'Demand: ' . $lvl . ' — ' . $lvlWhy;
    if ($existing && (in_array($lvl, ['High', 'Growing', 'Moderate'], true) || (float) $sold['q'] > 0) && $sit[0] !== 'in_stock') {
        $rec = 'Increase Stock Quantity'; $W[] = 'It is already an RM Offerings item (' . $sit[1] . '): this is a replenishment need, not a new product.';
        if ((float) $sold['q'] > 0) { $W[] = mi_num($sold['q']) . ' sold in the last 90 days.'; }
    } elseif ($unit === null || $sp === null) {
        $rec = 'More Market Research Needed'; $W[] = 'Missing: ' . implode(', ', $r['missing']) . '. A missing supplier price is not a confirmed cost.';
    } elseif ($r['unit_gp'] !== null && ($r['unit_gp'] <= 0 || $r['margin'] < $minM)) {
        $rec = 'Not Recommended for Now'; $W[] = 'Gross margin ' . mi_num(round($r['margin'], 1)) . '% is below the minimum of ' . mi_num($minM) . '%.';
    } elseif ($r['risk'] === 'High') {
        $rec = 'Not Recommended for Now'; $W[] = 'Stock risk is high: ' . implode('; ', $rw) . '.';
    } elseif (in_array($lvl, ['Low', 'Insufficient data'], true)) {
        $rec = $st && $st['recent'] > $st['previous'] ? 'Monitor Demand' : 'More Market Research Needed';
        $W[] = $rec === 'Monitor Demand' ? 'Demand is still low but increasing: watch it before investing.' : 'Not enough customers have asked for it yet.';
    } elseif (in_array($lvl, ['High', 'Growing'], true) && $quote && ($r['gap'] === null || $r['gap'] <= 0)) {
        $rec = 'Recommended to Introduce'; $W[] = 'Margin ' . mi_num(round($r['margin'], 1)) . '%, quotation from ' . $quote['supplier_name'] . ($r['gap'] !== null ? ', fully covered by the working capital.' : ' (Capital Fund not found: check funding).');
    } else {
        $rec = 'Consider Introducing';
        if (!$quote) { $W[] = 'The cost is an estimate: get a supplier quotation first.'; }
        if ($lvl === 'Moderate') { $W[] = 'Demand is promising but not yet high: verify with more customers.'; }
    }
    if ($r['gap'] > 0) { $W[] = 'Funding gap of ' . mi_money($r['gap']) . ': the available working capital covers only ' . mi_num(floor($r['coverage'])) . '% — not financially feasible without additional funds' . ($r['landed'] ? ' (it can fund about ' . mi_num(floor($cap['available'] / $r['landed'])) . ' units)' : '') . '.'; }
    if ($r['gross_profit'] !== null) { $W[] = 'Estimated gross profit if ' . mi_num($esq) . ' units sell: ' . mi_money($r['gross_profit']) . ' (gross, before operating expenses — not net profit).'; }
    unset($W);
    $r['rec'] = $rec;
    $r['feasible'] = $r['investment'] === null ? 'Incomplete' : ($r['gap'] === null ? 'Unknown (no Capital Fund)' : ($r['gap'] > 0 ? 'Funding gap' : 'Covered by working capital'));
    return $r;
}

/* ---------------------------------------------------------------- services and capability ---------------------------------------------------------------- */

/** Employees who have a skill (similar name) at or above a level, with availability. */
function mi_qualified(mysqli $conn, string $skill, int $level, int $withinDays = 7): array
{
    $key = mi_key($skill); $out = [];
    if ($key === '') { return []; }
    $busyMax = (int) mi_eset($conn, 'busy_tasks'); $to = date('Y-m-d', strtotime('+' . max(0, $withinDays) . ' days')); $today = date('Y-m-d');
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT s.*, u.names, u.department_id, u.is_active FROM mi_skills s JOIN users u ON u.id = s.user_id'), MYSQLI_ASSOC) as $s) {
        if (!(int) $s['is_active'] || mi_similarity($key, $s['skill_key']) < 70) { continue; }
        $uid = (int) $s['user_id'];
        $s['meets'] = (int) $s['proficiency'] >= $level;
        $leave = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM leave_requests WHERE user_id = $uid AND status = 'Approved' AND start_date <= '$to' AND end_date >= '$today'"));
        $s['on_leave'] = $leave && (int) $leave[0] > 0;
        $s['open_tasks'] = 0;
        try { $t = mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM tasks WHERE assigned_to = $uid AND status NOT IN ('Completed', 'Done', 'Cancelled')")); $s['open_tasks'] = (int) ($t[0] ?? 0); } catch (Throwable $e) { }
        $s['available'] = !$s['on_leave'] && $s['open_tasks'] < $busyMax;
        $out[$uid] = isset($out[$uid]) && $out[$uid]['proficiency'] >= $s['proficiency'] ? $out[$uid] : $s;
    }
    uasort($out, function ($a, $b) { return [(int) $b['meets'], (int) $b['available'], (int) $b['proficiency']] <=> [(int) $a['meets'], (int) $a['available'], (int) $a['proficiency']]; });
    return $out;
}

/** Learning Centre courses (RM SkillBank) whose title / category matches a skill. */
function mi_courses_for(mysqli $conn, string $skill): array
{
    $key = mi_key($skill); $out = [];
    try {
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, code, title, category, status, course_fee FROM lc_courses WHERE status NOT IN ('Archived')"), MYSQLI_ASSOC) as $c) {
            $sc = max(mi_similarity($key, mi_key($c['title'])), mi_similarity($key, mi_key((string) $c['category'])));
            foreach (explode(' ', $key) as $w) { if (strlen($w) > 3 && strpos(mi_key($c['title']), $w) !== false) { $sc = max($sc, 60); } }
            if ($sc >= 55) { $c['score'] = $sc; $out[] = $c; }
        }
    } catch (Throwable $e) { }
    usort($out, function ($a, $b) { return $b['score'] <=> $a['score']; });
    return array_slice($out, 0, 5);
}

/**
 * Capability assessment. $in: skill, level, department_id, employees (ids who could not deliver), equipment_missing,
 * equipment, software_missing, software, dept_insufficient, can_learn (Yes/No/Not sure), partner (Yes/No/Not sure), within_days.
 * Returns gap_type, recommendation, why (list), qualified, courses, alternatives (cost comparison).
 */
function mi_assess_capability(mysqli $conn, array $o, array $in): array
{
    $id = (int) $o['id']; $e = mi_eval_get($conn, $id);
    $st = mi_stats($conn, [$id])[$id] ?? null; [$lvl] = mi_classify($conn, $st);
    $od = (int) mi_eset($conn, 'occasional_days');
    $recent = mi_stats($conn, [$id], date('Y-m-d', strtotime("-$od days")))[$id] ?? null;
    $n = (int) ($recent['requests'] ?? 0);
    $internal = $id === 0; // a skill need recorded without a customer request (e.g. three employees need Advanced Excel)
    $occasional = !$internal && $n > 0 && ($n < (int) mi_setting($conn, 'moderate_requests') || ($recent['avg_gap_days'] !== null && $recent['avg_gap_days'] > 30));
    $level = max(1, min(4, (int) ($in['level'] ?? 2)));
    $affected = array_map('intval', $in['employees'] ?? []);
    $q = mi_qualified($conn, (string) $in['skill'], $level, (int) ($in['within_days'] ?? 7));
    $qualified = array_filter($q, function ($s) use ($affected) { return $s['meets'] && !in_array((int) $s['user_id'], $affected, true); });
    $availQ = array_filter($qualified, function ($s) { return $s['available']; });
    $courses = mi_courses_for($conn, (string) $in['skill']);
    $why = []; $alts = [];
    $why[] = $internal ? 'Internal skill need (not linked to a customer request).' : 'Demand: ' . $lvl . ' (' . $n . ' request(s) in the last ' . $od . ' days' . ($occasional ? ', occasional' : '') . ').';
    $why[] = count($qualified) . ' other employee(s) recorded with "' . $in['skill'] . '" at ' . MI_PROFICIENCY[$level] . ' level or above, ' . count($availQ) . ' available within ' . (int) ($in['within_days'] ?? 7) . ' days.';
    $offered = $internal ? '' : ($o['product_id'] ? 'The service exists in RM Offerings but could not be delivered.' : 'The service is not yet in RM Offerings.');
    if ($offered !== '') { $why[] = $offered; }
    // costs of the alternatives (12 months, from the feasibility inputs)
    $vol = (float) ($e['expected_volume'] ?? 0); $price = (float) ($e['service_price'] ?? 0);
    $direct = (float) ($e['materials_cost'] ?? 0) + (float) ($e['labour_hours'] ?? 0) * (float) ($e['labour_rate'] ?? 0);
    $mk = function ($label, $oneOff, $monthly, $perJob) use ($vol, $price, $direct) {
        $cost = $oneOff + 12 * $monthly + 12 * $vol * ($perJob + $direct);
        return ['label' => $label, 'one_off' => $oneOff, 'monthly' => $monthly, 'per_job' => $perJob, 'cost12' => $cost, 'gp12' => $price > 0 && $vol > 0 ? 12 * $vol * $price - $cost : null];
    };
    if (($e['training_cost'] ?? '') !== '') { $alts['train'] = $mk('Train existing employees', (float) $e['training_cost'], 0, 0); }
    if (($e['recruitment_cost_month'] ?? '') !== '') { $alts['recruit'] = $mk('Recruit a qualified employee', 0, (float) $e['recruitment_cost_month'], 0); }
    if (($e['external_cost'] ?? '') !== '') { $alts['outsource'] = $mk('Outsource or partner', 0, 0, (float) $e['external_cost']); }
    if (($e['equipment_cost'] ?? '') !== '' || ($e['software_cost'] ?? '') !== '') { $alts['equipment'] = $mk('Equipment / software', (float) ($e['equipment_cost'] ?? 0) + (float) ($e['software_cost'] ?? 0), 0, 0); }
    $cheapest = null; // the cheapest REALISTIC option: no training when employees cannot learn it in time
    foreach ($alts as $k => $a) { if ($k === 'equipment' || ($k === 'train' && ($in['can_learn'] ?? '') === 'No')) { continue; } if ($cheapest === null || $a['cost12'] < $alts[$cheapest]['cost12']) { $cheapest = $k; } }
    if (isset($alts['train']) && ($in['can_learn'] ?? '') === 'No') { $alts['train']['label'] .= ' (not possible in time)'; }
    $lowDemand = !$internal && in_array($lvl, ['Low', 'Insufficient data'], true);
    $partner = ($in['partner'] ?? '') === 'Yes';
    if (!empty($in['equipment_missing'])) {
        $type = 'equipment'; $why[] = 'The limitation is equipment (' . ($in['equipment'] ?: 'not named') . '), not an employee skill.';
        $eq = $alts['equipment']['one_off'] ?? null; $gp12 = $alts['equipment']['gp12'] ?? null;
        if ($lowDemand || $occasional) { $rec = $partner ? 'Outsource or partner' : 'Continue monitoring demand'; $why[] = 'One or a few requests do not justify buying equipment.'; }
        elseif ($eq !== null && $gp12 !== null && $gp12 > 0) { $rec = 'Purchase equipment'; $why[] = 'Equipment (' . mi_money($eq) . ') is recovered within 12 months (gross profit after it: ' . mi_money($gp12) . ').'; }
        elseif ($eq !== null) { $rec = $partner ? 'Outsource or partner' : 'Management review'; $why[] = 'The equipment cost is not recovered within 12 months from the expected volume.'; }
        else { $rec = 'Management review'; $why[] = 'Enter the equipment cost and expected volume in Feasibility to compare buying it with outsourcing.'; }
    } elseif (!empty($in['software_missing'])) {
        $type = 'software'; $why[] = 'The limitation is software / licence (' . ($in['software'] ?: 'not named') . ').';
        $rec = ($lowDemand || $occasional) ? ($partner ? 'Outsource or partner' : 'Continue monitoring demand') : 'Acquire software / licence';
    } elseif ($availQ) {
        $type = $affected ? 'individual' : 'none'; $names = implode(', ', array_map(function ($s) { return $s['names'] . ' (' . MI_PROFICIENCY[(int) $s['proficiency']] . ')'; }, array_slice($availQ, 0, 4)));
        $why[] = 'Not an organisational gap: ' . $names . ' can deliver it.';
        $rec = 'Assign a qualified colleague';
        if ($affected && !$lowDemand) { $why[] = 'Because the service is requested repeatedly, training the ' . count($affected) . ' employee(s) who could not deliver it is also worth considering.'; }
    } elseif ($qualified) {
        $type = 'availability'; $why[] = 'Qualified employees exist but are on leave or busy (' . implode(', ', array_map(function ($s) { return $s['names'] . ($s['on_leave'] ? ' — on leave' : ' — ' . $s['open_tasks'] . ' open tasks'); }, $qualified)) . ').';
        $rec = 'Review department capacity or scheduling';
    } elseif (!empty($in['dept_insufficient'])) {
        $type = 'department'; $why[] = 'The department does not have enough people or resources for this service.';
        $rec = $occasional && $partner ? 'Outsource or partner' : 'Review department capacity or scheduling';
    } else {
        $type = $affected && (($in['can_learn'] ?? '') === 'Yes' || $internal) ? 'individual' : 'organization';
        $why[] = 'Nobody recorded in MY MOTIVE has this skill at the required level' . ($q ? ' (some have it at a lower level)' : '') . '.';
        if ($lowDemand) { $rec = $partner ? 'Outsource or partner' : 'Continue monitoring demand'; $why[] = 'Demand is not yet established: no permanent recruitment or investment.' . ($partner ? ' A partner can take the occasional requests.' : ''); }
        elseif ($occasional) { $rec = 'Outsource or partner'; $why[] = 'Requested only occasionally: a permanent recruitment is not justified.'; }
        elseif (($in['can_learn'] ?? '') === 'Yes' || ($internal && ($in['can_learn'] ?? '') !== 'No')) { $rec = 'Train existing employees'; $why[] = 'Existing employees can learn it in the time needed.'; }
        else { $rec = 'Recruit a qualified employee'; $why[] = 'Specialised expertise is needed regularly and cannot be developed in time.'; }
        if ($cheapest && isset($alts[$cheapest]) && in_array($rec, ['Train existing employees', 'Recruit a qualified employee', 'Outsource or partner'], true)) {
            $map = ['train' => 'Train existing employees', 'recruit' => 'Recruit a qualified employee', 'outsource' => 'Outsource or partner'];
            if (isset($map[$cheapest]) && $map[$cheapest] !== $rec && !($occasional && $cheapest === 'recruit')) {
                $why[] = 'Cost comparison over 12 months: "' . $alts[$cheapest]['label'] . '" is cheapest (' . mi_money($alts[$cheapest]['cost12']) . ').' . ($rec === 'Recruit a qualified employee' && $cheapest === 'outsource' ? ' Recommended: outsource while demand grows.' : '');
                if ($rec === 'Recruit a qualified employee' && $cheapest === 'outsource') { $rec = 'Outsource or partner'; }
            }
        }
    }
    if ($courses && in_array($rec, ['Train existing employees', 'Assign a qualified colleague'], true)) { $why[] = 'RM Learning Centre course(s) for this skill: ' . implode(', ', array_column($courses, 'title')) . '.'; }
    elseif ($rec === 'Train existing employees') { $why[] = 'No RM Learning Centre course matches this skill yet: a new course or external training is needed.'; }
    return ['gap_type' => $type, 'recommendation' => $rec, 'why' => $why, 'qualified' => $q, 'courses' => $courses, 'alternatives' => $alts, 'occasional' => $occasional, 'level' => $lvl];
}

/** Feasibility of a SERVICE opportunity: the five assessments and the recommendation. */
function mi_service_feasibility(mysqli $conn, array $o, ?array $prod = null): array
{
    $id = (int) $o['id']; $e = mi_eval_get($conn, $id);
    $st = mi_stats($conn, [$id])[$id] ?? null; [$lvl, $lvlWhy] = mi_classify($conn, $st);
    $gap = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mi_gaps WHERE opportunity_id = $id AND status = 'Open' ORDER BY id DESC LIMIT 1"));
    $price = ($e['service_price'] ?? '') !== '' ? (float) $e['service_price'] : (($st['avg_price'] ?? null) ?: ($prod ? ((float) $prod['selling_price'] ?: null) : null));
    $labour = (float) ($e['labour_hours'] ?? 0) * (float) ($e['labour_rate'] ?? 0);
    $direct = (float) ($e['materials_cost'] ?? 0) + $labour + (($e['delivery_mode'] ?? '') === 'outsource' ? (float) ($e['external_cost'] ?? 0) : 0);
    $vol = (float) ($e['expected_volume'] ?? 0); $capm = (float) ($e['capacity_per_month'] ?? 0);
    $invest = (float) ($e['equipment_cost'] ?? 0) + (float) ($e['software_cost'] ?? 0) + (($e['delivery_mode'] ?? '') === 'train' ? (float) ($e['training_cost'] ?? 0) : 0); // training only when that is the chosen way
    $staff = ($e['delivery_mode'] ?? '') === 'recruit' ? (float) ($e['recruitment_cost_month'] ?? 0) : 0;
    $r = ['e' => $e, 'stats' => $st, 'level' => $lvl, 'level_why' => $lvlWhy, 'gap' => $gap, 'price' => $price, 'direct' => $direct, 'volume' => $vol, 'capacity' => $capm, 'investment' => $invest, 'staff' => $staff, 'why' => [], 'missing' => []];
    if ($price === null) { $r['missing'][] = 'expected service price'; }
    if ($vol <= 0) { $r['missing'][] = 'expected services per month'; }
    $r['unit_gp'] = $price !== null ? $price - $direct : null;
    $r['margin'] = $price ? $r['unit_gp'] / $price * 100 : null;
    $r['revenue_m'] = $price !== null ? $price * $vol : null;
    $r['gp_m'] = $r['unit_gp'] !== null ? $r['unit_gp'] * $vol - $staff : null;
    $r['payback'] = $invest > 0 && $r['gp_m'] > 0 ? $invest / $r['gp_m'] : null;
    $cap = mi_capital($conn); $r['capital'] = $cap;
    $r['fund_gap'] = $cap['available'] !== null ? max(0, $invest - $cap['available']) : null;
    // the five assessments
    $A = [];
    $A['Market demand'] = [$lvl, in_array($lvl, ['High', 'Growing'], true) ? 'success' : ($lvl === 'Moderate' ? 'warning' : 'secondary'), $lvlWhy];
    $A['Organisational capability'] = $gap ? [MI_GAP_TYPES[$gap['gap_type']] ?? $gap['gap_type'], $gap['gap_type'] === 'none' ? 'success' : 'warning', 'Assessment #' . $gap['id'] . ': ' . $gap['recommendation']] : ['Not assessed', 'secondary', 'Complete the Capability assessment.'];
    $A['Financial feasibility'] = $r['gp_m'] === null ? ['Incomplete', 'secondary', 'Missing: ' . implode(', ', $r['missing'])]
        : ($r['gp_m'] <= 0 ? ['Not profitable', 'danger', 'Expected monthly gross profit ' . mi_money($r['gp_m']) . '.'] : (($r['fund_gap'] ?? 0) > 0 ? ['Funding gap', 'warning', 'Investment ' . mi_money($invest) . ', gap ' . mi_money($r['fund_gap']) . '.'] : ['Feasible', 'success', 'Gross profit ' . mi_money($r['gp_m']) . ' per month' . ($r['payback'] ? ', investment recovered in ' . mi_num(round($r['payback'], 1)) . ' months' : '') . '.']));
    $A['Operational feasibility'] = $vol <= 0 ? ['Incomplete', 'secondary', 'Enter the expected volume.'] : ($capm <= 0 ? ['Unknown', 'secondary', 'Enter the delivery capacity per month.'] : ($capm >= $vol ? ['Feasible', 'success', mi_num($capm) . ' per month possible for ' . mi_num($vol) . ' expected.'] : ['Capacity short', 'warning', 'Only ' . mi_num($capm) . ' of ' . mi_num($vol) . ' per month can be delivered.']));
    $risk = 0; if (in_array($lvl, ['Low', 'Insufficient data'], true)) { $risk += 2; } if ($invest > 0 && ($r['payback'] === null || $r['payback'] > 12)) { $risk += 2; } if ($r['margin'] !== null && $r['margin'] < mi_eset($conn, 'min_margin')) { $risk += 1; } if (($r['fund_gap'] ?? 0) > 0) { $risk += 1; }
    $r['risk'] = $risk >= 4 ? 'High' : ($risk >= 2 ? 'Medium' : 'Low');
    $A['Business risk'] = [$r['risk'], ['High' => 'danger', 'Medium' => 'warning', 'Low' => 'success'][$r['risk']], $invest > 0 ? 'Investment ' . mi_money($invest) . ($r['payback'] ? ', payback ' . mi_num(round($r['payback'], 1)) . ' months' : ', not recovered from the expected volume') : 'No upfront investment.'];
    $r['assess'] = $A;
    // recommendation
    $W = &$r['why']; $W[] = 'Demand: ' . $lvl . ' — ' . $lvlWhy;
    if ($gap && $gap['gap_type'] !== 'none' && in_array($gap['recommendation'], ['Train existing employees', 'Recruit a qualified employee', 'Outsource or partner', 'Purchase equipment', 'Acquire software / licence', 'Review department capacity or scheduling'], true)) {
        $rec = $gap['recommendation']; $W[] = 'Capability: ' . (MI_GAP_TYPES[$gap['gap_type']] ?? '') . '. ' . strtok((string) $gap['why'], "\n");
    } elseif (in_array($lvl, ['Low', 'Insufficient data'], true)) { $rec = $st && $st['recent'] > $st['previous'] ? 'Continue monitoring demand' : 'More market research needed'; $W[] = 'Demand is not yet established.'; }
    elseif ($r['gp_m'] === null) { $rec = 'More market research needed'; $W[] = 'Missing: ' . implode(', ', $r['missing']) . '.'; }
    elseif ($r['gp_m'] <= 0 || $r['risk'] === 'High') { $rec = 'Defer the opportunity'; $W[] = $r['gp_m'] <= 0 ? 'The expected gross profit does not cover the direct costs.' : 'The business risk is high.'; }
    elseif ($gap && $gap['gap_type'] === 'none' || !$gap) { $rec = $gap ? 'Introduce the service' : 'Management review'; if (!$gap) { $W[] = 'Confirm the capability assessment before introducing it.'; } }
    else { $rec = 'Management review'; }
    if (($r['fund_gap'] ?? 0) > 0) { $W[] = 'Funding gap of ' . mi_money($r['fund_gap']) . ' for the investment.'; }
    if ($r['gp_m'] !== null) { $W[] = 'Estimated gross profit ' . mi_money($r['gp_m']) . ' per month (estimate, before operating expenses — not net profit).'; }
    unset($W);
    $r['rec'] = $rec;
    return $r;
}

/** The current recommendation of any opportunity (product or service). */
function mi_recommendation(mysqli $conn, array $o): array
{
    $prod = $o['product_id'] ? (mi_products($conn, [(int) $o['product_id']])[(int) $o['product_id']] ?? null) : null;
    return $o['kind'] === 'service' ? mi_service_feasibility($conn, $o, $prod) : mi_product_feasibility($conn, $o, $prod);
}

function mi_rec_badge(string $rec): string
{
    $c = MI_PRODUCT_RECS[$rec] ?? MI_SERVICE_RECS[$rec] ?? 'secondary';
    $st = ['success' => '#0FA968;color:#fff', 'primary' => '#1E2FE0;color:#fff', 'info' => '#E6F0FF;color:#1E2FE0;border:1px solid #C9D6FF', 'warning' => '#FFF4D6;color:#6B4E00;border:1px solid #F2DFA6',
           'secondary' => '#EEF0F5;color:#5B6275;border:1px solid #DDE1EA', 'danger' => '#FDECEC;color:#9B1C1C;border:1px solid #F5C2C2', 'dark' => '#1E2333;color:#fff'][$c];
    return '<span class="badge" style="background:' . $st . '">' . htmlspecialchars($rec, ENT_QUOTES, 'UTF-8') . '</span>';
}

/* ---------------------------------------------------------------- decisions ---------------------------------------------------------------- */

function mi_last_decision(mysqli $conn, int $oppId, ?string $action = null): ?array
{
    return mysqli_fetch_assoc(mysqli_query($conn, "SELECT d.*, u.names FROM mi_decisions d LEFT JOIN users u ON u.id = d.user_id WHERE d.opportunity_id = $oppId" . ($action ? " AND d.action = '" . mysqli_real_escape_string($conn, $action) . "'" : '') . ' ORDER BY d.id DESC LIMIT 1')) ?: null;
}

function mi_record_decision(mysqli $conn, int $oppId, array $d): int
{
    $f = ['action' => null, 'recommendation' => null, 'reason' => null, 'comments' => null, 'approved_qty' => null, 'quote_id' => null, 'budget' => null, 'responsible_id' => null,
          'next_action' => null, 'assigned_to' => null, 'follow_up' => null, 'snapshot' => null];
    foreach ($f as $k => $v) { if (isset($d[$k]) && $d[$k] !== '') { $f[$k] = $d[$k]; } }
    $u = (int) ($_SESSION['user_id'] ?? 0);
    $q = $f['approved_qty'] !== null ? (float) $f['approved_qty'] : null; $b = $f['budget'] !== null ? (float) $f['budget'] : null;
    $qi = $f['quote_id'] !== null ? (int) $f['quote_id'] : null; $ri = $f['responsible_id'] !== null ? (int) $f['responsible_id'] : null; $ai = $f['assigned_to'] !== null ? (int) $f['assigned_to'] : null;
    $s = mysqli_prepare($conn, 'INSERT INTO mi_decisions (opportunity_id, action, recommendation, reason, comments, approved_qty, quote_id, budget, responsible_id, next_action, assigned_to, follow_up, snapshot, user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'issssdidisissi', $oppId, $f['action'], $f['recommendation'], $f['reason'], $f['comments'], $q, $qi, $b, $ri, $f['next_action'], $ai, $f['follow_up'], $f['snapshot'], $u);
    mysqli_stmt_execute($s);
    return (int) mysqli_insert_id($conn);
}