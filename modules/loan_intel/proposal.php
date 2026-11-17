<?php
/**
 * NEW FILE: modules/loan_intel/proposal.php — one INVESTMENT PROPOSAL: figures and formulas, optimistic / base / pessimistic,
 * management decision, link to a company loan (allocation), money actually invested, actual results vs expected.
 * Nothing here creates a payment or a transaction automatically: approvals are decisions, money moves in the existing modules.
 */
require __DIR__ . '/common.php';
if (!li_can($conn, 'view') && !li_can($conn, 'propose')) { li_deny(); }
$id = (int) ($_GET['id'] ?? 0);
$p = li_proposal($conn, $id);
if (!$p || !li_proposal_visible($conn, $p)) { li_deny(); }
$me = (int) current_user_id();
$isAdmin = current_user_role() === 'Admin';
$back = 'proposal.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!li_csrf_ok()) { li_go($back, 'error', 'The page had expired. Please try again.'); }
    $do = $_POST['do'] ?? '';
    $note = trim((string) ($_POST['note'] ?? ''));
    if ($do === 'submit' && li_can($conn, 'propose') && in_array($p['status'], ['Draft', 'Rejected'], true)) {
        mysqli_query($conn, "UPDATE li_proposals SET status = 'Submitted', submitted_at = NOW() WHERE id = $id");
        li_audit($conn, 'proposal_submit', 'proposal', $id, ['status' => $p['status']], ['status' => 'Submitted'], $note ?: null);
        li_go($back, 'success', 'Submitted for management review.');
    }
    if ($do === 'decide' && li_can($conn, 'approve') && $p['status'] === 'Submitted') {
        $dec = ($_POST['decision'] ?? '') === 'Approved' ? 'Approved' : 'Rejected';
        $own = (int) $p['created_by'] === $me;
        if ($own && !$isAdmin) { li_go($back, 'error', 'You cannot decide on your own proposal.'); }
        if (($own || $dec === 'Rejected') && mb_strlen($note) < 3) { li_go($back, 'error', 'Please write the reason for the decision.'); }
        $fund = $dec === 'Approved' ? max(0, (float) ($_POST['approved_funding'] ?? 0)) : null;
        $s = mysqli_prepare($conn, "UPDATE li_proposals SET status = ?, approved_funding = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ? AND status = 'Submitted'");
        mysqli_stmt_bind_param($s, 'sdisi', $dec, $fund, $me, $note, $id);
        mysqli_stmt_execute($s);
        li_audit($conn, 'proposal_' . strtolower($dec), 'proposal', $id, ['status' => 'Submitted'], ['status' => $dec, 'approved_funding' => $fund], $note);
        li_go($back, 'success', 'Proposal ' . strtolower($dec) . '. This approves the investment purpose only; borrowing is approved separately.');
    }
    if ($do === 'cancel' && ((int) $p['created_by'] === $me || li_can($conn, 'approve')) && in_array($p['status'], ['Draft', 'Submitted', 'Rejected', 'Approved'], true)) {
        mysqli_query($conn, "UPDATE li_proposals SET status = 'Cancelled' WHERE id = $id");
        li_audit($conn, 'proposal_cancel', 'proposal', $id, ['status' => $p['status']], ['status' => 'Cancelled'], $note ?: null);
        li_go($back, 'success', 'Proposal cancelled.');
    }
    if ($do === 'allocate' && li_can($conn, 'finance') && in_array($p['status'], ['Approved', 'Funded'], true)) {
        $loanId = (int) ($_POST['loan_id'] ?? 0); $amt = round((float) ($_POST['amount'] ?? 0), 2);
        $loan = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM loans WHERE id = $loanId AND status IN ('Active', 'Fully Paid', 'Defaulted')"));
        if (!$loan || $amt <= 0) { li_go($back, 'error', 'Choose the loan and the amount.'); }
        $pf = li_loan_performance($conn, $loan);
        $pendingAlloc = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) FROM li_allocations WHERE loan_id = $loanId AND status = 'Proposed'"))[0];
        if ($amt > $pf['unallocated'] - $pendingAlloc + 0.01) { li_go($back, 'error', 'Only ' . li_rwf($pf['unallocated'] - $pendingAlloc) . ' of this loan is not yet allocated.'); }
        $s = mysqli_prepare($conn, 'INSERT INTO li_allocations (loan_id, proposal_id, amount, note, created_by) VALUES (?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iidsi', $loanId, $id, $amt, $note, $me);
        mysqli_stmt_execute($s);
        li_audit($conn, 'allocation_propose', 'proposal', $id, null, ['loan_id' => $loanId, 'amount' => $amt], $note ?: null);
        li_go($back, 'success', 'Allocation sent for management approval.');
    }
    if ($do === 'alloc_decide' && li_can($conn, 'approve')) {
        $aid = (int) ($_POST['allocation_id'] ?? 0); $dec = ($_POST['decision'] ?? '') === 'Approved' ? 'Approved' : 'Rejected';
        $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM li_allocations WHERE id = $aid AND proposal_id = $id AND status = 'Proposed'"));
        if (!$a) { li_go($back, 'error', 'Allocation not found.'); }
        if ((int) $a['created_by'] === $me && !$isAdmin) { li_go($back, 'error', 'You cannot approve an allocation you proposed.'); }
        if ($dec === 'Rejected' && mb_strlen($note) < 3) { li_go($back, 'error', 'Please write the reason.'); }
        mysqli_query($conn, "UPDATE li_allocations SET status = '$dec', decided_by = $me, decided_at = NOW() WHERE id = $aid");
        li_audit($conn, 'allocation_' . strtolower($dec), 'proposal', $id, ['allocation' => $aid, 'status' => 'Proposed'], ['status' => $dec, 'amount' => $a['amount'], 'loan_id' => $a['loan_id']], $note ?: null);
        li_go($back, 'success', 'Allocation ' . strtolower($dec) . '.');
    }
    if ($do === 'invest' && li_can($conn, 'finance')) {
        $aid = (int) ($_POST['allocation_id'] ?? 0); $amt = round((float) ($_POST['amount'] ?? 0), 2); $date = (string) ($_POST['invest_date'] ?? '');
        $desc = mb_substr(trim((string) ($_POST['description'] ?? '')), 0, 255); $ref = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 100);
        $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT a.*, (SELECT COALESCE(SUM(amount), 0) FROM li_investments WHERE allocation_id = a.id) inv FROM li_allocations a WHERE a.id = $aid AND a.proposal_id = $id AND a.status = 'Approved'"));
        if (!$a || $amt <= 0 || !strtotime($date) || $date > date('Y-m-d') || $desc === '') { li_go($back, 'error', 'Check the allocation, amount, date (not in the future) and description.'); }
        if ($amt > (float) $a['amount'] - (float) $a['inv'] + 0.01) { li_go($back, 'error', 'More than the approved allocation (' . li_rwf((float) $a['amount'] - (float) $a['inv']) . ' left).'); }
        if ($ref === '') { li_go($back, 'error', 'Give the reference of the record where the money was spent (purchase order, transaction or asset number): every amount must be traceable.'); }
        $s = mysqli_prepare($conn, 'INSERT INTO li_investments (allocation_id, amount, invest_date, description, reference, recorded_by) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'idsssi', $aid, $amt, $date, $desc, $ref, $me);
        mysqli_stmt_execute($s);
        mysqli_query($conn, "UPDATE li_proposals SET status = 'Funded', started_on = COALESCE(started_on, '" . mysqli_real_escape_string($conn, $date) . "') WHERE id = $id AND status IN ('Approved', 'Funded')");
        li_audit($conn, 'investment_record', 'proposal', $id, null, ['allocation' => $aid, 'amount' => $amt, 'date' => $date, 'reference' => $ref], $desc);
        li_go($back, 'success', 'Amount invested recorded (reference ' . $ref . '). The money itself is recorded in its own module (purchase order, asset, transaction); this is the link to the loan.');
    }
    if ($do === 'actual' && li_can($conn, 'finance') && in_array($p['status'], ['Funded', 'Completed'], true)) {
        $per = preg_match('/^\d{4}-\d{2}$/', $_POST['period'] ?? '') ? $_POST['period'] . '-01' : null;
        if (!$per || $per > date('Y-m-01')) { li_go($back, 'error', 'Choose a month that has started.'); }
        $r = round((float) ($_POST['revenue'] ?? 0), 2); $c = round((float) ($_POST['cost_of_sales'] ?? 0), 2); $o = round((float) ($_POST['opex'] ?? 0), 2);
        $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT revenue, cost_of_sales, opex FROM li_actuals WHERE proposal_id = $id AND period = '$per'"));
        $s = mysqli_prepare($conn, 'INSERT INTO li_actuals (proposal_id, period, revenue, cost_of_sales, opex, note, recorded_by) VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE revenue = VALUES(revenue), cost_of_sales = VALUES(cost_of_sales), opex = VALUES(opex), note = VALUES(note), recorded_by = VALUES(recorded_by)');
        mysqli_stmt_bind_param($s, 'isdddsi', $id, $per, $r, $c, $o, $note, $me);
        mysqli_stmt_execute($s);
        li_audit($conn, 'actual_' . ($old ? 'edit' : 'record'), 'proposal', $id, $old, ['period' => $per, 'revenue' => $r, 'cost_of_sales' => $c, 'opex' => $o], $note ?: null);
        li_go($back, 'success', 'Actual results for ' . date('M Y', strtotime($per)) . ' saved.');
    }
    if ($do === 'complete' && li_can($conn, 'approve') && $p['status'] === 'Funded') {
        mysqli_query($conn, "UPDATE li_proposals SET status = 'Completed' WHERE id = $id");
        li_audit($conn, 'proposal_complete', 'proposal', $id, ['status' => 'Funded'], ['status' => 'Completed'], $note ?: null);
        li_go($back, 'success', 'Investment marked as completed.');
    }
    if ($do === 'recommend' && li_can($conn, 'view')) {
        $sid = (int) ($_POST['scenario_id'] ?? 0);
        $sc = $sid ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM li_scenarios WHERE id = $sid")) : null;
        $rec = li_build_recommendation($conn, $p, $sc ?: null);
        $j = json_encode($rec, JSON_UNESCAPED_UNICODE);
        $s = mysqli_prepare($conn, 'INSERT INTO li_recommendations (decision, amount, proposal_id, scenario_id, snapshot, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        $sidN = $sc ? $sid : null; $amt = (float) $rec['amount'];
        mysqli_stmt_bind_param($s, 'sdiisi', $rec['decision'], $amt, $id, $sidN, $j, $me);
        mysqli_stmt_execute($s);
        $rid = (int) mysqli_insert_id($conn);
        li_audit($conn, 'recommendation_create', 'recommendation', $rid, null, ['decision' => $rec['decision'], 'amount' => $amt, 'proposal' => $id]);
        li_go('recommendations.php?id=' . $rid, 'success', 'Recommendation generated. It is advisory and waits for management review.');
    }
    li_go($back, 'error', 'You are not allowed to do this, or the proposal is not in the right status.');
}

