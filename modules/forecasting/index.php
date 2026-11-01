<?php
/**
 * NEW FILE: modules/forecasting/index.php
 * Task 3: RM BUSINESS FORECASTING — dashboard and tabs. Actual and projected figures are always labelled apart.
 */
require '../../config/db.php';
require_once '../../includes/forecast_helpers.php';
fc_ensure($conn);
fc_guard($conn);

$tabs = ['overview' => 'Overview', 'revenue' => 'Revenue', 'expenses' => 'Expenses', 'pnl' => 'Profit & Loss', 'cash' => 'Cash flow',
         'inventory' => 'Inventory & demand', 'growth' => 'Growth', 'scenarios' => 'Scenarios & what-if', 'accuracy' => 'Accuracy'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'overview';
$g = isset(FC_GRANULARITIES[$_GET['g'] ?? '']) ? $_GET['g'] : 'month';
$canManage = access_can('forecasting.manage', $conn);
$canExport = access_can('forecasting.reports', $conn);

// Saving the scenario assumptions (managers of the module only).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_scenarios') {
    if (!$canManage) { header('Location: index.php?tab=scenarios&error=' . urlencode('Only forecasting managers can change the saved scenarios.')); exit; }
    $save = [];
    foreach (array_keys(FC_DEFAULT_SCENARIOS) as $k) {
        foreach (['sales', 'expenses', 'cost', 'delay'] as $f) { $save[$k][$f] = max(-90, min(300, (float) ($_POST[$k][$f] ?? 0))); }
    }
    $json = json_encode($save);
    $st = mysqli_prepare($conn, "INSERT INTO fc_settings (name, value) VALUES ('scenarios', ?) ON DUPLICATE KEY UPDATE value = VALUES(value)");
    mysqli_stmt_bind_param($st, 's', $json);
    mysqli_stmt_execute($st);
    header('Location: index.php?tab=scenarios&success=' . urlencode('Scenario assumptions saved.')); exit;
}

// Data used by several tabs (monthly view for the overview, P&L, cash and scenarios).
$revM = fc_history($conn, 'revenue', 'month'); $revMfc = fc_project($revM, 'month');
$expM = fc_history($conn, 'expenses', 'month'); $expMfc = fc_project($expM, 'month');
$gm = fc_gross_margin($conn);
$recv = fc_receivables($conn);
$cash = fc_cash_now($conn);
$loanDues = fc_loan_dues($conn, 90);
$restockHist = fc_history($conn, 'restock', 'month');
$restockVals = array_slice(array_values($restockHist['periods']), -3);
$restockMonth = $restockVals ? array_sum($restockVals) / count($restockVals) : 0.0;
$cf = fc_cash_flow($cash, $revMfc, $expMfc, $recv, $loanDues, $restockMonth, 90);
fc_save_snapshots($conn, ['revenue' => $revMfc, 'expenses' => $expMfc]);

$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$m = function ($v) { return $v === null ? '—' : 'RWF ' . number_format((float) $v, 0); };
$next = function ($fc, $k = 0) { return !empty($fc['ok']) ? (array_values($fc['points'])[$k][0] ?? null) : null; };
$sumNext = function ($fc, $n) { return !empty($fc['ok']) ? array_sum(array_map(function ($p) { return $p[0]; }, array_slice($fc['points'], 0, $n))) : null; };

// CSV export of the current tab's table.
if (isset($_GET['export']) && $canExport) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="forecast-' . $tab . '-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    if ($tab === 'inventory') {
        fputcsv($o, ['Product', 'Unit', 'Stock', 'Avg daily sales', 'Days of stock left', 'Runs out (projected)', 'Status', 'Recommended purchase', 'Estimated cost (RWF)']);
        foreach (fc_inventory($conn) as $r) { fputcsv($o, [$r['product_name'], $r['unit'], $r['quantity'], $r['daily'], $r['days_left'], $r['runs_out'], $r['status'], $r['recommend'], $r['recommend_cost']]); }
    } else {
        $metric = $tab === 'expenses' ? 'expenses' : 'revenue';
        $h = fc_history($conn, $metric, $g); $f = fc_project($h, $g);
        fputcsv($o, ['Period', 'Type', ucfirst($metric) . ' (RWF)', 'Low', 'High', 'Confidence']);
        foreach ($h['periods'] as $p => $v) { fputcsv($o, [fc_label($p, $g), 'Actual', round($v, 2), '', '', '']); }
        if (!empty($f['ok'])) { foreach ($f['points'] as $p => [$v, $lo, $hi]) { fputcsv($o, [fc_label($p, $g), 'Projected', $v, $lo, $hi, $f['confidence']]); } }
    }
    fclose($o); exit;
}

