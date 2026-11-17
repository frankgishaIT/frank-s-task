<?php
/**
 * NEW FILE: modules/loan_intel/performance.php — LOAN PERFORMANCE & INVESTMENT IMPACT TRACKING (spec 9).
 * For every company loan: money approved / received / costs, where it went (approved purposes), what was invested,
 * repayments, and expected vs actual results of the investments it financed.
 */
require __DIR__ . '/common.php';
li_require($conn, 'view');
$w = "l.status NOT IN ('Deleted', 'Cancelled') AND " . li_loan_scope_sql($conn);
$loans = mysqli_fetch_all(mysqli_query($conn, "SELECT l.* FROM loans l WHERE $w ORDER BY l.loan_start_date DESC"), MYSQLI_ASSOC);
$rows = [];
foreach ($loans as $l) { $rows[(int) $l['id']] = ['loan' => $l, 'pf' => li_loan_performance($conn, $l)]; }
$head = ['Loan', 'Lender', 'Approved', 'Received', 'Loan costs', 'Allocated', 'Invested', 'Unallocated', 'Expected profit to date', 'Actual profit to date', 'Expected ROI %', 'Actual ROI %',
         'Principal repaid', 'Interest paid', 'Outstanding principal', 'Overdue instalments', 'Assessment'];
$csv = array_map(function ($r) { $l = $r['loan']; $p = $r['pf']; return ['#' . $l['id'], $l['lender'], $p['approved'], $p['received'], $p['costs'], $p['allocated'], $p['invested'], $p['unallocated'], $p['expected_profit'],
    $p['actual_profit'], $p['expected_roi'], $p['actual_roi'], $p['principal_repaid'], $p['interest_paid'], $p['outstanding'], $p['overdue_n'], $p['assessment']]; }, $rows);
if (($_GET['export'] ?? '') === 'csv') { li_audit($conn, 'export', 'performance', null, null, null, 'Loan performance CSV'); li_csv('loan-performance-' . date('Ymd') . '.csv', $head, array_values($csv)); }
if (($_GET['export'] ?? '') === 'pdf') { li_audit($conn, 'export', 'performance', null, null, null, 'Loan performance PDF');
    an_render_pdf('Loan Performance & Investment Impact', [['Prepared', date('d M Y H:i')]], [], [], [['title' => 'Company loans', 'columns' => $head, 'rows' => array_values($csv)]], 'loan-performance-' . date('Ymd') . '.pdf'); }
