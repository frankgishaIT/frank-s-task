<?php
/**
 * NEW FILE: modules/loan_intel/index.php — Company Loans & Investment Intelligence DASHBOARD (spec 10).
 * Key insights first; every section links to the page with the details and the formulas.
 */
require __DIR__ . '/common.php';
li_require($conn, 'view');

$co = li_company($conn);
$minD = li_num($conn, 'min_dscr');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01', strtotime('-11 months'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$fLoan = (int) ($_GET['loan'] ?? 0); $fLender = trim((string) ($_GET['lender'] ?? '')); $fBranch = (int) ($_GET['branch'] ?? 0);
$fDept = (int) ($_GET['department'] ?? 0); $fCat = (string) ($_GET['category'] ?? ''); $fStatus = (string) ($_GET['status'] ?? '');

// Loans (filtered)
$w = ["l.status NOT IN ('Deleted')"];
if ($fLoan) { $w[] = 'l.id = ' . $fLoan; }
if ($fLender !== '') { $w[] = "l.lender = '" . mysqli_real_escape_string($conn, $fLender) . "'"; }
if ($fStatus !== '') { $w[] = "l.status = '" . mysqli_real_escape_string($conn, $fStatus) . "'"; }
if ($fBranch && br_enabled($conn)) { $w[] = 'COALESCE(l.branch_id, ' . br_main_id($conn) . ') = ' . $fBranch; }
if ($fDept) { $w[] = 'd.department_id = ' . $fDept; }
$w[] = li_loan_scope_sql($conn);
$loans = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.*, d.processing_fee, d.other_charges, d.rate_type, d.department_id FROM loans l LEFT JOIN li_loan_details d ON d.loan_id = l.id WHERE ' . implode(' AND ', $w) . ' ORDER BY l.loan_start_date DESC'), MYSQLI_ASSOC);
$ids = array_map('intval', array_column($loans, 'id')) ?: [0];
$in = implode(',', $ids);
$open = array_filter($loans, function ($l) { return in_array($l['status'], ['Active', 'Defaulted'], true); });
$sum = function ($rows, $f) { return array_sum(array_map($f, $rows)); };
$borrowed = $sum($loans, function ($l) { return in_array($l['status'], ['Cancelled'], true) ? 0 : (float) $l['loan_amount']; });
$outstanding = $sum($open, function ($l) { return max(0, (float) $l['loan_amount'] - (float) $l['principal_repaid']); });
$interestPaid = $sum($loans, function ($l) { return (float) $l['interest_paid']; });
$feesAll = $sum($loans, function ($l) { return (float) $l['processing_fee'] + (float) $l['other_charges']; });
$f = mysqli_real_escape_string($conn, $from); $t = mysqli_real_escape_string($conn, $to);
$periodPaid = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(principal_portion), 0) p, COALESCE(SUM(interest_portion), 0) i FROM loan_payments WHERE loan_id IN ($in) AND payment_date BETWEEN '$f' AND '$t'"));
$next12 = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount_due - paid_amount), 0) FROM loan_schedule WHERE loan_id IN ($in) AND status <> 'Paid' AND due_date < '" . date('Y-m-01', strtotime('first day of +12 month')) . "'"))[0]; // same 12 months as the DSCR (overdue included)
$upcoming = mysqli_fetch_all(mysqli_query($conn, "SELECT s.*, l.lender, l.id lid FROM loan_schedule s JOIN loans l ON l.id = s.loan_id WHERE s.loan_id IN ($in) AND l.status IN ('Active', 'Defaulted')
    AND s.status <> 'Paid' AND s.due_date <= DATE_ADD(CURDATE(), INTERVAL 60 DAY) ORDER BY s.due_date LIMIT 12"), MYSQLI_ASSOC);

// Investments (filtered by category / branch / department)
$pw = [li_proposal_scope_sql($conn, 'p')];
if ($fCat !== '') { $pw[] = "p.category = '" . mysqli_real_escape_string($conn, $fCat) . "'"; }
if ($fBranch) { $pw[] = 'p.branch_id = ' . $fBranch; }
if ($fDept) { $pw[] = 'p.department_id = ' . $fDept; }
$pWhere = implode(' AND ', $pw);
$invested = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(i.amount), 0) FROM li_investments i JOIN li_allocations a ON a.id = i.allocation_id JOIN li_proposals p ON p.id = a.proposal_id
    WHERE a.loan_id IN ($in) AND a.status = 'Approved' AND $pWhere"))[0];
$expected = 0.0; $actual = 0.0; $actualKnown = false; $unalloc = 0.0; $perf = [];
foreach ($open + array_filter($loans, function ($l) { return $l['status'] === 'Fully Paid'; }) as $l) {
    $pf = li_loan_performance($conn, $l);
    $expected += $pf['expected_profit']; if ($pf['actual_profit'] !== null) { $actual += $pf['actual_profit']; $actualKnown = true; }
    $unalloc += $pf['unallocated'];
    if ($pf['allocs']) { $perf[$pf['assessment']] = ($perf[$pf['assessment']] ?? 0) + 1; }
}
$props = mysqli_fetch_all(mysqli_query($conn, "SELECT p.* FROM li_proposals p WHERE $pWhere AND p.status IN ('Draft', 'Submitted', 'Approved')"), MYSQLI_ASSOC);
$ranked = [];
foreach ($props as $p) { $ev = li_proposal_eval($conn, $p, $co['confidence']); if (!$ev['missing']) { $ranked[] = $p + ['ev' => $ev]; } }
usort($ranked, function ($a, $b) { return $b['ev']['score'] <=> $a['ev']['score']; });
$needs = li_needs($conn, $co);
$awaiting = mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, p.name pname FROM li_recommendations r LEFT JOIN li_proposals p ON p.id = r.proposal_id WHERE r.status = 'Awaiting review' ORDER BY r.id DESC LIMIT 5"), MYSQLI_ASSOC);
$lastRec = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM li_recommendations WHERE status <> 'Rejected' ORDER BY id DESC LIMIT 1"));
$room = $co['cfads'] > 0 ? max(0, $co['cfads'] / $minD - $co['debt_month']) : 0.0;
$affRef = li_affordable_principal($co['cfads'], $co['debt_month'], $minD, 18, 24, 'Monthly', 0, 'emi');
$highRisk = [];
foreach ($open as $l) {
    $od = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM loan_schedule WHERE loan_id = ' . (int) $l['id'] . " AND status <> 'Paid' AND due_date < CURDATE()"))[0];
    $why = [];
    if ($l['status'] === 'Defaulted') { $why[] = 'Defaulted'; }
    if ($od) { $why[] = $od . ' overdue instalment(s)'; }
    if (($l['rate_type'] ?? '') === 'Variable') { $why[] = 'Variable rate'; }
    if ($why) { $highRisk[] = [$l, implode(', ', $why)]; }
}
if ($co['dscr_forward'] !== null && $co['dscr_forward'] < $minD) { $highRisk[] = [null, 'Forward DSCR ' . $co['dscr_forward'] . ' is below the minimum ' . $minD]; }

// Chart: CFADS vs debt service (12 months back actual, 12 forward scheduled)
$paidM = li_debt_paid_by_month($conn);
$labels = []; $cf = []; $ds = []; $kind = [];
foreach (array_slice($co['history'], -12) as $h) { $labels[] = date('M y', strtotime($h['month'] . '-01')); $cf[] = $h['cfads']; $ds[] = $paidM[$h['month']] ?? 0; $kind[] = 'Actual'; }
for ($m = 1; $m <= 12; $m++) { $labels[] = date('M y', strtotime('first day of +' . ($m - 1) . ' month')); $cf[] = $co['enough_history'] ? $co['cfads'] : null; $ds[] = round($co['existing'][$m] ?? 0, 2); $kind[] = 'Plan'; }
$byLender = [];
foreach ($open as $l) { $byLender[$l['lender']] = ($byLender[$l['lender']] ?? 0) + max(0, (float) $l['loan_amount'] - (float) $l['principal_repaid']); }

$summary = [['Total borrowed', li_rwf($borrowed)], ['Outstanding principal', li_rwf($outstanding)], ['Interest paid', li_rwf($interestPaid)], ['Loan costs (interest + fees)', li_rwf($interestPaid + $feesAll)],
    ['Monthly debt obligations', li_rwf($next12 / 12)], ['Available cash', li_rwf($co['cash'])], ['DSCR (last 12 months)', $co['dscr_hist'] ?? '—'], ['DSCR (next 12 months)', $co['dscr_forward'] ?? '—'],
    ['Invested from borrowed funds', li_rwf($invested)], ['Expected returns to date', li_rwf($expected)], ['Actual returns to date', $actualKnown ? li_rwf($actual) : '—'],
    ['Unallocated borrowed funds', li_rwf($unalloc)], ['Affordable repayment capacity / month', li_rwf($room)], ['Data confidence', $co['confidence']]];
if (($_GET['export'] ?? '') === 'pdf') {
    li_audit($conn, 'export', 'dashboard', null, null, ['from' => $from, 'to' => $to], 'Dashboard PDF');
    an_render_pdf('Company Loans & Investment Intelligence', [['Period', $from . ' to ' . $to], ['Prepared', date('d M Y H:i')]], $summary, an_posted_charts(), [
        ['title' => 'Company loans', 'columns' => ['Lender', 'Type', 'Principal', 'Rate %', 'Start', 'Outstanding principal', 'Status'],
         'rows' => array_map(function ($l) { return [$l['lender'], $l['loan_type'], number_format($l['loan_amount'], 0), $l['interest_rate'], $l['loan_start_date'], number_format(max(0, $l['loan_amount'] - $l['principal_repaid']), 0), $l['status']]; }, $loans)],
        ['title' => 'Financing needs detected', 'columns' => ['Need', 'Classification', 'Funding (RWF)', 'Funding gap (RWF)', 'Basis'],
         'rows' => array_map(function ($n) { return [$n['title'], $n['class'], $n['funding'] === null ? 'to be entered' : number_format($n['funding'], 0), $n['gap'] === null ? '—' : number_format($n['gap'], 0), $n['basis']]; }, $needs)],
    ], 'company-loans-intelligence-' . date('Ymd') . '.pdf');
}
$lenders = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT lender FROM loans WHERE status <> 'Deleted' ORDER BY lender"), MYSQLI_ASSOC), 'lender');
$allLoans = mysqli_fetch_all(mysqli_query($conn, "SELECT id, lender, loan_amount FROM loans WHERE status <> 'Deleted' ORDER BY id DESC"), MYSQLI_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'index.php', 'Company Loans & Investment Intelligence', 'All recommendations are advisory: only authorized management approves borrowing or investments.');
?>
<form class="row g-2 align-items-end mb-3" method="GET">
    <div class="col-6 col-md-2"><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= li_e($from); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= li_e($to); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Loan</label><select name="loan" class="form-select form-select-sm"><option value="0">All</option><?php foreach ($allLoans as $l) { ?><option value="<?= (int) $l['id']; ?>" <?= $fLoan === (int) $l['id'] ? 'selected' : ''; ?>>#<?= (int) $l['id']; ?> <?= li_e($l['lender']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Lender</label><select name="lender" class="form-select form-select-sm"><option value="">All</option><?php foreach ($lenders as $x) { ?><option <?= $fLender === $x ? 'selected' : ''; ?>><?= li_e($x); ?></option><?php } ?></select></div>
    <?php if ($br = li_branches($conn)) { ?><div class="col-6 col-md-2"><label class="form-label small mb-0">Branch</label><select name="branch" class="form-select form-select-sm"><option value="0">All</option><?php foreach ($br as $id => $n) { ?><option value="<?= $id; ?>" <?= $fBranch === $id ? 'selected' : ''; ?>><?= li_e($n); ?></option><?php } ?></select></div><?php } ?>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Department</label><select name="department" class="form-select form-select-sm"><option value="0">All</option><?php foreach (li_departments($conn) as $id => $n) { ?><option value="<?= $id; ?>" <?= $fDept === $id ? 'selected' : ''; ?>><?= li_e($n); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Investment category</label><select name="category" class="form-select form-select-sm"><option value="">All</option><?php foreach (LI_CATEGORIES as $c) { ?><option <?= $fCat === $c ? 'selected' : ''; ?>><?= li_e($c); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Loan status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach (['Active', 'Defaulted', 'Fully Paid', 'Cancelled'] as $s) { ?><option <?= $fStatus === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-1"><button class="btn btn-sm btn-primary w-100">Filter</button></div>
    <div class="col-6 col-md-1"><a href="index.php" class="btn btn-sm btn-light border w-100">Reset</a></div>
</form>
<div class="d-flex justify-content-end mb-2"><?php an_pdf_button('Dashboard PDF'); ?></div>

<?php if (!$co['enough_history'] || $co['confidence'] === 'Low') { ?>
<div class="alert alert-warning py-2 small"><strong>MORE DATA REQUIRED for reliable borrowing advice.</strong> <?= (int) $co['active_months']; ?> month(s) with recorded sales (at least <?= (int) $co['min_history']; ?> needed). See the data checks on the Financial Risk page.</div>
<?php } ?>

<div class="li-sec">Financing overview</div>
<div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
    <?= li_kpi('Total borrowed capital', li_rwf($borrowed), li_tag('Actual'), count($loans) . ' loan(s)'); ?>
    <?= li_kpi('Outstanding principal', li_rwf($outstanding), li_tag('Actual'), count($open) . ' open loan(s)', '#E24B4A'); ?>
    <?= li_kpi('Interest paid', li_rwf($interestPaid), li_tag('Actual'), 'In the period: ' . li_rwf($periodPaid['i'])); ?>
    <?= li_kpi('Total loan costs', li_rwf($interestPaid + $feesAll), li_tag('Actual'), 'Interest + fees and charges'); ?>
    <?= li_kpi('Monthly debt obligations', li_rwf($next12 / 12), li_tag('Actual'), 'Average of the next 12 months (schedules)'); ?>
    <?= li_kpi('Available cash', li_rwf($co['cash']), li_tag('Actual'), 'Free for investment: ' . li_rwf($co['internal'])); ?>
    <?= li_kpi('Debt service coverage', ($co['dscr_hist'] ?? '—') . ' / ' . ($co['dscr_forward'] ?? '—'), li_tag('Estimated'), 'Last 12 months / next 12 months · minimum ' . $minD,
        ($co['dscr_forward'] !== null && $co['dscr_forward'] < $minD) ? '#E24B4A' : '#0FA968'); ?>
    <?= li_kpi('Upcoming repayments', li_rwf($co['due_30']), li_tag('Actual'), 'Due in 30 days' . ($co['overdue_n'] ? ' · <span class="text-danger">' . $co['overdue_n'] . ' overdue</span>' : '')); ?>
</div>

<div class="li-sec">Investment overview</div>
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-6 g-2 mb-3">
    <?= li_kpi('Invested from borrowed funds', li_rwf($invested), li_tag('Actual')); ?>
    <?= li_kpi('Expected returns to date', li_rwf($expected), li_tag('Estimated'), 'Operating profit planned in the proposals'); ?>
    <?= li_kpi('Actual returns to date', $actualKnown ? li_rwf($actual) : '—', li_tag('Actual'), $actualKnown ? '' : 'Not yet measurable'); ?>
    <?= li_kpi('Investment performance', $perf ? implode('<br>', array_map(function ($k, $v) { return '<span class="small">' . li_status_badge($k) . ' ' . $v . '</span>'; }, array_keys($perf), $perf)) : '—', ''); ?>
    <?= li_kpi('Unallocated borrowed funds', li_rwf($unalloc), li_tag('Actual'), 'Received but not given to an approved purpose', $unalloc > 0 ? '#B7791F' : ''); ?>
    <?= li_kpi('Opportunities identified', (string) (count($needs) + count($props)), '', count($needs) . ' detected · ' . count($props) . ' proposal(s) open'); ?>
</div>

<div class="li-sec">Decision support</div>
<div class="row g-3 mb-3">
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Current financing needs <a href="needs.php" class="small float-end">Details</a></h6>
        <?php foreach (array_slice($needs, 0, 5) as $n) { ?><div class="border-top py-1 small"><strong><?= li_e($n['title']); ?></strong><div class="text-muted"><?= li_e($n['class']); ?><?= $n['gap'] ? ' · gap ' . li_rwf($n['gap']) : ''; ?></div></div><?php } ?>
        <?php if (!$needs) { ?><div class="text-muted small">No financing need detected in the records.</div><?php } ?>
    </div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Recommended financing &amp; capacity</h6>
        <div class="small">Latest recommendation: <?= $lastRec ? li_decision_badge($lastRec['decision']) . ' ' . li_rwf($lastRec['amount']) . ' <a href="recommendations.php?id=' . (int) $lastRec['id'] . '">open</a>' : '<span class="text-muted">none yet — generate one from a proposal</span>'; ?></div>
        <div class="small mt-2">Affordable extra repayment: <strong><?= li_rwf($room); ?></strong> a month <?= li_tag('Estimated'); ?></div>
        <div class="small text-muted">CFADS <?= li_rwf($co['cfads']); ?> / minimum DSCR <?= $minD; ?> - existing repayments <?= li_rwf($co['debt_month']); ?></div>
        <div class="small mt-1">≈ <strong><?= li_rwf($affRef); ?></strong> of new borrowing at 18% over 24 months (reference terms; <a href="simulate.php">simulate real offers</a>).</div>
    </div></div></div>
    <div class="col-lg-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Best-ranked investment opportunities <a href="proposals.php?view=compare" class="small float-end">Compare</a></h6>
        <?php foreach (array_slice($ranked, 0, 4) as $i => $p) { ?><div class="border-top py-1 small d-flex justify-content-between"><span><?= $i + 1; ?>. <a href="proposal.php?id=<?= (int) $p['id']; ?>"><?= li_e($p['name']); ?></a> <span class="text-muted"><?= li_e($p['status']); ?></span></span><span>score <strong><?= $p['ev']['score']; ?></strong> · ROI <?= li_pct($p['ev']['m']['roi']); ?></span></div><?php } ?>
        <?php if (!$ranked) { ?><div class="text-muted small">No complete proposal yet. <a href="proposal_edit.php">Create one</a>.</div><?php } ?>
    </div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>High-risk financial commitments</h6>
        <?php foreach ($highRisk as [$l, $why]) { ?><div class="border-top py-1 small text-danger"><?= $l ? '<a href="../loans/view.php?id=' . (int) $l['id'] . '">#' . (int) $l['id'] . ' ' . li_e($l['lender']) . '</a>: ' : ''; ?><?= li_e($why); ?></div><?php } ?>
        <?php if (!$highRisk) { ?><div class="text-muted small">None.</div><?php } ?>
    </div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Recommendations awaiting management review <a href="recommendations.php" class="small float-end">All</a></h6>
        <?php foreach ($awaiting as $r) { ?><div class="border-top py-1 small"><a href="recommendations.php?id=<?= (int) $r['id']; ?>">#<?= (int) $r['id']; ?></a> <?= li_decision_badge($r['decision']); ?> <?= li_rwf($r['amount']); ?> · <?= li_e($r['pname'] ?: 'Loan scenario'); ?> <span class="text-muted"><?= li_e($r['created_at']); ?></span></div><?php } ?>
        <?php if (!$awaiting) { ?><div class="text-muted small">None.</div><?php } ?>
    </div></div></div>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-8"><?php an_chart_card('liCf', 'Cash flow available for debt service vs debt service', 'Left: actual (last 12 months). Right: scheduled repayments and the average CFADS (no growth assumed).', 260); ?></div>
    <div class="col-lg-4"><?php an_chart_card('liLender', 'Outstanding principal by lender', '', 260); ?></div>
</div>

<div class="card border-0 shadow-sm"><div class="card-body">
    <h6>Upcoming repayments (60 days)</h6>
    <div class="table-responsive"><table class="table table-sm small mb-0"><tr><th>Due</th><th>Loan</th><th>Instalment</th><th class="text-end">Principal</th><th class="text-end">Interest</th><th class="text-end">Still due</th></tr>
    <?php foreach ($upcoming as $u) { ?><tr class="<?= $u['due_date'] < date('Y-m-d') ? 'table-danger' : ''; ?>"><td><?= li_e($u['due_date']); ?></td><td><a href="../loans/view.php?id=<?= (int) $u['lid']; ?>">#<?= (int) $u['lid']; ?> <?= li_e($u['lender']); ?></a></td><td><?= (int) $u['installment_no']; ?></td>
        <td class="text-end"><?= li_n($u['principal_due']); ?></td><td class="text-end"><?= li_n($u['interest_due']); ?></td><td class="text-end fw-semibold"><?= li_n($u['amount_due'] - $u['paid_amount']); ?></td></tr><?php } ?>
    <?php if (!$upcoming) { ?><tr><td colspan="6" class="text-muted">Nothing due in the next 60 days.</td></tr><?php } ?></table></div>
</div></div>

<?php an_js(); ?>
<script>
(function () {
    var L = <?= json_encode($labels); ?>, K = <?= json_encode($kind); ?>;
    new Chart(document.getElementById('liCf'), { type: 'bar', data: { labels: L, datasets: [
        { type: 'line', label: 'CFADS', data: <?= json_encode($cf); ?>, borderColor: '#0FA968', backgroundColor: '#0FA968', tension: .25, spanGaps: true,
          segment: { borderDash: function (c) { return K[c.p1DataIndex] === 'Plan' ? [5, 4] : undefined; } } },
        { label: 'Debt service', data: <?= json_encode($ds); ?>, backgroundColor: L.map(function (x, i) { return K[i] === 'Plan' ? 'rgba(30,47,224,.35)' : '#1E2FE0'; }) }] },
        options: { maintainAspectRatio: false, plugins: { tooltip: { callbacks: { label: function (c) { return c.dataset.label + ' (' + (K[c.dataIndex] === 'Plan' ? 'plan' : 'actual') + '): RWF ' + Math.round(c.raw || 0).toLocaleString(); } } } } } });
    var B = <?= json_encode($byLender); ?>;
    new Chart(document.getElementById('liLender'), { type: 'doughnut', data: { labels: Object.keys(B), datasets: [{ data: Object.values(B), backgroundColor: ['#1E2FE0', '#0FA968', '#7C5CE0', '#06B6D4', '#F59E0B', '#94A3B8'] }] },
        options: { maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } } });
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>