/** A chart: actual (solid) and projected (dashed) with its range. */
function fc_chart_data(array $hist, array $fc, string $g): array
{
    $labels = []; $actual = []; $proj = []; $lo = []; $hi = [];
    foreach ($hist['periods'] as $p => $v) { $labels[] = fc_label($p, $g); $actual[] = round($v); $proj[] = null; $lo[] = null; $hi[] = null; }
    if (!empty($fc['ok'])) {
        if ($actual) { $proj[count($proj) - 1] = end($actual); }
        foreach ($fc['points'] as $p => [$v, $l, $h]) { $labels[] = fc_label($p, $g) . ' (P)'; $actual[] = null; $proj[] = round($v); $lo[] = round($l); $hi[] = round($h); }
    }
    return compact('labels', 'actual', 'proj', 'lo', 'hi');
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
function fcChart(id, d, money) {
    const el = document.getElementById(id); if (!el || !window.Chart) { return; }
    new Chart(el, { type: 'line', data: { labels: d.labels, datasets: [
        { label: 'Actual', data: d.actual, borderColor: '#1E2FE0', backgroundColor: '#1E2FE0', tension: .25, spanGaps: false },
        { label: 'Projected', data: d.proj, borderColor: '#0FA968', borderDash: [6, 5], pointStyle: 'rectRot', tension: .25 },
        { label: 'Projected range (high)', data: d.hi, borderColor: 'rgba(15,169,104,.25)', pointRadius: 0, fill: '+1', backgroundColor: 'rgba(15,169,104,.08)' },
        { label: 'Projected range (low)', data: d.lo, borderColor: 'rgba(15,169,104,.25)', pointRadius: 0, fill: false }
    ] }, options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
        plugins: { legend: { labels: { filter: i => !i.text.startsWith('Projected range (low)') } },
                   tooltip: { callbacks: { label: c => c.dataset.label + ': ' + (c.parsed.y === null ? '—' : (money ? 'RWF ' : '') + Math.round(c.parsed.y).toLocaleString()) } } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => (money ? 'RWF ' : '') + Number(v).toLocaleString() } } } } });
}
</script>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success"><?= $e($_GET['success']); ?></div><?php } ?>
<?php if (isset($_GET['error'])) { ?><div class="alert alert-danger"><?= $e($_GET['error']); ?></div><?php } ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
    <h2 class="mb-0">RM Business Forecasting</h2>
    <span class="small text-muted">Projections from MY MOTIVE's own records · <span class="badge bg-primary">Actual</span> <span class="badge bg-success">Projected</span></span>
</div>
<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">
    <?php foreach ($tabs as $k => $l) { ?><li class="nav-item"><a class="nav-link text-nowrap <?= $tab === $k ? 'active' : ''; ?>" href="index.php?tab=<?= $k; ?>"><?= $e($l); ?></a></li><?php } ?>
</ul>

