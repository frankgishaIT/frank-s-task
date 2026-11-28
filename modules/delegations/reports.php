<?php
/**
 * NEW FILE: modules/delegations/reports.php — DELEGATION REPORTS (18), each filterable by period, RM Tasker and
 * service, and exportable to Excel (CSV). Figures come from the delegations themselves (actual verified figures
 * once verified, expected before — always labelled), Tasker payments from dl_payments (posted in Transactions),
 * issues from Service Quality. Nothing is copied or kept twice.
 */
require __DIR__ . '/common.php';
dl_require($conn, 'reports');
const DLR = [1 => 'Delegated Sales', 'Delegation Cost', 'Tasker Percentage', 'Delegation Profitability', 'Tasker Performance', 'Service Demand', 'Lost Sales Opportunities', 'Quality & Error',
    'Recommended vs Selected Tasker', 'Cost Savings', 'Internalization', 'Tasker Payment Status', 'Outstanding Tasker Balances', 'Overdue Tasker Payments', 'Payment Confirmation', 'Payment Dispute',
    'Tasker Payment History', 'Staff Delegation Performance'];
$r = isset(DLR[(int) ($_GET['r'] ?? 1)]) ? (int) ($_GET['r'] ?? 1) : 1;
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01', strtotime('-2 months'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$party = (int) ($_GET['party'] ?? 0);
$product = (int) ($_GET['product'] ?? 0);
$names = dl_users($conn);
$tk = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT bp.*, t.id tasker_no FROM dl_taskers t JOIN business_parties bp ON bp.id = t.party_id WHERE bp.type = 'Tasker' ORDER BY bp.name"), MYSQLI_ASSOC) as $t) { $tk[(int) $t['id']] = dl_tasker_name($t); }
$prods = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT p.id, p.product_name FROM products p WHERE p.id IN (SELECT product_id FROM dl_delegations) OR p.id IN (SELECT product_id FROM dl_tasker_offerings) ORDER BY p.product_name'), MYSQLI_ASSOC), 'product_name', 'id');
$F = ['party' => $party, 'product' => $product];
$D = function () use ($conn, $from, $to, $F) { return dlq_delegations($conn, $from, $to, $F); };
$M = function ($v) { return round((float) $v, 2); };
$fig = function ($d) { return $d['actual'] ? 'Actual (verified)' : 'Expected'; };
$inP = function ($dt) use ($from, $to) { return $dt && substr($dt, 0, 10) >= $from && substr($dt, 0, 10) <= $to; };
$cols = []; $rows = []; $note = ''; $tot = [];

