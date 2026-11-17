<?php
/**
 * NEW FILE: modules/loan_intel/simulate.php — LOAN AFFORDABILITY & REPAYMENT SIMULATION (spec 6).
 * Up to 3 loan offers side by side, the largest affordable amount for each, and the stress test
 * (optimistic / base / pessimistic) of the chosen offer. Uses the company's actual cash flow and existing loans.
 */
require __DIR__ . '/common.php';
li_require($conn, 'view');
$co = li_company($conn);
$disc = li_num($conn, 'discount_rate'); $minD = li_num($conn, 'min_dscr'); $minS = li_num($conn, 'min_dscr_stress');
$me = (int) current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'save') {
    if (!li_csrf_ok()) { li_go('simulate.php', 'error', 'The page had expired.'); }
    $s = $_POST;
    $name = mb_substr(trim((string) ($s['name'] ?? '')), 0, 120);
    $pid = (int) ($s['proposal'] ?? 0) ?: null;
    if ($name === '' || (float) $s['principal'] <= 0 || (int) $s['n'] <= 0) { li_go('simulate.php', 'error', 'Give the scenario a name, an amount and a number of instalments.'); }
    $st = mysqli_prepare($conn, 'INSERT INTO li_scenarios (name, proposal_id, lender, principal, rate, rate_type, n, freq, grace, structure, fees, other, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $lender = mb_substr(trim((string) ($s['lender'] ?? '')), 0, 150); $pr = (float) $s['principal']; $rt = (float) $s['rate']; $ty = $s['rate_type'] === 'Variable' ? 'Variable' : 'Fixed';
    $n = (int) $s['n']; $fq = isset(LI_FREQUENCIES[$s['freq']]) ? $s['freq'] : 'Monthly'; $gr = max(0, (int) $s['grace']); $str = isset(LI_STRUCTURES[$s['structure']]) ? $s['structure'] : 'emi';
    $fe = max(0, (float) $s['fees']); $ot = max(0, (float) $s['other']);
    mysqli_stmt_bind_param($st, 'sisddsisisddi', $name, $pid, $lender, $pr, $rt, $ty, $n, $fq, $gr, $str, $fe, $ot, $me);
    mysqli_stmt_execute($st);
    $sid = (int) mysqli_insert_id($conn);
    li_audit($conn, 'scenario_save', 'scenario', $sid, null, compact('name', 'pid', 'lender', 'pr', 'rt', 'ty', 'n', 'fq', 'gr', 'str', 'fe', 'ot'));
    li_go('simulate.php?load=' . $sid . ($pid ? '&proposal=' . $pid : ''), 'success', 'Loan scenario saved. Use it when you generate a recommendation.');
}

$pid = (int) ($_GET['proposal'] ?? 0);
$prop = $pid ? li_proposal($conn, $pid) : null;
if ($prop && !li_proposal_visible($conn, $prop)) { $prop = null; $pid = 0; }
$saved = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM li_scenarios ORDER BY id DESC LIMIT 40'), MYSQLI_ASSOC);
$def = ['lender' => '', 'principal' => 0, 'rate' => 18, 'rate_type' => 'Fixed', 'n' => 24, 'freq' => 'Monthly', 'grace' => 0, 'structure' => 'emi', 'fees' => 0, 'other' => 0];
$in = (array) ($_GET['s'] ?? []);
if (isset($_GET['load'])) {
    foreach ($saved as $x) { if ((int) $x['id'] === (int) $_GET['load']) { $in = [array_intersect_key($x, $def)]; if (!$pid && $x['proposal_id']) { $pid = (int) $x['proposal_id']; $prop = li_proposal($conn, $pid); } } }
}
if ($prop && !li_proposal_visible($conn, $prop)) { $prop = null; $pid = 0; }
$need = $prop ? li_proposal_metrics(li_proposal_input($prop), $disc)['total_cost'] : 0;
$def['principal'] = $need > 0 ? max(0, $need - $co['internal']) : 0;   // default: the funding gap
if (!$in) { $in = [$def, ['rate' => 15, 'n' => 36] + $def]; }
$offers = [];
foreach (array_slice($in, 0, 3) as $k => $x) {
    $o = [];
    foreach ($def as $f => $dv) { $o[$f] = isset($x[$f]) && $x[$f] !== '' ? $x[$f] : $dv; }
    $o['principal'] = max(0, (float) $o['principal']); $o['rate'] = max(0, (float) $o['rate']); $o['n'] = max(1, min(360, (int) $o['n'])); $o['grace'] = max(0, min($o['n'] - 1, (int) $o['grace']));
    $o['freq'] = isset(LI_FREQUENCIES[$o['freq']]) ? $o['freq'] : 'Monthly'; $o['structure'] = isset(LI_STRUCTURES[$o['structure']]) ? $o['structure'] : 'emi';
    $o['rate_type'] = $o['rate_type'] === 'Variable' ? 'Variable' : 'Fixed'; $o['fees'] = max(0, (float) $o['fees']); $o['other'] = max(0, (float) $o['other']);
    $offers[] = $o;
}
foreach ($offers as $i => $o) { if ($i > 0 && $o['principal'] <= 0) { unset($offers[$i]); } } // empty offers B / C are not compared
$base = li_sim_base($co);
$scen = li_scenarios($conn);
$propIn = $prop ? li_proposal_input($prop) : null;
$res = [];
foreach ($offers as $i => $o) {
    $res[$i] = ['sim' => $o['principal'] > 0 ? li_simulate($base, $o, $propIn, $disc, $scen['base']) : null,
                'aff' => li_affordable_principal($co['cfads'], $co['debt_month'], $minD, $o['rate'], $o['n'], $o['freq'], $o['grace'], $o['structure'])];
}
$pick = max(0, min(count($offers) - 1, (int) ($_GET['stress'] ?? 0)));
$stress = [];
if ($offers[$pick]['principal'] > 0) { foreach ($scen as $k => $adj) { $stress[$k] = li_simulate($base, $offers[$pick], $propIn, $disc, $adj); } }

$cols = ['Instalment', 'Total interest', 'Fees and charges', 'Total loan cost', 'Total repayment', 'New debt service / month', 'All debt service / month', 'Cash flow left after debt service / month',
         'Lowest DSCR (loan years)', 'Average DSCR', 'Expected investment profit', 'Cash at the end', 'Largest affordable amount'];
$table = [];
foreach ($offers as $i => $o) {
    $s = $res[$i]['sim'];
    $table[$i] = $s ? [li_n($s['instalment']), li_n($s['total_interest']), li_n($o['fees'] + $o['other']), li_n($s['total_cost']), li_n($s['total_repayment']), li_n($s['monthly_debt_new']),
        li_n($s['monthly_debt_total']), li_n($s['monthly_after']), $s['dscr_min'] ?? '—', $s['dscr_avg'] ?? '—', li_n($s['investment_profit']), li_n($s['cash_end']), li_n($res[$i]['aff'])]
        : array_merge(array_fill(0, 12, '—'), [li_n($res[$i]['aff'])]);
}
$label = function ($o, $i) { return 'Offer ' . chr(65 + $i) . ($o['lender'] ? ' · ' . $o['lender'] : '') . ': ' . number_format($o['principal']) . ' @ ' . $o['rate'] . '% · ' . $o['n'] . ' x ' . strtolower($o['freq']); };
if (($_GET['export'] ?? '') === 'csv') {
    $rows = [];
    foreach ($cols as $k => $c) { $row = [$c]; foreach ($table as $t) { $row[] = $t[$k]; } $rows[] = $row; }
    li_audit($conn, 'export', 'simulation', null, null, $offers, 'Loan simulation CSV');
    li_csv('loan-simulation-' . date('Ymd') . '.csv', array_merge([''], array_map($label, $offers, array_keys($offers))), $rows, [['CFADS per month (base)', $co['cfads']], ['Existing debt service per month', $co['debt_month']], ['Minimum DSCR', $minD]]);
}
if (($_GET['export'] ?? '') === 'pdf') {
    $rows = []; foreach ($cols as $k => $c) { $row = [$c]; foreach ($table as $t) { $row[] = $t[$k]; } $rows[] = $row; }
    $st = []; foreach ($stress as $k => $s) { $st[] = [$scen[$k]['label'], $s['dscr_min'] ?? '—', number_format($s['monthly_after']), number_format($s['cash_low']), $s['first_below_reserve'] ? 'month ' . $s['first_below_reserve'] : 'never', $s['dscr_min'] !== null && $s['dscr_min'] >= ($k === 'pessimistic' ? $minS : $minD) ? 'Yes' : 'No']; }
    li_audit($conn, 'export', 'simulation', null, null, $offers, 'Loan simulation PDF');
    an_render_pdf('Loan Affordability & Repayment Simulation', [['Investment', $prop ? $prop['name'] : 'none'], ['CFADS per month', number_format($co['cfads'])], ['Existing debt service per month', number_format($co['debt_month'])], ['Minimum DSCR', $minD . ' (stress ' . $minS . ')']],
        [], an_posted_charts(), [['title' => 'Loan offers compared', 'columns' => array_merge([''], array_map($label, $offers, array_keys($offers))), 'rows' => $rows],
        ['title' => 'Stress test · ' . $label($offers[$pick], $pick), 'columns' => ['Scenario', 'Lowest DSCR', 'Left after debt / month', 'Lowest cash', 'Cash below reserve', 'Repayments covered'], 'rows' => $st]], 'loan-simulation-' . date('Ymd') . '.pdf');
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'simulate.php', 'Loan Affordability & Repayment Simulation');
$qs = $_GET; unset($qs['export']);
?>
<div class="row row-cols-2 row-cols-md-5 g-2 mb-3">
    <?= li_kpi('CFADS per month', li_rwf($co['cfads']), li_tag('Estimated'), 'Average of recent months'); ?>
    <?= li_kpi('Existing loan repayments / month', li_rwf($co['debt_month']), li_tag('Actual'), 'Next 12 months, schedules'); ?>
    <?= li_kpi('Room for new repayments / month', li_rwf(max(0, $co['cfads'] / $minD - $co['debt_month'])), li_tag('Estimated'), 'CFADS / ' . $minD . ' - existing'); ?>
    <?= li_kpi('Cash today', li_rwf($co['cash']), li_tag('Actual'), 'Reserve ' . li_rwf($co['reserve'])); ?>
    <?= li_kpi('Internal funds free', li_rwf($co['internal']), li_tag('Actual'), $prop ? 'Need: ' . li_rwf($need) : ''); ?>
</div>
<?php if (!$co['enough_history']) { ?><div class="alert alert-warning py-2 small"><strong>MORE DATA REQUIRED:</strong> only <?= (int) $co['active_months']; ?> month(s) of sales history. The results below are indicative only.</div><?php } ?>

<form method="GET" class="card border-0 shadow-sm mb-3"><div class="card-body">
    <div class="row g-2 mb-2"><div class="col-md-6"><label class="form-label small mb-0">Investment financed (optional)</label><select name="proposal" class="form-select form-select-sm"><option value="0">— none: borrowing without a specific investment —</option>
        <?php foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.code, p.name, p.status, p.created_by, p.branch_id FROM li_proposals p WHERE p.status IN ('Draft', 'Submitted', 'Approved', 'Funded') AND " . li_proposal_scope_sql($conn, 'p') . ' ORDER BY p.id DESC'), MYSQLI_ASSOC) as $x) { ?>
        <option value="<?= (int) $x['id']; ?>" <?= $pid === (int) $x['id'] ? 'selected' : ''; ?>><?= li_e($x['code'] . ' · ' . $x['name'] . ' (' . $x['status'] . ')'); ?></option><?php } ?></select></div>
        <div class="col-md-4"><label class="form-label small mb-0">Load a saved scenario</label><select class="form-select form-select-sm" onchange="if(this.value)location.href='?load='+this.value"><option value="">—</option><?php foreach ($saved as $x) { ?><option value="<?= (int) $x['id']; ?>"><?= li_e($x['name']); ?></option><?php } ?></select></div></div>
    <div class="table-responsive"><table class="table table-sm small align-middle mb-2"><tr><th style="width:160px;"></th><?php foreach ([0, 1, 2] as $i) { ?><th>Offer <?= chr(65 + $i); ?><?= $i === 2 ? ' <span class="text-muted fw-normal">(optional)</span>' : ''; ?></th><?php } ?></tr>
        <?php $fld = ['lender' => ['Lender', 'text'], 'principal' => ['Proposed principal (RWF)', 'number'], 'rate' => ['Interest rate (% a year)', 'number'], 'rate_type' => ['Rate type', ['Fixed', 'Variable']],
            'n' => ['Number of instalments (term)', 'number'], 'freq' => ['Repayment frequency', array_keys(LI_FREQUENCIES)], 'grace' => ['Grace period (instalments, interest only)', 'number'],
            'structure' => ['Repayment structure', LI_STRUCTURES], 'fees' => ['Processing fees (RWF)', 'number'], 'other' => ['Other charges (RWF)', 'number']];
        foreach ($fld as $f => [$l, $t]) { ?><tr><td><?= $l; ?></td><?php foreach ([0, 1, 2] as $i) { $val = $offers[$i][$f] ?? (is_array($t) ? $def[$f] : ''); ?><td>
            <?php if (is_array($t)) { ?><select name="s[<?= $i; ?>][<?= $f; ?>]" class="form-select form-select-sm"><?php foreach ($t as $k => $opt) { $kv = is_int($k) ? $opt : $k; ?><option value="<?= li_e($kv); ?>" <?= (string) $val === (string) $kv ? 'selected' : ''; ?>><?= li_e($opt); ?></option><?php } ?></select>
            <?php } else { ?><input name="s[<?= $i; ?>][<?= $f; ?>]" type="<?= $t; ?>" <?= $t === 'number' ? 'step="any" min="0"' : ''; ?> value="<?= li_e($val); ?>" class="form-control form-control-sm"><?php } ?></td><?php } ?></tr><?php } ?>
    </table></div>
    <button class="btn btn-sm btn-primary"><i class="bi bi-calculator"></i> Calculate</button> <span class="small text-muted">Leave Offer C's principal empty to compare two offers.</span>
