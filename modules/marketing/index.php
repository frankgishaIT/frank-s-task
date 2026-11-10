<?php
/**
 * modules/marketing/index.php — MARKETING DASHBOARD (stage 4): filters, key figures and charts, from verified records:
 * registered leads, converted customers, fully paid sales approved in Transactions, approved expenses linked to campaigns.
 */
require __DIR__ . '/mkt_common.php';
mkt_ensure_studio($conn);
try { mkt_check_behind($conn); mkt_refresh_acquisitions($conn); mkt_followup_reminders($conn); } catch (Throwable $x) { error_log('marketing alerts: ' . $x->getMessage()); }
$from = DateTime::createFromFormat('Y-m-d', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01', strtotime('-5 months'));
$to = DateTime::createFromFormat('Y-m-d', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$cf = (int) ($_GET['campaign'] ?? 0);
$camps = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM mkt_campaigns c WHERE ' . mkt_campaign_scope_sql($conn, $me) . ($cf ? " AND c.id = $cf" : '') . ' ORDER BY c.start_date DESC'), MYSQLI_ASSOC);
$allCamps = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM mkt_campaigns ORDER BY name'), MYSQLI_ASSOC);
$ids = array_map('intval', array_column($camps, 'id'));
$tot = mkt_actuals($conn, $ids);
$active = array_values(array_filter($camps, function ($c) { return $c['status'] === 'Active'; }));
$lw = $cf ? " AND campaign_id = $cf" : '';
$funnel = []; foreach (MKT_LEAD_FLOW as $s) { $funnel[$s] = 0; }
$s = mysqli_prepare($conn, "SELECT status, COUNT(*) n FROM mkt_leads WHERE DATE(created_at) BETWEEN ? AND ?$lw GROUP BY status"); mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) { $funnel[$r['status']] = (int) $r['n']; }
$s = mysqli_prepare($conn, "SELECT COALESCE(source, 'Unknown') src, COUNT(*) n FROM mkt_leads WHERE DATE(created_at) BETWEEN ? AND ?$lw GROUP BY src ORDER BY n DESC"); mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
$sources = array_column(mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC), 'n', 'src');
$monthly = mkt_monthly($conn, $from, $to, $cf ?: null);
$byCamp = []; foreach (array_slice($camps, 0, 8) as $c) { $a = mkt_actuals($conn, [(int) $c['id']]); $byCamp[] = ['name' => mb_strimwidth($c['name'], 0, 22, '…'), 'revenue' => $a['revenue'], 'profit' => $a['profit'], 'spent' => $a['expenses']]; }
$top = [];
if (mkt_is_manager()) { $perf = mkt_team_performance($conn, $from, $to); uasort($perf, function ($a, $b) { return $b['points'] <=> $a['points']; });
    $names = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users'), MYSQLI_ASSOC), 'names', 'id');
    foreach (array_slice($perf, 0, 5, true) as $u => $r) { $top[] = ['name' => $names[$u] ?? '#' . $u, 'points' => $r['points']]; } }
$overdue = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_leads WHERE next_followup < CURDATE() AND status NOT IN ('Won', 'Lost')" . ($cf ? " AND campaign_id = $cf" : '') . (mkt_is_manager() ? '' : " AND owner_id = $me")))[0];
$pending = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT (SELECT COUNT(*) FROM mkt_campaigns WHERE status = 'Pending Approval') + (SELECT COUNT(*) FROM mkt_plans WHERE status = 'Submitted for Approval') + (SELECT COUNT(*) FROM mkt_materials WHERE status = 'Submitted') + (SELECT COUNT(*) FROM mkt_pools WHERE status = 'Proposed')"))[0];
$roi = mkt_pct($tot['profit'] - $tot['expenses'], $tot['expenses']);
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('index.php');
?>
<form class="d-flex flex-wrap gap-2 mb-3 align-items-end small"><div><label class="text-muted">From</label><input type="date" name="from" value="<?= $e($from); ?>" class="form-control form-control-sm"></div>
    <div><label class="text-muted">To</label><input type="date" name="to" value="<?= $e($to); ?>" class="form-control form-control-sm"></div>
    <div><label class="text-muted">Campaign</label><select name="campaign" class="form-select form-select-sm"><option value="">All campaigns</option><?php foreach ($allCamps as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $cf === (int) $c['id'] ? 'selected' : ''; ?>><?= $e($c['name']); ?></option><?php } ?></select></div>
    <button class="btn btn-sm btn-primary">Show</button></form>
<?php if ($overdue || ($pending && access_can('marketing.approve', $conn))) { ?><div class="alert alert-primary small d-flex flex-wrap gap-3">
    <?php if ($overdue) { ?><a href="leads.php?overdue=1" class="text-danger fw-semibold"><i class="bi bi-alarm"></i> <?= $overdue; ?> overdue follow-up(s)</a><?php } ?>
    <?php if ($pending && access_can('marketing.approve', $conn)) { ?><span><i class="bi bi-hourglass-split"></i> <?= $pending; ?> item(s) waiting for your approval (plans, campaigns, materials, reward pools)</span><?php } ?></div><?php } ?>
<div class="row g-3 mb-3">
    <?php foreach ([['Active campaigns', count($active), 'bi-play-circle'], ['Leads', $tot['leads'], 'bi-person-plus'], ['Qualified leads', $tot['qualified'], 'bi-funnel'], ['Verified paying customers', $tot['paying'], 'bi-patch-check'],
                    ['Marketing revenue', $rwf($tot['revenue']), 'bi-cash-stack'], ['Eligible profit', $rwf($tot['profit']), 'bi-graph-up-arrow'], ['Marketing spending', $rwf($tot['expenses']), 'bi-wallet2'], ['Marketing ROI', mkt_pct_text($roi), 'bi-percent']] as [$l, $v, $ic]) { ?>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex gap-3 align-items-center">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:46px; height:46px; background:#EEF3FF;"><i class="bi <?= $ic; ?>" style="font-size:1.35rem; color:#1E2FE0;"></i></div>
        <div><div class="small text-muted"><?= $l; ?></div><div class="fw-bold" style="font-size:1.15rem;"><?= $e($v); ?></div></div></div></div></div><?php } ?>
