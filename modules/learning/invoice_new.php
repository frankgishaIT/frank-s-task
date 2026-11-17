<?php
/**
 * NEW FILE: modules/learning/invoice_new.php — BILL an applicant / student (?application=ID&kind=application|tuition|other).
 * Proposes the fees from the course / intake / module fee structure (modules included in the course fee are never
 * charged again; fees already invoiced are left out), approved discounts and scholarships with their reason, and
 * full payment or instalments with due dates. The result is a normal Sales credit invoice (RM Offerings services):
 * payments, receipts and cancellations are done in Sales as usual.
 */
require __DIR__ . '/common.php';
lcs_require($conn, 'finance');
$a = lc_application($conn, (int) ($_GET['application'] ?? $_POST['application'] ?? 0));
if (!$a) { lcs_go('fees.php', 'error', 'Application not found.'); }
if (!lcs_branch_ok($conn, (int) $a['branch_id'] ?: null)) { lcs_deny(); }
$kind = in_array($_GET['kind'] ?? $_POST['kind'] ?? '', ['application', 'tuition', 'other'], true) ? ($_GET['kind'] ?? $_POST['kind']) : 'tuition';
$back = 'application.php?id=' . (int) $a['id'];
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go($back, 'error', 'The page had expired.'); }
    $lines = []; $adj = []; $plan = [];
    foreach ((array) ($_POST['l_type'] ?? []) as $i => $t) { if (!empty($_POST['l_on'][$i])) { $lines[] = [(string) $t, (string) ($_POST['l_desc'][$i] ?? ''), (float) ($_POST['l_amt'][$i] ?? 0)]; } }
    foreach ((array) ($_POST['a_type'] ?? []) as $i => $t) { $adj[] = [(string) $t, (float) ($_POST['a_amt'][$i] ?? 0), (string) ($_POST['a_why'][$i] ?? '')]; }
    if (($_POST['plan'] ?? 'full') === 'full') { $plan[] = [(string) ($_POST['full_due'] ?? ''), 0]; $planFull = true; }
    else { foreach ((array) ($_POST['p_due'] ?? []) as $i => $d) { $plan[] = [(string) $d, (float) ($_POST['p_amt'][$i] ?? 0)]; } }
    try {
        if (!empty($planFull)) {   // one payment: the whole total on that date
            $sub = array_sum(array_map(function ($l) { return max(0, $l[2]); }, $lines)); $d = array_sum(array_map(function ($x) { return max(0, $x[1]); }, $adj));
            $plan = [[$plan[0][0], round($sub - $d, 2)]];
        }
        $sid = lc_create_invoice($conn, $a, $kind, $lines, $adj, $plan, (string) ($_POST['notes'] ?? ''));
        $st = mysqli_fetch_row(mysqli_query($conn, "SELECT status FROM sales WHERE id = $sid"))[0];
        lcs_go($back, 'success', 'Invoice ' . lc_sale_number($sid) . ' created' . ($st === 'Pending Discount Approval' ? ': the discount / scholarship waits for Manager approval in Sales before the invoice is issued.' : ' and sent to the student (SMS and Customer Portal).'));
    } catch (RuntimeException $e) { $error = $e->getMessage(); }
}
$sugg = lc_suggest_lines($conn, $a, $kind);
$billed = lc_billed_types($conn, (int) $a['id']);
$course = lc_course($conn, (int) $a['course_id']);
$intake = $a['intake_id'] ? (lc_intakes($conn, (int) $a['course_id'])[(int) $a['intake_id']] ?? null) : null;
$cust = $a['customer_id'] ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, name, phone FROM customers WHERE id = ' . (int) $a['customer_id'])) : null;
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'fees.php', 'New invoice · ' . $a['ref']);
$defDue = date('Y-m-d', strtotime('+7 days'));
$start = $intake['start_date'] ?? date('Y-m-d', strtotime('+30 days'));
?>
<div class="small text-muted mb-2"><?= lc_e($a['full_name']); ?> · <?= $cust ? 'Customer ' . lc_e(lc_customer_id_label($conn, (int) $cust['id'])) : '<span class="text-danger">no customer linked</span>'; ?> · <?= lc_e($course['code'] . ' ' . $course['title']); ?> · <?= lc_e($intake['name'] ?? ''); ?> · fee structure: <?= lc_e(['course' => 'one course fee', 'module' => 'per module', 'combined' => 'course fee + extra modules'][$course['fee_structure']] ?? $course['fee_structure']); ?></div>
<?php if ($error) { ?><div class="alert alert-danger py-2"><?= lc_e($error); ?></div><?php } ?>
<?php if (!$cust) { ?><div class="alert alert-warning">Link the application to the customer first (application page → Customer &amp; student). Invoices are always issued to a customer record, so the Student ID, receipts and the portal all match.</div>
<?php } else { ?>
<ul class="nav nav-pills small mb-3"><?php foreach (['application' => 'Application fee', 'tuition' => 'Registration, tuition and exams', 'other' => 'Other charges'] as $k => $l) { ?><li class="nav-item"><a class="nav-link py-1 <?= $kind === $k ? 'active' : ''; ?>" href="?application=<?= (int) $a['id']; ?>&kind=<?= $k; ?>"><?= $l; ?></a></li><?php } ?></ul>
<?php if ($billed) { ?><div class="small text-muted mb-2">Already invoiced: <?= lc_e(implode(', ', array_map(function ($t) { return LC_FEE_TYPES[$t]; }, array_keys($billed)))); ?> (not proposed again).</div><?php } ?>
<form method="POST" id="inv"><?= lcs_csrf_field(); ?><input type="hidden" name="application" value="<?= (int) $a['id']; ?>"><input type="hidden" name="kind" value="<?= lc_e($kind); ?>">
<div class="row g-3">
<div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">Fees</div>
        <table class="table table-sm align-middle mb-1" style="font-size:13px;"><tr class="table-light"><th></th><th>Fee</th><th>Description (printed on the invoice)</th><th style="width:140px">Amount (RWF)</th></tr>
        <?php $rows = $sugg; for ($x = 0; $x < 3; $x++) { $rows[] = [$kind === 'application' ? 'application' : 'other', '', 0, null]; }
        foreach ($rows as $i => [$t, $d, $amt]) { $isS = $i < count($sugg); ?>
        <tr><td><input type="checkbox" class="form-check-input" name="l_on[<?= $i; ?>]" value="1" <?= $isS ? 'checked' : ''; ?>></td>
            <td><select name="l_type[<?= $i; ?>]" class="form-select form-select-sm"><?php foreach (LC_FEE_TYPES as $k => $l) { ?><option value="<?= $k; ?>" <?= $t === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></td>
            <td><input name="l_desc[<?= $i; ?>]" class="form-control form-control-sm" value="<?= lc_e($d); ?>" maxlength="150"></td>
            <td><input name="l_amt[<?= $i; ?>]" class="form-control form-control-sm text-end lc-amt" inputmode="decimal" value="<?= $amt ? (float) $amt : ''; ?>"></td></tr>
        <?php } ?></table>
        <?php if (!$sugg) { ?><div class="small text-muted">Nothing to propose from the fee structure<?= $billed ? ' (already invoiced)' : ''; ?>. Tick and fill a line to add a charge.</div><?php } ?>
        <div class="small text-muted">Modules included in the course fee are never proposed again. Accommodation is billed separately from tuition.</div>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="fw-semibold mb-2">Approved discounts and scholarships</div>
        <?php for ($i = 0; $i < 2; $i++) { ?><div class="row g-1 mb-1"><div class="col-3"><select name="a_type[<?= $i; ?>]" class="form-select form-select-sm"><?php foreach (LC_ADJ_TYPES as $t) { ?><option><?= $t; ?></option><?php } ?></select></div>
            <div class="col-3"><input name="a_amt[<?= $i; ?>]" class="form-control form-control-sm text-end lc-disc" inputmode="decimal" placeholder="Amount"></div><div class="col-6"><input name="a_why[<?= $i; ?>]" class="form-control form-control-sm" placeholder="Reason / approval reference" maxlength="300"></div></div><?php } ?>
        <div class="small text-muted"><?= current_user_role() === 'Employee' ? 'Your discounts wait for Manager / Admin approval in Sales (Approve discounts) before the invoice is issued.' : 'Recorded as the invoice discount, with the reason in the audit trail.'; ?></div>
    </div></div>
</div>
<div class="col-lg-5">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex justify-content-between"><span>Fees</span><strong id="tSub">—</strong></div><div class="d-flex justify-content-between text-success"><span>Discounts / scholarships</span><strong id="tDisc">—</strong></div>
        <div class="d-flex justify-content-between fs-5 border-top mt-1 pt-1"><span>Total</span><strong id="tTot">—</strong></div>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">Payment plan</div>
        <div class="form-check"><input class="form-check-input" type="radio" name="plan" value="full" id="pf" checked><label class="form-check-label small" for="pf">Full payment by</label> <input type="date" name="full_due" class="form-control form-control-sm d-inline-block" style="width:160px" value="<?= lc_e($kind === 'application' ? $defDue : min($start, max($defDue, $start))); ?>"></div>
        <div class="form-check mt-1"><input class="form-check-input" type="radio" name="plan" value="inst" id="pi" <?= !$course['installments_allowed'] ? '' : ''; ?>><label class="form-check-label small" for="pi">Instalments<?= $course['installments_allowed'] ? '' : ' <span class="text-muted">(not offered on the course page)</span>'; ?></label></div>
        <div id="instBox" class="mt-2" style="display:none;">
            <div class="d-flex gap-1 mb-1 small align-items-center"><input type="number" id="nInst" min="2" max="6" value="<?= $course['installments_allowed'] ? 2 : 2; ?>" class="form-control form-control-sm" style="width:64px"> instalments, every <select id="every" class="form-select form-select-sm" style="width:110px"><option value="1">month</option><option value="2">2 months</option><option value="0.5">2 weeks</option></select>
                <button type="button" class="btn btn-sm btn-outline-primary" id="split">Split</button></div>
            <?php for ($i = 0; $i < 6; $i++) { ?><div class="d-flex gap-1 mb-1 lc-inst" style="<?= $i > 1 ? 'display:none!important' : ''; ?>"><span class="small text-muted pt-1" style="width:18px"><?= $i + 1; ?>.</span><input type="date" name="p_due[<?= $i; ?>]" class="form-control form-control-sm"><input name="p_amt[<?= $i; ?>]" class="form-control form-control-sm text-end" inputmode="decimal" placeholder="Amount"></div><?php } ?>
            <div class="small text-muted"><?= lc_e($course['payment_terms'] ?: ''); ?></div>
        </div>
        <textarea name="notes" class="form-control form-control-sm mt-2" rows="2" maxlength="300" placeholder="Internal note (optional)"></textarea>
        <button class="btn btn-primary btn-sm mt-2"><i class="bi bi-receipt"></i> Create the invoice</button> <a href="<?= $back; ?>" class="btn btn-sm btn-light border mt-2">Cancel</a>
        <div class="small text-muted mt-2">The invoice is a normal Sales invoice: record payments with "Pay" in Sales (cash, MoMo, bank, Digital Payment); receipts come from Sales. <?= lc_setting($conn, 'fee_overdue_charges') === '1' ? 'Overdue charges apply after the last due date.' : 'No overdue charges on fees.'; ?></div>
    </div></div>
</div></div>
</form>
<script>
(function () {
    var f = document.getElementById('inv'); var fmt = function (n) { return 'RWF ' + Math.round(n).toLocaleString('en-US'); };
    function sum() { var s = 0, d = 0;
        f.querySelectorAll('.lc-amt').forEach(function (el, i) { var on = f.querySelector('[name="l_on[' + i + ']"]'); var v = parseFloat(el.value || 0); if (on && on.checked && v > 0) s += v; });
        f.querySelectorAll('.lc-disc').forEach(function (el) { var v = parseFloat(el.value || 0); if (v > 0) d += v; });
        document.getElementById('tSub').innerText = fmt(s); document.getElementById('tDisc').innerText = '− ' + fmt(d); document.getElementById('tTot').innerText = fmt(Math.max(0, s - d)); return Math.max(0, s - d); }
    f.addEventListener('input', sum); f.addEventListener('change', function (e) { sum(); if (e.target.name === 'plan') document.getElementById('instBox').style.display = e.target.value === 'inst' ? '' : 'none'; });
    f.querySelectorAll('.lc-amt').forEach(function (el, i) { el.addEventListener('input', function () { var on = f.querySelector('[name="l_on[' + i + ']"]'); if (el.value) on.checked = true; }); });
    document.getElementById('split').addEventListener('click', function () {
        var n = Math.min(6, Math.max(2, parseInt(document.getElementById('nInst').value || 2))), ev = parseFloat(document.getElementById('every').value), tot = sum();
        var first = f.querySelector('[name=full_due]').value || new Date().toISOString().slice(0, 10), each = Math.floor(tot / n);
        f.querySelectorAll('.lc-inst').forEach(function (row, i) { var d = row.querySelector('input[type=date]'), a = row.querySelector('input[name^=p_amt]');
            if (i < n) { row.style.setProperty('display', 'flex', 'important'); var dt = new Date(first + 'T12:00:00'); if (ev >= 1) { dt.setMonth(dt.getMonth() + i * ev); } else { dt.setDate(dt.getDate() + i * 14); }
                d.value = dt.toISOString().slice(0, 10); a.value = i === n - 1 ? (tot - each * (n - 1)) : each; }
            else { row.style.setProperty('display', 'none', 'important'); d.value = ''; a.value = ''; } });
    });
    sum();
})();
</script>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>