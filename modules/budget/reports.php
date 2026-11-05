<?php
/**
 * NEW FILE: modules/budget/reports.php — Budget, Fund and Management reports (20), from approved MY MOTIVE records.
 * Every report: company name, title, period, generation date, amounts, variance, remaining, fund info, totals.
 * View online, search, print / save as PDF, export to Excel (CSV).
 */
require __DIR__ . '/budget_common.php';
if (!access_can('budget.reports', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$L = bdg_lists($conn);
$R = ['A1' => ['Budget', 'Annual Budget Report'], 'A2' => ['Budget', 'Monthly Budget Report'], 'A3' => ['Budget', 'Quarterly Budget Report'], 'A4' => ['Budget', 'Departmental Budget Report'],
      'A5' => ['Budget', 'Project Budget Report'], 'A6' => ['Budget', 'Expense Category Budget Report'], 'A7' => ['Budget', 'Revenue Budget vs Actual Report'], 'A8' => ['Budget', 'Budget Variance Report'],
      'A9' => ['Budget', 'Overspending Report'], 'A10' => ['Budget', 'Budget Utilization Report'], 'B1' => ['Fund', 'Fund Transfer Report'], 'B2' => ['Fund', 'Fund Reconciliation Report'],
      'C1' => ['Management', 'Company-Wide Budget Performance'], 'C2' => ['Management', 'Departmental Spending Performance'], 'C3' => ['Management', 'Monthly Financial Performance'],
      'C4' => ['Management', 'Annual Financial Performance'], 'C5' => ['Management', 'Planned vs Actual Revenue and Expenses'], 'C6' => ['Management', 'Budget Revision History'],
      'C7' => ['Management', 'Pending Budget Approvals'], 'C8' => ['Management', 'Financial Commitment Report']];
$key = isset($R[$_GET['r'] ?? '']) ? $_GET['r'] : 'A8';
$f = ['year' => (int) ($_GET['year'] ?? date('Y')), 'month' => preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : '', 'quarter' => (int) ($_GET['quarter'] ?? 0) ?: '',
      'department_id' => (int) ($_GET['department_id'] ?? 0) ?: '', 'project_id' => (int) ($_GET['project_id'] ?? 0) ?: '', 'category' => (string) ($_GET['category'] ?? ''), 'fund_id' => (int) ($_GET['fund_id'] ?? 0) ?: ''];
$q = trim((string) ($_GET['q'] ?? ''));
// The selected period.
if ($f['month']) { $from = $f['month'] . '-01'; $to = date('Y-m-t', strtotime($from)); $periodLabel = date('F Y', strtotime($from)); }
elseif ($f['quarter']) { $from = sprintf('%d-%02d-01', $f['year'], 3 * $f['quarter'] - 2); $to = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $f['year'], 3 * $f['quarter']))); $periodLabel = 'Q' . $f['quarter'] . ' ' . $f['year']; }
else { $from = $f['year'] . '-01-01'; $to = $f['year'] . '-12-31'; $periodLabel = 'Financial year ' . $f['year']; }
$money = function ($v) { return $v === null || $v === '' ? '' : number_format((float) $v, 0); };
$cols = []; $rows = []; $totals = null; $note = '';
$budgetRows = function (array $bs, ?callable $keep = null, ?callable $sort = null) use ($conn, $money, &$totals) {
    $out = []; $t = ['budget' => 0, 'actual' => 0, 'variance' => 0, 'remaining' => 0, 'pending' => 0, 'available' => 0];
    foreach ($bs as $b) { $x = bdg_figures($conn, $b); if ($keep && !$keep($b, $x)) { continue; }
        $out[] = ['_u' => $x['utilization'] ?? -1, 'Budget' => $b['name'] . ' (' . bdg_ref('budget', (int) $b['id']) . ')', 'Period' => bdg_period_label($b), 'For' => bdg_scope_label($b), 'Fund' => $b['fund'] ?? 'Any',
            'Approved budget' => $money($x['budget']), 'Actual' => $money($x['actual']), 'Variance' => $money($x['variance']), 'Variance %' => $x['variance_pct'] === null ? '' : $x['variance_pct'] . '%',
            'Remaining' => $money($x['remaining']), 'Utilization %' => $x['utilization'] === null ? '' : $x['utilization'] . '%', 'Pending commitments' => $money($x['pending']), 'Available' => $money($x['available']), 'Status' => $x['level']];
        foreach ($t as $k => $v) { $t[$k] += $x[$k === 'budget' ? 'budget' : $k]; } }
    if ($sort) { usort($out, $sort); }
    foreach ($out as &$o) { unset($o['_u']); } unset($o);
    $totals = ['Budget' => 'TOTAL', 'Approved budget' => $money($t['budget']), 'Actual' => $money($t['actual']), 'Variance' => $money($t['variance']), 'Remaining' => $money($t['remaining']), 'Pending commitments' => $money($t['pending']), 'Available' => $money($t['available'])];
    return $out;
};
$bf = $f;
switch ($key) {
    case 'A1': $rows = $budgetRows(bdg_budgets($conn, $bf + ['period_type' => 'annual'])); break;
    case 'A2': $rows = $budgetRows(bdg_budgets($conn, $bf + ['period_type' => 'monthly'])); break;
    case 'A3': $rows = $budgetRows(bdg_budgets($conn, $bf + ['period_type' => 'quarterly'])); break;
    case 'A4': $rows = $budgetRows(bdg_budgets($conn, $bf + ['scope' => 'department'])); break;
    case 'A5': $rows = $budgetRows(bdg_budgets($conn, $bf + ['scope' => 'project'])); break;
    case 'A6': $rows = $budgetRows(bdg_budgets($conn, $bf + ['scope' => 'category'])); break;
    case 'A7': $rows = $budgetRows(bdg_budgets($conn, $bf + ['scope' => 'revenue'])); $note = 'For revenue, "Actual" is the approved income recorded; a negative variance means the target was exceeded.'; break;
    case 'A8': $rows = $budgetRows(array_filter(bdg_budgets($conn, $bf), function ($b) { return $b['kind'] === 'expense'; })); $note = 'Variance = Budget − Actual spending. Negative = overspent.'; break;
    case 'A9': $rows = $budgetRows(bdg_budgets($conn, $bf), function ($b, $x) { return $b['kind'] === 'expense' && $x['over'] > 0; }); $note = 'Budgets whose actual spending is above the approved budget.'; break;
    case 'A10': $rows = $budgetRows(array_filter(bdg_budgets($conn, $bf), function ($b) { return $b['kind'] === 'expense'; }), null, function ($a, $b) { return $b['_u'] <=> $a['_u']; }); $note = 'Budget Utilization = Actual ÷ Approved budget × 100. Highest first.'; break;
    case 'B1':
        $s = mysqli_prepare($conn, 'SELECT t.*, a.name src, b.name dst, u.names req, d.names decider FROM fund_transfers t JOIN funds a ON a.id = t.from_fund_id JOIN funds b ON b.id = t.to_fund_id LEFT JOIN users u ON u.id = t.requested_by LEFT JOIN users d ON d.id = t.decided_by WHERE DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.id');
        mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s); $sum = 0;
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $t) { if ($f['fund_id'] && !in_array((int) $f['fund_id'], [(int) $t['from_fund_id'], (int) $t['to_fund_id']], true)) { continue; }
            if ($t['status'] === 'Executed') { $sum += (float) $t['amount']; }
            $rows[] = ['Reference' => bdg_ref('transfer', (int) $t['id']), 'Date and time' => date('d M Y, H:i', strtotime($t['created_at'])), 'Source fund' => $t['src'], 'Destination fund' => $t['dst'], 'Amount' => $money($t['amount']),
                'Reason' => $t['reason'], 'Requested by' => $t['req'], 'Status' => $t['status'], 'Approved / rejected by' => $t['decider'], 'Decided' => $t['decided_at'] ? date('d M Y, H:i', strtotime($t['decided_at'])) : ''];
        }
        $totals = ['Reference' => 'TOTAL EXECUTED', 'Amount' => $money($sum)]; break;
    case 'B2':
        $t = array_fill_keys(['opening', 'inflows', 'transfers_in', 'expenses', 'transfers_out', 'closing', 'reserved', 'available'], 0);
        foreach ($L['funds'] as $fd) { if ($f['fund_id'] && (int) $f['fund_id'] !== (int) $fd['id']) { continue; } $s = bdg_fund_statement($conn, (int) $fd['id'], $from, $to);
            $rows[] = ['Fund' => $fd['name'], 'Allocation %' => $fd['allocation_percent'] !== null ? (float) $fd['allocation_percent'] . '%' : '—', 'Opening balance' => $money($s['opening']), 'Inflows' => $money($s['inflows']),
                'Transfers in' => $money($s['transfers_in']), 'Expenses' => $money($s['expenses']), 'Transfers out' => $money($s['transfers_out']), 'Closing balance' => $money($s['closing']), 'Reserved (now)' => $money($s['reserved']), 'Available (now)' => $money($s['available'])];
            foreach ($t as $k => $v) { $t[$k] += $s[$k]; } }
        $totals = ['Fund' => 'TOTAL', 'Opening balance' => $money($t['opening']), 'Inflows' => $money($t['inflows']), 'Transfers in' => $money($t['transfers_in']), 'Expenses' => $money($t['expenses']), 'Transfers out' => $money($t['transfers_out']),
                   'Closing balance' => $money($t['closing']), 'Reserved (now)' => $money($t['reserved']), 'Available (now)' => $money($t['available'])];
        $note = 'Closing = Opening + Inflows + Transfers in − Expenses − Transfers out (from the fund ledger). Available = Closing − Reserved. Transfers in and out balance to zero across all funds.'; break;
    case 'C1':
        foreach (BDG_SCOPES as $sc => $lab) { $bs = bdg_budgets($conn, $bf + ['scope' => $sc]); if (!$bs) { continue; } $a = ['b' => 0, 'a' => 0, 'p' => 0, 'o' => 0];
            foreach ($bs as $b) { $x = bdg_figures($conn, $b); $a['b'] += $x['budget']; $a['a'] += $x['actual']; $a['p'] += $x['pending']; $a['o'] += $x['over']; }
            $rows[] = ['Budget level' => $lab, 'Budgets' => count($bs), 'Approved budget' => $money($a['b']), 'Actual' => $money($a['a']), 'Variance' => $money($a['b'] - $a['a']), 'Utilization %' => $a['b'] > 0 ? round(100 * $a['a'] / $a['b'], 1) . '%' : '',
                'Pending commitments' => $money($a['p']), 'Available' => $money($a['b'] - $a['a'] - $a['p']), 'Overspending' => $money($a['o'])]; }
        $note = 'One line per budget level. Levels may cover the same spending (e.g. company-wide and departmental), so they are not added together.'; break;
    case 'C2':
        $s = mysqli_prepare($conn, "SELECT COALESCE(d.name, d2.name, 'No department') dep, SUM(t.amount) v FROM transactions t LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN users u ON u.id = t.recorded_by
            LEFT JOIN departments d2 ON d2.id = u.department_id WHERE t.transaction_type = 'Expense' AND t.status = 'approved' AND t.transaction_date BETWEEN ? AND ? GROUP BY dep ORDER BY v DESC");
        mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
        $spent = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC); // read before the next query
        $budg = []; foreach (bdg_budgets($conn, $bf + ['scope' => 'department']) as $b) { $budg[$b['department']] = ($budg[$b['department']] ?? 0) + (float) $b['amount']; }
        $sum = 0; foreach ($spent as $r) { $bud = $budg[$r['dep']] ?? null; $sum += (float) $r['v'];
            $rows[] = ['Department' => $r['dep'], 'Actual spending' => $money($r['v']), 'Departmental budget' => $bud === null ? 'No budget' : $money($bud), 'Variance' => $bud === null ? '' : $money($bud - (float) $r['v']), 'Utilization %' => $bud ? round(100 * (float) $r['v'] / $bud, 1) . '%' : '']; }
        $totals = ['Department' => 'TOTAL', 'Actual spending' => $money($sum)]; break;
    case 'C3': case 'C4':
        $grp = $key === 'C3' ? "DATE_FORMAT(transaction_date, '%Y-%m')" : 'YEAR(transaction_date)';
        $w = $key === 'C3' ? 'YEAR(transaction_date) = ' . (int) $f['year'] : 'YEAR(transaction_date) BETWEEN ' . ((int) $f['year'] - 4) . ' AND ' . (int) $f['year'];
        $t = ['i' => 0, 'x' => 0];
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT $grp p, SUM(CASE WHEN transaction_type = 'Income' THEN amount ELSE 0 END) inc, SUM(CASE WHEN transaction_type = 'Expense' THEN amount ELSE 0 END) exp FROM transactions WHERE status = 'approved' AND $w GROUP BY p ORDER BY p"), MYSQLI_ASSOC) as $r) {
            $t['i'] += (float) $r['inc']; $t['x'] += (float) $r['exp'];
            $rows[] = [($key === 'C3' ? 'Month' : 'Year') => $key === 'C3' ? date('F Y', strtotime($r['p'] . '-01')) : $r['p'], 'Income' => $money($r['inc']), 'Expenses' => $money($r['exp']), 'Net (income − expenses)' => $money((float) $r['inc'] - (float) $r['exp'])]; }
        $totals = [($key === 'C3' ? 'Month' : 'Year') => 'TOTAL', 'Income' => $money($t['i']), 'Expenses' => $money($t['x']), 'Net (income − expenses)' => $money($t['i'] - $t['x'])];
        $note = 'Approved transactions (cash view). The full accounting view (cost of goods, depreciation) is in Financial Reports.'; break;
    case 'C5':
        $y = (int) $f['year']; $m = []; for ($i = 1; $i <= 12; $i++) { $m[sprintf('%d-%02d', $y, $i)] = [0, 0, 0, 0]; }
        foreach (bdg_budgets($conn, ['year' => $y, 'scope' => 'company']) + [] as $b) { $s0 = strtotime($b['period_start']); $e0 = strtotime($b['period_end']); $days = max(1, (int) round(($e0 - $s0) / 86400) + 1);
            foreach ($m as $mk => &$mv) { $ms = strtotime($mk . '-01'); $me0 = strtotime(date('Y-m-t', $ms)); $ov = min($e0, $me0) - max($s0, $ms); if ($ov >= 0) { $mv[0] += (float) $b['amount'] * (((int) round($ov / 86400) + 1) / $days); } } unset($mv); }
        foreach (bdg_budgets($conn, ['year' => $y, 'scope' => 'revenue']) as $b) { $s0 = strtotime($b['period_start']); $e0 = strtotime($b['period_end']); $days = max(1, (int) round(($e0 - $s0) / 86400) + 1);
            foreach ($m as $mk => &$mv) { $ms = strtotime($mk . '-01'); $me0 = strtotime(date('Y-m-t', $ms)); $ov = min($e0, $me0) - max($s0, $ms); if ($ov >= 0) { $mv[2] += (float) $b['amount'] * (((int) round($ov / 86400) + 1) / $days); } } unset($mv); }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT DATE_FORMAT(transaction_date, '%Y-%m') p, transaction_type ty, SUM(amount) v FROM transactions WHERE status = 'approved' AND YEAR(transaction_date) = $y GROUP BY p, ty"), MYSQLI_ASSOC) as $r) { if (isset($m[$r['p']])) { $m[$r['p']][$r['ty'] === 'Income' ? 3 : 1] += (float) $r['v']; } }
        $t = [0, 0, 0, 0];
        foreach ($m as $mk => $v) { for ($i = 0; $i < 4; $i++) { $t[$i] += $v[$i]; }
            $rows[] = ['Month' => date('F Y', strtotime($mk . '-01')), 'Planned expenses' => $money($v[0]), 'Actual expenses' => $money($v[1]), 'Expense variance' => $money($v[0] - $v[1]), 'Planned revenue' => $money($v[2]), 'Actual revenue' => $money($v[3]), 'Revenue variance' => $money($v[3] - $v[2])]; }
        $totals = ['Month' => 'TOTAL', 'Planned expenses' => $money($t[0]), 'Actual expenses' => $money($t[1]), 'Expense variance' => $money($t[0] - $t[1]), 'Planned revenue' => $money($t[2]), 'Actual revenue' => $money($t[3]), 'Revenue variance' => $money($t[3] - $t[2])];
        $note = 'Planned = company-wide expense budgets and revenue budgets, spread over the months of their period.'; break;
    case 'C6':
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT r.*, b.name, b.original_amount, u.names req, d.names decider FROM budget_revisions r JOIN budgets b ON b.id = r.budget_id LEFT JOIN users u ON u.id = r.requested_by LEFT JOIN users d ON d.id = r.decided_by ORDER BY r.id DESC'), MYSQLI_ASSOC) as $r) {
            $rows[] = ['Revision' => bdg_ref('revision', (int) $r['id']), 'Budget' => $r['name'], 'Original approved amount' => $money($r['original_amount']), 'Amount before' => $money($r['old_amount']), 'Revised amount' => $money($r['new_amount']),
                'Reason' => $r['reason'], 'Requested by' => $r['req'], 'Date' => date('d M Y', strtotime($r['created_at'])), 'Status' => $r['status'], 'Decided by' => $r['decider'], 'Decision note' => $r['decision_note']]; } break;
    case 'C7':
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT b.*, u.names req FROM budgets b LEFT JOIN users u ON u.id = b.created_by WHERE b.status = 'Pending Approval'"), MYSQLI_ASSOC) as $r) { $rows[] = ['Type' => 'Budget', 'Reference' => bdg_ref('budget', (int) $r['id']), 'Description' => $r['name'] . ' · ' . bdg_period_label($r), 'Amount' => $money($r['amount']), 'Requested by' => $r['req'], 'Since' => date('d M Y', strtotime($r['created_at']))]; }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, b.name, u.names req FROM budget_revisions r JOIN budgets b ON b.id = r.budget_id LEFT JOIN users u ON u.id = r.requested_by WHERE r.status = 'Pending'"), MYSQLI_ASSOC) as $r) { $rows[] = ['Type' => 'Budget revision', 'Reference' => bdg_ref('revision', (int) $r['id']), 'Description' => $r['name'] . ': ' . number_format((float) $r['old_amount']) . ' → ' . number_format((float) $r['new_amount']), 'Amount' => $money($r['new_amount']), 'Requested by' => $r['req'], 'Since' => date('d M Y', strtotime($r['created_at']))]; }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT t.*, a.name src, b.name dst, u.names req FROM fund_transfers t JOIN funds a ON a.id = t.from_fund_id JOIN funds b ON b.id = t.to_fund_id LEFT JOIN users u ON u.id = t.requested_by WHERE t.status = 'Pending'"), MYSQLI_ASSOC) as $r) { $rows[] = ['Type' => 'Fund transfer', 'Reference' => bdg_ref('transfer', (int) $r['id']), 'Description' => $r['src'] . ' → ' . $r['dst'] . ': ' . $r['reason'], 'Amount' => $money($r['amount']), 'Requested by' => $r['req'], 'Since' => date('d M Y', strtotime($r['created_at']))]; }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, u.names req FROM fund_alloc_revisions r LEFT JOIN users u ON u.id = r.requested_by WHERE r.status = 'Pending'"), MYSQLI_ASSOC) as $r) { $rows[] = ['Type' => 'Allocation change', 'Reference' => bdg_ref('allocation', (int) $r['id']), 'Description' => $r['reason'], 'Amount' => '', 'Requested by' => $r['req'], 'Since' => date('d M Y', strtotime($r['created_at']))]; }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT c.*, f.name fund, u.names req FROM bdg_commitments c JOIN funds f ON f.id = c.fund_id LEFT JOIN users u ON u.id = c.requested_by WHERE c.status = 'Pending'"), MYSQLI_ASSOC) as $r) { $rows[] = ['Type' => 'Purchase / payment request', 'Reference' => bdg_ref('commitment', (int) $r['id']), 'Description' => $r['description'] . ' (' . $r['fund'] . ')', 'Amount' => $money($r['amount']), 'Requested by' => $r['req'], 'Since' => date('d M Y', strtotime($r['created_at']))]; }
        break;
    case 'C8':
        $sum = 0;
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT c.*, f.name fund, d.name dep, u.names req FROM bdg_commitments c JOIN funds f ON f.id = c.fund_id LEFT JOIN departments d ON d.id = c.department_id LEFT JOIN users u ON u.id = c.requested_by WHERE c.status IN ('Pending', 'Approved') ORDER BY c.status, c.id"), MYSQLI_ASSOC) as $r) {
            if ($f['fund_id'] && (int) $f['fund_id'] !== (int) $r['fund_id']) { continue; } if ($r['status'] === 'Approved') { $sum += (float) $r['amount']; }
            $rows[] = ['Reference' => bdg_ref('commitment', (int) $r['id']), 'Kind' => $r['status'] === 'Approved' ? 'Approved commitment (reserved)' : 'Pending request (not reserved)', 'Fund' => $r['fund'], 'Description' => $r['description'], 'Department' => $r['dep'], 'Amount' => $money($r['amount']), 'Requested by' => $r['req'], 'Date' => date('d M Y', strtotime($r['created_at']))]; }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT t.*, f.name fund, u.names req FROM transactions t LEFT JOIN funds f ON f.id = t.fund_id LEFT JOIN users u ON u.id = t.recorded_by WHERE t.transaction_type = 'Expense' AND t.status = 'pending' ORDER BY t.id"), MYSQLI_ASSOC) as $r) {
            if ($f['fund_id'] && (int) $f['fund_id'] !== (int) $r['fund_id']) { continue; } $sum += (float) $r['amount'];
            $rows[] = ['Reference' => 'TX-' . str_pad((string) $r['id'], 6, '0', STR_PAD_LEFT), 'Kind' => 'Pending expense transaction (reserved)', 'Fund' => $r['fund'], 'Description' => $r['description'], 'Department' => '', 'Amount' => $money($r['amount']), 'Requested by' => $r['req'], 'Date' => date('d M Y', strtotime($r['transaction_date']))]; }
        $totals = ['Reference' => 'TOTAL RESERVED', 'Amount' => $money($sum)]; $note = 'Reserved = approved commitments + pending expense transactions. Pending requests are listed but not reserved yet.'; break;
}
if ($q !== '') { $rows = array_values(array_filter($rows, function ($r) use ($q) { return stripos(implode(' ', $r), $q) !== false; })); }
$cols = $rows ? array_keys($rows[0]) : ($totals ? array_keys($totals) : []);
$title = $R[$key][1];
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9]+/', '-', $title) . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['RISE MOTIVE Ltd']); fputcsv($o, [$title]); fputcsv($o, ['Period: ' . $periodLabel]); fputcsv($o, ['Generated: ' . date('d M Y, H:i') . ' by ' . ($_SESSION['user_name'] ?? '')]); fputcsv($o, []);
    fputcsv($o, $cols); foreach ($rows as $r) { fputcsv($o, array_values($r)); } if ($totals) { fputcsv($o, array_map(function ($c) use ($totals) { return $totals[$c] ?? ''; }, $cols)); }
    if ($note) { fputcsv($o, []); fputcsv($o, [$note]); } fclose($o); exit;
}
$print = isset($_GET['print']);
$qs = function (array $extra) use ($key, $f, $q) { return 'reports.php?' . http_build_query(array_filter(['r' => $key, 'q' => $q] + $f, function ($v) { return $v !== '' && $v !== null; }) + $extra); };
if ($print) { echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $e($title) . '</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{font-size:12px} @media print {.noprint{display:none}}</style></head><body class="p-4">'; }
else { include '../../includes/header.php'; include '../../includes/sidebar.php'; bdg_tabs('reports.php'); }
?>
<?php if (!$print) { ?>
<form class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end small">
    <div class="col-md-4"><label class="text-muted">Report</label><select name="r" class="form-select form-select-sm"><?php foreach (['Budget' => 'A. Budget reports', 'Fund' => 'B. Fund reports', 'Management' => 'C. Management reports'] as $g => $gl) { ?><optgroup label="<?= $gl; ?>"><?php foreach ($R as $k => [$gg, $t]) { if ($gg !== $g) { continue; } ?><option value="<?= $k; ?>" <?= $key === $k ? 'selected' : ''; ?>><?= $e($t); ?></option><?php } ?></optgroup><?php } ?></select></div>
    <div class="col-6 col-md-1"><label class="text-muted">Year</label><input type="number" name="year" value="<?= (int) $f['year']; ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="text-muted">Month</label><input type="month" name="month" value="<?= $e($f['month']); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-1"><label class="text-muted">Quarter</label><select name="quarter" class="form-select form-select-sm"><option value="">—</option><?php for ($i = 1; $i <= 4; $i++) { ?><option value="<?= $i; ?>" <?= (int) $f['quarter'] === $i ? 'selected' : ''; ?>>Q<?= $i; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="text-muted">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($L['departments'] as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= (int) $f['department_id'] === (int) $d['id'] ? 'selected' : ''; ?>><?= $e($d['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="text-muted">Fund</label><select name="fund_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($L['funds'] as $fd) { ?><option value="<?= (int) $fd['id']; ?>" <?= (int) $f['fund_id'] === (int) $fd['id'] ? 'selected' : ''; ?>><?= $e($fd['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="text-muted">Project</label><select name="project_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($L['projects'] as $p) { ?><option value="<?= (int) $p['id']; ?>" <?= (int) $f['project_id'] === (int) $p['id'] ? 'selected' : ''; ?>><?= $e($p['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="text-muted">Category</label><select name="category" class="form-select form-select-sm"><option value="">All</option><?php foreach ($L['categories'] as $c) { ?><option <?= $f['category'] === $c ? 'selected' : ''; ?>><?= $e($c); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="text-muted">Search</label><input name="q" value="<?= $e($q); ?>" class="form-control form-control-sm" placeholder="Search in the results"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">View report</button></div>
</div></form>
<div class="d-flex gap-2 mb-2"><a href="<?= $e($qs(['export' => 1])); ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a><a href="<?= $e($qs(['print' => 1])); ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer"></i> Print / PDF</a></div>
<?php } ?>
<div class="card border-0 shadow-sm"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap mb-2"><div><div class="fw-bold" style="color:#0B1A8C;">RISE MOTIVE Ltd</div><h5 class="mb-0"><?= $e($title); ?></h5><div class="small text-muted">Period: <?= $e($periodLabel); ?><?= $q !== '' ? ' · search "' . $e($q) . '"' : ''; ?></div></div>
        <div class="small text-muted text-end">Generated <?= date('d M Y, H:i'); ?><br>by <?= $e($_SESSION['user_name'] ?? ''); ?></div></div>
    <div class="table-responsive"><table class="table table-sm table-bordered mb-0 align-middle" style="font-size:12px;">
        <tr class="table-light"><?php foreach ($cols as $c) { ?><th><?= $e($c); ?></th><?php } ?></tr>
        <?php if (!$rows) { ?><tr><td colspan="<?= max(1, count($cols)); ?>" class="text-center text-muted py-3">No records for this report and period.</td></tr><?php } ?>
        <?php foreach ($rows as $r) { ?><tr><?php foreach ($cols as $c) { $v = (string) ($r[$c] ?? ''); ?><td class="<?= preg_match('/^-?[\d,]+(\.\d+)?%?$/', $v) ? 'text-end' : ''; ?> <?= strpos($v, '-') === 0 || in_array($v, ['Overspent', 'Exhausted', 'Critical'], true) ? 'text-danger' : ''; ?>"><?= $e($v); ?></td><?php } ?></tr><?php } ?>
        <?php if ($totals) { ?><tr class="fw-bold table-light"><?php foreach ($cols as $c) { ?><td class="text-end"><?= $e($totals[$c] ?? ''); ?></td><?php } ?></tr><?php } ?>
    </table></div>
    <?php if ($note) { ?><div class="small text-muted mt-2"><?= $e($note); ?></div><?php } ?>
</div></div>
<?php if ($print) { echo '<script>window.print()</script></body></html>'; } else { include '../../includes/footer.php'; } ?>