<?php if ($tab === 'overview') {
    $risks = []; $advice = [];
    if (!empty($cf['first_shortage'])) { $risks[] = 'Cash is projected to run out on <strong>' . date('d M Y', strtotime($cf['first_shortage'])) . '</strong> (lowest: ' . $m($cf['lowest'][1]) . ' on ' . date('d M', strtotime($cf['lowest'][0])) . ').';
        $advice[] = 'Collect overdue invoices, delay non-urgent purchases, or plan financing before ' . date('d M Y', strtotime($cf['first_shortage'])) . '.'; }
    if (!empty($revMfc['ok']) && $revMfc['slope_pct'] < -3) { $risks[] = 'Revenue is trending <strong>down</strong> (' . $revMfc['slope_pct'] . '% per month).'; $advice[] = 'Review prices, promotions and follow-ups with past customers.'; }
    if (!empty($revMfc['ok']) && !empty($expMfc['ok']) && $expMfc['slope_pct'] > $revMfc['slope_pct'] + 3) { $risks[] = 'Expenses are growing faster than revenue (' . $expMfc['slope_pct'] . '% vs ' . $revMfc['slope_pct'] . '% per month).'; $advice[] = 'Check the fastest-growing expense categories in the Expenses tab.'; }
    if ($recv['open'] > 0 && $revM['periods'] && $recv['open'] > 0.5 * (array_sum($revM['periods']) / max(1, count($revM['periods'])))) { $risks[] = 'Customers owe ' . $m($recv['open']) . ', more than half of an average month of sales.'; $advice[] = 'Send credit reminders (SMS) and follow up the biggest balances.'; }
    $inv = fc_inventory($conn); $soon = array_filter($inv, function ($r) { return in_array($r['status'], ['Out of stock', 'Will run out soon'], true); });
    if ($soon) { $risks[] = count($soon) . ' product(s) are out of stock or will run out within ' . FC_LEAD_DAYS . ' days.'; $advice[] = 'Order the recommended quantities (Inventory & demand tab).'; }
    $nextProfit = ($next($revMfc) !== null && $next($expMfc) !== null && $gm['margin'] !== null) ? $next($revMfc) * $gm['margin'] - $next($expMfc) : null;
?>
<div class="row g-3 mb-3">
    <?php foreach ([['Projected revenue', 'next month', $next($revMfc), $revMfc], ['Projected expenses', 'next month', $next($expMfc), $expMfc],
                    ['Projected profit / loss', 'next month', $nextProfit, null], ['Projected cash', 'in 90 days', $cf['days'] ? end($cf['days']) : null, null]] as [$t, $when, $v, $fc]) { ?>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <div class="small text-muted"><?= $t; ?> <span class="badge bg-success">Projected</span></div>
        <div class="fs-5 fw-bold <?= $v !== null && $v < 0 ? 'text-danger' : ''; ?>"><?= $m($v); ?></div>
        <div class="small text-muted"><?= $when; ?><?= $fc && !empty($fc['ok']) ? ' · confidence ' . $fc['confidence'] : ''; ?></div>
    </div></div></div>
    <?php } ?>
</div>
<div class="row g-3">
    <div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body"><h6>Monthly revenue: actual and projected</h6>
        <?php if (empty($revMfc['ok'])) { ?><div class="alert alert-info small mb-2"><?= $e($revMfc['reason']); ?></div><?php } ?>
        <div style="height:280px;"><canvas id="ovRev"></canvas></div></div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Financial risks</h6>
        <?php if (!$risks) { ?><div class="small text-success">No significant risk detected in the projections.</div><?php } ?>
        <ul class="small ps-3"><?php foreach ($risks as $r) { ?><li class="mb-1"><?= $r; ?></li><?php } ?></ul>
        <h6 class="mt-3">Recommendations</h6>
        <ul class="small ps-3 mb-0"><?php foreach ($advice ?: ['Keep monitoring: review this dashboard each week.'] as $a) { ?><li class="mb-1"><?= $e($a); ?></li><?php } ?></ul>
    </div></div></div>
</div>
<script>fcChart('ovRev', <?= json_encode(fc_chart_data($revM, $revMfc, 'month')); ?>, true);</script>

<?php } elseif ($tab === 'revenue' || $tab === 'expenses') {
    $hist = fc_history($conn, $tab, $g); $fc = fc_project($hist, $g); ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <div class="btn-group btn-group-sm"><?php foreach (FC_GRANULARITIES as $k => $l) { ?><a href="index.php?tab=<?= $tab; ?>&g=<?= $k; ?>" class="btn <?= $g === $k ? 'btn-primary' : 'btn-outline-primary'; ?>"><?= $l; ?></a><?php } ?></div>
    <?php if ($canExport) { ?><a href="index.php?tab=<?= $tab; ?>&g=<?= $g; ?>&export=csv" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i> CSV</a><?php } ?>
</div>
<?php if (empty($fc['ok'])) { ?><div class="alert alert-info"><?= $e($fc['reason']); ?></div><?php } ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body"><div style="height:320px;"><canvas id="mainChart"></canvas></div>
    <div class="small text-muted mt-2"><?= $tab === 'revenue' ? 'Recorded sales (cancelled sales excluded).' : 'Approved operating expenses (salaries, rent, utilities, transport, interest …); stock purchases, assets and loan principal are not operating costs and are left out.'; ?>
        This period so far (<?= fc_label($hist['current_start'], $g); ?>): <strong><?= $m($hist['current_so_far']); ?></strong> actual.</div></div></div>
<?php if (!empty($fc['ok'])) { ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Period</th><th class="text-end">Projected</th><th class="text-end">Likely range</th><th>Confidence</th></tr>
    <?php foreach ($fc['points'] as $p => [$v, $lo, $hi]) { ?><tr><td class="ps-3"><?= fc_label($p, $g); ?> <span class="badge bg-success">Projected</span></td><td class="text-end fw-semibold"><?= $m($v); ?></td><td class="text-end small"><?= $m($lo); ?> – <?= $m($hi); ?></td><td><?= $e($fc['confidence']); ?></td></tr><?php } ?>
    <tr class="table-light"><td class="ps-3" colspan="4">Trend: <?= $fc['slope_pct'] >= 0 ? '+' : ''; ?><?= $fc['slope_pct']; ?>% per period, from <?= (int) $fc['n']; ?> completed periods.</td></tr>
</table></div></div></div>
<?php } ?>
<?php if ($tab === 'expenses') {
    $cats = mysqli_fetch_all(mysqli_query($conn, "SELECT COALESCE(NULLIF(expense_category, ''), category) c,
        SUM(CASE WHEN transaction_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN amount ELSE 0 END) / 3 last3,
        SUM(CASE WHEN transaction_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN amount ELSE 0 END) / 3 prev3
        FROM transactions WHERE transaction_type = 'Expense' AND status = 'approved' AND transaction_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
        AND category NOT IN ('Purchase (Re-stock)', 'Asset Purchase', 'Asset Loss') AND NOT (category = 'Loan Repayment' AND description LIKE 'Loan principal%')
        GROUP BY c ORDER BY last3 DESC LIMIT 15"), MYSQLI_ASSOC); $pm = fc_payroll_month($conn); ?>
<div class="card border-0 shadow-sm mt-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Expense category</th><th class="text-end">Monthly average, last 3 months (actual)</th><th class="text-end">Change vs the 3 months before</th><th class="text-end">Next month (projected, same trend)</th></tr>
    <?php foreach ($cats as $c) { $chg = (float) $c['prev3'] > 0 ? ((float) $c['last3'] - (float) $c['prev3']) / (float) $c['prev3'] : null; ?>
    <tr><td class="ps-3"><?= $e($c['c']); ?></td><td class="text-end"><?= $m($c['last3']); ?></td>
        <td class="text-end <?= $chg !== null && $chg > 0.15 ? 'text-danger' : ''; ?>"><?= $chg === null ? 'new' : (($chg >= 0 ? '+' : '') . round(100 * $chg) . '%'); ?></td>
        <td class="text-end"><?= $m((float) $c['last3'] * (1 + ($chg !== null ? max(-0.5, min(0.5, $chg)) / 3 : 0))); ?></td></tr>
    <?php } ?>
</table></div></div></div>
<?php if ($pm !== null) { ?><div class="small text-muted mt-2">Payroll: about <?= $m($pm); ?> per month (last 3 months), included in the expenses above when salaries are paid through Transactions.</div><?php } ?>
<?php } ?>
<script>fcChart('mainChart', <?= json_encode(fc_chart_data($hist, $fc, $g)); ?>, true);</script>

<?php } elseif ($tab === 'pnl') { ?>
<?php if (empty($revMfc['ok']) || empty($expMfc['ok']) || $gm['margin'] === null) { ?>
<div class="alert alert-info">Not enough history for a profit forecast yet. <?= $e($revMfc['reason'] ?? ''); ?> <?= $e($expMfc['reason'] ?? ''); ?></div>
<?php } else { ?>
<div class="small text-muted mb-2">Gross margin from the recorded costs of the last 90 days: <strong><?= round(100 * $gm['margin'], 1); ?>%</strong>. Projected profit = projected revenue × margin − projected operating expenses. These are <strong>projections</strong>, not results: actual results are in Financial Reports.</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Month</th><th class="text-end">Revenue</th><th class="text-end">Gross profit</th><th class="text-end">Operating expenses</th><th class="text-end">Profit / loss</th></tr>
    <?php $ex = array_values($expMfc['points']); $i = 0; foreach ($revMfc['points'] as $p => [$v]) { $gp = $v * $gm['margin']; $x = $ex[$i++][0] ?? 0; ?>
    <tr><td class="ps-3"><?= fc_label($p, 'month'); ?> <span class="badge bg-success">Projected</span></td><td class="text-end"><?= $m($v); ?></td><td class="text-end"><?= $m($gp); ?></td><td class="text-end"><?= $m($x); ?></td>
        <td class="text-end fw-bold <?= $gp - $x < 0 ? 'text-danger' : 'text-success'; ?>"><?= $m($gp - $x); ?></td></tr>
    <?php } ?>
</table></div></div></div>
<?php } ?>

<?php } elseif ($tab === 'cash') { ?>
<?php if ($cash === null || !$cf['days']) { ?><div class="alert alert-info">A cash projection needs the cash balance and a revenue forecast. <?= $e($revMfc['reason'] ?? ''); ?></div><?php } else { ?>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Cash today <span class="badge bg-primary">Actual</span></div><div class="fs-5 fw-bold"><?= $m($cash); ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">In 90 days <span class="badge bg-success">Projected</span></div><div class="fs-5 fw-bold <?= end($cf['days']) < 0 ? 'text-danger' : ''; ?>"><?= $m(end($cf['days'])); ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Customers owe <span class="badge bg-primary">Actual</span></div><div class="fs-5 fw-bold"><?= $m($recv['open']); ?></div><div class="small text-muted">usually <?= round(100 * $recv['collection_rate']); ?>% collected</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Loan instalments due (90 days)</div><div class="fs-5 fw-bold"><?= $m(array_sum($loanDues)); ?></div></div></div></div>
</div>
<?php if ($cf['first_shortage']) { ?><div class="alert alert-danger"><strong>Cash shortage projected on <?= date('d M Y', strtotime($cf['first_shortage'])); ?>.</strong> Lowest point: <?= $m($cf['lowest'][1]); ?> on <?= date('d M Y', strtotime($cf['lowest'][0])); ?>.</div><?php } ?>
<div class="card border-0 shadow-sm"><div class="card-body"><div style="height:300px;"><canvas id="cashChart"></canvas></div>
    <div class="small text-muted mt-2">Assumes 85% of projected sales are paid in the month, receivables collected over 30 days, operating expenses and stock purchases (≈ <?= $m($restockMonth); ?> per month, last 3 months) spread evenly, and loan instalments on their due dates.</div></div></div>
<script>
(function () { const d = <?= json_encode(['labels' => array_map(function ($x) { return date('d M', strtotime($x)); }, array_keys($cf['days'])), 'v' => array_values($cf['days'])]); ?>;
  if (!window.Chart) { return; }
  new Chart(document.getElementById('cashChart'), { type: 'line', data: { labels: d.labels, datasets: [{ label: 'Projected cash', data: d.v, borderColor: '#0FA968', borderDash: [6, 5], pointRadius: 0,
    segment: { borderColor: c => c.p1.parsed.y < 0 ? '#E24B4A' : '#0FA968' } }] }, options: { responsive: true, maintainAspectRatio: false,
    scales: { y: { ticks: { callback: v => 'RWF ' + Number(v).toLocaleString() } } } } }); })();
</script>
<?php } ?>

<?php } elseif ($tab === 'inventory') { $inv = fc_inventory($conn); ?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-2"><div class="small text-muted">Demand = average daily sales (last 30 days weighted with the last 90). Recommended purchase covers <?= FC_LEAD_DAYS; ?> days of supplier lead time + 30 days of sales.</div>
<?php if ($canExport) { ?><a href="index.php?tab=inventory&export=csv" class="btn btn-sm btn-outline-secondary"><i class="bi bi-download"></i> CSV</a><?php } ?></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Product</th><th class="text-end">Stock</th><th class="text-end">Sold per day</th><th class="text-end">Days left</th><th>Runs out (projected)</th><th>Status</th><th class="text-end">Recommended purchase</th><th class="text-end">Est. cost</th></tr>
    <?php if (!$inv) { ?><tr><td colspan="8" class="text-center text-muted py-3">No active products.</td></tr><?php } ?>
    <?php foreach ($inv as $r) { $cls = ['Out of stock' => 'bg-danger', 'Will run out soon' => 'bg-warning', 'Slow / not moving' => 'bg-secondary', 'Overstocked' => 'bg-secondary', 'OK' => 'bg-success'][$r['status']]; ?>
    <tr><td class="ps-3"><?= $e($r['product_name']); ?></td><td class="text-end"><?= number_format((float) $r['quantity']); ?> <?= $e($r['unit']); ?></td><td class="text-end"><?= $r['daily']; ?></td>
        <td class="text-end"><?= $r['days_left'] === null ? '—' : number_format($r['days_left']); ?></td><td><?= $e($r['runs_out'] ?? '—'); ?></td><td><span class="badge <?= $cls; ?>"><?= $e($r['status']); ?></span></td>
        <td class="text-end fw-semibold"><?= $r['recommend'] ? number_format($r['recommend']) . ' ' . $e($r['unit']) : '—'; ?></td><td class="text-end"><?= $r['recommend'] ? $m($r['recommend_cost']) : '—'; ?></td></tr>
    <?php } ?>
</table></div></div></div>

<?php } elseif ($tab === 'growth') {
    $cust = fc_history($conn, 'customers', 'month'); $custFc = fc_project($cust, 'month'); ?>
<div class="row g-3">
    <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><h6>Revenue growth</h6>
        <?php if (!empty($revMfc['ok'])) { ?><div class="small mb-2">Trend: <strong><?= $revMfc['slope_pct'] >= 0 ? '+' : ''; ?><?= $revMfc['slope_pct']; ?>%</strong> per month · next 12 months projected <strong><?= $m($sumNext(fc_project($revM, 'month', 12), 12)); ?></strong></div><?php } else { ?><div class="alert alert-info small"><?= $e($revMfc['reason']); ?></div><?php } ?>
        <div style="height:260px;"><canvas id="gRev"></canvas></div></div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm"><div class="card-body"><h6>New customers per month</h6>
        <?php if (!empty($custFc['ok'])) { ?><div class="small mb-2">Trend: <strong><?= $custFc['slope_pct'] >= 0 ? '+' : ''; ?><?= $custFc['slope_pct']; ?>%</strong> per month · about <strong><?= round($next($custFc)); ?></strong> new customers expected next month</div><?php } else { ?><div class="alert alert-info small"><?= $e($custFc['reason']); ?></div><?php } ?>
        <div style="height:260px;"><canvas id="gCust"></canvas></div></div></div></div>
</div>
<script>fcChart('gRev', <?= json_encode(fc_chart_data($revM, fc_project($revM, 'month', 12), 'month')); ?>, true); fcChart('gCust', <?= json_encode(fc_chart_data($cust, $custFc, 'month')); ?>, false);</script>

<?php } elseif ($tab === 'scenarios') {
    $sc = fc_scenarios($conn);
    $custom = ['label' => 'Your what-if', 'sales' => (float) ($_GET['w_sales'] ?? 0), 'expenses' => (float) ($_GET['w_expenses'] ?? 0), 'cost' => (float) ($_GET['w_cost'] ?? 0), 'delay' => (float) ($_GET['w_delay'] ?? 0)];
    $all = $sc + ['custom' => $custom]; ?>
<?php if (empty($revMfc['ok'])) { ?><div class="alert alert-info"><?= $e($revMfc['reason']); ?></div><?php } else { ?>
<form class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end"><input type="hidden" name="tab" value="scenarios">
    <div class="col-12 small fw-semibold">What-if: change the assumptions and see the next 3 months</div>
    <?php foreach (['w_sales' => 'Sales change %', 'w_expenses' => 'Operating expenses change %', 'w_cost' => 'Stock purchasing cost change %', 'w_delay' => 'Customer payments delayed (days)'] as $k => $l) { ?>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1"><?= $l; ?></label><input type="number" step="1" name="<?= $k; ?>" class="form-control form-control-sm" value="<?= $e($_GET[$k] ?? 0); ?>"></div>
    <?php } ?><div class="col-12"><button class="btn btn-primary btn-sm">Calculate</button></div></div></form>
<div class="row g-3">
<?php foreach ($all as $k => $a) { $rows = fc_scenario_result($revMfc, $expMfc, $gm['margin'], $recv, $cash, array_sum($loanDues), $restockMonth, $a);
    $tot = ['revenue' => 0, 'net' => 0]; foreach ($rows as $r) { $tot['revenue'] += $r['revenue']; $tot['net'] += $r['net']; } $endCash = end($rows)['cash_end'] ?? null; ?>
    <div class="col-md-6 col-xl-3"><div class="card border-0 shadow-sm h-100" style="border-top:3px solid <?= ['optimistic' => '#0FA968', 'base' => '#1E2FE0', 'pessimistic' => '#E24B4A', 'custom' => '#1E2333'][$k]; ?> !important;"><div class="card-body small">
        <div class="fw-bold mb-1"><?= $e($a['label']); ?> <span class="badge bg-success">Projected</span></div>
        <div class="text-muted mb-2">Sales <?= $a['sales'] >= 0 ? '+' : ''; ?><?= $a['sales']; ?>% · expenses <?= $a['expenses'] >= 0 ? '+' : ''; ?><?= $a['expenses']; ?>% · costs <?= $a['cost'] >= 0 ? '+' : ''; ?><?= $a['cost']; ?>% · payments <?= (int) $a['delay']; ?> days late</div>
        <div>3-month revenue: <strong><?= $m($tot['revenue']); ?></strong></div>
        <div>3-month profit / loss: <strong class="<?= $tot['net'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= $m($tot['net']); ?></strong></div>
        <div>Cash after 3 months: <strong class="<?= $endCash !== null && $endCash < 0 ? 'text-danger' : ''; ?>"><?= $m($endCash); ?></strong></div>
    </div></div></div>
<?php } ?>
</div>
<?php if ($canManage) { ?>
<form method="POST" class="card border-0 shadow-sm mt-3"><div class="card-body"><input type="hidden" name="action" value="save_scenarios">
    <div class="small fw-semibold mb-2">Saved scenario assumptions (used by everyone)</div>
    <div class="table-responsive"><table class="table table-sm mb-2 align-middle" style="font-size:13px;"><tr><th>Scenario</th><th>Sales %</th><th>Expenses %</th><th>Purchase costs %</th><th>Payment delay (days)</th></tr>
    <?php foreach ($sc as $k => $a) { ?><tr><td><?= $e($a['label']); ?></td><?php foreach (['sales', 'expenses', 'cost', 'delay'] as $f) { ?><td><input type="number" step="1" name="<?= $k; ?>[<?= $f; ?>]" value="<?= $e($a[$f]); ?>" class="form-control form-control-sm" style="max-width:110px;"></td><?php } ?></tr><?php } ?>
    </table></div><button class="btn btn-primary btn-sm">Save assumptions</button></div></form>
<?php } ?>
<?php } ?>

<?php } elseif ($tab === 'accuracy') { $acc = fc_accuracy($conn);
    $errs = array_filter(array_map(function ($r) { return $r['error_pct'] !== null ? abs($r['error_pct']) : null; }, $acc), function ($x) { return $x !== null; }); ?>
<div class="small text-muted mb-2">Each month the forecast for the next 3 months is saved. Once a month is over, it is compared with what actually happened.
    <?php if ($errs) { ?>Average error so far: <strong><?= round(array_sum($errs) / count($errs), 1); ?>%</strong>.<?php } ?></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Month</th><th>What</th><th>Forecast made</th><th class="text-end">Projected</th><th class="text-end">Actual</th><th class="text-end">Error</th><th>Within range?</th></tr>
    <?php if (!$acc) { ?><tr><td colspan="7" class="text-center text-muted py-3">No completed forecast months yet. Come back after the first month has passed.</td></tr><?php } ?>
    <?php foreach ($acc as $r) { ?><tr><td class="ps-3"><?= date('M Y', strtotime($r['period_start'])); ?></td><td><?= $e(ucfirst($r['metric'])); ?></td><td class="small"><?= date('d M Y', strtotime($r['created_at'])); ?></td>
        <td class="text-end"><?= $m($r['projected']); ?></td><td class="text-end"><?= $m($r['actual']); ?></td>
        <td class="text-end <?= $r['error_pct'] !== null && abs($r['error_pct']) > 20 ? 'text-danger' : ''; ?>"><?= $r['error_pct'] === null ? '—' : (($r['error_pct'] >= 0 ? '+' : '') . $r['error_pct'] . '%'); ?></td>
        <td><?= $r['inside'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>'; ?></td></tr><?php } ?>
</table></div></div></div>
<?php } ?>
<?php include '../../includes/footer.php'; ?>