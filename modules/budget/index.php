<?php
/**
 * NEW FILE: modules/budget/index.php — Budget & Fund Control dashboard: 8 cards, 7 charts, filters.
 * Totals are for ONE budget level at a time (company-wide by default), so overlapping budgets are never added twice.
 */
require __DIR__ . '/budget_common.php';
try { bdg_check_alerts($conn); } catch (Throwable $x) { error_log('budget alerts: ' . $x->getMessage()); }
$L = bdg_lists($conn);
$f = ['year' => (int) ($_GET['year'] ?? date('Y')), 'month' => preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : '',
      'quarter' => (int) ($_GET['quarter'] ?? 0) ?: '', 'department_id' => (int) ($_GET['department_id'] ?? 0) ?: '', 'project_id' => (int) ($_GET['project_id'] ?? 0) ?: '',
      'category' => (string) ($_GET['category'] ?? ''), 'fund_id' => (int) ($_GET['fund_id'] ?? 0) ?: ''];
$txStatus = in_array($_GET['tx_status'] ?? '', ['approved', 'pending', 'all'], true) ? $_GET['tx_status'] : 'approved';
$all = bdg_budgets($conn, $f);
$levels = array_values(array_unique(array_column(array_filter($all, function ($b) { return $b['kind'] === 'expense'; }), 'scope')));
$level = in_array($_GET['level'] ?? '', array_keys(BDG_SCOPES), true) ? $_GET['level'] : (in_array('company', $levels, true) ? 'company' : ($levels[0] ?? 'company'));
$exp = array_values(array_filter($all, function ($b) use ($level) { return $b['kind'] === 'expense' && $b['scope'] === $level; }));
$tot = ['budget' => 0, 'actual' => 0, 'pending' => 0, 'available' => 0, 'over' => 0];
foreach ($exp as &$b) { $b['fig'] = bdg_figures($conn, $b); foreach ($tot as $k => $v) { $tot[$k] += $b['fig'][$k === 'budget' ? 'budget' : $k]; } } unset($b);
$fundsBal = 0; $fundsRes = 0; foreach ($L['funds'] as $fd) { $fundsBal += fund_cash_balance($conn, (int) $fd['id']); $fundsRes += fund_pending_total($conn, (int) $fd['id']); }
$util = $tot['budget'] > 0 ? round(100 * $tot['actual'] / $tot['budget'], 1) : null;

// Monthly series for the year (budget spread over its period by days; actual = transactions by month).
$y = $f['year']; $months = []; for ($m = 1; $m <= 12; $m++) { $months[sprintf('%d-%02d', $y, $m)] = ['budget' => 0, 'actual' => 0, 'rev_budget' => 0, 'rev_actual' => 0]; }
foreach ($all as $b) {
    if ($b['kind'] === 'expense' && $b['scope'] !== $level) { continue; }
    $s = strtotime($b['period_start']); $e2 = strtotime($b['period_end']); $days = max(1, (int) round(($e2 - $s) / 86400) + 1);
    foreach ($months as $mk => &$mv) { $ms = strtotime($mk . '-01'); $me2 = strtotime(date('Y-m-t', $ms)); $ov = min($e2, $me2) - max($s, $ms);
        if ($ov >= 0) { $mv[$b['kind'] === 'revenue' ? 'rev_budget' : 'budget'] += (float) $b['amount'] * (((int) round($ov / 86400) + 1) / $days); } } unset($mv);
}
$tw = ["YEAR(t.transaction_date) = $y"];
if ($f['department_id']) { $d = (int) $f['department_id']; $tw[] = "(t.department_id = $d OR (t.department_id IS NULL AND t.recorded_by IN (SELECT id FROM users WHERE department_id = $d)))"; }
if ($f['project_id']) { $tw[] = 't.project_id = ' . (int) $f['project_id']; }
if ($f['category'] !== '') { $tw[] = "COALESCE(NULLIF(t.expense_category, ''), t.category) = '" . mysqli_real_escape_string($conn, $f['category']) . "'"; }
if ($f['fund_id']) { $tw[] = 't.fund_id = ' . (int) $f['fund_id']; }
$stSql = $txStatus === 'all' ? "t.status IN ('approved', 'pending')" : "t.status = '$txStatus'";
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT DATE_FORMAT(t.transaction_date, '%Y-%m') m, t.transaction_type ty, SUM(t.amount) v FROM transactions t WHERE $stSql AND " . implode(' AND ', $tw) . ' GROUP BY m, ty'), MYSQLI_ASSOC) as $r) {
    if (isset($months[$r['m']])) { $months[$r['m']][$r['ty'] === 'Income' ? 'rev_actual' : 'actual'] += (float) $r['v']; }
}
$byDept = []; $byCat = [];
foreach ($all as $b) { if ($b['kind'] !== 'expense') { continue; } $fig = bdg_figures($conn, $b);
    if ($b['scope'] === 'department') { $k = $b['department'] ?? '—'; $byDept[$k] = ['budget' => ($byDept[$k]['budget'] ?? 0) + $fig['budget'], 'actual' => ($byDept[$k]['actual'] ?? 0) + $fig['actual']]; }
    if ($b['scope'] === 'category') { $k = $b['category'] ?? '—'; $byCat[$k] = ['budget' => ($byCat[$k]['budget'] ?? 0) + $fig['budget'], 'actual' => ($byCat[$k]['actual'] ?? 0) + $fig['actual']]; } }