switch ($r) {
    case 1: // Delegated Sales
        $cols = ['Delegation', 'Date', 'Sale', 'Customer', 'Service', 'RM Tasker', 'Revenue', 'Delegation status', 'Payment status'];
        foreach ($D() as $d) { $rows[] = [$d['ref'], substr($d['created_at'], 0, 10), 'RM' . str_pad((string) $d['sale_id'], 6, '0', STR_PAD_LEFT), $d['customer_name'] ?: 'Walk-in', $d['product_name'], $d['tasker_name'], $M($d['revenue']), $d['status'], $d['pos']['status']]; }
        $tot = [6]; break;
    case 2: // Delegation Cost
        $cols = ['Delegation', 'Service', 'RM Tasker', 'Figures', 'Materials (cost of sales)', 'Other direct costs', 'Tasker cost', 'Total cost', 'Cost of errors'];
        foreach ($D() as $d) { if ($d['ended']) { continue; } $rows[] = [$d['ref'], $d['product_name'], $d['tasker_name'], $fig($d), $M($d['cogs']), $M($d['direct']), $M($d['tasker_cost']), $M($d['cogs'] + $d['direct'] + $d['tasker_cost']), $M($d['error_cost'])]; }
        $tot = [4, 5, 6, 7, 8]; break;
    case 3: // Tasker Percentage
        $cols = ['RM Tasker', 'Service', 'Current rate', 'Valid', 'Status', 'Delegations in period', 'Rates used in period', 'Rate changes (all time)'];
        $used = []; foreach ($D() as $d) { if ($d['party_id']) { $used[(int) $d['party_id'] . '-' . (int) $d['product_id']][] = (float) $d['rate_pct']; } }
        $w = []; if ($party) { $w[] = 'o.party_id = ' . $party; } if ($product) { $w[] = 'o.product_id = ' . $product; }
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT o.*, p.product_name, (SELECT COUNT(*) FROM dl_rate_history h WHERE h.party_id = o.party_id AND h.product_id = o.product_id) changes FROM dl_tasker_offerings o JOIN products p ON p.id = o.product_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY p.product_name, o.rate_pct'), MYSQLI_ASSOC) as $o) {
            $u = $used[(int) $o['party_id'] . '-' . (int) $o['product_id']] ?? [];
            $rows[] = [$tk[(int) $o['party_id']] ?? '#' . $o['party_id'], $o['product_name'], dl_pct($o['rate_pct']), ($o['valid_from'] ?: '…') . ' – ' . ($o['valid_to'] ?: '…'), $o['status'], count($u), $u ? implode(', ', array_map('dl_pct', array_unique($u))) : '—', (int) $o['changes']];
        }
        $note = 'Each delegation keeps the rate agreed at the time; later rate changes never alter it.'; break;
    case 4: // Delegation Profitability
        $cols = ['Delegation', 'Service', 'RM Tasker', 'Figures', 'Revenue', 'Cost of sales', 'Other direct costs', 'Tasker payment', 'Gross profit', 'Margin', 'Cost of errors', 'Profit after errors'];
        foreach ($D() as $d) { if ($d['ended']) { continue; } $rows[] = [$d['ref'], $d['product_name'], $d['tasker_name'], $fig($d), $M($d['revenue']), $M($d['cogs']), $M($d['direct']), $M($d['tasker_cost']), $d['profit'], (float) $d['revenue'] > 0 ? round($d['profit'] / (float) $d['revenue'] * 100, 1) . '%' : '—', $M($d['error_cost']), $d['profit_after']]; }
        $tot = [4, 5, 6, 7, 8, 10, 11]; $note = 'Actual profitability uses the verified figures; delegations not verified yet show expected figures and are labelled.'; break;
    case 5: // Tasker Performance
        $cols = ['RM Tasker', 'Rating', 'Level', 'Final assignments', 'Provisional', 'Quality', 'On time', 'Customer satisfaction', 'Instructions', 'Communication', 'Accepted', 'Issues upheld', 'Under investigation', 'Not upheld', 'Error frequency', 'Preferred'];
        foreach (dlq_all_ratings($conn) as $t) { if ($party && (int) $t['id'] !== $party) { continue; } $x = $t['rating'];
            $rows[] = [$t['display'], $x['score'], $x['band'], $x['final'], $x['provisional'], $x['quality'] !== null ? round($x['quality'], 2) : '', $x['ontime'] !== null ? round($x['ontime'] * 100) . '%' : '', $x['satisfaction'] !== null ? round($x['satisfaction'], 2) : '',
                $x['instructions'] !== null ? round($x['instructions'], 2) : '', $x['communication'] !== null ? round($x['communication'], 2) : '', $x['accepted'] !== null ? round($x['accepted'] * 100) . '%' : '', $x['upheld'], $x['open_issues'], $x['not_upheld'], $x['error_rate'] !== null ? round($x['error_rate'] * 100) . '%' : '', (int) $t['preferred'] ? 'Yes' : ''];
        }
        $note = 'All time, verified and final outcomes only. Complaints under investigation are not counted.'; break;
    case 6: // Service Demand
        $cols = ['Service', 'Fulfilment', 'Delegations', 'Revenue', 'Unavailable requests', 'Potential revenue lost', 'Eligible RM Taskers'];
        $agg = [];
        foreach ($D() as $d) { if ($d['status'] === 'Reassigned') { continue; } $k = (int) $d['product_id']; $agg[$k] = $agg[$k] ?? ['n' => 0, 'rev' => 0, 'lost' => 0, 'lv' => 0, 'name' => $d['product_name']]; $agg[$k]['n']++; if (!$d['ended']) { $agg[$k]['rev'] += (float) $d['revenue']; } }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT l.product_id, COALESCE(p.product_name, l.service_text) name, COUNT(*) n, SUM(COALESCE(l.potential_revenue, 0)) v FROM dl_lost l LEFT JOIN products p ON p.id = l.product_id WHERE l.created_at >= '$from 00:00:00' AND l.created_at <= '$to 23:59:59'" . ($product ? ' AND l.product_id = ' . $product : '') . ' GROUP BY l.product_id, COALESCE(p.product_name, l.service_text)'), MYSQLI_ASSOC) as $l) {
            $k = $l['product_id'] ? (int) $l['product_id'] : 'x' . $l['name']; $agg[$k] = $agg[$k] ?? ['n' => 0, 'rev' => 0, 'lost' => 0, 'lv' => 0, 'name' => $l['name']]; $agg[$k]['lost'] += (int) $l['n']; $agg[$k]['lv'] += (float) $l['v'];
        }
        foreach ($agg as $k => $a) { $o = is_int($k) ? dl_offering($conn, $k) : ['fulfilment' => '—']; $rows[] = [$a['name'], $o['fulfilment'], $a['n'], $M($a['rev']), $a['lost'], $M($a['lv']), is_int($k) ? count(dl_candidates($conn, $k)['eligible']) : 0]; }
        usort($rows, function ($a, $b) { return ($b[2] + $b[4]) <=> ($a[2] + $a[4]); });
        $tot = [2, 3, 4, 5]; break;
    case 7: // Lost Sales Opportunities
        $cols = ['Date', 'Service', 'Customer', 'Phone', 'Reason', 'Note', 'Price quoted', 'Potential revenue', 'Follow-up', 'Status', 'Recorded by'];
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT l.*, p.product_name FROM dl_lost l LEFT JOIN products p ON p.id = l.product_id WHERE l.created_at >= '$from 00:00:00' AND l.created_at <= '$to 23:59:59'" . ($product ? ' AND l.product_id = ' . $product : '') . ' ORDER BY l.id DESC'), MYSQLI_ASSOC) as $l) {
            $rows[] = [substr($l['created_at'], 0, 10), $l['product_name'] ?: $l['service_text'], $l['customer_name'], $l['customer_phone'], $l['reason'], $l['reason_note'], $M($l['price_quoted']), $M($l['potential_revenue']), (int) $l['follow_up'] ? ($l['follow_up_date'] ?: 'Yes') : '', $l['status'], $names[(int) $l['user_id']] ?? ''];
        }
        $tot = [7]; break;
    case 8: // Quality & Error
        $cols = ['Issue', 'Delegation', 'RM Tasker', 'Service', 'Type', 'Reported by', 'Date', 'Financial impact', 'Responsibility', 'Status', 'Recovery', 'Recovery status'];
        $w = ["i.reported_at >= '$from 00:00:00'", "i.reported_at <= '$to 23:59:59'"]; if ($party) { $w[] = 'i.party_id = ' . $party; } if ($product) { $w[] = 'i.product_id = ' . $product; }
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT i.*, p.product_name FROM dl_issues i LEFT JOIN products p ON p.id = i.product_id WHERE ' . implode(' AND ', $w) . ' ORDER BY i.id DESC'), MYSQLI_ASSOC) as $i) {
            $rows[] = [dlq_ref((int) $i['id']), dl_ref((int) $i['delegation_id']), $tk[(int) $i['party_id']] ?? '', $i['product_name'], $i['type'], $i['reported_by_kind'], substr($i['reported_at'], 0, 10), $M($i['impact_amount']), $i['responsibility'], $i['status'], $i['recovery_amount'] !== null ? $M($i['recovery_amount']) : '', $i['recovery_status']];
        }
        $tot = [7]; break;
    case 9: // Recommended vs Selected
        $cols = ['Delegation', 'Service', 'Recommended', 'Selected', 'Same?', 'Reason for a different choice', 'Rate', 'Performance (verified)', 'Issues'];
        foreach ($D() as $d) { $rec = (int) $d['recommended_party_id']; $same = !$rec || $rec === (int) $d['party_id'];
            $rows[] = [$d['ref'], $d['product_name'], $rec ? ($tk[$rec] ?? '#' . $rec) : '—', $d['tasker_name'], $same ? 'Yes' : 'No', $same ? '' : (string) $d['override_reason'], dl_pct($d['rate_pct']), $d['perf_score'] !== null ? (float) $d['perf_score'] : '', $d['issues']]; }
        $n = count($rows); $diff = count(array_filter($rows, function ($x) { return $x[4] === 'No'; }));
        $note = $n ? $diff . ' of ' . $n . ' delegations used another Tasker than recommended (' . round($diff / $n * 100) . '%).' : ''; break;
    case 10: // Cost Savings
        $cols = ['Delegation', 'Service', 'RM Tasker', 'Profit pool', 'Expected rate (RISE MOTIVE)', 'Agreed rate', 'Tasker cost at the expected percentage', 'Tasker cost at the agreed percentage', 'Saving'];
        foreach ($D() as $d) { if ($d['ended']) { continue; } $o = dl_offering($conn, (int) $d['product_id']); if ($o['expected_rate'] === null || $o['expected_rate'] === '') { continue; }
            $pool = (float) ($d['actual'] ? $d['pool_act'] : $d['pool_exp']); $atExp = round($pool * (float) $o['expected_rate'] / 100, 2);
            $atAgreed = (float) ($d['actual'] ? $d['pay_act'] : $d['pay_exp']);
            $rows[] = [$d['ref'], $d['product_name'], $d['tasker_name'], $M($pool), dl_pct($o['expected_rate']), dl_pct($d['rate_pct']), $atExp, $M($atAgreed), round($atExp - $atAgreed, 2)]; }
        $tot = [6, 7, 8]; $note = 'Saving = Tasker cost at the expected percentage set for the service − the cost at the percentage agreed with the Tasker (negative = more than expected). Recoveries and adjustments are not savings and are left out. Only services with an expected rate.'; break;
    case 11: // Internalization
        $cols = ['Service', 'Fulfilment', 'Delegations (last ' . (int) dl_setting($conn, 'int_months') . ' months)', 'Monthly volume', 'Average Tasker %', 'Monthly Tasker payments', 'Annual outsourcing cost', 'Quality', 'Saving / month if internal', 'Payback (months)', 'Suggestion', 'Decision'];
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name, s.fulfilment FROM products p JOIN dl_offering_settings s ON s.product_id = p.id WHERE s.fulfilment IN ('External Only', 'Delegatable', 'Not Available')" . ($product ? ' AND p.id = ' . $product : '') . ' ORDER BY p.product_name'), MYSQLI_ASSOC) as $s) {
            $A = dlq_outsourcing($conn, (int) $s['id'], (int) dl_setting($conn, 'int_months')); $P = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_internal_plans WHERE product_id = ' . (int) $s['id'])) ?: null; $E = dlq_internal_eval($conn, $A, $P);
            $rows[] = [$s['product_name'], $s['fulfilment'], $A['count'], $A['monthly_volume'], $A['avg_rate'] !== null ? dl_pct($A['avg_rate']) : '', $A['monthly_payments'], $A['annual_cost'], $A['quality'] ?? '', $E['ready'] ? $E['saving'] : '', $E['payback'] ?? '', $E['verdict'], $P['decision'] ?? ''];
        }
        $note = 'Details and the internal cost estimates: Internalization tab.'; break;
    case 12: // Payment Status
        $cols = ['Delegation', 'RM Tasker', 'Service', 'Entitlement', 'Approved', 'Paid', 'Outstanding', 'Payment status', 'Due date', 'Overdue', 'Reason not paid'];
        foreach ($D() as $d) { if (!$d['verified_at']) { continue; } $p = $d['pos']; $rows[] = [$d['ref'], $d['tasker_name'], $d['product_name'], $M($p['entitled']), $p['approved'] !== null ? $M($p['approved']) : '', $M($p['paid']), $M($p['outstanding']), $p['status'], $p['due_date'] ?: '', $p['overdue'] ? 'OVERDUE' : '', (string) $d['nonpay_reason']]; }
        $tot = [3, 4, 5, 6]; break;
    case 13: // Outstanding balances
        $cols = ['RM Tasker', 'Earned (approved / entitled)', 'Paid', 'Outstanding', 'Overpaid (investigate)', 'Unpaid assignments', 'Partially paid', 'Disputes', 'Average days to pay'];
        foreach ($tk as $pid => $n) { if ($party && $pid !== $party) { continue; } $m = dl_tasker_money($conn, $pid); if (!$m['rows']) { continue; } $rows[] = [$n, $M($m['earned']), $M($m['paid']), $M($m['outstanding']), $M($m['overpaid']), $m['unpaid'], $m['partial'], $m['disputes'], $m['avg_days'] ?? '']; }
        $tot = [1, 2, 3, 4]; $note = 'All verified assignments to date (not limited to the period): Total earned − total paid = outstanding.'; break;
    case 14: // Overdue
        $cols = ['Delegation', 'RM Tasker', 'Service', 'Due date', 'Days overdue', 'Outstanding', 'Reason recorded'];
        foreach (dlq_delegations($conn, '2000-01-01', date('Y-m-d'), $F) as $d) { if (!$d['pos']['overdue']) { continue; } $rows[] = [$d['ref'], $d['tasker_name'], $d['product_name'], $d['pos']['due_date'], (int) floor((time() - strtotime($d['pos']['due_date'])) / 86400), $M($d['pos']['outstanding']), (string) $d['nonpay_reason']]; }
        $tot = [5]; $note = 'Today. Legitimate holds (customer not paid yet, issue under review, details missing…) are not overdue.'; break;
    case 15: // Payment Confirmation
    case 17: // Payment History
        $cols = $r === 15 ? ['Payment', 'Delegation', 'RM Tasker', 'Amount', 'Paid on', 'Method', 'Reference', 'Confirmation', 'Confirmed on', 'How', 'Requested until']
            : ['Payment', 'Delegation', 'RM Tasker', 'Service', 'Amount', 'Paid on', 'Method', 'Reference', 'Status', 'Transaction', 'Confirmation', 'Recorded by'];
        $w = ["p.paid_on >= '$from'", "p.paid_on <= '$to'"]; if ($party) { $w[] = 'p.party_id = ' . $party; } if ($product) { $w[] = 'd.product_id = ' . $product; } if ($r === 15) { $w[] = "p.status = 'Recorded'"; }
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT p.*, pr.product_name FROM dl_payments p JOIN dl_delegations d ON d.id = p.delegation_id LEFT JOIN products pr ON pr.id = d.product_id WHERE ' . implode(' AND ', $w) . ' ORDER BY p.paid_on DESC, p.id DESC'), MYSQLI_ASSOC) as $p) {
            $rows[] = $r === 15 ? [dl_pay_ref((int) $p['id']), dl_ref((int) $p['delegation_id']), $tk[(int) $p['party_id']] ?? '', $M($p['amount']), $p['paid_on'], $p['method'], $p['reference'], $p['confirm_status'] ?: '', $p['confirmed_at'] ? substr($p['confirmed_at'], 0, 10) : '', $p['confirmed_by_staff'] ? 'Recorded by staff' : ($p['confirmed_at'] ? 'RM Tasker (portal)' : ''), $p['confirm_due'] ? substr($p['confirm_due'], 0, 16) : '']
                : [dl_pay_ref((int) $p['id']), dl_ref((int) $p['delegation_id']), $tk[(int) $p['party_id']] ?? '', $p['product_name'], $M($p['amount']), $p['paid_on'], $p['method'], $p['reference'], $p['status'] . ($p['void_reason'] ? ' (' . $p['void_reason'] . ')' : ''), $p['transaction_id'] ? '#' . $p['transaction_id'] : '', $p['confirm_status'] ?: '', $names[(int) $p['recorded_by']] ?? ''];
        }
        $tot = [$r === 15 ? 3 : 4];
        if ($r === 15) { $c = array_count_values(array_column($rows, 7)); $note = implode(' · ', array_map(function ($k, $v) { return $k . ': ' . $v; }, array_keys($c), $c)); }
        break;
    case 16: // Disputes
        $cols = ['Dispute', 'Delegation', 'RM Tasker', 'Type', 'Payment reference', 'Expected', 'Received', 'Disputed', 'Status', 'Opened', 'Resolved', 'Resolution'];
        $w = ["x.created_at >= '$from 00:00:00'", "x.created_at <= '$to 23:59:59'"]; if ($party) { $w[] = 'x.party_id = ' . $party; }
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT x.* FROM dl_disputes x WHERE ' . implode(' AND ', $w) . ' ORDER BY x.id DESC'), MYSQLI_ASSOC) as $x) {
            $rows[] = [dl_dispute_ref((int) $x['id']), dl_ref((int) $x['delegation_id']), $tk[(int) $x['party_id']] ?? '', $x['kind'], $x['payment_reference'], $x['expected_amount'] !== null ? $M($x['expected_amount']) : '', $x['amount_received'] !== null ? $M($x['amount_received']) : '', $x['disputed_amount'] !== null ? $M($x['disputed_amount']) : '', $x['status'], substr($x['created_at'], 0, 10), $x['resolved_at'] ? substr($x['resolved_at'], 0, 10) : '', (string) $x['resolution']];
        }
        break;
    case 18: // Staff
        $cols = ['Employee', 'Handled through delegation', 'Successful', 'Revenue', 'Saved from rejection', 'Recorded as unavailable', 'Customer satisfaction', 'Delegated although internal', 'Overrides (no reason)', 'Not approved', 'Compliance'];
        foreach (dlq_staff($conn, $from, $to) as $u => $s) { $rows[] = [$names[$u] ?? '#' . $u, $s['handled'], $s['successful'], $M($s['revenue']), $s['saved'], $s['lost'], $s['satisfaction'] ?? '', $s['avoidable'], $s['override'] . ' (' . $s['override_noreason'] . ')', $s['refused'], $s['compliance'] !== null ? $s['compliance'] . '%' : '']; }
        $tot = [1, 2, 3, 4, 5]; $note = 'Recognition and details: Staff delegation tab.'; break;
}
$totRow = null;
if ($rows && $tot) { $totRow = array_fill(0, count($cols), ''); $totRow[0] = 'Total'; foreach ($tot as $c) { $totRow[$c] = round(array_sum(array_map(function ($x) use ($c) { return is_numeric($x[$c]) ? (float) $x[$c] : 0; }, $rows)), 2); } }

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="delegation_report_' . $r . '_' . preg_replace('/\W+/', '_', strtolower(DLR[$r])) . '_' . $from . '_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, [DLR[$r] . ' Report', $from . ' to ' . $to]);
    fputcsv($o, $cols);
    foreach ($rows as $x) { fputcsv($o, $x); }
    if ($totRow) { fputcsv($o, $totRow); }
    exit;
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'reports.php', 'Delegation reports', '<a class="btn btn-outline-secondary btn-sm" href="?' . dl_e(http_build_query(array_merge($_GET, ['r' => $r, 'export' => 'csv']))) . '"><i class="bi bi-download"></i> Excel (CSV)</a>');
$isMoney = function ($c) use ($cols) { return preg_match('/revenue|cost|paid|amount|outstanding|overpaid|earned|profit|payment|saving|impact|pool|entitlement|approved|expected$|received|disputed|recovery$|price|potential|materials/i', $cols[$c]) && !preg_match('/status|rate|%|reason|date|on$|confirmation|days|reference|frequency/i', $cols[$c]); };
?>
<form class="d-flex flex-wrap gap-2 align-items-end mb-2">
    <div><label class="form-label small mb-0">Report</label><select name="r" class="form-select form-select-sm" onchange="this.form.submit()"><?php foreach (DLR as $k => $n) { ?><option value="<?= $k; ?>" <?= $k === $r ? 'selected' : ''; ?>><?= $k; ?>. <?= dl_e($n); ?></option><?php } ?></select></div>
    <div><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= dl_e($from); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= dl_e($to); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">RM Tasker</label><select name="party" class="form-select form-select-sm"><option value="">All</option><?php foreach ($tk as $id => $n) { ?><option value="<?= $id; ?>" <?= $party === $id ? 'selected' : ''; ?>><?= dl_e($n); ?></option><?php } ?></select></div>
    <div><label class="form-label small mb-0">Service</label><select name="product" class="form-select form-select-sm"><option value="">All</option><?php foreach ($prods as $id => $n) { ?><option value="<?= (int) $id; ?>" <?= $product === (int) $id ? 'selected' : ''; ?>><?= dl_e($n); ?></option><?php } ?></select></div>
    <button class="btn btn-outline-primary btn-sm">Show</button>
