<?php
// NEW FILE: portal/loans.php — RM CUSTOMER LOANS: eligibility, loans and applications, calculator, APPLY FOR A LOAN.
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/cl_helpers.php';
cl_ensure($conn);
if (cl_setting($conn, 'enabled') !== '1') { portal_header('RM Loans', $me); echo '<div class="alert alert-info">RM Customer Loans are not available at the moment.</div>'; portal_footer(); exit; }
cl_refresh_overdue($conn, $cid);
$el = cl_eligibility($conn, $cid);
$apps = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, (SELECT COALESCE(SUM(amount_due - paid), 0) FROM cl_schedule s WHERE s.application_id = a.id) AS owed,
    (SELECT MIN(due_date) FROM cl_schedule s WHERE s.application_id = a.id AND s.paid < s.amount_due) AS next_due FROM cl_applications a WHERE a.customer_id = $cid ORDER BY a.id DESC"), MYSQLI_ASSOC);
$liveStatuses = ['Disbursed', 'Active', 'Overdue', 'Defaulted', 'Agreement Signed'];
$live = array_filter($apps, function ($a) use ($liveStatuses) { return in_array($a['status'], $liveStatuses, true); });
$payments = mysqli_fetch_all(mysqli_query($conn, "SELECT p.*, a.created_at AS app_created FROM cl_payments p JOIN cl_applications a ON a.id = p.application_id WHERE a.customer_id = $cid ORDER BY p.id DESC LIMIT 20"), MYSQLI_ASSOC);
$rate = (float) cl_setting($conn, 'monthly_rate'); $periods = array_filter(array_map('intval', explode(',', cl_setting($conn, 'periods'))));
portal_header('RM Loans', $me);
portal_alerts();
?>
<?php if (cl_testing($conn)) { ?><div class="alert alert-warning small"><i class="bi bi-info-circle me-1"></i><strong>Pilot phase:</strong> you can check your eligibility and apply, and RISE MOTIVE will assess your application. Loans are not paid out yet while RISE MOTIVE completes its lending authorisation.</div><?php } ?>
<div class="card mb-3" style="border-left:4px solid #1E2FE0 !important;"><div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
    <div>
        <div class="small text-muted">RM CUSTOMER LOANS</div>
        <?php if ($el['limit'] > 0) { ?>
        <div class="fs-5">Based on your verified business relationship with RISE MOTIVE, you are eligible to apply for an unsecured loan of up to <strong><?= cl_money($el['limit']); ?></strong>.</div>
        <?php } else { ?>
        <div class="fs-6">You have no unsecured loan eligibility available right now.
            <?= $el['owed_loans'] + $el['owed_invoices'] > 0 ? 'Paying what you currently owe (' . cl_money($el['owed_loans'] + $el['owed_invoices']) . ') increases it.' : 'It grows with every fully paid purchase.'; ?>
            You can still apply with collateral.</div>
        <?php } ?>
        <div class="small text-muted mt-1">Eligibility is not automatic approval: every application is reviewed by RISE MOTIVE.</div>
    </div>
    <a href="loan_apply.php" class="btn btn-primary btn-lg"><i class="bi bi-pencil-square me-1"></i>APPLY FOR A LOAN</a>
</div></div>
<div class="row g-3 mb-3">
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Loyalty points</div><div class="fs-5 fw-bold"><?= number_format($el['current_points']); ?></div>
        <div class="small text-muted"><?= number_format($el['points']); ?> verified (fully paid purchases)</div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Profit generated</div><div class="fs-5 fw-bold"><?= cl_money($el['profit']); ?></div>
        <div class="small text-muted"><?= number_format($el['points']); ?> × RWF <?= number_format((float) cl_setting($conn, 'point_value')); ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Unsecured limit (<?= (float) cl_setting($conn, 'unsecured_pct'); ?>%)</div><div class="fs-5 fw-bold"><?= cl_money($el['gross_limit']); ?></div>
        <div class="small text-muted">available now: <?= cl_money($el['limit']); ?></div></div></div></div>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-muted">Outstanding loan balance</div><div class="fs-5 fw-bold <?= $el['owed_loans'] > 0 ? 'text-danger' : ''; ?>"><?= cl_money($el['owed_loans']); ?></div>
        <div class="small text-muted"><?= count($live); ?> open loan(s)</div></div></div></div>