$co = li_company($conn);
$ev = li_proposal_eval($conn, $p, $co['confidence']);
$m = $ev['m'];
$disc = li_num($conn, 'discount_rate');
$scen = [];
foreach (li_scenarios($conn) as $k => $adj) { $scen[$k] = ['label' => $adj['label'], 'adj' => $adj, 'm' => li_proposal_metrics(li_proposal_input($p), $disc, $adj)]; }
$allocs = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, l.lender, l.loan_amount, u.names who, (SELECT COALESCE(SUM(amount), 0) FROM li_investments i WHERE i.allocation_id = a.id) invested
    FROM li_allocations a JOIN loans l ON l.id = a.loan_id LEFT JOIN users u ON u.id = a.created_by WHERE a.proposal_id = $id ORDER BY a.id"), MYSQLI_ASSOC);
$invests = mysqli_fetch_all(mysqli_query($conn, "SELECT i.*, u.names who FROM li_investments i JOIN li_allocations a ON a.id = i.allocation_id LEFT JOIN users u ON u.id = i.recorded_by WHERE a.proposal_id = $id ORDER BY i.invest_date"), MYSQLI_ASSOC);
$act = li_proposal_actuals($conn, $p);
$expTo = ['revenue' => 0.0, 'profit' => 0.0];
for ($k = 1; $k <= min($act['months'], $m['horizon']); $k++) { $expTo['revenue'] += $m['monthly'][$k]['revenue']; $expTo['profit'] += $m['monthly'][$k]['net']; }
$investedTotal = array_sum(array_column($invests, 'amount'));
$actRoi = ($act['profit'] !== null && $investedTotal > 0) ? round($act['profit'] / $investedTotal * 100, 1) : null;
$expRoiTo = $m['total_cost'] > 0 ? round($expTo['profit'] / $m['total_cost'] * 100, 1) : null;
$cumPaid = $act['profit'] !== null ? $act['profit'] + 0 : null;
$assessment = li_assess($expTo['profit'] ?: null, $act['profit'], 0, $p['status'] === 'Completed', $act['profit'] !== null && $act['months'] > $m['impl_months']);
$loansOpen = mysqli_fetch_all(mysqli_query($conn, "SELECT id, lender, loan_amount, loan_start_date FROM loans WHERE status IN ('Active', 'Fully Paid', 'Defaulted') ORDER BY id DESC"), MYSQLI_ASSOC);
$scenarios = mysqli_fetch_all(mysqli_query($conn, "SELECT id, name, principal, rate, n FROM li_scenarios WHERE proposal_id = $id OR proposal_id IS NULL ORDER BY id DESC LIMIT 30"), MYSQLI_ASSOC);
$history = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM li_audit a LEFT JOIN users u ON u.id = a.user_id WHERE a.ref_type = 'proposal' AND a.ref_id = $id ORDER BY a.id DESC"), MYSQLI_ASSOC);
$recs = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM li_recommendations WHERE proposal_id = $id ORDER BY id DESC"), MYSQLI_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'proposals.php', ($p['code'] ? $p['code'] . ' · ' : '') . $p['name']);
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <?= li_status_badge($p['status']); ?> <span class="badge bg-light text-dark border"><?= li_e($p['category']); ?></span>
    <span class="small text-muted">by <?= li_e($p['creator']); ?> · <?= li_e($p['created_at']); ?><?= $p['source'] === 'detected' ? ' · detected by MY MOTIVE' : ''; ?><?= $p['branch_id'] && br_enabled($conn) ? ' · ' . li_e(br_get($conn, (int) $p['branch_id'])['name'] ?? '') : ''; ?></span>
    <span class="ms-auto d-flex gap-2">
        <?php if (in_array($p['status'], ['Draft', 'Rejected'], true) && li_can($conn, 'propose')) { ?><a href="proposal_edit.php?id=<?= $id; ?>" class="btn btn-sm btn-outline-primary">Edit</a>
            <form method="POST"><?= li_csrf_field(); ?><input type="hidden" name="do" value="submit"><button class="btn btn-sm btn-primary">Submit for review</button></form><?php } ?>
        <a href="simulate.php?proposal=<?= $id; ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-calculator"></i> Simulate a loan for this</a>
    </span>
