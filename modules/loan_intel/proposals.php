<?php
/**
 * NEW FILE: modules/loan_intel/proposals.php — INVESTMENT OPPORTUNITIES: list, ranking and side-by-side comparison (spec 5).
 * Ranked by configurable criteria (Settings), not by revenue: ROI, payback, cash flow, risk, demand evidence, data confidence, NPV.
 */
require __DIR__ . '/common.php';
if (!li_can($conn, 'view') && !li_can($conn, 'propose')) { li_deny(); }
$co = li_company($conn);
$fStatus = (string) ($_GET['status'] ?? 'open'); $fCat = (string) ($_GET['category'] ?? '');
$w = [li_proposal_scope_sql($conn, 'p')];
if ($fStatus === 'open') { $w[] = "p.status IN ('Draft', 'Submitted', 'Approved', 'Funded')"; } elseif (in_array($fStatus, LI_PROPOSAL_STATUSES, true)) { $w[] = "p.status = '$fStatus'"; }
if (in_array($fCat, LI_CATEGORIES, true)) { $w[] = "p.category = '" . mysqli_real_escape_string($conn, $fCat) . "'"; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT p.*, u.names creator FROM li_proposals p LEFT JOIN users u ON u.id = p.created_by WHERE ' . implode(' AND ', $w) . ' ORDER BY p.id DESC'), MYSQLI_ASSOC);
foreach ($rows as &$r) { $r['ev'] = li_proposal_eval($conn, $r, $co['confidence']); }
unset($r);
usort($rows, function ($a, $b) { return [count($a['ev']['missing']) > 0, -$a['ev']['score']] <=> [count($b['ev']['missing']) > 0, -$b['ev']['score']]; });
$sel = array_map('intval', (array) ($_GET['ids'] ?? []));
$compare = $sel ? array_values(array_filter($rows, function ($r) use ($sel) { return in_array((int) $r['id'], $sel, true); })) : array_slice(array_values(array_filter($rows, function ($r) { return !$r['ev']['missing']; })), 0, 4);
$weights = li_weights($conn);
$head = ['Rank', 'Code', 'Investment', 'Category', 'Status', 'Score', 'Total cost', 'Net profit / month', 'ROI %', 'Payback (months)', 'NPV', 'IRR %', 'Risk', 'Evidence', 'Data confidence'];
$csvRows = [];
foreach ($rows as $i => $r) { $m = $r['ev']['m']; $csvRows[] = [$i + 1, $r['code'], $r['name'], $r['category'], $r['status'], $r['ev']['missing'] ? 'incomplete' : $r['ev']['score'], $m['total_cost'], $m['monthly_net'], $m['roi'], $m['payback'], $m['npv'], $m['irr'], $r['risk_level'], $r['evidence_level'], $r['ev']['confidence']]; }
if (($_GET['export'] ?? '') === 'csv') { li_audit($conn, 'export', 'proposals', null, null, null, 'Investment ranking CSV'); li_csv('investment-ranking-' . date('Ymd') . '.csv', $head, $csvRows, [['Weights', json_encode($weights)]]); }
if (($_GET['export'] ?? '') === 'pdf') {
    li_audit($conn, 'export', 'proposals', null, null, null, 'Investment ranking PDF');
    an_render_pdf('Investment Opportunities — Ranking', [['Status', $fStatus], ['Ranking weights', implode(', ', array_map(function ($k, $v) { return "$k $v"; }, array_keys($weights), $weights))]], [], an_posted_charts(),
        [['title' => 'Ranking', 'columns' => $head, 'rows' => $csvRows]], 'investment-ranking-' . date('Ymd') . '.pdf');
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'proposals.php', 'Investment Opportunity Analysis');
$qs = $_GET; unset($qs['export']);
?>
<div class="d-flex flex-wrap gap-2 align-items-end mb-3">
    <form class="d-flex gap-2"><select name="status" class="form-select form-select-sm" onchange="this.form.submit()"><option value="open" <?= $fStatus === 'open' ? 'selected' : ''; ?>>Current (draft to funded)</option><option value="all" <?= $fStatus === 'all' ? 'selected' : ''; ?>>All</option>
        <?php foreach (LI_PROPOSAL_STATUSES as $s) { ?><option <?= $fStatus === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select>
        <select name="category" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All categories</option><?php foreach (LI_CATEGORIES as $c) { ?><option <?= $fCat === $c ? 'selected' : ''; ?>><?= li_e($c); ?></option><?php } ?></select></form>
    <div class="ms-auto d-flex gap-2">
        <a href="?<?= li_e(http_build_query($qs + ['export' => 'csv'])); ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a><?php an_pdf_button('PDF'); ?>
        <?php if (li_can($conn, 'propose')) { ?><a href="proposal_edit.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle"></i> New proposal</a><a href="needs.php" class="btn btn-sm btn-outline-primary">From detected needs</a><?php } ?>
    </div>
</div>

<form method="GET"><input type="hidden" name="status" value="<?= li_e($fStatus); ?>">
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover align-middle mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3"><i class="bi bi-check2-square" title="Compare"></i></th><th>#</th><th>Investment</th><th>Status</th><th class="text-end">Score</th><th class="text-end">Total cost</th><th class="text-end">Net profit / month</th>
        <th class="text-end">ROI</th><th class="text-end">Payback</th><th class="text-end">NPV</th><th>Risk</th><th>Evidence</th><th>Confidence</th></tr>
    <?php foreach ($rows as $i => $r) { $m = $r['ev']['m']; ?>
    <tr><td class="ps-3"><input type="checkbox" name="ids[]" value="<?= (int) $r['id']; ?>" <?= in_array((int) $r['id'], array_map('intval', array_column($compare, 'id')), true) ? 'checked' : ''; ?>></td>
        <td><?= $r['ev']['missing'] ? '—' : $i + 1; ?></td>
        <td><a href="proposal.php?id=<?= (int) $r['id']; ?>"><strong><?= li_e($r['name']); ?></strong></a><div class="small text-muted"><?= li_e($r['code'] . ' · ' . $r['category'] . ' · ' . $r['creator']); ?><?= $r['source'] === 'detected' ? ' · detected' : ''; ?></div></td>
        <td><?= li_status_badge($r['status']); ?></td>
        <td class="text-end fw-bold"><?= $r['ev']['missing'] ? '<span class="text-warning small">MORE DATA</span>' : $r['ev']['score']; ?></td>
        <td class="text-end"><?= li_n($m['total_cost']); ?></td><td class="text-end <?= $m['monthly_net'] < 0 ? 'text-danger' : ''; ?>"><?= li_n($m['monthly_net']); ?></td>
        <td class="text-end"><?= li_pct($m['roi']); ?></td><td class="text-end"><?= $m['payback'] === null ? 'never' : $m['payback'] . ' mo'; ?></td><td class="text-end <?= $m['npv'] < 0 ? 'text-danger' : ''; ?>"><?= li_n($m['npv']); ?></td>
        <td><?= li_e($r['risk_level']); ?></td><td><?= li_e(['data' => 'Records', 'partial' => 'Partial', 'assumption' => 'Assumption'][$r['evidence_level']]); ?></td><td><?= li_e($r['ev']['confidence']); ?></td></tr>
    <?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="13" class="text-center text-muted py-4">No proposals. Create one, or start from a need MY MOTIVE detected.</td></tr><?php } ?>
</table></div></div></div>
<?php if ($rows) { ?><button class="btn btn-sm btn-outline-primary mb-3">Compare the selected</button><?php } ?>
</form>

<?php if ($compare) { $crit = ['total_cost' => 'Required capital', 'monthly_net' => 'Expected net profit / month', 'net_total' => 'Net profit over the period', 'roi' => 'Expected ROI', 'payback' => 'Payback period',
    'npv' => 'NPV', 'irr' => 'IRR', 'monthly_cash' => 'Cash flow impact / month']; ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <h6>Side-by-side comparison</h6>
    <div class="table-responsive"><table class="table table-sm small mb-0"><tr><th></th><?php foreach ($compare as $c) { ?><th class="text-end"><a href="proposal.php?id=<?= (int) $c['id']; ?>"><?= li_e($c['name']); ?></a></th><?php } ?></tr>
        <?php foreach ($crit as $k => $l) { $vals = array_map(function ($c) use ($k) { return $c['ev']['m'][$k]; }, $compare); $num = array_filter($vals, function ($v) { return $v !== null; });
            $best = $num ? ($k === 'payback' || $k === 'total_cost' ? min($num) : max($num)) : null; ?>
        <tr><td><?= $l; ?></td><?php foreach ($vals as $v) { ?><td class="text-end <?= $v !== null && $v === $best && count($compare) > 1 ? 'fw-bold text-success' : ''; ?>"><?= in_array($k, ['roi', 'irr'], true) ? li_pct($v) : ($k === 'payback' ? ($v === null ? 'never' : $v . ' months') : li_n($v)); ?></td><?php } ?></tr><?php } ?>
        <tr><td>Risk level</td><?php foreach ($compare as $c) { ?><td class="text-end"><?= li_e($c['risk_level']); ?></td><?php } ?></tr>
        <tr><td>Demand evidence</td><?php foreach ($compare as $c) { ?><td class="text-end"><?= li_e(['data' => 'Records', 'partial' => 'Partial', 'assumption' => 'Assumptions'][$c['evidence_level']]); ?></td><?php } ?></tr>
        <tr><td>Data confidence</td><?php foreach ($compare as $c) { ?><td class="text-end"><?= li_e($c['ev']['confidence']); ?></td><?php } ?></tr>
        <tr class="table-light"><td><strong>Ranking score</strong></td><?php foreach ($compare as $c) { ?><td class="text-end fw-bold"><?= $c['ev']['missing'] ? 'MORE DATA' : $c['ev']['score']; ?></td><?php } ?></tr>
        <?php foreach (['roi' => 'ROI', 'payback' => 'Payback', 'cash_flow' => 'Cash flow', 'risk' => 'Risk', 'evidence' => 'Evidence', 'confidence' => 'Confidence', 'npv' => 'NPV > 0'] as $k => $l) { ?>
        <tr class="text-muted"><td>&nbsp;&nbsp;<?= $l; ?> (weight <?= (float) ($weights[$k] ?? 0); ?>)</td><?php foreach ($compare as $c) { ?><td class="text-end"><?= round($c['ev']['parts'][$k] * 100); ?>%</td><?php } ?></tr><?php } ?>
    </table></div>
    <div class="small text-muted mt-2">Score = Σ weight x criterion (each 0-100%) / Σ weights. Criteria: ROI (0% → 0, ≥ 2 x the <?= li_setting($conn, 'min_roi'); ?>% minimum → 100%), payback (≤ 3 months → 100%, ≥ <?= li_setting($conn, 'max_payback'); ?> months → 0), monthly cash flow / cost (≥ 10% → 100%), risk (Low 100%, Medium 60%, High 20%), evidence (records 100%, partial 60%, assumptions 30%), data confidence (High 100%, Medium 60%, Low 30%), NPV positive. Weights are set in Settings.</div>
</div></div>
<div><?php an_chart_card('liCmp', 'ROI and payback of the proposals compared', '', 240); ?></div>
<?php an_js(); ?>
<script>
new Chart(document.getElementById('liCmp'), { type: 'bar', data: { labels: <?= json_encode(array_map(function ($c) { return $c['name']; }, $compare)); ?>, datasets: [
    { label: 'ROI %', data: <?= json_encode(array_map(function ($c) { return $c['ev']['m']['roi']; }, $compare)); ?>, backgroundColor: '#1E2FE0', yAxisID: 'y' },
    { label: 'Payback (months)', data: <?= json_encode(array_map(function ($c) { return $c['ev']['m']['payback']; }, $compare)); ?>, backgroundColor: '#0FA968', yAxisID: 'y1' }] },
    options: { maintainAspectRatio: false, scales: { y: { position: 'left', title: { display: true, text: 'ROI %' } }, y1: { position: 'right', grid: { drawOnChartArea: false }, title: { display: true, text: 'months' } } } } });
</script>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>