</div>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body"><h2 class="h6">My loans and applications</h2><div class="table-responsive"><table class="table mb-0 align-middle small">
    <tr><th>Reference</th><th class="text-end">Amount</th><th>Status</th><th class="text-end">Balance</th><th>Next due</th><th></th></tr>
    <?php if (!$apps) { ?><tr><td colspan="6" class="text-muted">No applications yet.</td></tr><?php } ?>
    <?php foreach ($apps as $a) { $amt = $a['approved_amount'] ?? $a['requested_amount']; ?>
    <tr><td><?= cl_ref((int) $a['id'], $a['created_at']); ?></td><td class="text-end"><?= $amt !== null ? cl_money($amt) : '—'; ?></td>
        <td><span class="badge <?= in_array($a['status'], ['Rejected', 'Overdue', 'Defaulted'], true) ? 'bg-danger' : (in_array($a['status'], ['Fully Repaid', 'Active', 'Disbursed'], true) ? 'bg-success' : 'bg-primary'); ?>"><?= portal_e($a['status']); ?></span></td>
        <td class="text-end"><?= in_array($a['status'], $liveStatuses, true) ? cl_money($a['owed']) : '—'; ?></td><td><?= $a['next_due'] && in_array($a['status'], $liveStatuses, true) ? date('d M Y', strtotime($a['next_due'])) : '—'; ?></td>
        <td><a href="<?= $a['status'] === 'Draft' ? 'loan_apply.php?id=' : 'loan.php?id='; ?><?= (int) $a['id']; ?>" class="btn btn-sm btn-outline-primary"><?= $a['status'] === 'Draft' ? 'Continue' : 'Open'; ?></a></td></tr>
    <?php } ?>
</table></div></div></div>
<div class="card mt-3"><div class="card-body"><h2 class="h6">Repayment history</h2>
    <?php if (!$payments) { ?><div class="small text-muted">No repayments yet.</div><?php } foreach ($payments as $p) { ?>
    <div class="d-flex justify-content-between small border-bottom py-1"><span><?= date('d M Y', strtotime($p['paid_on'])); ?> · <?= cl_ref((int) $p['application_id'], $p['app_created']); ?> · <?= portal_e($p['reference']); ?></span>
        <span><?= cl_money($p['amount']); ?> <span class="badge <?= $p['status'] === 'Verified' ? 'bg-success' : ($p['status'] === 'Rejected' ? 'bg-danger' : 'bg-secondary'); ?>"><?= portal_e($p['status']); ?></span></span></div><?php } ?>
</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-body"><h2 class="h6">Loan calculator</h2>
    <div class="row g-2 mb-2"><div class="col-7"><label class="small text-muted">Amount (RWF)</label><input type="number" id="cAmt" class="form-control" min="0" step="1000" value="<?= (int) min(max($el['limit'], 50000), 250000); ?>"></div>
        <div class="col-5"><label class="small text-muted">Months</label><select id="cMon" class="form-select"><?php foreach ($periods as $m) { ?><option><?= $m; ?></option><?php } ?></select></div></div>
    <table class="table table-sm small mb-1"><tr><td>Principal</td><td class="text-end" id="cP"></td></tr><tr><td>Interest (<?= $rate; ?>% per month<?= cl_setting($conn, 'interest_method') === 'reducing' ? ', reducing balance' : ', flat'; ?>)</td><td class="text-end" id="cI"></td></tr>
        <?php if ((float) cl_setting($conn, 'fee_pct') > 0) { ?><tr><td>Fee (<?= (float) cl_setting($conn, 'fee_pct'); ?>%)</td><td class="text-end" id="cF"></td></tr><?php } ?>
        <tr class="fw-bold"><td>Total repayment</td><td class="text-end" id="cT"></td></tr><tr><td>Monthly instalment</td><td class="text-end" id="cM"></td></tr></table>
    <div class="small text-muted">An estimate. Your exact offer and schedule are shown before you accept anything.</div>
</div></div></div>
</div>
<script>
(function () {
    var rate = <?= json_encode($rate / 100); ?>, fee = <?= json_encode((float) cl_setting($conn, 'fee_pct') / 100); ?>, reducing = <?= json_encode(cl_setting($conn, 'interest_method') === 'reducing'); ?>;
    function f(v) { return 'RWF ' + Math.round(v).toLocaleString(); }
    function calc() {
        var p = Math.max(0, +document.getElementById('cAmt').value || 0), n = +document.getElementById('cMon').value, i;
        if (reducing && rate > 0) { var emi = p * rate / (1 - Math.pow(1 + rate, -n)); i = emi * n - p; } else { i = p * rate * n; }
        var fe = p * fee, t = p + i + fe;
        document.getElementById('cP').textContent = f(p); document.getElementById('cI').textContent = f(i);
        if (document.getElementById('cF')) { document.getElementById('cF').textContent = f(fe); }
        document.getElementById('cT').textContent = f(t); document.getElementById('cM').textContent = f(t / n);
    }
    ['cAmt', 'cMon'].forEach(function (id) { document.getElementById(id).addEventListener('input', calc); }); calc();
})();
</script>
<?php portal_footer();