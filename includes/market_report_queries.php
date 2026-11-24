<?php
/**
 * NEW FILE: includes/market_report_queries.php — the 12 RM MARKET INSIGHTS REPORTS, in the shared MY MOTIVE report
 * shape (see report_pdf.php), so they show on screen, print / download as PDF and export to Excel (CSV) the same way.
 * Filters: period, product category, demand classification, product / service.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/market_ops.php';

function mi_report_types(): array
{
    return ['most_requested' => '1. Most Requested Products', 'not_in_stock' => '2. Most Requested Products Not in Stock', 'new_opportunities' => '3. New Product Opportunities',
        'increasing' => '4. Products with Increasing Demand', 'frequently_oos' => '5. Products Frequently Out of Stock', 'customer_demand' => '6. Customer Product Demand',
        'supplier_comparison' => '7. Supplier Price Comparison', 'recommended' => '8. Recommended Products', 'approved_not_purchased' => '9. Approved but Not Yet Purchased Products',
        'introduced' => '10. Introduced Products Performance', 'est_vs_actual' => '11. Estimated vs Actual Profitability', 'rejected_deferred' => '12. Rejected and Deferred Opportunities'];
}

/** Builds one report. $req: report, period, from, to, category, level, kind. */
function mi_report_build(mysqli $conn, string $type, array $req): array
{
    $types = mi_report_types();
    if (!isset($types[$type])) { return ['error' => 'Please choose a report.', 'title' => 'RM Market Insights Report', 'type' => $type]; }
    $per = report_period_from_request($req + ['period' => 'this_month'], true);
    if ($per['error']) { return ['error' => $per['error'], 'title' => $types[$type], 'type' => $type]; }
    $from = $per['start']; $to = $per['end'];
    $cat = trim((string) ($req['category'] ?? '')); $lvlF = isset(MI_DEMAND_LEVELS[$req['level'] ?? '']) ? $req['level'] : '';
    $kind = in_array($req['kind'] ?? '', ['product', 'service'], true) ? $req['kind'] : (in_array($type, ['customer_demand', 'rejected_deferred', 'recommended'], true) ? '' : 'product');
    $filters = [['Period', $per['label']]]; if ($cat !== '') { $filters[] = ['Category', $cat]; } if ($lvlF) { $filters[] = ['Demand', $lvlF]; } if ($kind) { $filters[] = ['Type', $kind === 'service' ? 'Services' : 'Products']; }
    $R = ['error' => null, 'type' => $type, 'title' => preg_replace('/^\d+\.\s*/', '', $types[$type]), 'filters' => $filters, 'summary' => [], 'columns' => [], 'rows' => [], 'notes' => []];
    $w = ['o.merged_into IS NULL']; if ($cat !== '') { $w[] = "o.category = '" . mysqli_real_escape_string($conn, $cat) . "'"; } if ($kind) { $w[] = "o.kind = '$kind'"; }
    $opps = function (string $extra = '') use ($conn, $w) { return mysqli_fetch_all(mysqli_query($conn, 'SELECT o.* FROM mi_opportunities o WHERE ' . implode(' AND ', $w) . ($extra ? ' AND ' . $extra : '') . ' ORDER BY o.id'), MYSQLI_ASSOC); };
    $col = function ($k, $l, $wd, $a = 'L', $t = 'text') { return ['key' => $k, 'label' => $l, 'w' => $wd, 'align' => $a, 'type' => $t]; };
    $demandRows = function (array $list, ?callable $keep = null) use ($conn, $from, $to, $lvlF) {
        $st = mi_stats($conn, array_map('intval', array_column($list, 'id')), $from, $to); $pr = mi_products($conn, array_column($list, 'product_id')); $out = [];
        foreach ($list as $o) { $s = $st[(int) $o['id']] ?? null; if (!$s) { continue; } [$l] = mi_classify($conn, $s); if ($lvlF && $l !== $lvlF) { continue; }
            $sit = mi_situation($o, $o['product_id'] ? ($pr[(int) $o['product_id']] ?? null) : null); if ($keep && !$keep($o, $s, $sit)) { continue; }
            $out[] = ['name' => $o['name'], 'category' => $o['category'], 'situation' => $sit[1], 'sit' => $sit[0], 'requests' => $s['requests'], 'customers' => $s['customers'], 'quantity' => trim(mi_num($s['quantity']) . ' ' . $o['unit']), 'qty' => $s['quantity'],
                'latest' => substr((string) $s['last'], 0, 10), 'level' => $l, 'recent' => $s['recent'], 'previous' => $s['previous'], 'ready' => $s['ready'], 'id' => (int) $o['id'], 'o' => $o, 's' => $s]; }
        usort($out, function ($a, $b) { return [$b['customers'], $b['requests'], $b['qty']] <=> [$a['customers'], $a['requests'], $a['qty']]; });
        return $out;
    };
    $demandCols = [$col('name', 'Product / service', 26), $col('category', 'Category', 13), $col('situation', 'Situation', 16), $col('requests', 'Requests', 8, 'R', 'int'), $col('customers', 'Customers', 9, 'R', 'int'), $col('quantity', 'Quantity', 10, 'R'), $col('latest', 'Latest', 10, 'L', 'date'), $col('level', 'Demand', 10)];
    switch ($type) {
        case 'most_requested':
        case 'not_in_stock':
            $rows = $demandRows($opps(), $type === 'not_in_stock' ? function ($o, $s, $sit) { return in_array($sit[0], ['new', 'research', 'oos', 'low', 'service_new'], true); } : null);
            $R['columns'] = $demandCols; $R['rows'] = $rows;
            $R['summary'] = [['Products', (string) count($rows)], ['Requests', (string) array_sum(array_column($rows, 'requests'))], ['Customers (sum)', (string) array_sum(array_column($rows, 'customers'))], ['Never stocked', (string) count(array_filter($rows, function ($r) { return in_array($r['sit'], ['new', 'research'], true); }))]];
            $R['notes'][] = 'Customers are counted once per product, even when they asked several times. A request is a sign of demand, not a sale.';
            break;
        case 'increasing':
            $rows = $demandRows($opps(), function ($o, $s) { return $s['recent'] > $s['previous']; });
            foreach ($rows as &$r) { $r['growth'] = $r['previous'] > 0 ? round(($r['recent'] - $r['previous']) / $r['previous'] * 100) . '%' : 'new'; } unset($r);
            $d = (int) mi_setting($conn, 'growth_days');
            $R['columns'] = [$col('name', 'Product / service', 28), $col('category', 'Category', 14), $col('recent', 'Last ' . $d . ' days', 11, 'R', 'int'), $col('previous', $d . ' days before', 11, 'R', 'int'), $col('growth', 'Growth', 9, 'R'), $col('customers', 'Customers', 9, 'R', 'int'), $col('level', 'Demand', 11)];
            $R['rows'] = $rows; $R['summary'] = [['Products with rising demand', (string) count($rows)], ['Classified "Growing"', (string) count(array_filter($rows, function ($r) { return $r['level'] === 'Growing'; }))]];
            break;
        case 'new_opportunities':
            $list = $opps("(o.product_id IS NULL OR o.origin IN ('research', 'portal', 'system'))" . ($from ? " AND o.created_at >= '$from 00:00:00' AND o.created_at <= '$to 23:59:59'" : ''));
            $st = mi_stats($conn, array_map('intval', array_column($list, 'id'))); $rows = [];
            foreach ($list as $o) { $s = $st[(int) $o['id']] ?? null; [$l] = mi_classify($conn, $s); if ($lvlF && $l !== $lvlF) { continue; }
                $rows[] = ['name' => $o['name'], 'kind' => $o['kind'] === 'service' ? 'Service' : 'Product', 'origin' => MI_ORIGINS[$o['origin']] ?? $o['origin'], 'category' => $o['category'], 'created' => substr($o['created_at'], 0, 10), 'requests' => $s['requests'] ?? 0, 'customers' => $s['customers'] ?? 0, 'level' => $l, 'status' => $o['status']]; }
            $R['columns'] = [$col('name', 'Product / service', 24), $col('kind', 'Type', 8), $col('origin', 'Found through', 16), $col('category', 'Category', 12), $col('created', 'Recorded', 10, 'L', 'date'), $col('requests', 'Requests', 8, 'R', 'int'), $col('customers', 'Customers', 9, 'R', 'int'), $col('level', 'Demand', 10), $col('status', 'Status', 11)];
            $R['rows'] = $rows; $R['summary'] = [['New opportunities', (string) count($rows)], ['From market research', (string) count(array_filter($rows, function ($r) { return $r['origin'] === MI_ORIGINS['research']; }))], ['From the Customer Portal', (string) count(array_filter($rows, function ($r) { return $r['origin'] === MI_ORIGINS['portal']; }))]];
            break;
        case 'frequently_oos':
            $q = "SELECT r.opportunity_id, COUNT(*) n, COUNT(DISTINCT COALESCE(r.customer_id, r.walkin_phone, r.id)) c FROM mi_requests r WHERE r.reason = 'Out of stock'" . ($from ? " AND r.requested_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'" : '') . ' GROUP BY r.opportunity_id';
            $cnt = []; foreach (mysqli_fetch_all(mysqli_query($conn, $q), MYSQLI_ASSOC) as $x) { $cnt[(int) $x['opportunity_id']] = $x; }
            $list = array_filter($opps('o.product_id IS NOT NULL'), function ($o) use ($cnt) { return isset($cnt[(int) $o['id']]); });
            $pr = mi_products($conn, array_column($list, 'product_id')); $rows = [];
            foreach ($list as $o) { $p = $pr[(int) $o['product_id']] ?? null; $s = mi_sold($conn, (int) $o['product_id'], 90);
                $rows[] = ['name' => $o['name'], 'category' => $o['category'], 'oos' => (int) $cnt[(int) $o['id']]['n'], 'customers' => (int) $cnt[(int) $o['id']]['c'], 'stock' => $p ? mi_num($p['quantity']) . ' ' . $p['unit'] : '', 'reorder' => $p ? mi_num($p['reorder_level']) : '', 'sold' => mi_num($s['q'])]; }
            usort($rows, function ($a, $b) { return $b['oos'] <=> $a['oos']; });
            $R['columns'] = [$col('name', 'Product', 28), $col('category', 'Category', 14), $col('oos', 'Customers turned away (out of stock)', 14, 'R', 'int'), $col('customers', 'Different customers', 11, 'R', 'int'), $col('stock', 'Stock now', 11, 'R'), $col('reorder', 'Reorder level', 9, 'R'), $col('sold', 'Sold (90 days)', 10, 'R')];
            $R['rows'] = $rows; $R['summary'] = [['Products', (string) count($rows)], ['Customers turned away', (string) array_sum(array_column($rows, 'oos'))]];
            break;
        case 'customer_demand':
            $q = 'SELECT r.customer_id, c.name, c.phone, COUNT(*) n, COUNT(DISTINCT r.opportunity_id) products, SUM(r.quantity) qty, MAX(r.requested_at) last FROM mi_requests r JOIN customers c ON c.id = r.customer_id JOIN mi_opportunities o ON o.id = r.opportunity_id WHERE ' . implode(' AND ', $w)
                . ($from ? " AND r.requested_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'" : '') . ' GROUP BY r.customer_id, c.name, c.phone ORDER BY n DESC, products DESC LIMIT 500';
            $rows = [];
            foreach (mysqli_fetch_all(mysqli_query($conn, $q), MYSQLI_ASSOC) as $x) {
                $top = mysqli_fetch_all(mysqli_query($conn, 'SELECT o.name, COUNT(*) n FROM mi_requests r JOIN mi_opportunities o ON o.id = r.opportunity_id WHERE r.customer_id = ' . (int) $x['customer_id'] . ($from ? " AND r.requested_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'" : '') . ' GROUP BY o.name ORDER BY n DESC LIMIT 3'), MYSQLI_ASSOC);
                $rows[] = ['customer' => $x['name'] . ' (RMC' . str_pad((string) $x['customer_id'], 6, '0', STR_PAD_LEFT) . ')', 'phone' => $x['phone'], 'requests' => (int) $x['n'], 'products' => (int) $x['products'], 'latest' => substr($x['last'], 0, 10), 'asked' => implode(', ', array_column($top, 'name'))]; }
            $walk = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM mi_requests r JOIN mi_opportunities o ON o.id = r.opportunity_id WHERE r.customer_id IS NULL AND ' . implode(' AND ', $w) . ($from ? " AND r.requested_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'" : '')))[0];
            $R['columns'] = [$col('customer', 'Customer', 24), $col('phone', 'Phone', 12), $col('requests', 'Requests', 8, 'R', 'int'), $col('products', 'Different products', 10, 'R', 'int'), $col('latest', 'Latest', 10, 'L', 'date'), $col('asked', 'Most asked for', 36)];
            $R['rows'] = $rows; $R['summary'] = [['Customers', (string) count($rows)], ['Their requests', (string) array_sum(array_column($rows, 'requests'))], ['Walk-in requests (not identified)', (string) $walk]];
            $R['notes'][] = 'Customer details are shown to authorised staff only. Customers who ask repeatedly show real interest.';
            break;
        case 'supplier_comparison':
            $list = $opps("o.kind = 'product'"); $rows = [];
            foreach ($list as $o) {
                $qs = array_filter(mi_quotes($conn, (int) $o['id'], false), function ($q) use ($from, $to) { return !$from || ((string) $q['quote_date'] >= $from && (string) $q['quote_date'] <= $to); });
                if (!$qs) { continue; } $e = mi_eval_get($conn, (int) $o['id']);
                $cmp = mi_compare_quotes(array_filter($qs, function ($q) { return (int) $q['is_active'] === 1; }), max(1, (float) ($e['proposed_qty'] ?? 1)));
                foreach ($cmp['rows'] as $q) { $rows[] = ['name' => $o['name'], 'supplier' => $q['supplier_name'], 'price' => $q['unit_price'], 'moq' => $q['moq'] ? mi_num($q['moq']) : '', 'extra' => (float) $q['delivery_cost'] + (float) $q['other_costs'], 'landed' => round($q['landed'], 2),
                    'days' => $q['delivery_days'] !== null ? $q['delivery_days'] . ' d' : '', 'terms' => trim($q['payment_terms'] . ($q['credit_days'] ? ' ' . $q['credit_days'] . ' d' : '')), 'valid' => $q['valid_until'] ?: '',
                    'flag' => $cmp['suggested'] === (int) $q['id'] ? 'Suggested' : ($q['expired'] ? 'Expired' : ($cmp['cheapest'] === (int) $q['id'] ? 'Lowest unit price' : ''))]; }
            }
            $R['columns'] = [$col('name', 'Product', 16), $col('supplier', 'Supplier', 15), $col('price', 'Unit price', 9, 'R', 'money'), $col('moq', 'Min. order', 7, 'R'), $col('extra', 'Delivery + other', 9, 'R', 'money'), $col('landed', 'Cost / unit', 9, 'R', 'money'),
                $col('days', 'Delivery', 6, 'R'), $col('terms', 'Payment', 9), $col('valid', 'Valid until', 9, 'L', 'date'), $col('flag', '', 10)];
            $R['rows'] = $rows; $R['summary'] = [['Quotations', (string) count($rows)], ['Products compared', (string) count(array_unique(array_column($rows, 'name')))]];
            $R['notes'][] = 'Cost / unit includes delivery and other costs for the proposed quantity. The cheapest unit price is not always the best overall cost.';
            break;
        case 'recommended':
            $list = $opps("o.status NOT IN ('Rejected', 'Completed', 'Launched/Active')"); $rows = []; $inv = 0; $gp = 0;
            foreach ($list as $o) { $X = mi_recommendation($conn, $o); if (!in_array($X['rec'], ['Recommended to Introduce', 'Increase Stock Quantity', 'Consider Introducing', 'Introduce the service', 'Train existing employees', 'Recruit a qualified employee', 'Outsource or partner', 'Purchase equipment', 'Acquire software / licence', 'Assign a qualified colleague'], true)) { continue; }
                if ($lvlF && $X['level'] !== $lvlF) { continue; }
                $g = $o['kind'] === 'service' ? ($X['gp_m'] !== null ? $X['gp_m'] * 12 : null) : $X['gross_profit'];
                $rows[] = ['name' => $o['name'], 'rec' => $X['rec'], 'level' => $X['level'], 'investment' => $X['investment'], 'gp' => $g, 'funds' => $o['kind'] === 'service' ? ($X['assess']['Financial feasibility'][0] ?? '') : $X['feasible'], 'risk' => $X['risk'], 'status' => $o['status'], 'why' => implode(' ', array_slice($X['why'], 1, 2))];
                $inv += (float) $X['investment']; $gp += (float) $g; }
            $R['columns'] = [$col('name', 'Product / service', 17), $col('rec', 'Recommendation', 15), $col('level', 'Demand', 8), $col('investment', 'Investment', 10, 'R', 'money'), $col('gp', 'Expected gross profit', 10, 'R', 'money'), $col('funds', 'Funds', 10), $col('risk', 'Risk', 6), $col('status', 'Status', 9), $col('why', 'Why', 25)];
            $R['rows'] = $rows; $R['summary'] = [['Recommended', (string) count($rows)], ['Estimated investment', report_money($inv)], ['Expected gross profit', report_money($gp)]];
            $R['notes'][] = 'All figures are estimates; gross profit is before operating expenses (not net profit). Services: 12 months.';
            break;
        case 'approved_not_purchased':
            $list = $opps("o.status IN ('Approved', 'In Implementation')"); $rows = [];
            foreach ($list as $o) { $L = mi_links($conn, (int) $o['id']); $stage = mi_purchase_stage($o, $L); if (array_filter($L['po'], function ($p) { return in_array($p['status'], ['Ordered', 'Received'], true); })) { continue; }
                $d = mi_last_decision($conn, (int) $o['id'], 'Approved'); if (!$d || ($from && (substr($d['created_at'], 0, 10) < $from || substr($d['created_at'], 0, 10) > $to))) { continue; }
                $sup = $d['quote_id'] ? mysqli_fetch_row(mysqli_query($conn, 'SELECT supplier_name FROM mi_quotes WHERE id = ' . (int) $d['quote_id'])) : null;
                $rows[] = ['name' => $o['name'], 'approved' => substr($d['created_at'], 0, 10), 'by' => $d['names'], 'qty' => $d['approved_qty'] ? mi_num($d['approved_qty']) . ' ' . $o['unit'] : '', 'budget' => $d['budget'], 'supplier' => $sup[0] ?? '', 'waiting' => (int) floor((time() - strtotime($d['created_at'])) / 86400), 'stage' => $stage ?: $o['status']]; }
            $R['columns'] = [$col('name', 'Product / service', 22), $col('approved', 'Approved on', 10, 'L', 'date'), $col('by', 'Approved by', 13), $col('qty', 'Quantity', 10, 'R'), $col('budget', 'Budget', 11, 'R', 'money'), $col('supplier', 'Supplier', 14), $col('waiting', 'Days waiting', 8, 'R', 'int'), $col('stage', 'Stage', 14)];
            $R['rows'] = $rows; $R['summary'] = [['Not yet purchased', (string) count($rows)], ['Approved budget', report_money(array_sum(array_column($rows, 'budget')))]];
            break;
        case 'introduced':
        case 'est_vs_actual':
            $list = $opps("o.launched_at IS NOT NULL" . ($from ? " AND o.launched_at <= '$to 23:59:59' AND (o.completed_at IS NULL OR o.completed_at >= '$from 00:00:00')" : '')); $rows = [];
            foreach ($list as $o) { $p = mi_performance($conn, $o); if (!$p) { continue; }
                $rows[] = ['name' => $o['name'], 'launched' => $p['from'], 'days' => $p['days'], 'bought' => $p['bought'], 'sold' => $p['sold'], 'stock' => $p['stock'], 'revenue' => $p['revenue'], 'gp' => $p['gross_profit'], 'through' => $p['sell_through'] !== null ? round($p['sell_through']) . '%' : '',
                    'verdict' => $p['verdict'], 'exp_qty' => $p['exp_qty'], 'exp_gp' => $p['exp_gp'], 'exp_price' => $p['exp_price'], 'price' => $p['price'] !== null ? round($p['price'], 2) : null,
                    'acc_q' => $p['exp_qty'] ? round($p['sold'] / $p['exp_qty'] * 100) . '%' : '', 'acc_g' => $p['gp_accuracy'] !== null ? round($p['gp_accuracy']) . '%' : '', 'status' => $o['status']]; }
            if ($type === 'introduced') {
                $R['columns'] = [$col('name', 'Product / service', 20), $col('launched', 'In stock since', 10, 'L', 'date'), $col('bought', 'Purchased', 8, 'R'), $col('sold', 'Sold', 8, 'R'), $col('stock', 'Stock left', 8, 'R'), $col('through', 'Sold through', 8, 'R'), $col('revenue', 'Revenue', 11, 'R', 'money'), $col('gp', 'Gross profit', 11, 'R', 'money'), $col('verdict', 'Against the estimate', 16)];
                $R['summary'] = [['Introduced', (string) count($rows)], ['Revenue', report_money(array_sum(array_column($rows, 'revenue')))], ['Gross profit', report_money(array_sum(array_column($rows, 'gp')))], ['Faster than expected', (string) count(array_filter($rows, function ($r) { return $r['verdict'] === 'Selling faster than expected'; }))]];
            } else {
                $R['columns'] = [$col('name', 'Product / service', 20), $col('exp_qty', 'Expected qty', 9, 'R'), $col('sold', 'Actual qty', 9, 'R'), $col('acc_q', 'Qty accuracy', 8, 'R'), $col('exp_price', 'Expected price', 10, 'R', 'money'), $col('price', 'Actual price', 10, 'R', 'money'), $col('exp_gp', 'Expected gross profit', 11, 'R', 'money'), $col('gp', 'Actual gross profit', 11, 'R', 'money'), $col('acc_g', 'Profit accuracy', 8, 'R')];
                $eg = array_sum(array_column($rows, 'exp_gp')); $ag = array_sum(array_column($rows, 'gp'));
                $R['summary'] = [['Products', (string) count($rows)], ['Expected gross profit', report_money($eg)], ['Actual gross profit', report_money($ag)], ['Overall accuracy', $eg ? round($ag / $eg * 100) . '%' : '—']];
                $R['notes'][] = 'Actual gross profit = sales − quantity sold × actual purchase cost per unit. Gross profit is not net profit. Products still selling are compared with the time passed.';
            }
            $R['rows'] = $rows;
            break;
        case 'rejected_deferred':
            $q = "SELECT d.*, o.name, o.kind, o.category, o.status ostatus, u.names FROM mi_decisions d JOIN mi_opportunities o ON o.id = d.opportunity_id LEFT JOIN users u ON u.id = d.user_id WHERE d.action IN ('Rejected', 'Deferred') AND " . implode(' AND ', $w)
                . ($from ? " AND d.created_at BETWEEN '$from 00:00:00' AND '$to 23:59:59'" : '') . ' ORDER BY d.created_at DESC';
            $rows = array_map(function ($d) { return ['name' => $d['name'], 'decision' => $d['action'], 'date' => substr($d['created_at'], 0, 10), 'by' => $d['names'], 'reason' => $d['reason'], 'comments' => $d['comments'], 'follow' => $d['follow_up'] ?: '', 'now' => $d['ostatus']]; }, mysqli_fetch_all(mysqli_query($conn, $q), MYSQLI_ASSOC));
            $R['columns'] = [$col('name', 'Product / service', 18), $col('decision', 'Decision', 8), $col('date', 'Date', 9, 'L', 'date'), $col('by', 'By', 12), $col('reason', 'Reason', 15), $col('comments', 'Comments', 22), $col('follow', 'Follow-up', 9, 'L', 'date'), $col('now', 'Status now', 9)];
            $reasons = array_count_values(array_filter(array_column($rows, 'reason')));
            arsort($reasons);
            $R['rows'] = $rows; $R['summary'] = [['Rejected', (string) count(array_filter($rows, function ($r) { return $r['decision'] === 'Rejected'; }))], ['Deferred', (string) count(array_filter($rows, function ($r) { return $r['decision'] === 'Deferred'; }))], ['Main reason', (string) (array_key_first($reasons) ?? '—')]];
            $R['notes'][] = 'Rejected and deferred opportunities are kept: new requests are still added to them.';
            break;
    }
    return $R;
}

/** Excel export (CSV, UTF-8 with BOM, opens in Excel) of a built report. */
function mi_report_csv(array $R): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="market-' . $R['type'] . '-' . date('Ymd') . '.csv"');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, [$R['title']]); foreach ($R['filters'] as $x) { fputcsv($f, $x); } fputcsv($f, []);
    fputcsv($f, array_column($R['columns'], 'label'));
    foreach ($R['rows'] as $r) { $line = []; foreach ($R['columns'] as $c) { $v = $r[$c['key']] ?? ''; $line[] = ($c['type'] ?? '') === 'money' && $v !== '' && $v !== null ? round((float) $v, 2) : $v; } fputcsv($f, $line); }
    if ($R['summary']) { fputcsv($f, []); foreach ($R['summary'] as $s) { fputcsv($f, $s); } }
    exit;
}