<?php
// NEW FILE: modules/kpi/index.php — the performance dashboard (business, departments, teams, employees).
require __DIR__ . '/kpi_common.php';
kpi_guard($conn, 'kpi.view');
$on = DateTime::createFromFormat('Y-m-d', (string) ($_GET['on'] ?? '')) ? $_GET['on'] : date('Y-m-d');
$f = ['level' => $_GET['level'] ?? '', 'department_id' => $_GET['department_id'] ?? '', 'team_id' => $_GET['team_id'] ?? '', 'user_id' => $_GET['user_id'] ?? ''];
$results = kpi_results($conn, kpi_list($conn, $f), $on);
$by = function ($level, $key, $label) use ($results) {
    $g = [];
    foreach ($results as $r) { if ($r['level'] !== $level) { continue; } $g[$r[$key]]['label'] = $r[$label] ?? '—'; $g[$r[$key]]['items'][] = $r; }
    foreach ($g as &$x) { $x['score'] = kpi_weighted($x['items']); } unset($x);
    uasort($g, function ($a, $b) { return ($b['score'] ?? -1) <=> ($a['score'] ?? -1); });
    return $g;
};
$business = array_values(array_filter($results, function ($r) { return $r['level'] === 'business'; }));
$overall = kpi_weighted($results);
$under = array_values(array_filter($results, function ($r) { return $r['achievement'] !== null && $r['achievement'] < 70; }));
$top = array_values(array_filter($results, function ($r) { return $r['achievement'] !== null && $r['achievement'] >= 100; }));
// Trend: overall weighted score at the end of each of the last 6 months.
$trend = [];
for ($i = 5; $i >= 0; $i--) {
    $d = date('Y-m-t', strtotime(date('Y-m-01', strtotime($on)) . " -$i month"));
    $d = min($d, date('Y-m-d'));
    $trend[] = ['label' => date('M Y', strtotime($d)), 'score' => kpi_weighted(kpi_results($conn, kpi_list($conn, $f), $d))];
}
$deps = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC);
$teams = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM kpi_teams ORDER BY name'), MYSQLI_ASSOC);
$people = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
kpi_tabs('index.php');
?>
<form class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Level</label><select name="level" class="form-select form-select-sm"><option value="">All</option><?php foreach (KPI_LEVELS as $k => $l) { ?><option value="<?= $k; ?>" <?= $f['level'] === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($deps as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= (string) $f['department_id'] === (string) $d['id'] ? 'selected' : ''; ?>><?= $e($d['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Team</label><select name="team_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($teams as $t) { ?><option value="<?= (int) $t['id']; ?>" <?= (string) $f['team_id'] === (string) $t['id'] ? 'selected' : ''; ?>><?= $e($t['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">Employee</label><select name="user_id" class="form-select form-select-sm"><option value="">All</option><?php foreach ($people as $p) { ?><option value="<?= (int) $p['id']; ?>" <?= (string) $f['user_id'] === (string) $p['id'] ? 'selected' : ''; ?>><?= $e($p['names']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Performance on</label><input type="date" name="on" class="form-control form-control-sm" value="<?= $e($on); ?>"></div>
    <div class="col-6 col-md-1"><button class="btn btn-primary btn-sm w-100">Show</button></div>
</div></form>
<div class="row g-3 mb-3">
    <?php foreach ([['Overall score', $overall !== null ? $overall . '%' : '—', kpi_rating($conn, $overall)], ['Business score', ($b = kpi_weighted($business)) !== null ? $b . '%' : '—', kpi_rating($conn, $b)],
                    ['Targets achieved', count($top) . ' / ' . count(array_filter($results, function ($r) { return $r['achievement'] !== null; })), 'KPIs at 100% or more'],
                    ['Underperforming', (string) count($under), 'KPIs below 70%']] as [$t, $v, $s]) { ?>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted"><?= $t; ?></div><div class="fs-4 fw-bold"><?= $e($v); ?></div><div class="small text-muted"><?= $e($s ?? ''); ?></div></div></div></div>
    <?php } ?>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Performance trend (overall weighted score)</h6><div style="height:220px;"><canvas id="trend"></canvas></div></div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body p-0"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
        <tr><th class="ps-3">Department / team / employee</th><th>Level</th><th style="width:40%;">Weighted score</th><th>Rating</th></tr>
        <?php $anyGroup = false; foreach ([['department', 'department_id', 'department'], ['team', 'team_id', 'team'], ['employee', 'user_id', 'employee']] as [$lvl, $key, $lab]) { foreach ($by($lvl, $key, $lab) as $g) { $anyGroup = true; ?>
        <tr><td class="ps-3"><?= $e($g['label']); ?></td><td class="small"><?= KPI_LEVELS[$lvl]; ?></td><td><?= kpi_bar($g['score']); ?></td><td class="small"><?= $e(kpi_rating($conn, $g['score']) ?? '—'); ?></td></tr>
        <?php } } if (!$anyGroup) { ?><tr><td colspan="4" class="text-center text-muted py-3">No department, team or employee KPIs yet.</td></tr><?php } ?>
    </table></div></div></div>
</div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">KPI</th><th>For</th><th>Period</th><th class="text-end">Target</th><th class="text-end">Actual</th><th style="width:22%;">Achievement</th><th>Data</th></tr>
    <?php if (!$results) { ?><tr><td colspan="7" class="text-center text-muted py-4">No active KPIs<?= access_can('kpi.create') ? '. Create the first ones in the KPIs tab.' : '.'; ?></td></tr><?php } ?>
    <?php foreach ($results as $r) { ?>
    <tr><td class="ps-3"><a href="kpi.php?id=<?= (int) $r['id']; ?>" class="text-decoration-none"><?= $e($r['name']); ?></a><div class="small text-muted"><?= $e(KPI_TYPES[$r['measure_type']] ?? ''); ?> · weight <?= (float) $r['weight']; ?></div></td>
        <td class="small"><?= $e(kpi_scope_label($r)); ?></td><td class="small"><?= $e($r['period_label']); ?></td>
        <td class="text-end"><?= $r['direction'] === 'range' ? $e(kpi_fmt((float) $r['range_min'], $r['unit']) . ' – ' . kpi_fmt((float) $r['range_max'], $r['unit'])) : $e(($r['direction'] === 'lower' ? '≤ ' : '') . kpi_fmt((float) $r['target'], $r['unit'])); ?></td>
        <td class="text-end fw-semibold"><?= $e(kpi_fmt($r['actual'], $r['unit'])); ?></td><td><?= kpi_bar($r['achievement']); ?></td>
        <td class="small text-muted"><?= $r['source'] === 'automatic' ? 'automatic' : ($r['source'] === 'manual' ? 'entered' : '—'); ?></td></tr>
    <?php } ?>
</table></div></div></div>
<div class="row g-3">
    <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h6 class="text-danger">Underperforming areas (below 70%)</h6>
        <?php if (!$under) { ?><div class="small text-muted">None.</div><?php } foreach ($under as $r) { ?><div class="small border-bottom py-1"><a href="kpi.php?id=<?= (int) $r['id']; ?>"><?= $e($r['name']); ?></a> · <?= $e(kpi_scope_label($r)); ?> · <strong><?= $r['achievement']; ?>%</strong></div><?php } ?></div></div></div>
    <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h6 class="text-success">Outstanding achievements (100% or more)</h6>
        <?php if (!$top) { ?><div class="small text-muted">None yet.</div><?php } foreach ($top as $r) { ?><div class="small border-bottom py-1"><a href="kpi.php?id=<?= (int) $r['id']; ?>"><?= $e($r['name']); ?></a> · <?= $e(kpi_scope_label($r)); ?> · <strong><?= $r['achievement']; ?>%</strong></div><?php } ?></div></div></div>
</div>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>(function () { if (!window.Chart) { return; } const d = <?= json_encode($trend); ?>;
  new Chart(document.getElementById('trend'), { type: 'line', data: { labels: d.map(x => x.label), datasets: [{ label: 'Weighted score %', data: d.map(x => x.score), borderColor: '#1E2FE0', tension: .25 }] },
    options: { responsive: true, maintainAspectRatio: false, scales: { y: { suggestedMin: 0, suggestedMax: 120 } } } }); })();</script>
<?php include '../../includes/footer.php'; ?>