</div>
<div class="row g-3 mb-3">
    <?php foreach ([['cFunnel', 'Lead pipeline (leads registered in the period)', 7], ['cSources', 'Lead sources', 5], ['cMonthly', 'Leads, new customers and paying customers by month', 7], ['cCamp', 'Revenue, eligible profit and spending by campaign', 5]] as [$id, $t, $w]) { ?>
    <div class="col-lg-<?= $w; ?>"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6 class="small fw-bold"><?= $e($t); ?></h6><div style="height:250px;"><canvas id="<?= $id; ?>"></canvas></div></div></div></div><?php } ?>
    <?php if ($top) { ?><div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6 class="small fw-bold">Top performers (points)</h6><div style="height:220px;"><canvas id="cTop"></canvas></div></div></div></div><?php } ?>
    <div class="col-lg-<?= $top ? 7 : 12; ?>"><div class="card border-0 shadow-sm h-100"><div class="card-body p-0"><div class="p-3 pb-1 fw-semibold small">Active campaigns</div><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle" style="font-size:12.5px;">
        <tr><th class="ps-3">Campaign</th><th class="text-end">Leads</th><th class="text-end">Paying</th><th class="text-end">Revenue</th><th class="text-end">Achievement</th><th class="text-end">Days left</th></tr>
        <?php if (!$active) { ?><tr><td colspan="6" class="text-center text-muted py-3">No active campaign.</td></tr><?php } ?>
        <?php foreach ($active as $c) { $a = mkt_actuals($conn, [(int) $c['id']]); $pf = mkt_performance($c, $a); $bh = mkt_is_behind($conn, $pf); ?>
        <tr><td class="ps-3"><a href="campaign.php?id=<?= (int) $c['id']; ?>"><?= $e($c['name']); ?></a></td><td class="text-end"><?= $a['leads']; ?>/<?= (int) $c['expected_leads']; ?></td><td class="text-end"><?= $a['paying']; ?>/<?= (int) $c['expected_customers']; ?></td>
            <td class="text-end"><?= $rwf($a['revenue']); ?></td><td class="text-end <?= $bh ? 'text-danger fw-bold' : ''; ?>"><?= mkt_pct_text($pf['overall']); ?><?= $bh ? ' · behind' : ''; ?></td><td class="text-end"><?= $pf['days_left']; ?></td></tr><?php } ?>
    </table></div></div></div></div>
</div>
<p class="small text-muted">Only verified records count: registered leads, converted customers, fully paid sales approved in Transactions, and approved expenses linked to campaigns. ROI = (eligible profit − spending) ÷ spending.</p>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function () {
    if (!window.Chart) { return; }
    var B = '#1E2FE0', G = '#0FA968', K = '#1E2333', R = '#E24B4A', money = { ticks: { callback: function (v) { return Number(v).toLocaleString(); } } }, opt = { responsive: true, maintainAspectRatio: false };
    var f = <?= json_encode($funnel); ?>;
    new Chart(document.getElementById('cFunnel'), { type: 'bar', data: { labels: Object.keys(f), datasets: [{ label: 'Leads', data: Object.values(f), backgroundColor: Object.keys(f).map(function (k) { return k === 'Won' ? G : (k === 'Lost' ? R : B); }) }] }, options: Object.assign({ indexAxis: 'y', plugins: { legend: { display: false } } }, opt) });
    var s = <?= json_encode($sources); ?>;
    new Chart(document.getElementById('cSources'), { type: 'doughnut', data: { labels: Object.keys(s), datasets: [{ data: Object.values(s), backgroundColor: [B, G, K, R, '#3D5BFF', '#087A4B', '#6B7280', '#0B1A8C', '#9AA8F5', '#B9E5CF', '#2A3352'] }] }, options: opt });
    var m = <?= json_encode($monthly); ?>, ml = Object.keys(m);
    new Chart(document.getElementById('cMonthly'), { type: 'line', data: { labels: ml, datasets: [{ label: 'Leads', data: ml.map(function (k) { return m[k].leads; }), borderColor: B, tension: .25 }, { label: 'New customers', data: ml.map(function (k) { return m[k].converted; }), borderColor: K, tension: .25 }, { label: 'Paying customers', data: ml.map(function (k) { return m[k].paying; }), borderColor: G, tension: .25 }] }, options: opt });
    var c = <?= json_encode($byCamp); ?>;
    new Chart(document.getElementById('cCamp'), { type: 'bar', data: { labels: c.map(function (x) { return x.name; }), datasets: [{ label: 'Revenue', data: c.map(function (x) { return x.revenue; }), backgroundColor: B }, { label: 'Eligible profit', data: c.map(function (x) { return x.profit; }), backgroundColor: G }, { label: 'Spending', data: c.map(function (x) { return x.spent; }), backgroundColor: R }] }, options: Object.assign({ scales: { y: money } }, opt) });
    var t = <?= json_encode($top); ?>;
    if (t.length) { new Chart(document.getElementById('cTop'), { type: 'bar', data: { labels: t.map(function (x) { return x.name; }), datasets: [{ label: 'Points', data: t.map(function (x) { return x.points; }), backgroundColor: G }] }, options: Object.assign({ indexAxis: 'y', plugins: { legend: { display: false } } }, opt) }); }
})();
</script>
<?php include '../../includes/footer.php'; ?>