$fundChart = array_map(function ($fd) use ($conn) { $id = (int) $fd['id']; return ['name' => preg_replace('/^RM | Fund$/', '', $fd['name']), 'pct' => (float) $fd['allocation_percent'], 'balance' => round(fund_cash_balance($conn, $id)), 'available' => round(fund_available($conn, $id))]; }, $L['funds']);
$over = array_values(array_filter(array_map(function ($b) use ($conn) { return $b + ['f' => bdg_figures($conn, $b)]; }, array_filter($all, function ($b) { return $b['kind'] === 'expense'; })), function ($b) { return in_array($b['f']['level'], ['Critical', 'Exhausted', 'Overspent'], true); }));
include '../../includes/header.php'; include '../../includes/sidebar.php';
bdg_tabs('index.php');
?>
<form class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end small">
    <div class="col-6 col-md-1"><label class="text-muted">Year</label><input type="number" name="year" value="<?= $y; ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="text-muted">Month</label><input type="month" name="month" value="<?= $e($f['month']); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-1"><label class="text-muted">Quarter</label><select name="quarter" class="form-select form-select-sm"><option value="">All</option><?php for ($q = 1; $q <= 4; $q++) { ?><option value="<?= $q; ?>" <?= (int) $f['quarter'] === $q ? 'selected' : ''; ?>>Q<?= $q; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="text-muted">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($L['departments'] as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= (int) $f['department_id'] === (int) $d['id'] ? 'selected' : ''; ?>><?= $e($d['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="text-muted">Project</label><select name="project_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($L['projects'] as $p) { ?><option value="<?= (int) $p['id']; ?>" <?= (int) $f['project_id'] === (int) $p['id'] ? 'selected' : ''; ?>><?= $e($p['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="text-muted">Category</label><select name="category" class="form-select form-select-sm"><option value="">All</option><?php foreach ($L['categories'] as $c) { ?><option <?= $f['category'] === $c ? 'selected' : ''; ?>><?= $e($c); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="text-muted">Fund</label><select name="fund_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($L['funds'] as $fd) { ?><option value="<?= (int) $fd['id']; ?>" <?= (int) $f['fund_id'] === (int) $fd['id'] ? 'selected' : ''; ?>><?= $e($fd['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="text-muted">Transaction status</label><select name="tx_status" class="form-select form-select-sm"><?php foreach (['approved' => 'Approved (actual)', 'pending' => 'Pending', 'all' => 'Approved + pending'] as $k => $l) { ?><option value="<?= $k; ?>" <?= $txStatus === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="text-muted">Budget level for totals</label><select name="level" class="form-select form-select-sm"><?php foreach (BDG_SCOPES as $k => $l) { if ($k === 'revenue') { continue; } ?><option value="<?= $k; ?>" <?= $level === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-1"><button class="btn btn-primary btn-sm w-100">Show</button></div>
</div></form>
<div class="small text-muted mb-2">Totals: <strong><?= $e(strtolower(BDG_SCOPES[$level])); ?></strong> budgets (<?= count($exp); ?>), so overlapping budgets are not added twice. Actual = approved spending only; reserved amounts are shown apart.</div>
<div class="row g-3 mb-3">
    <?php foreach ([['Total Approved Budget', $rwf($tot['budget']), 'bi-clipboard-check'], ['Total Actual Spending', $rwf($tot['actual']), 'bi-cash-stack'], ['Total Reserved Funds', $rwf($fundsRes), 'bi-lock'], ['Total Available Budget', $rwf($tot['available']), 'bi-wallet2'],
                    ['Total Fund Balance', $rwf($fundsBal), 'bi-bank'], ['Budget Utilization', $util !== null ? $util . '%' : '—', 'bi-speedometer2'], ['Total Overspending', $rwf($tot['over']), 'bi-exclamation-triangle'], ['Remaining Company Budget', $rwf($tot['budget'] - $tot['actual']), 'bi-piggy-bank']] as [$l, $v, $ic]) { ?>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex gap-3 align-items-center">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:46px; height:46px; background:<?= $l === 'Total Overspending' && $tot['over'] > 0 ? '#FDECEC' : '#EEF3FF'; ?>;"><i class="bi <?= $ic; ?>" style="font-size:1.35rem; color:<?= $l === 'Total Overspending' && $tot['over'] > 0 ? '#E24B4A' : '#1E2FE0'; ?>;"></i></div>
        <div><div class="small text-muted"><?= $l; ?></div><div class="fw-bold <?= $l === 'Total Overspending' && $tot['over'] > 0 ? 'text-danger' : ''; ?>" style="font-size:1.15rem;"><?= $e($v); ?></div></div></div></div></div><?php } ?>
</div>
<?php if ($over) { ?><div class="alert alert-danger small"><strong>Needs attention:</strong> <?php foreach ($over as $b) { ?><span class="me-3"><?= $e($b['name']); ?> <?= bdg_badge($b['f']['level']); ?> <?= $b['f']['utilization']; ?>%</span><?php } ?></div><?php } ?>
<div class="row g-3">
    <?php foreach ([['cMonth', 'Budget vs Actual by Month (' . $y . ')', 8], ['cAlloc', 'Budget Allocation by Fund (%)', 4], ['cDept', 'Budget vs Actual by Department', 6], ['cCat', 'Budget vs Actual by Expense Category', 6],
                    ['cFund', 'Fund Balance Comparison', 6], ['cTrend', 'Monthly Spending Trends (' . $y . ')', 6], ['cRev', 'Revenue Budget vs Actual (' . $y . ')', 12]] as [$id, $t, $w]) { ?>
    <div class="col-lg-<?= $w; ?>"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6 class="small fw-bold"><?= $e($t); ?></h6><div style="height:250px;"><canvas id="<?= $id; ?>"></canvas></div></div></div></div><?php } ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (!window.Chart) { return; }
    var B = '#1E2FE0', G = '#0FA968', R = '#E24B4A', K = '#1E2333', money = { ticks: { callback: function (v) { return Number(v).toLocaleString(); } } };
    function bars(id, labels, budget, actual, l1, l2) { new Chart(document.getElementById(id), { type: 'bar', data: { labels: labels, datasets: [{ label: l1 || 'Budget', data: budget, backgroundColor: B }, { label: l2 || 'Actual', data: actual, backgroundColor: G }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: money } } }); }
    var M = <?= json_encode(array_map(function ($k) { return date('M', strtotime($k . '-01')); }, array_keys($months))); ?>, mv = <?= json_encode(array_values($months)); ?>;
    bars('cMonth', M, mv.map(function (x) { return Math.round(x.budget); }), mv.map(function (x) { return Math.round(x.actual); }));
    var d = <?= json_encode($byDept); ?>; bars('cDept', Object.keys(d), Object.values(d).map(function (x) { return x.budget; }), Object.values(d).map(function (x) { return x.actual; }));
    var c = <?= json_encode($byCat); ?>; bars('cCat', Object.keys(c), Object.values(c).map(function (x) { return x.budget; }), Object.values(c).map(function (x) { return x.actual; }));
    var f = <?= json_encode($fundChart); ?>;
    new Chart(document.getElementById('cAlloc'), { type: 'doughnut', data: { labels: f.filter(function (x) { return x.pct > 0; }).map(function (x) { return x.name; }), datasets: [{ data: f.filter(function (x) { return x.pct > 0; }).map(function (x) { return x.pct; }), backgroundColor: [B, G, K, R, '#3D5BFF', '#087A4B'] }] }, options: { responsive: true, maintainAspectRatio: false } });
    bars('cFund', f.map(function (x) { return x.name; }), f.map(function (x) { return x.balance; }), f.map(function (x) { return x.available; }), 'Balance', 'Available');
    new Chart(document.getElementById('cTrend'), { type: 'line', data: { labels: M, datasets: [{ label: 'Spending', data: mv.map(function (x) { return Math.round(x.actual); }), borderColor: B, tension: .25 }] }, options: { responsive: true, maintainAspectRatio: false, scales: { y: money } } });
    bars('cRev', M, mv.map(function (x) { return Math.round(x.rev_budget); }), mv.map(function (x) { return Math.round(x.rev_actual); }), 'Revenue budget / target', 'Actual revenue');
})();
</script>
<?php include '../../includes/footer.php'; ?>