</div></form>

<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Offers compared <?= li_tag('Estimated'); ?></h6>
    <div class="d-flex gap-2"><a href="?<?= li_e(http_build_query($qs + ['export' => 'csv'])); ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a><?php an_pdf_button('PDF'); ?></div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3"></th><?php foreach ($offers as $i => $o) { ?><th class="text-end"><?= li_e($label($o, $i)); ?></th><?php } ?></tr>
    <?php foreach ($cols as $k => $c) { ?><tr><td class="ps-3"><?= li_e($c); ?></td><?php foreach ($offers as $i => $o) { $s = $res[$i]['sim']; $bad = $k === 8 && $s && $s['dscr_min'] !== null && $s['dscr_min'] < $minD; ?>
        <td class="text-end <?= $bad ? 'text-danger fw-bold' : ($k === 8 ? 'fw-bold text-success' : ''); ?> <?= $k === 12 && $o['principal'] > $res[$i]['aff'] ? 'text-danger' : ''; ?>"><?= li_e($table[$i][$k]); ?></td><?php } ?></tr><?php } ?>
    <tr><td class="ps-3">Save this offer</td><?php foreach ($offers as $i => $o) { ?><td class="text-end"><form method="POST" class="d-flex gap-1 justify-content-end"><?= li_csrf_field(); ?><input type="hidden" name="do" value="save"><input type="hidden" name="proposal" value="<?= $pid; ?>">
        <?php foreach ($o as $f => $vv) { ?><input type="hidden" name="<?= $f; ?>" value="<?= li_e($vv); ?>"><?php } ?><input name="name" class="form-control form-control-sm" style="max-width:150px;" placeholder="Name" value="<?= li_e(($o['lender'] ?: 'Offer ' . chr(65 + $i)) . ' ' . date('d M')); ?>"><button class="btn btn-sm btn-outline-primary">Save</button></form></td><?php } ?></tr>