</form>
<h6 class="mb-1"><?= $r; ?>. <?= dl_e(DLR[$r]); ?> report <span class="small text-muted fw-normal"><?= count($rows); ?> line<?= count($rows) === 1 ? '' : 's'; ?></span></h6>
<?php if ($note) { ?><div class="small text-muted mb-1"><?= dl_e($note); ?></div><?php } ?>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><?php foreach ($cols as $c) { ?><th><?= dl_e($c); ?></th><?php } ?></tr></thead><tbody>
<?php foreach ($rows as $x) { ?><tr><?php foreach ($x as $c => $v) { $num = is_float($v) || (is_int($v) && $isMoney($c)); ?><td class="<?= $num ? 'text-end text-nowrap' : 'small'; ?>"><?php
    if (preg_match('/^DL\d{6}$/', (string) $v)) { echo '<a href="view.php?ref=' . dl_e($v) . '">' . dl_e($v) . '</a>'; }
    elseif (preg_match('/^QI0*(\d+)$/', (string) $v, $m)) { echo '<a href="issue.php?id=' . (int) $m[1] . '">' . dl_e($v) . '</a>'; }
    elseif ($num && $isMoney($c)) { echo dl_e(number_format((float) $v)); }
    else { echo dl_e($v); } ?></td><?php } ?></tr><?php } ?>
<?php if ($totRow) { ?><tr class="fw-semibold" style="background:#F7F8FB"><?php foreach ($totRow as $c => $v) { ?><td class="<?= $c ? 'text-end text-nowrap' : ''; ?>"><?= is_float($v) || is_int($v) ? dl_e(number_format((float) $v)) : dl_e($v); ?></td><?php } ?></tr><?php } ?>
<?php if (!$rows) { ?><tr><td colspan="<?= count($cols); ?>" class="text-center text-muted py-4">Nothing for these filters.</td></tr><?php } ?>
</tbody></table></div></div>
<div class="small text-muted mt-1">Amounts in RWF.</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>