</div>
<?php if ($ev['missing']) { ?><div class="alert alert-warning py-2 small"><strong>MORE DATA REQUIRED:</strong> missing <?= li_e(implode(', ', $ev['missing'])); ?>. Figures below cannot be trusted until they are entered.</div><?php } ?>
<?php if ($p['decision_note']) { ?><div class="alert alert-light border py-2 small">Decision by <?= li_e($p['decider']); ?> (<?= li_e($p['decided_at']); ?>): <?= li_e($p['decision_note']); ?><?= $p['approved_funding'] !== null ? ' · approved funding ' . li_rwf($p['approved_funding']) : ''; ?></div><?php } ?>

<div class="row g-3">
<div class="col-xl-8">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="li-sec">Expected figures <?= li_tag($p['evidence_level'] === 'data' ? 'Estimated' : 'Assumption'); ?> <span class="text-muted text-lowercase fw-normal">data confidence: <?= li_e($ev['confidence']); ?> · ranking score <?= $ev['score']; ?>/100</span></div>
        <div class="row row-cols-2 row-cols-md-4 g-2 mb-2">
            <?= li_kpi('Investment cost', li_rwf($p['investment_cost'])); ?><?= li_kpi('Working capital', li_rwf($p['working_capital'])); ?>
            <?= li_kpi('Implementation', (int) $p['impl_months'] . ' month(s)'); ?><?= li_kpi('Evaluation period', (int) $p['horizon_months'] . ' months'); ?>
            <?= li_kpi('Expected revenue / month', li_rwf($m['monthly_revenue'])); ?><?= li_kpi('Gross profit / month', li_rwf($m['monthly_gross'])); ?>
            <?= li_kpi('Net operating profit / month', li_rwf($m['monthly_net']), '', '', $m['monthly_net'] < 0 ? '#E24B4A' : '#0FA968'); ?><?= li_kpi('Monthly cash flow', li_rwf($m['monthly_cash'])); ?>
            <?= li_kpi('Expected ROI', li_pct($m['roi']), '', 'minimum ' . li_setting($conn, 'min_roi') . '%', $m['roi'] !== null && $m['roi'] >= li_num($conn, 'min_roi') ? '#0FA968' : '#E24B4A'); ?>
            <?= li_kpi('Payback period', $m['payback'] === null ? 'not within ' . (int) $p['horizon_months'] . ' months' : $m['payback'] . ' months', '', 'maximum ' . li_setting($conn, 'max_payback')); ?>
            <?= li_kpi('NPV', li_rwf($m['npv']), '', 'at ' . li_setting($conn, 'discount_rate') . '% a year', $m['npv'] < 0 ? '#E24B4A' : ''); ?><?= li_kpi('IRR', li_pct($m['irr']), '', 'a year'); ?>
        </div>
        <details class="small"><summary>How these are calculated</summary>
            <div class="mt-2"><span class="li-formula">Gross profit = revenue x (1 - <?= li_n($p['cost_of_sales_pct'], 1); ?>%) = <?= li_rwf($m['monthly_gross']); ?></span><br>
            <span class="li-formula">Net operating profit = gross profit - extra expenses <?= li_rwf($p['opex']); ?> = <?= li_rwf($m['monthly_net']); ?></span><br>
            <span class="li-formula">Operating profit over <?= (int) $m['horizon']; ?> months (after <?= (int) $p['impl_months']; ?> month(s) of setup<?= $p['ramp_months'] ? ', ' . (int) $p['ramp_months'] . ' months to full revenue' : ''; ?>) = <?= li_rwf($m['net_total']); ?></span><br>
            <span class="li-formula">ROI = (<?= li_n($m['net_total']); ?> + <?= li_n($m['working_capital']); ?> - <?= li_n($m['total_cost']); ?>) / <?= li_n($m['total_cost']); ?> x 100 = <?= li_pct($m['roi']); ?></span><br>
            <span class="li-formula">Payback = month when cumulative cash flow (starting at -<?= li_n($m['total_cost']); ?>) reaches 0</span><br>
            <span class="li-formula">NPV = Σ cash flow(m) / (1 + <?= round(li_monthly_rate($disc) * 100, 3); ?>%)^m</span></div></details>
    </div></div>

    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="li-sec">Optimistic · Base · Pessimistic</div>
        <div class="table-responsive"><table class="table table-sm small mb-0"><tr><th></th><?php foreach ($scen as $s) { ?><th class="text-end"><?= li_e($s['label']); ?></th><?php } ?></tr>
            <tr><td class="text-muted">Assumption: revenue / expenses / delay / stock sells slower</td><?php foreach ($scen as $s) { ?><td class="text-end text-muted"><?= sprintf('%+d%% / %+d%% / %d mo / %d%%', $s['adj']['revenue_pct'], $s['adj']['opex_pct'], $s['adj']['delay_months'], $s['adj']['slower_pct']); ?></td><?php } ?></tr>
            <?php foreach (['monthly_net' => 'Net operating profit / month', 'net_total' => 'Operating profit over the period', 'roi' => 'ROI', 'payback' => 'Payback (months)', 'npv' => 'NPV'] as $k => $l) { ?>
            <tr><td><?= $l; ?></td><?php foreach ($scen as $s) { $x = $s['m'][$k]; ?><td class="text-end <?= ($k === 'npv' || $k === 'monthly_net') && $x < 0 ? 'text-danger' : ''; ?>"><?= $k === 'roi' ? li_pct($x) : ($k === 'payback' ? ($x === null ? 'never' : $x) : li_n($x)); ?></td><?php } ?></tr><?php } ?>
        </table></div>
    </div></div>

    <div class="mb-3"><?php an_chart_card('liProj', 'Expected cumulative cash flow of the investment', 'Base case (solid) and pessimistic case (dashed); 0 = money recovered', 230); ?></div>

    <?php if (in_array($p['status'], ['Approved', 'Funded', 'Completed'], true)) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="li-sec">Financed by company loans</div>
        <table class="table table-sm small"><tr><th>Loan</th><th class="text-end">Allocated</th><th>Status</th><th class="text-end">Invested</th><th></th></tr>
        <?php foreach ($allocs as $a) { ?><tr><td><a href="../loans/view.php?id=<?= (int) $a['loan_id']; ?>">#<?= (int) $a['loan_id']; ?> <?= li_e($a['lender']); ?></a><div class="text-muted">by <?= li_e($a['who']); ?><?= $a['note'] ? ': ' . li_e($a['note']) : ''; ?></div></td>
            <td class="text-end"><?= li_n($a['amount']); ?></td><td><?= li_status_badge($a['status']); ?></td><td class="text-end"><?= li_n($a['invested']); ?></td>
            <td><?php if ($a['status'] === 'Proposed' && li_can($conn, 'approve')) { ?><form method="POST" class="d-flex gap-1"><?= li_csrf_field(); ?><input type="hidden" name="do" value="alloc_decide"><input type="hidden" name="allocation_id" value="<?= (int) $a['id']; ?>">
                <input name="note" class="form-control form-control-sm" placeholder="Note"><button name="decision" value="Approved" class="btn btn-sm btn-success">Approve</button><button name="decision" value="Rejected" class="btn btn-sm btn-outline-danger">Reject</button></form><?php } ?></td></tr><?php } ?>
        <?php if (!$allocs) { ?><tr><td colspan="5" class="text-muted">Not linked to a loan yet.</td></tr><?php } ?></table>
        <?php if (li_can($conn, 'finance') && $p['status'] !== 'Completed') { ?>
        <form method="POST" class="row g-2"><?= li_csrf_field(); ?><input type="hidden" name="do" value="allocate">
            <div class="col-md-5"><select name="loan_id" class="form-select form-select-sm" required><option value="">Company loan…</option><?php foreach ($loansOpen as $l) { ?><option value="<?= (int) $l['id']; ?>">#<?= (int) $l['id']; ?> <?= li_e($l['lender']); ?> · <?= li_rwf($l['loan_amount']); ?></option><?php } ?></select></div>
            <div class="col-md-3"><input name="amount" type="number" min="1" step="1" class="form-control form-control-sm" placeholder="Amount" required></div>
            <div class="col-md-4"><input name="note" class="form-control form-control-sm" placeholder="Note"></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary">Propose allocation (management approves)</button></div></form>
        <?php } ?>
        <?php $approvedAllocs = array_filter($allocs, function ($a) { return $a['status'] === 'Approved' && (float) $a['invested'] < (float) $a['amount']; }); if ($approvedAllocs && li_can($conn, 'finance')) { ?>
        <h6 class="mt-3 small fw-bold">Record money actually invested</h6>
        <form method="POST" class="row g-2"><?= li_csrf_field(); ?><input type="hidden" name="do" value="invest">
            <div class="col-md-4"><select name="allocation_id" class="form-select form-select-sm"><?php foreach ($approvedAllocs as $a) { ?><option value="<?= (int) $a['id']; ?>">Loan #<?= (int) $a['loan_id']; ?> · <?= li_rwf($a['amount'] - $a['invested']); ?> left</option><?php } ?></select></div>
            <div class="col-md-2"><input name="amount" type="number" min="1" step="1" class="form-control form-control-sm" placeholder="Amount" required></div>
            <div class="col-md-2"><input name="invest_date" type="date" max="<?= date('Y-m-d'); ?>" value="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" required></div>
            <div class="col-md-4"><input name="reference" class="form-control form-control-sm" placeholder="Reference (PO-12, TX-845, asset code)" required></div>
            <div class="col-12"><input name="description" class="form-control form-control-sm" placeholder="What was bought / paid" required></div>
            <div class="col-12"><button class="btn btn-sm btn-primary">Record</button> <span class="small text-muted">Classify the purchase in its own module (stock → Purchase Orders, equipment → Assets, other → Transactions).</span></div></form>
        <?php } ?>
        <?php if ($invests) { ?><table class="table table-sm small mt-2"><tr><th>Date</th><th>What</th><th>Reference</th><th class="text-end">Amount</th><th>By</th></tr>
            <?php foreach ($invests as $i) { ?><tr><td><?= li_e($i['invest_date']); ?></td><td><?= li_e($i['description']); ?></td><td><?= li_e($i['reference']); ?></td><td class="text-end"><?= li_n($i['amount']); ?></td><td><?= li_e($i['who']); ?></td></tr><?php } ?></table><?php } ?>
    </div></div>
    <?php } ?>

    <?php if (in_array($p['status'], ['Funded', 'Completed'], true)) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="li-sec">Expected vs actual <?= li_status_badge($assessment); ?></div>
        <div class="small text-muted mb-2">Started <?= li_e($p['started_on']); ?> · <?= (int) $act['months']; ?> month(s) running · actual figures: <?= li_e($act['source'] === 'none' ? 'not entered yet' : $act['source']); ?></div>
        <table class="table table-sm small"><tr><th></th><th class="text-end">Expected to date <?= li_tag('Estimated'); ?></th><th class="text-end">Actual to date <?= li_tag('Actual'); ?></th><th class="text-end">Difference</th></tr>
            <tr><td>Revenue</td><td class="text-end"><?= li_n($expTo['revenue']); ?></td><td class="text-end"><?= li_n($act['revenue']); ?></td><td class="text-end"><?= $act['revenue'] === null ? '—' : li_n($act['revenue'] - $expTo['revenue']); ?></td></tr>
            <tr><td>Operating profit</td><td class="text-end"><?= li_n($expTo['profit']); ?></td><td class="text-end"><?= li_n($act['profit']); ?></td><td class="text-end <?= $act['profit'] !== null && $act['profit'] < $expTo['profit'] ? 'text-danger' : ''; ?>"><?= $act['profit'] === null ? '—' : li_n($act['profit'] - $expTo['profit']); ?></td></tr>
            <tr><td>ROI to date (profit / money invested)</td><td class="text-end"><?= li_pct($expRoiTo); ?></td><td class="text-end"><?= li_pct($actRoi); ?></td><td></td></tr>
            <tr><td>Payback</td><td class="text-end"><?= $m['payback'] === null ? 'not within the period' : $m['payback'] . ' months'; ?></td><td class="text-end"><?= $act['profit'] !== null && $investedTotal > 0 ? round(min(100, max(0, $act['profit'] / $investedTotal * 100))) . '% recovered' : '—'; ?></td><td></td></tr>
        </table>
        <?php if (li_can($conn, 'finance')) { ?>
        <form method="POST" class="row g-2"><?= li_csrf_field(); ?><input type="hidden" name="do" value="actual">
            <div class="col-md-2"><input type="month" name="period" max="<?= date('Y-m'); ?>" value="<?= date('Y-m', strtotime('first day of last month')); ?>" class="form-control form-control-sm"></div>
            <div class="col-md-2"><input name="revenue" type="number" step="1" class="form-control form-control-sm" placeholder="Revenue"></div>
            <div class="col-md-2"><input name="cost_of_sales" type="number" step="1" class="form-control form-control-sm" placeholder="Cost of sales"></div>
            <div class="col-md-2"><input name="opex" type="number" step="1" class="form-control form-control-sm" placeholder="Expenses"></div>
            <div class="col-md-3"><input name="note" class="form-control form-control-sm" placeholder="Source / reason for differences"></div>
            <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Save</button></div></form>
        <div class="small text-muted mt-1">Monthly actual results of this investment, from the records (enter the reason for differences in the note). <?= $p['measure'] === 'product' ? 'When no month is entered, results are measured from the linked product.' : ''; ?></div>
        <?php } ?>
        <?php if ($act['rows']) { ?><table class="table table-sm small mt-2"><tr><th>Month</th><th class="text-end">Revenue</th><th class="text-end">Cost of sales</th><th class="text-end">Expenses</th><th>Note</th></tr>
            <?php foreach ($act['rows'] as $r) { ?><tr><td><?= date('M Y', strtotime($r['period'])); ?></td><td class="text-end"><?= li_n($r['revenue']); ?></td><td class="text-end"><?= li_n($r['cost_of_sales']); ?></td><td class="text-end"><?= li_n($r['opex']); ?></td><td><?= li_e($r['note']); ?></td></tr><?php } ?></table><?php } ?>
        <?php if ($p['status'] === 'Funded' && li_can($conn, 'approve')) { ?><form method="POST" class="mt-2"><?= li_csrf_field(); ?><input type="hidden" name="do" value="complete"><button class="btn btn-sm btn-outline-dark" onclick="return confirm('Mark this investment as completed?');">Mark completed</button></form><?php } ?>
    </div></div>
    <?php } ?>