</table></div></div></div>
<div class="small text-muted mb-3">DSCR = CFADS of each loan year / (existing + new repayments due in that year); minimum <?= $minD; ?>. Largest affordable amount = the principal whose repayments keep the DSCR at <?= $minD; ?> with these terms. Loan costs = interest + fees + charges (fees are paid when the money is received). Cash at the end = cash today + loan received - fees - investment + monthly cash flow - all repayments.</div>

<?php if ($stress) { ?>
<div class="d-flex justify-content-between align-items-center mb-2"><h6 class="mb-0">Financial stress test · <?= li_e($label($offers[$pick], $pick)); ?></h6>
    <div class="btn-group btn-group-sm"><?php foreach ($offers as $i => $o) { ?><a class="btn btn-<?= $i === $pick ? 'primary' : 'outline-primary'; ?>" href="?<?= li_e(http_build_query(['stress' => $i] + $qs)); ?>">Offer <?= chr(65 + $i); ?></a><?php } ?></div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3">Scenario</th><th>What changes</th><th class="text-end">Lowest DSCR</th><th class="text-end">Left after debt / month</th><th class="text-end">Lowest cash</th><th>Cash below reserve</th><th>Repayments covered?</th></tr>
    <?php foreach ($stress as $k => $s) { $a = $scen[$k]; $okS = $s['dscr_min'] !== null && $s['dscr_min'] >= ($k === 'pessimistic' ? $minS : $minD); ?>
    <tr><td class="ps-3"><strong><?= li_e($a['label']); ?></strong></td><td class="small text-muted">Revenue <?= sprintf('%+d', $a['revenue_pct']); ?>%, expenses <?= sprintf('%+d', $a['opex_pct']); ?>%, investment delayed <?= (int) $a['delay_months']; ?> month(s), stock <?= (int) $a['slower_pct']; ?>% slower, variable rate +<?= (float) $a['rate_up']; ?> pts<?= $a['shock'] ? ', unexpected cost ' . li_n($a['shock']) . ' in month 3' : ''; ?></td>
        <td class="text-end fw-bold <?= $okS ? 'text-success' : 'text-danger'; ?>"><?= $s['dscr_min'] ?? '—'; ?></td><td class="text-end"><?= li_n($s['monthly_after']); ?></td>
        <td class="text-end <?= $s['cash_low'] < 0 ? 'text-danger' : ''; ?>"><?= li_n($s['cash_low']); ?></td><td><?= $s['first_below_reserve'] ? 'from month ' . $s['first_below_reserve'] : 'never'; ?></td>
        <td><?= $okS ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-danger">No</span>'; ?></td></tr><?php } ?>
</table></div></div></div>
<div class="row g-3 mb-3">
    <div class="col-lg-7"><?php an_chart_card('liCash', 'Cash balance month by month', 'Base, optimistic and pessimistic; dashed line = cash reserve', 250); ?></div>
    <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Repayment schedule (base)</h6>
        <div style="max-height:250px;overflow:auto;"><table class="table table-sm small mb-0"><tr><th>#</th><th>Month</th><th class="text-end">Interest</th><th class="text-end">Principal</th><th class="text-end">Payment</th><th class="text-end">Balance</th></tr>
        <?php foreach ($stress['base']['schedule']['rows'] as $r) { ?><tr><td><?= $r['no']; ?></td><td><?= date('M Y', strtotime('first day of +' . ($r['month'] - 1) . ' month')); ?></td><td class="text-end"><?= li_n($r['interest']); ?></td><td class="text-end"><?= li_n($r['principal']); ?></td><td class="text-end"><?= li_n($r['payment']); ?></td><td class="text-end"><?= li_n($r['balance']); ?></td></tr><?php } ?></table></div>
    </div></div></div>
</div>
<?php an_js(); ?>
<script>
(function () {
    var S = <?= json_encode(array_map(function ($s) { return array_column($s['rows'], 'cash'); }, $stress)); ?>, n = S.base.length, L = [];
    for (var i = 1; i <= n; i++) { var d = new Date(); d.setDate(1); d.setMonth(d.getMonth() + i - 1); L.push(d.toLocaleString('en', { month: 'short', year: '2-digit' })); }
    new Chart(document.getElementById('liCash'), { type: 'line', data: { labels: L, datasets: [
        { label: 'Base', data: S.base, borderColor: '#1E2FE0', pointRadius: 0 }, { label: 'Optimistic', data: S.optimistic, borderColor: '#0FA968', pointRadius: 0 },
        { label: 'Pessimistic', data: S.pessimistic, borderColor: '#E24B4A', pointRadius: 0 },
        { label: 'Reserve', data: L.map(function () { return <?= json_encode($co['reserve']); ?>; }), borderColor: '#94A3B8', borderDash: [5, 4], pointRadius: 0 }] }, options: { maintainAspectRatio: false } });
})();
</script>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>