$sel = (int) ($_GET['loan'] ?? 0);
$one = $rows[$sel] ?? null;
$plan = [];
if ($one) {
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT DATE_FORMAT(due_date, '%Y-%m') ym, SUM(amount_due) v FROM loan_schedule WHERE loan_id = $sel GROUP BY ym"), MYSQLI_ASSOC) as $r) { $plan[$r['ym']]['due'] = (float) $r['v']; }
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT DATE_FORMAT(payment_date, '%Y-%m') ym, SUM(amount) v FROM loan_payments WHERE loan_id = $sel GROUP BY ym"), MYSQLI_ASSOC) as $r) { $plan[$r['ym']]['paid'] = (float) $r['v']; }
    ksort($plan);
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'performance.php', 'Loan Performance & Investment Impact');
?>
<div class="d-flex justify-content-between mb-2"><div class="small text-muted">Did the borrowed money create measurable value? Expected = the approved proposals' plan for the months since the money was invested. Actual = results entered by finance or measured from sales.</div>
    <div class="d-flex gap-2"><a href="?export=csv" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a><a href="?export=pdf" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf"></i> PDF</a></div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3">Loan</th><th class="text-end">Received</th><th class="text-end">Costs</th><th class="text-end">Allocated / invested</th><th class="text-end">Unallocated</th><th class="text-end">Expected vs actual profit</th>
        <th class="text-end">ROI exp. / act.</th><th class="text-end">Repaid (principal / interest)</th><th class="text-end">Outstanding</th><th>Overdue</th><th>Assessment</th></tr>
    <?php foreach ($rows as $id => $r) { $l = $r['loan']; $p = $r['pf']; ?>
    <tr class="<?= $sel === $id ? 'table-primary' : ''; ?>" style="cursor:pointer" onclick="location.href='?loan=<?= $id; ?>'">
        <td class="ps-3"><a href="?loan=<?= $id; ?>">#<?= $id; ?> <?= li_e($l['lender']); ?></a><div class="small text-muted"><?= li_e($l['loan_start_date'] . ' · ' . $l['status']); ?></div></td>
        <td class="text-end"><?= li_n($p['received']); ?><div class="small text-muted">of <?= li_n($p['approved']); ?></div></td><td class="text-end"><?= li_n($p['costs']); ?></td>
        <td class="text-end"><?= li_n($p['allocated']); ?><div class="small text-muted"><?= li_n($p['invested']); ?> invested</div></td><td class="text-end <?= $p['unallocated'] > 0 ? 'text-warning fw-bold' : ''; ?>"><?= li_n($p['unallocated']); ?></td>
        <td class="text-end"><?= li_n($p['expected_profit']); ?><div class="small <?= $p['actual_profit'] !== null && $p['actual_profit'] < $p['expected_profit'] ? 'text-danger' : 'text-success'; ?>"><?= li_n($p['actual_profit']); ?></div></td>
        <td class="text-end"><?= li_pct($p['expected_roi']); ?> / <?= li_pct($p['actual_roi']); ?></td><td class="text-end"><?= li_n($p['principal_repaid']); ?> / <?= li_n($p['interest_paid']); ?></td>
        <td class="text-end"><?= li_n($p['outstanding']); ?></td><td><?= $p['overdue_n'] ? '<span class="text-danger">' . $p['overdue_n'] . ' (' . li_n($p['overdue_v']) . ')</span>' : '0'; ?></td><td><?= li_status_badge($p['allocs'] ? $p['assessment'] : ($l['status'] === 'Fully Paid' ? 'Completed' : 'Not yet measurable')); ?></td></tr>
    <?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="11" class="text-center text-muted py-4">No company loan.</td></tr><?php } ?>
</table></div></div></div>