</div>

<div class="col-xl-4">
    <?php if ($p['status'] === 'Submitted' && li_can($conn, 'approve')) { ?>
    <div class="card border-0 shadow-sm mb-3 border-start border-4 border-warning"><div class="card-body">
        <h6>Management decision</h6>
        <form method="POST"><?= li_csrf_field(); ?><input type="hidden" name="do" value="decide">
            <label class="form-label small">Approved funding (RWF)</label><input name="approved_funding" type="number" min="0" step="1" class="form-control form-control-sm mb-2" value="<?= (int) $m['total_cost']; ?>">
            <textarea name="note" class="form-control form-control-sm mb-2" rows="2" placeholder="Reason (required to reject)"></textarea>
            <button name="decision" value="Approved" class="btn btn-sm btn-success">Approve investment</button> <button name="decision" value="Rejected" class="btn btn-sm btn-outline-danger">Reject</button>
        </form>
        <div class="small text-muted mt-2">Approving the investment does not approve borrowing. Generate a recommendation first.</div>
    </div></div>
    <?php } ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6>Recommendation</h6>
        <form method="POST"><?= li_csrf_field(); ?><input type="hidden" name="do" value="recommend">
            <select name="scenario_id" class="form-select form-select-sm mb-2"><option value="0">Without a loan offer (reference terms)</option><?php foreach ($scenarios as $s) { ?><option value="<?= (int) $s['id']; ?>"><?= li_e($s['name']); ?> · <?= li_rwf($s['principal']); ?> @ <?= (float) $s['rate']; ?>% / <?= (int) $s['n']; ?></option><?php } ?></select>
            <button class="btn btn-sm btn-primary"><i class="bi bi-clipboard-check"></i> Generate recommendation</button></form>
        <?php foreach ($recs as $r) { ?><div class="small border-top mt-2 pt-1"><a href="recommendations.php?id=<?= (int) $r['id']; ?>">#<?= (int) $r['id']; ?></a> <?= li_decision_badge($r['decision']); ?> <?= li_rwf($r['amount']); ?> · <?= li_e($r['status']); ?></div><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <h6>Justification</h6><div style="white-space:pre-line;"><?= li_e($p['justification']); ?></div>
        <h6 class="mt-3">Demand evidence <span class="badge bg-light text-dark border"><?= li_e(['data' => 'records', 'partial' => 'partly records', 'assumption' => 'assumptions'][$p['evidence_level']]); ?></span></h6><div style="white-space:pre-line;"><?= li_e($p['demand_evidence'] ?: '—'); ?></div>
        <h6 class="mt-3">Risks <span class="badge bg-<?= $p['risk_level'] === 'High' ? 'danger' : ($p['risk_level'] === 'Low' ? 'success' : 'warning text-dark'); ?>"><?= li_e($p['risk_level']); ?></span></h6><div style="white-space:pre-line;"><?= li_e($p['risks'] ?: '—'); ?></div>
        <h6 class="mt-3">Assumptions</h6><div style="white-space:pre-line;"><?= li_e($p['assumptions'] ?: '—'); ?></div>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <h6>History</h6>
        <?php foreach ($history as $h) { ?><div class="border-top py-1"><strong><?= li_e(str_replace('_', ' ', $h['action'])); ?></strong> · <?= li_e($h['names'] ?: 'System'); ?> · <span class="text-muted"><?= li_e($h['created_at']); ?></span>
            <?= $h['reason'] ? '<div>' . li_e($h['reason']) . '</div>' : ''; ?><?= $h['prev_json'] && $h['prev_json'] !== 'null' ? '<div class="text-muted">before: ' . li_e(mb_substr($h['prev_json'], 0, 200)) . '</div>' : ''; ?></div><?php } ?>
    </div></div>
    <?php if (in_array($p['status'], ['Draft', 'Submitted', 'Rejected', 'Approved'], true) && ((int) $p['created_by'] === $me || li_can($conn, 'approve'))) { ?>
    <form method="POST" class="text-end"><?= li_csrf_field(); ?><input type="hidden" name="do" value="cancel"><input type="hidden" name="note" value="Cancelled from the proposal page"><button class="btn btn-sm btn-link text-danger" onclick="return confirm('Cancel this proposal?');">Cancel proposal</button></form>
    <?php } ?>
</div>
</div>
<?php an_js(); ?>
<script>
(function () {
    function cum(f) { var c = 0; return f.map(function (x) { c += x; return Math.round(c); }); }
    var b = <?= json_encode($scen['base']['m']['flows']); ?>, w = <?= json_encode($scen['pessimistic']['m']['flows']); ?>;
    var L = b.map(function (x, i) { return i === 0 ? 'Start' : 'M' + i; });
    new Chart(document.getElementById('liProj'), { type: 'line', data: { labels: L, datasets: [
        { label: 'Base', data: cum(b), borderColor: '#1E2FE0', backgroundColor: '#1E2FE0', tension: .2, pointRadius: 0 },
        { label: 'Pessimistic', data: cum(w), borderColor: '#E24B4A', backgroundColor: '#E24B4A', borderDash: [5, 4], tension: .2, pointRadius: 0 }] },
        options: { maintainAspectRatio: false } });
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
