<?php
/**
 * NEW FILE: modules/loan_intel/risk.php — FINANCIAL RISK ANALYSIS of company borrowing (spec 6 F/G, 14).
 * Debt service coverage month by month, debt-to-cash-flow, maturity profile, lender concentration, variable rates,
 * stress test of the EXISTING loans, the 90-day cash forecast and the data checks behind every figure.
 */
require __DIR__ . '/common.php';
li_require($conn, 'view');
$co = li_company($conn);
$minD = li_num($conn, 'min_dscr'); $minS = li_num($conn, 'min_dscr_stress'); $maxShare = li_num($conn, 'max_debt_share');
$paid = li_debt_paid_by_month($conn);
$hist = array_slice($co['history'], -12);
$annualCfads = $co['cfads'] * 12;
$years = $annualCfads > 0 ? round($co['outstanding'] / $annualCfads, 1) : null;
$maturity = mysqli_fetch_all(mysqli_query($conn, "SELECT YEAR(ls.due_date) y, SUM(GREATEST(0, ls.principal_due - GREATEST(0, ls.paid_amount - ls.interest_due))) p, SUM(ls.amount_due - ls.paid_amount) t
    FROM loan_schedule ls JOIN loans l ON l.id = ls.loan_id WHERE l.status IN ('Active', 'Defaulted') AND ls.status <> 'Paid' GROUP BY y ORDER BY y"), MYSQLI_ASSOC);
$lenders = mysqli_fetch_all(mysqli_query($conn, "SELECT l.lender, l.loan_type, COUNT(*) n, SUM(GREATEST(0, l.loan_amount - l.principal_repaid)) o, MAX(COALESCE(d.rate_type, 'Fixed')) rt, MAX(l.interest_rate) r
    FROM loans l LEFT JOIN li_loan_details d ON d.loan_id = l.id WHERE l.status IN ('Active', 'Defaulted') GROUP BY l.lender, l.loan_type ORDER BY o DESC"), MYSQLI_ASSOC);
$variable = array_sum(array_map(function ($x) { return $x['rt'] === 'Variable' ? (float) $x['o'] : 0; }, $lenders));
// Existing loans only, under the stress scenarios: is the current debt covered?
$stress = [];
foreach (li_scenarios($conn) as $k => $a) {
    $c = $co['cfads'] + $co['revenue'] * $co['margin'] * $a['revenue_pct'] / 100 - $co['opex_cash'] * $a['opex_pct'] / 100;
    $d = $co['debt_month'] * (1 + ($a['rate_up'] > 0 && $variable > 0 && $co['outstanding'] > 0 ? $a['rate_up'] / 100 * $variable / $co['outstanding'] : 0));
    $stress[$k] = ['label' => $a['label'], 'cfads' => $c, 'debt' => $d, 'dscr' => $d > 0 ? round($c / $d, 2) : null];
}
$flow = null;
try {
    $revFc = fc_project(fc_history($conn, 'revenue', 'month'), 'month', 4); $expFc = fc_project(fc_history($conn, 'expenses', 'month'), 'month', 4);
    $rs = fc_history($conn, 'restock', 'month');
    $flow = fc_cash_flow(fc_cash_now($conn), $revFc, $expFc, fc_receivables($conn), fc_loan_dues($conn, 90), $rs['periods'] ? array_sum(array_slice($rs['periods'], -3)) / min(3, count($rs['periods'])) : 0.0, 90);
} catch (Throwable $e) { error_log('li risk flow: ' . $e->getMessage()); }
$investorLoans = null;
try { $investorLoans = (float) fin_position($conn, date('Y-m-d'))['investor_loans']; } catch (Throwable $e) { }
$alerts = [];
if ($co['overdue_n']) { $alerts[] = ['danger', $co['overdue_n'] . ' instalment(s) overdue (' . li_rwf($co['overdue_v']) . ').']; }
if ($co['dscr_forward'] !== null && $co['dscr_forward'] < $minD) { $alerts[] = ['danger', 'Forward DSCR ' . $co['dscr_forward'] . ' is below the minimum ' . $minD . ': current cash flow does not comfortably cover the scheduled repayments.']; }
if ($co['debt_share'] !== null && $co['debt_share'] > $maxShare) { $alerts[] = ['warning', 'Repayments take ' . $co['debt_share'] . '% of the cash flow available (limit ' . $maxShare . '%).']; }
if ($stress['pessimistic']['dscr'] !== null && $stress['pessimistic']['dscr'] < $minS) { $alerts[] = ['warning', 'In the pessimistic case the existing loans are not covered (DSCR ' . $stress['pessimistic']['dscr'] . ').']; }
if ($flow && $flow['first_shortage']) { $alerts[] = ['warning', 'The cash forecast goes below zero on ' . date('d M Y', strtotime($flow['first_shortage'])) . '.']; }
if ($lenders && $co['outstanding'] > 0 && (float) $lenders[0]['o'] / $co['outstanding'] > 0.7 && count($lenders) > 1) { $alerts[] = ['info', 'Concentration: ' . round((float) $lenders[0]['o'] / $co['outstanding'] * 100) . '% of the debt is owed to ' . $lenders[0]['lender'] . '.']; }

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'risk.php', 'Financial Risk Analysis');
?>
<?php foreach ($alerts as [$c, $t]) { ?><div class="alert alert-<?= $c; ?> py-2 small mb-2"><?= li_e($t); ?></div><?php } ?>
<?php if (!$alerts) { ?><div class="alert alert-success py-2 small">No financial risk alert on company borrowing.</div><?php } ?>
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-2 mb-3">
    <?= li_kpi('DSCR, last 12 months', (string) ($co['dscr_hist'] ?? '—'), li_tag('Actual'), 'CFADS / repayments actually paid'); ?>
    <?= li_kpi('DSCR, next 12 months', (string) ($co['dscr_forward'] ?? '—'), li_tag('Estimated'), 'Average CFADS x 12 / scheduled repayments', ($co['dscr_forward'] !== null && $co['dscr_forward'] < $minD) ? '#E24B4A' : ''); ?>
    <?= li_kpi('Debt-to-cash-flow', li_pct($co['debt_share']), li_tag('Estimated'), 'Repayments / CFADS per month (limit ' . $maxShare . '%)'); ?>
    <?= li_kpi('Years of cash flow to repay', $years === null ? '—' : $years . ' years', li_tag('Estimated'), 'Outstanding principal / CFADS of a year'); ?>
    <?= li_kpi('Variable-rate debt', li_rwf($variable), li_tag('Actual'), $co['outstanding'] > 0 ? round($variable / $co['outstanding'] * 100) . '% of the debt' : ''); ?>
    <?= li_kpi('Data confidence', li_e($co['confidence']), '', (int) $co['active_months'] . ' month(s) of history'); ?>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-8"><?php an_chart_card('liDscr', 'Debt service coverage, month by month (actual)', 'Bars: CFADS and repayments paid. Line: DSCR of the month (minimum ' . $minD . ')', 250); ?></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Existing loans under stress</h6>
        <table class="table table-sm small mb-0"><tr><th>Scenario</th><th class="text-end">CFADS / month</th><th class="text-end">Repayments / month</th><th class="text-end">DSCR</th></tr>
        <?php foreach ($stress as $k => $s) { $okS = $s['dscr'] === null || $s['dscr'] >= ($k === 'pessimistic' ? $minS : $minD); ?><tr><td><?= li_e($s['label']); ?></td><td class="text-end"><?= li_n($s['cfads']); ?></td><td class="text-end"><?= li_n($s['debt']); ?></td><td class="text-end fw-bold <?= $okS ? 'text-success' : 'text-danger'; ?>"><?= $s['dscr'] ?? '—'; ?></td></tr><?php } ?></table>
        <div class="small text-muted mt-2">Pessimistic: revenue and expenses changed as in Settings; variable-rate loans' repayments rise with the rate increase.</div>
    </div></div></div>
</div>
<div class="row g-3 mb-3">
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Maturity profile (still to pay)</h6>
        <table class="table table-sm small mb-0"><tr><th>Year</th><th class="text-end">Principal</th><th class="text-end">Principal + interest</th><th class="text-end">CFADS of a year</th><th class="text-end">Coverage</th></tr>
        <?php foreach ($maturity as $r) { $cov = (float) $r['t'] > 0 ? round($annualCfads / (float) $r['t'], 2) : null; ?><tr><td><?= (int) $r['y']; ?></td><td class="text-end"><?= li_n($r['p']); ?></td><td class="text-end"><?= li_n($r['t']); ?></td><td class="text-end"><?= li_n($annualCfads); ?></td><td class="text-end <?= $cov !== null && $cov < $minD ? 'text-danger fw-bold' : ''; ?>"><?= $cov ?? '—'; ?></td></tr><?php } ?>
        <?php if (!$maturity) { ?><tr><td colspan="5" class="text-muted">No open company loan.</td></tr><?php } ?></table></div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Lenders</h6>
        <table class="table table-sm small mb-0"><tr><th>Lender</th><th>Type</th><th class="text-end">Loans</th><th class="text-end">Outstanding principal</th><th class="text-end">Rate</th><th>Rate type</th></tr>
        <?php foreach ($lenders as $r) { ?><tr><td><?= li_e($r['lender']); ?></td><td><?= li_e($r['loan_type']); ?></td><td class="text-end"><?= (int) $r['n']; ?></td><td class="text-end"><?= li_n($r['o']); ?></td><td class="text-end"><?= (float) $r['r']; ?>%</td><td><?= li_e($r['rt']); ?></td></tr><?php } ?>
        <?php if (!$lenders) { ?><tr><td colspan="6" class="text-muted">No open company loan.</td></tr><?php } ?></table>
        <div class="small text-muted mt-2">Not included (different modules): customer loans of RMC - Credit Hub (money owed TO the company)<?= $investorLoans ? ', and investor loans of RMC - Investors Hub (' . li_rwf($investorLoans) . ' owed to investors — shown in the Balance Sheet)' : ''; ?>.</div>
    </div></div></div>
</div>
<?php if ($flow && $flow['days']) { ?><div class="mb-3"><?php an_chart_card('liFlow', 'Cash forecast, next 90 days (forecast)', 'From RM Business Forecasting: projected sales and expenses, money customers owe, stock purchases and loan instalments on their due dates', 230); ?></div><?php } ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Data checks behind the analysis</h6>
    <table class="table table-sm small mb-0"><tr><th>Check</th><th class="text-end">Value</th><th>Status</th></tr>
    <?php foreach ($co['checks'] as [$l, $v, $okC, $sev]) { ?><tr><td><?= li_e($l); ?></td><td class="text-end"><?= li_e($v); ?></td><td><?= $okC ? '<span class="badge bg-success">OK</span>' : '<span class="badge bg-' . ($sev === 'critical' ? 'danger' : ($sev === 'warning' ? 'warning text-dark' : 'secondary')) . '">' . ($sev === 'critical' ? 'Missing data' : 'Check') . '</span>'; ?></td></tr><?php } ?></table>
    <div class="small text-muted mt-2">Confidence High = 12+ months of sales, no missing critical data, stable cash flow (variation < 35%). Medium = at least <?= (int) $co['min_history']; ?> months. Low = less: recommendations then say MORE DATA REQUIRED.</div>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-body small"><h6>Monthly figures used (from the Profit &amp; Loss)</h6>
    <div class="table-responsive"><table class="table table-sm mb-0"><tr><th>Month</th><th class="text-end">Revenue</th><th class="text-end">Gross profit</th><th class="text-end">Operating expenses</th><th class="text-end">of which non-cash</th><th class="text-end">Operating profit</th><th class="text-end">CFADS</th><th class="text-end">Repayments paid</th><th class="text-end">DSCR</th></tr>
    <?php foreach ($hist as $h) { $d = $paid[$h['month']] ?? 0; ?><tr><td><?= date('M Y', strtotime($h['month'] . '-01')); ?></td><td class="text-end"><?= li_n($h['revenue']); ?></td><td class="text-end"><?= li_n($h['gross']); ?></td><td class="text-end"><?= li_n($h['opex']); ?></td><td class="text-end"><?= li_n($h['non_cash']); ?></td>
        <td class="text-end"><?= li_n($h['operating']); ?></td><td class="text-end fw-semibold"><?= li_n($h['cfads']); ?></td><td class="text-end"><?= li_n($d); ?></td><td class="text-end"><?= $d > 0 ? round($h['cfads'] / $d, 2) : '—'; ?></td></tr><?php } ?></table></div>
    <div class="text-muted mt-1"><span class="li-formula">CFADS = operating profit + non-cash charges (depreciation, impairment, assets written off)</span> · loan money received is never income; principal repaid is never an expense; interest is a finance cost below operating profit.</div>
</div></div>
<?php an_js(); ?>
<script>
(function () {
    var H = <?= json_encode(array_map(function ($h) use ($paid) { return [date('M y', strtotime($h['month'] . '-01')), $h['cfads'], $paid[$h['month']] ?? 0]; }, $hist)); ?>;
    new Chart(document.getElementById('liDscr'), { type: 'bar', data: { labels: H.map(function (x) { return x[0]; }), datasets: [
        { label: 'CFADS', data: H.map(function (x) { return x[1]; }), backgroundColor: '#0FA968', yAxisID: 'y' },
        { label: 'Repayments paid', data: H.map(function (x) { return x[2]; }), backgroundColor: '#1E2FE0', yAxisID: 'y' },
        { type: 'line', label: 'DSCR', data: H.map(function (x) { return x[2] > 0 ? Math.round(x[1] / x[2] * 100) / 100 : null; }), borderColor: '#F59E0B', backgroundColor: '#F59E0B', yAxisID: 'y1', spanGaps: true }] },
        options: { maintainAspectRatio: false, scales: { y1: { position: 'right', grid: { drawOnChartArea: false } } } } });
    <?php if ($flow && $flow['days']) { ?>
    var F = <?= json_encode($flow['days']); ?>;
    new Chart(document.getElementById('liFlow'), { type: 'line', data: { labels: Object.keys(F), datasets: [{ label: 'Cash', data: Object.values(F), borderColor: '#7C5CE0', pointRadius: 0, fill: false }] }, options: { maintainAspectRatio: false } });
    <?php } ?>
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>