<?php if ($one) { $l = $one['loan']; $p = $one['pf']; ?>
<div class="row g-3">
    <div class="col-xl-8">
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <div class="d-flex justify-content-between"><h6>#<?= $sel; ?> <?= li_e($l['lender']); ?> · <?= li_status_badge($p['allocs'] ? $p['assessment'] : 'Not yet measurable'); ?></h6>
                <span><?php if (li_can($conn, 'finance')) { ?><a href="loan_details.php?id=<?= $sel; ?>" class="btn btn-sm btn-outline-primary">Loan details &amp; fees</a><?php } ?> <?php if (current_user_role() === 'Admin') { ?><a href="../loans/view.php?id=<?= $sel; ?>" class="btn btn-sm btn-light border">Repayments</a><?php } ?></span></div>
            <div class="row row-cols-2 row-cols-md-4 g-2 small mb-2">
                <div class="col"><div class="text-muted">Approved loan amount</div><strong><?= li_rwf($p['approved']); ?></strong></div><div class="col"><div class="text-muted">Actual amount received</div><strong><?= li_rwf($p['received']); ?></strong></div>
                <div class="col"><div class="text-muted">Actual loan costs</div><strong><?= li_rwf($p['costs']); ?></strong><div class="text-muted">interest paid + fees</div></div><div class="col"><div class="text-muted">Remaining unallocated</div><strong><?= li_rwf($p['unallocated']); ?></strong></div>
            </div>
            <h6 class="small fw-bold mt-3">Approved purposes (investment allocation)</h6>
            <table class="table table-sm small"><tr><th>Investment</th><th class="text-end">Allocated</th><th class="text-end">Invested</th><th class="text-end">Months running</th><th class="text-end">Expected profit to date</th><th class="text-end">Actual profit to date</th><th>Status</th></tr>
            <?php foreach ($p['allocs'] as $a) { ?><tr><td><a href="proposal.php?id=<?= (int) $a['proposal_id']; ?>"><?= li_e($a['code'] . ' ' . $a['name']); ?></a></td><td class="text-end"><?= li_n($a['amount']); ?> <?= li_status_badge($a['status']); ?></td><td class="text-end"><?= li_n($a['invested']); ?></td>
                <td class="text-end"><?= isset($a['actual']) ? (int) $a['actual']['months'] : '—'; ?></td><td class="text-end"><?= isset($a['expected']) ? li_n($a['expected']['profit'] * $a['share']) : '—'; ?></td>
                <td class="text-end"><?= isset($a['actual']) && $a['actual']['profit'] !== null ? li_n($a['actual']['profit'] * $a['share']) : '—'; ?></td><td><?= li_e($a['pstatus']); ?></td></tr>
                <?php if (!empty($a['actual']['rows'])) { foreach ($a['actual']['rows'] as $ar) { if ($ar['note']) { ?><tr class="text-muted"><td colspan="7">&nbsp;&nbsp;<?= date('M Y', strtotime($ar['period'])); ?>: <?= li_e($ar['note']); ?></td></tr><?php } } } ?>
            <?php } ?>
            <?php if (!$p['allocs']) { ?><tr><td colspan="7" class="text-muted">This loan is not linked to any approved investment purpose yet. Link it from an approved proposal (Investment Opportunities).</td></tr><?php } ?></table>
            <h6 class="small fw-bold mt-3">Expected vs actual</h6>
            <table class="table table-sm small mb-0"><tr><th></th><th class="text-end">Expected</th><th class="text-end">Actual</th></tr>
                <tr><td>Revenue to date</td><td class="text-end"><?= li_n($p['expected_revenue']); ?></td><td class="text-end"><?= li_n($p['actual_revenue']); ?></td></tr>
                <tr><td>Operating profit to date</td><td class="text-end"><?= li_n($p['expected_profit']); ?></td><td class="text-end"><?= li_n($p['actual_profit']); ?></td></tr>
                <tr><td>ROI</td><td class="text-end"><?= li_pct($p['expected_roi']); ?> <span class="text-muted">(full period)</span></td><td class="text-end"><?= li_pct($p['actual_roi']); ?> <span class="text-muted">(to date, after loan costs)</span></td></tr>
                <tr><td>Repayments to date</td><td class="text-end"><?= li_n($p['due_to_date']); ?> due</td><td class="text-end"><?= li_n($p['paid_to_date']); ?> paid</td></tr>
                <tr><td>Overdue instalments</td><td></td><td class="text-end <?= $p['overdue_n'] ? 'text-danger fw-bold' : ''; ?>"><?= $p['overdue_n']; ?> (<?= li_n($p['overdue_v']); ?>)</td></tr></table>
            <div class="small text-muted mt-2">Assessment: Above Expectations ≥ 110% of the expected profit; as expected 90-110%; Below 60-90%; Underperforming < 60%; Financially At Risk = instalments overdue; Completed = loan fully paid.</div>
        </div></div>
    </div>
    <div class="col-xl-4"><?php an_chart_card('liRep', 'Repayment schedule vs actual repayments', 'Per month', 300); ?></div>
</div>
<?php an_js(); ?>
<script>
var P = <?= json_encode($plan); ?>, K = Object.keys(P);
new Chart(document.getElementById('liRep'), { type: 'bar', data: { labels: K, datasets: [{ label: 'Due (schedule)', data: K.map(function (k) { return P[k].due || 0; }), backgroundColor: 'rgba(30,47,224,.35)' },
    { label: 'Paid', data: K.map(function (k) { return P[k].paid || 0; }), backgroundColor: '#0FA968' }] }, options: { maintainAspectRatio: false } });
</script>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>