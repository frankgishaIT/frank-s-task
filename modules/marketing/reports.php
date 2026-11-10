<?php
/**
 * NEW FILE: modules/marketing/reports.php — MARKETING REPORTS (15), from verified records.
 * View, search, PDF (the standard MY MOTIVE report builder), Excel (CSV), print. Commission reports: own lines only for
 * employees; the team for Managers / Administrators / Finance.
 */
require __DIR__ . '/mkt_common.php';
mkt_ensure_studio($conn);
if (!access_can('marketing.reports', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$R = ['R1' => 'Campaign Performance Report', 'R2' => 'Marketing Plan vs Actual Report', 'R3' => 'Lead Register', 'R4' => 'Lead Source Analysis', 'R5' => 'Lead Conversion Funnel',
      'R6' => 'Lost Leads Analysis', 'R7' => 'Follow-up Compliance Report', 'R8' => 'Customer Acquisition Report', 'R9' => 'Revenue & Profit Attribution Report',
      'R10' => 'Marketing ROI & Cost per Customer', 'R11' => 'Marketing Budget vs Spending', 'R12' => 'Team Performance Report', 'R13' => 'Commission Report',
      'R14' => 'Commission Payment & Payroll Reconciliation', 'R15' => 'Marketing Activities Report'];
$key = isset($R[$_GET['r'] ?? '']) ? $_GET['r'] : 'R1';
$from = DateTime::createFromFormat('Y-m-d', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-01-01');
$to = DateTime::createFromFormat('Y-m-d', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$cf = (int) ($_GET['campaign'] ?? 0); $uf = (int) ($_GET['user'] ?? 0); $q = trim((string) ($_GET['q'] ?? ''));
$seesPay = access_can('marketing.finance', $conn) || access_can('marketing.approve', $conn) || mkt_is_manager();
$names = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users'), MYSQLI_ASSOC), 'names', 'id');
$camps = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM mkt_campaigns' . ($cf ? " WHERE id = $cf" : '') . ' ORDER BY start_date DESC'), MYSQLI_ASSOC);
$m = function ($v) { return $v === null ? '' : number_format((float) $v, 0); };
$fd = function ($d) { return $d ? date('d M Y', strtotime($d)) : ''; };
$range = function (string $col) use ($conn, $from, $to) { return " AND DATE($col) BETWEEN '" . mysqli_real_escape_string($conn, $from) . "' AND '" . mysqli_real_escape_string($conn, $to) . "'"; };
$rows = []; $totals = null; $note = '';
switch ($key) {
    case 'R1': case 'R10': case 'R11':
        $t = ['budget' => 0, 'spent' => 0, 'leads' => 0, 'paying' => 0, 'revenue' => 0, 'profit' => 0];
        foreach ($camps as $c) { $a = mkt_actuals($conn, [(int) $c['id']]); $p = mkt_performance($c, $a);
            foreach (['budget' => (float) $c['budget'], 'spent' => $a['expenses'], 'leads' => $a['leads'], 'paying' => $a['paying'], 'revenue' => $a['revenue'], 'profit' => $a['profit']] as $k => $v) { $t[$k] += $v; }
            if ($key === 'R1') { $rows[] = ['Campaign' => mkt_code('campaign', $c) . ' ' . $c['name'], 'Status' => $c['status'], 'Leads (actual / target)' => $a['leads'] . ' / ' . (int) $c['expected_leads'],
                'Paying (actual / target)' => $a['paying'] . ' / ' . (int) $c['expected_customers'], 'Revenue' => $m($a['revenue']), 'Target revenue' => $m($c['expected_revenue']), 'Eligible profit' => $m($a['profit']),
                'Achievement %' => mkt_pct_text($p['overall']), 'Conversion %' => mkt_pct_text($p['conversion']), 'ROI %' => mkt_pct_text($p['roi'])]; }
            elseif ($key === 'R10') { $rows[] = ['Campaign' => mkt_code('campaign', $c) . ' ' . $c['name'], 'Spending' => $m($a['expenses']), 'Paying customers' => $a['paying'], 'Cost per paying customer' => $p['cac'] === null ? 'N/A' : $m($p['cac']),
                'Eligible profit' => $m($a['profit']), 'Profit − spending' => $m($a['profit'] - $a['expenses']), 'ROI %' => mkt_pct_text($p['roi'])]; }
            else { $rows[] = ['Campaign' => mkt_code('campaign', $c) . ' ' . $c['name'], 'Status' => $c['status'], 'Budget' => $m($c['budget']), 'Spent (approved)' => $m($a['expenses']), 'Remaining' => $m((float) $c['budget'] - $a['expenses']),
                'Used %' => mkt_pct_text($p['budget_used']), 'Linked expenses' => (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM mkt_expense_links WHERE campaign_id = ' . (int) $c['id']))[0]]; }
        }
        if ($key === 'R1') { $totals = ['Campaign' => 'TOTAL', 'Revenue' => $m($t['revenue']), 'Eligible profit' => $m($t['profit'])]; $note = 'Revenue and profit: fully paid sales (approved in Transactions) of the customers each campaign acquired, within the commission period. Achievement = average of the set targets. N/A = nothing to divide by.'; }
        elseif ($key === 'R10') { $totals = ['Campaign' => 'TOTAL', 'Spending' => $m($t['spent']), 'Paying customers' => $t['paying'], 'Cost per paying customer' => $t['paying'] && $t['spent'] ? $m($t['spent'] / $t['paying']) : 'N/A', 'Eligible profit' => $m($t['profit']), 'Profit − spending' => $m($t['profit'] - $t['spent']), 'ROI %' => mkt_pct_text(mkt_pct($t['profit'] - $t['spent'], $t['spent']))];
            $note = 'ROI = (eligible profit − spending) ÷ spending × 100. Cost per paying customer = spending ÷ verified paying customers.'; }
        else { $totals = ['Campaign' => 'TOTAL', 'Budget' => $m($t['budget']), 'Spent (approved)' => $m($t['spent']), 'Remaining' => $m($t['budget'] - $t['spent']), 'Used %' => mkt_pct_text(mkt_pct($t['spent'], $t['budget']))]; $note = 'Spending = approved expense transactions linked to each campaign (Transactions is the only record of the money).'; }
        break;
    case 'R2':
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM mkt_plans ORDER BY start_date DESC'), MYSQLI_ASSOC) as $p) {
            $a = mkt_actuals($conn, array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id FROM mkt_campaigns WHERE plan_id = ' . (int) $p['id']), MYSQLI_ASSOC), 'id')); $pf = mkt_performance($p, $a);
            $rows[] = ['Plan' => mkt_code('plan', $p) . ' ' . $p['title'], 'Status' => $p['status'], 'Period' => $fd($p['start_date']) . ' – ' . $fd($p['end_date']), 'Leads' => $a['leads'] . ' / ' . (int) $p['expected_leads'],
                'Paying customers' => $a['paying'] . ' / ' . (int) $p['expected_customers'], 'Revenue' => $m($a['revenue']) . ' / ' . $m($p['expected_revenue']), 'Profit' => $m($a['profit']) . ' / ' . $m($p['expected_profit']),
                'Budget used' => mkt_pct_text($pf['budget_used']), 'Time elapsed' => $pf['elapsed'] === null ? '' : $pf['elapsed'] . '%', 'Achievement' => mkt_pct_text($pf['overall'])]; }
        $note = 'Each cell: actual / planned. Actuals come from the plan\'s campaigns.'; break;
    case 'R3': case 'R6':
        $w = $key === 'R6' ? "l.status = 'Lost'" : '1 = 1'; if ($cf) { $w .= " AND l.campaign_id = $cf"; } if ($uf) { $w .= " AND l.owner_id = $uf"; }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT l.*, c.name AS campaign FROM mkt_leads l LEFT JOIN mkt_campaigns c ON c.id = l.campaign_id WHERE $w" . $range('l.created_at') . ' ORDER BY l.id'), MYSQLI_ASSOC) as $l) {
            $rows[] = $key === 'R3' ? ['Lead ID' => mkt_code('lead', $l), 'Name' => $l['name'], 'Contact' => trim($l['contact_person'] . ' ' . $l['phone']), 'Source' => $l['source'], 'Campaign' => $l['campaign'], 'Owner' => $names[$l['owner_id']] ?? '',
                    'Status' => $l['status'], 'Est. value' => $m($l['estimated_value']), 'Next follow-up' => $fd($l['next_followup']), 'Registered' => $fd($l['created_at'])]
                : ['Lead ID' => mkt_code('lead', $l), 'Name' => $l['name'], 'Source' => $l['source'], 'Campaign' => $l['campaign'], 'Owner' => $names[$l['owner_id']] ?? '', 'Est. value' => $m($l['estimated_value']), 'Reason lost' => $l['lost_reason'], 'Registered' => $fd($l['created_at'])];
        }
        if ($key === 'R6') { $reasons = []; foreach ($rows as $r) { $k = mb_strtolower(trim((string) $r['Reason lost'])); $reasons[$k] = ($reasons[$k] ?? 0) + 1; } arsort($reasons);
            $note = 'Most frequent reasons: ' . implode('; ', array_map(function ($k, $v) { return "$k ($v)"; }, array_keys(array_slice($reasons, 0, 5, true)), array_slice($reasons, 0, 5, true))) . '.'; }
        $totals = [array_key_first($rows[0] ?? ['Lead ID' => 1]) => count($rows) . ' lead(s)']; break;
    case 'R4':
        $w = $cf ? " AND campaign_id = $cf" : '';
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT COALESCE(source, 'Unknown') src, COUNT(*) leads, SUM(status IN ('" . implode("','", MKT_QUALIFIED) . "')) qualified, SUM(status = 'Won') won, SUM(status = 'Lost') lost,
            (SELECT COUNT(*) FROM mkt_acquisitions a JOIN mkt_leads x ON x.id = a.lead_id WHERE a.status = 'Paying' AND COALESCE(x.source, 'Unknown') = COALESCE(l.source, 'Unknown')) paying
            FROM mkt_leads l WHERE 1 = 1$w" . $range('created_at') . ' GROUP BY src ORDER BY leads DESC'), MYSQLI_ASSOC) as $r) {
            $rows[] = ['Source' => $r['src'], 'Leads' => (int) $r['leads'], 'Qualified' => (int) $r['qualified'], 'Won' => (int) $r['won'], 'Lost' => (int) $r['lost'], 'Paying customers' => (int) $r['paying'], 'Conversion %' => mkt_pct_text(mkt_pct((float) $r['paying'], (float) $r['qualified']))]; }
        $note = 'Conversion = paying customers ÷ qualified leads.'; break;
    case 'R5':
        $w = ($cf ? " AND campaign_id = $cf" : '') . ($uf ? " AND owner_id = $uf" : '');
        $cnt = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT status, COUNT(*) n FROM mkt_leads WHERE 1 = 1$w" . $range('created_at') . ' GROUP BY status'), MYSQLI_ASSOC), 'n', 'status'); $all = array_sum($cnt);
        foreach (MKT_LEAD_FLOW as $s) { $rows[] = ['Stage' => $s, 'Leads' => (int) ($cnt[$s] ?? 0), 'Share of all leads' => mkt_pct_text(mkt_pct((float) ($cnt[$s] ?? 0), (float) $all))]; }
        $totals = ['Stage' => 'TOTAL', 'Leads' => $all]; $note = 'Current stage of the leads registered in the period.'; break;
    case 'R7':
        $w = ($cf ? " AND campaign_id = $cf" : '') . ($uf ? " AND owner_id = $uf" : '');
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT owner_id, SUM(status NOT IN ('Won', 'Lost')) open, SUM(status NOT IN ('Won', 'Lost') AND next_followup < CURDATE()) overdue, SUM(status NOT IN ('Won', 'Lost') AND next_followup IS NULL) nodate FROM mkt_leads WHERE owner_id IS NOT NULL$w GROUP BY owner_id"), MYSQLI_ASSOC) as $r) {
            $fu = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM mkt_followups WHERE user_id = ' . (int) $r['owner_id'] . $range('created_at')))[0];
            $rows[] = ['Owner' => $names[$r['owner_id']] ?? '#' . $r['owner_id'], 'Open leads' => (int) $r['open'], 'Overdue follow-ups' => (int) $r['overdue'], 'Without next date' => (int) $r['nodate'], 'Follow-ups in period' => $fu,
                'On schedule %' => mkt_pct_text(mkt_pct((float) $r['open'] - (float) $r['overdue'] - (float) $r['nodate'], (float) $r['open']))]; }
        $note = 'On schedule = open leads with a future follow-up date.'; break;
    case 'R8': case 'R9':
        $w = ($cf ? " AND a.campaign_id = $cf" : '') . ($uf ? " AND a.owner_id = $uf" : ''); $months = (int) mkt_setting($conn, 'commission_months'); $t = [0, 0];
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, cu.name AS customer, c.name AS campaign, l.created_at AS lead_created FROM mkt_acquisitions a JOIN customers cu ON cu.id = a.customer_id LEFT JOIN mkt_campaigns c ON c.id = a.campaign_id LEFT JOIN mkt_leads l ON l.id = a.lead_id WHERE 1 = 1$w" . $range('a.acquired_at') . ' ORDER BY a.acquired_at'), MYSQLI_ASSOC) as $a) {
            if ($key === 'R8') { $rows[] = ['Customer' => $a['customer'], 'Lead' => $a['lead_id'] ? mkt_code('lead', ['id' => $a['lead_id'], 'created_at' => $a['lead_created']]) : '', 'Campaign' => $a['campaign'], 'Acquired by' => $names[$a['owner_id']] ?? '',
                'Converted' => $fd($a['acquired_at']), 'First verified payment' => $fd($a['first_paid_at']), 'Status' => $a['status']]; continue; }
            if (!$a['first_paid_at']) { continue; }
            $win = [substr($a['acquired_at'], 0, 10), $months > 0 ? date('Y-m-d', strtotime($a['first_paid_at'] . " +$months months")) : substr($a['first_paid_at'], 0, 10)];
            $rev = 0; $pro = 0; $n = 0; foreach (mkt_verified_sales($conn, [(int) $a['customer_id'] => $win]) as $s) { $rev += (float) $s['total_amount']; $pro += mkt_sale_profit($conn, (int) $s['id']); $n++; }
            $t[0] += $rev; $t[1] += $pro;
            $rows[] = ['Customer' => $a['customer'], 'Campaign' => $a['campaign'], 'Acquired by' => $names[$a['owner_id']] ?? '', 'Attribution window' => $fd($win[0]) . ' – ' . $fd($win[1]), 'Paid sales' => $n, 'Revenue' => $m($rev), 'Eligible profit' => $m($pro)]; }
        if ($key === 'R9') { $totals = ['Customer' => 'TOTAL', 'Revenue' => $m($t[0]), 'Eligible profit' => $m($t[1])]; $note = 'Only fully paid sales approved in Transactions, within ' . $months . ' month(s) after the first verified payment. Existing customers are not attributed.'; }
        else { $note = '"Existing customer" = had paid purchases before the lead: linked, but not a new acquisition.'; }
        break;
    case 'R12':
        $perf = mkt_team_performance($conn, $from, $to); if (!mkt_is_manager()) { $perf = array_intersect_key($perf, [$me => 1]); } if ($uf) { $perf = array_intersect_key($perf, [$uf => 1]); }
        uasort($perf, function ($a, $b) { return $b['points'] <=> $a['points']; });
        foreach ($perf as $u => $r) { $rows[] = ['Team member' => $names[$u] ?? '#' . $u, 'Leads' => (int) ($r['lead'] ?? 0), 'Follow-ups' => (int) ($r['followup'] ?? 0), 'Qualified' => (int) ($r['qualified'] ?? 0), 'New customers' => (int) ($r['conversion'] ?? 0),
            'Paying customers' => (int) ($r['paying'] ?? 0), 'Activities on time' => (int) ($r['activity_on_time'] ?? 0), 'Activities late' => (int) ($r['activity_late'] ?? 0), 'Quality' => isset($r['quality']) ? $r['quality'] . '/5' : '', 'Points' => $r['points']]; }
        $note = 'Points weights are set in Marketing Settings.'; break;
    case 'R13': case 'R14':
        $w = $seesPay ? ($uf ? "c.user_id = $uf" : '1 = 1') : "c.user_id = $me";
        $w .= $key === 'R14' ? " AND c.status IN ('In payroll', 'Paid')" : ''; if ($cf) { $w .= " AND c.campaign_id = $cf"; }
        $w .= " AND c.period BETWEEN '" . substr($from, 0, 7) . "' AND '" . substr($to, 0, 7) . "'"; $sum = 0;
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT c.*, cu.name AS customer FROM mkt_commissions c LEFT JOIN customers cu ON cu.id = c.customer_id WHERE $w ORDER BY c.user_id, c.period, c.id"), MYSQLI_ASSOC) as $c) {
            $sum += in_array($c['status'], ['Reversed', 'Rejected'], true) ? 0 : (float) $c['amount'];
            $rows[] = $key === 'R13' ? ['Line' => 'COM-' . $c['id'], 'Person' => $names[$c['user_id']] ?? '', 'Kind' => $c['kind'], 'Customer' => $c['customer'], 'Period' => $c['period'], 'Eligible profit' => $m($c['profit_adjusted'] ?? $c['eligible_profit']),
                    'Rate × share' => $c['rate'] !== null ? (float) $c['rate'] . '% × ' . (float) $c['share_pct'] . '%' : '', 'Amount' => $m($c['amount']), 'Status' => $c['status']]
                : ['Line' => 'COM-' . $c['id'], 'Person' => $names[$c['user_id']] ?? '', 'Kind' => $c['kind'], 'Period' => $c['period'], 'Amount' => $m($c['amount']), 'Payroll line' => $c['payroll_id'] ? '#' . $c['payroll_id'] : '', 'Status' => $c['status'], 'Paid on' => $fd($c['paid_at'])];
        }
        $totals = ['Line' => 'TOTAL', 'Amount' => $m($sum)]; $note = ($seesPay ? '' : 'Your own lines only. ') . 'Reversed and rejected lines are listed but not counted in the total.'; break;
    case 'R15':
        $w = ($cf ? " AND a.campaign_id = $cf" : '') . ($uf ? " AND t.assigned_to = $uf" : '');
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, t.title, t.status AS ts, t.due_date, t.submitted_at, t.assigned_to, c.name AS campaign FROM mkt_activities a JOIN tasks t ON t.id = a.task_id LEFT JOIN mkt_campaigns c ON c.id = a.campaign_id WHERE 1 = 1$w" . $range('t.due_date') . ' ORDER BY t.due_date'), MYSQLI_ASSOC) as $a) {
            $rows[] = ['Activity' => 'ACT-' . $a['id'] . ' ' . $a['title'], 'Person' => $names[$a['assigned_to']] ?? '', 'Type' => $a['activity_type'], 'Campaign' => $a['campaign'], 'Due' => $fd($a['due_date']), 'Task status' => $a['ts'],
                'On time' => $a['submitted_at'] ? (substr($a['submitted_at'], 0, 10) <= $a['due_date'] ? 'Yes' : 'No') : '', 'Quality' => $a['quality_score'] ? $a['quality_score'] . '/5' : '', 'Review' => $a['review_status']]; }
        $note = 'On time = submitted on or before the due date (same rule as the Task reports).'; break;
}
if ($q !== '') { $rows = array_values(array_filter($rows, function ($r) use ($q) { return stripos(implode(' ', $r), $q) !== false; })); }
$cols = $rows ? array_keys($rows[0]) : ($totals ? array_keys($totals) : []);
$title = $R[$key]; $periodLabel = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9]+/', '-', $title) . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF"); fputcsv($o, ['RISE MOTIVE Ltd']); fputcsv($o, [$title]); fputcsv($o, ['Period: ' . $periodLabel]); fputcsv($o, ['Generated: ' . date('d M Y, H:i') . ' by ' . ($_SESSION['user_name'] ?? '')]); fputcsv($o, []);
    fputcsv($o, $cols); foreach ($rows as $r) { fputcsv($o, array_values($r)); } if ($totals) { fputcsv($o, array_map(function ($c) use ($totals) { return $totals[$c] ?? ''; }, $cols)); } if ($note) { fputcsv($o, []); fputcsv($o, [$note]); } fclose($o); exit;
}
if (isset($_GET['pdf'])) { // the standard MY MOTIVE report design (same builder as every other report)
    require_once __DIR__ . '/../../includes/report_pdf.php';
    $isNum = function ($v) { return preg_match('/^-?[\d,]+(\.\d+)?%?$/', (string) $v) === 1; };
    $columns = []; $keys = [];
    foreach ($cols as $i => $c) { $keys[$c] = 'c' . $i; $num = false; foreach (array_slice($rows, 0, 20) as $r) { if (($r[$c] ?? '') !== '') { $num = $isNum($r[$c]); break; } } $columns[] = ['key' => 'c' . $i, 'label' => $c, 'w' => 0, 'align' => $num ? 'R' : 'L', 'type' => 'text']; }
    $wts = array_map(function ($c) { return $c['align'] === 'R' ? 1.0 : 1.7; }, $columns); $sw = array_sum($wts) ?: 1; foreach ($columns as $i => &$c) { $c['w'] = round(100 * $wts[$i] / $sw, 1); } unset($c);
    $pr = array_map(function ($r) use ($keys) { $o = []; foreach ($keys as $l => $k) { $o[$k] = (string) ($r[$l] ?? ''); } return $o; }, $rows);
    $pt = null; $tl = 'TOTAL'; if ($totals) { $pt = []; foreach ($totals as $l => $v) { if (!isset($keys[$l])) { continue; } if ($keys[$l] === 'c0') { $tl = (string) $v; continue; } $pt[$keys[$l]] = (string) $v; } }
    $filters = [['Period', $periodLabel]]; if ($cf && $camps) { $filters[] = ['Campaign', $camps[0]['name']]; } if ($uf) { $filters[] = ['Person', $names[$uf] ?? '']; } if ($q !== '') { $filters[] = ['Search', $q]; }
    try { $mpdf = report_render_pdf(['title' => $title, 'filters' => $filters, 'summary' => [['Records', (string) count($rows)]], 'columns' => $columns, 'rows' => $pr, 'totals' => $pt, 'totals_label' => $tl, 'footer_summary' => [], 'notes' => $note ? [$note] : []]);
        while (ob_get_level()) { ob_end_clean(); }
        $mpdf->Output(preg_replace('/[^A-Za-z0-9]+/', '-', $title) . '-' . date('Ymd') . '.pdf', !empty($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);
    } catch (Throwable $x) { http_response_code(500); echo 'The PDF could not be created: ' . htmlspecialchars($x->getMessage(), ENT_QUOTES, 'UTF-8') . '. Use "Print" and choose "Save as PDF" instead.'; }
    exit;
}
$print = isset($_GET['print']);
$qs = function (array $extra) use ($key, $from, $to, $cf, $uf, $q) { return 'reports.php?' . http_build_query(array_filter(['r' => $key, 'from' => $from, 'to' => $to, 'campaign' => $cf ?: null, 'user' => $uf ?: null, 'q' => $q !== '' ? $q : null]) + $extra); };
if ($print) { echo '<!doctype html><html><head><meta charset="utf-8"><title>' . $e($title) . '</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"><style>body{font-size:12px}</style></head><body class="p-4">'; }
else { include '../../includes/header.php'; include '../../includes/sidebar.php'; mkt_tabs('reports.php'); }
?>
<?php if (!$print) { $allCamps = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM mkt_campaigns ORDER BY name'), MYSQLI_ASSOC); ?>
<form class="card border-0 shadow-sm mb-2"><div class="card-body row g-2 small align-items-end">
    <div class="col-md-4"><label class="text-muted">Report</label><select name="r" class="form-select form-select-sm"><?php foreach ($R as $k => $t) { ?><option value="<?= $k; ?>" <?= $key === $k ? 'selected' : ''; ?>><?= $e($t); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="text-muted">From</label><input type="date" name="from" value="<?= $e($from); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="text-muted">To</label><input type="date" name="to" value="<?= $e($to); ?>" class="form-control form-control-sm"></div>
    <div class="col-md-2"><label class="text-muted">Campaign</label><select name="campaign" class="form-select form-select-sm"><option value="">All</option><?php foreach ($allCamps as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $cf === (int) $c['id'] ? 'selected' : ''; ?>><?= $e($c['name']); ?></option><?php } ?></select></div>
    <div class="col-md-2"><label class="text-muted">Person</label><select name="user" class="form-select form-select-sm"><option value="">Everyone</option><?php foreach ($names as $id => $n) { ?><option value="<?= (int) $id; ?>" <?= $uf === (int) $id ? 'selected' : ''; ?>><?= $e($n); ?></option><?php } ?></select></div>
    <div class="col-md-10"><input name="q" value="<?= $e($q); ?>" class="form-control form-control-sm" placeholder="Search in the results"></div><div class="col-md-2"><button class="btn btn-primary btn-sm w-100">View report</button></div>
</div></form>
<div class="d-flex flex-wrap gap-2 mb-2"><a href="<?= $e($qs(['pdf' => 1])); ?>" target="_blank" class="btn btn-sm btn-outline-danger"><i class="bi bi-file-earmark-pdf"></i> PDF</a><a href="<?= $e($qs(['pdf' => 1, 'download' => 1])); ?>" class="btn btn-sm btn-outline-danger"><i class="bi bi-download"></i> Download PDF</a>
    <a href="<?= $e($qs(['export' => 1])); ?>" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel</a><a href="<?= $e($qs(['print' => 1])); ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer"></i> Print</a></div>
<?php } ?>
<div class="card border-0 shadow-sm"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap mb-2"><div><div class="fw-bold" style="color:#0B1A8C;">RISE MOTIVE Ltd</div><h5 class="mb-0"><?= $e($title); ?></h5><div class="small text-muted">Period: <?= $e($periodLabel); ?></div></div><div class="small text-muted text-end">Generated <?= date('d M Y, H:i'); ?><br>by <?= $e($_SESSION['user_name'] ?? ''); ?></div></div>
    <div class="table-responsive"><table class="table table-sm table-bordered mb-0 align-middle" style="font-size:12px;">
        <tr class="table-light"><?php foreach ($cols as $c) { ?><th><?= $e($c); ?></th><?php } ?></tr>
        <?php if (!$rows) { ?><tr><td colspan="<?= max(1, count($cols)); ?>" class="text-center text-muted py-3">No records for this report and period.</td></tr><?php } ?>
        <?php foreach ($rows as $r) { ?><tr><?php foreach ($cols as $c) { $v = (string) ($r[$c] ?? ''); ?><td class="<?= preg_match('/^-?[\d,]+(\.\d+)?%?$/', $v) ? 'text-end' : ''; ?> <?= strpos($v, '-') === 0 ? 'text-danger' : ''; ?>"><?= $e($v); ?></td><?php } ?></tr><?php } ?>
        <?php if ($totals) { ?><tr class="fw-bold table-light"><?php foreach ($cols as $c) { ?><td class="text-end"><?= $e($totals[$c] ?? ''); ?></td><?php } ?></tr><?php } ?>
    </table></div>
    <?php if ($note) { ?><div class="small text-muted mt-2"><?= $e($note); ?></div><?php } ?>
</div></div>
<?php if ($print) { echo '<script>window.print()</script></body></html>'; } else { include '../../includes/footer.php'; } ?>