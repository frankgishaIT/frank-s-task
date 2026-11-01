<?php
/**
 * NEW FILE: modules/rm_loans/application.php — one application: everything the customer submitted, eligibility,
 * documents, and the decisions (review, more information, approve with adjustments, reject, disbursement,
 * repayments, default). Every action is audited with the reason.
 */
require __DIR__ . '/loans_common.php';
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_applications WHERE id = $id AND status <> 'Draft'"));
if (!$a) { header('Location: index.php?error=' . urlencode('Application not found.')); exit; }
$c = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM customers WHERE id = ' . (int) $a['customer_id']));
$ref = cl_ref($id, $a['created_at']);
$canEdit = access_can('rm_loans.edit', $conn); $canApprove = access_can('rm_loans.approve', $conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        $need = ['review' => 'edit', 'request_info' => 'edit', 'payment_verify' => 'edit', 'payment_reject' => 'edit', 'payment_record' => 'edit',
                 'approve' => 'approve', 'reject' => 'approve', 'disburse' => 'approve', 'default' => 'approve', 'cancel' => 'approve'][$act] ?? null;
        if (!$need || !access_can('rm_loans.' . $need, $conn)) { throw new RuntimeException('You are not allowed to do that.'); }
        $st = $a['status']; $msg = '';
        switch ($act) {
            case 'review':
                if (!in_array($st, ['Submitted'], true)) { throw new RuntimeException('Only a submitted application can be taken into review.'); }
                cl_set_status($conn, $id, 'Under Review', $me, $reason ?: null, 'Review started'); $msg = 'Under review. The customer was notified.'; break;
            case 'request_info':
                if (!in_array($st, ['Submitted', 'Under Review'], true)) { throw new RuntimeException('Not possible at this stage.'); }
                if (mb_strlen($reason) < 10) { throw new RuntimeException('Write exactly what the customer must provide.'); }
                $s = mysqli_prepare($conn, 'UPDATE cl_applications SET info_request = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'si', $reason, $id); mysqli_stmt_execute($s);
                cl_set_status($conn, $id, 'Additional Information Required', $me, $reason, 'More information requested'); $msg = 'Request sent to the customer.'; break;
            case 'approve':
                if (!in_array($st, ['Submitted', 'Under Review'], true)) { throw new RuntimeException('Not possible at this stage.'); }
                $amt = (float) $_POST['approved_amount']; $rate = (float) $_POST['monthly_rate']; $months = (int) $_POST['period_months'];
                $freq = in_array($_POST['frequency'] ?? '', ['Monthly', 'Weekly'], true) ? $_POST['frequency'] : 'Monthly';
                $method = ($_POST['interest_method'] ?? '') === 'reducing' ? 'reducing' : 'flat'; $fee = max(0, (float) ($_POST['fee_pct'] ?? 0));
                if ($amt <= 0 || $amt > (float) cl_setting($conn, 'max_amount') || $amt < (float) cl_setting($conn, 'min_amount')) { throw new RuntimeException('The amount must be between ' . cl_money(cl_setting($conn, 'min_amount')) . ' and ' . cl_money(cl_setting($conn, 'max_amount')) . '.'); }
                if ($rate < 0 || $rate > 100 || $months < 1 || $months > 120) { throw new RuntimeException('Check the interest rate and the period.'); }
                $changed = abs($amt - (float) $a['requested_amount']) > 0.5 || $rate != (float) cl_setting($conn, 'monthly_rate') || $months !== (int) $a['period_months'];
                if ($changed && mb_strlen($reason) < 10) { throw new RuntimeException('The amount, rate or period differs from the request / standard: write the documented reason.'); }
                $calc = cl_schedule_calc($amt, $rate, $months, $freq, $method, $fee, date('Y-m-d'));
                mysqli_begin_transaction($conn);
                cl_save_schedule($conn, $id, $calc);
                $s = mysqli_prepare($conn, 'UPDATE cl_applications SET approved_amount = ?, monthly_rate = ?, period_months = ?, frequency = ?, interest_method = ?, fee_pct = ?, offered_at = NOW() WHERE id = ?');
                mysqli_stmt_bind_param($s, 'ddissdi', $amt, $rate, $months, $freq, $method, $fee, $id); mysqli_stmt_execute($s);
                cl_audit($conn, $id, 'Approved: ' . cl_money($amt) . ' at ' . $rate . '%/month ' . $method . ', ' . $months . ' month(s) ' . strtolower($freq) . ($fee ? ', fee ' . $fee . '%' : '') . ($changed ? ' (adjusted)' : ''), $st, 'Approved', $reason ?: null, $me);
                mysqli_query($conn, "UPDATE cl_applications SET status = 'Approved' WHERE id = $id");
                cl_set_status($conn, $id, 'Awaiting Customer Acceptance', $me, 'Offer sent: total to repay ' . cl_money($calc['totals']['total']), 'Offer sent');
                mysqli_commit($conn);
                $msg = 'Approved. The offer (total ' . cl_money($calc['totals']['total']) . ') is waiting for the customer\'s acceptance.'; break;
            case 'reject':
                if (!in_array($st, ['Submitted', 'Under Review', 'Additional Information Required'], true)) { throw new RuntimeException('Not possible at this stage.'); }
                if (mb_strlen($reason) < 10) { throw new RuntimeException('Write the reason for the decision (the customer sees it).'); }
                cl_set_status($conn, $id, 'Rejected', $me, $reason, 'Rejected'); $msg = 'Rejected. The customer was notified.'; break;
            case 'disburse':
                if ($st !== 'Agreement Signed') { throw new RuntimeException('Only a signed agreement can be disbursed.'); }
                if (cl_testing($conn)) { throw new RuntimeException('Testing mode is ON: no disbursement is allowed until RISE MOTIVE has regulatory clearance (Settings).'); }
                $dref = mb_substr(trim((string) $_POST['disbursement_ref']), 0, 80); $ddate = (string) $_POST['disbursed_on'];
                if ($dref === '' || !DateTime::createFromFormat('Y-m-d', $ddate) || $ddate > date('Y-m-d')) { throw new RuntimeException('Enter the transfer reference and the date it was paid out.'); }
                // The schedule runs from the disbursement date, same terms as accepted.
                $calc = cl_schedule_calc((float) $a['approved_amount'], (float) $a['monthly_rate'], (int) $a['period_months'], (string) $a['frequency'], (string) $a['interest_method'], (float) $a['fee_pct'], $ddate);
                mysqli_begin_transaction($conn);
                cl_save_schedule($conn, $id, $calc);
                $s = mysqli_prepare($conn, 'UPDATE cl_applications SET disbursed_at = ?, disbursement_ref = ? WHERE id = ?'); $dts = $ddate . ' ' . date('H:i:s');
                mysqli_stmt_bind_param($s, 'ssi', $dts, $dref, $id); mysqli_stmt_execute($s);
                cl_set_status($conn, $id, 'Disbursed', $me, 'Paid out ' . $ddate . ', ref ' . $dref, 'Disbursement recorded');
                cl_set_status($conn, $id, 'Active', $me, 'Repayments start', 'Loan active');
                mysqli_commit($conn);
                $msg = 'Disbursement recorded; the loan is active.'; break;
            case 'payment_verify': case 'payment_reject':
                $pid = (int) $_POST['payment_id'];
                $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_payments WHERE id = $pid AND application_id = $id AND status = 'Pending verification'"));
                if (!$p) { throw new RuntimeException('That payment was already handled.'); }
                mysqli_begin_transaction($conn);
                $new = $act === 'payment_verify' ? 'Verified' : 'Rejected';
                $s = mysqli_prepare($conn, "UPDATE cl_payments SET status = ?, verified_by = ?, verified_at = NOW(), note = ? WHERE id = ? AND status = 'Pending verification'");
                mysqli_stmt_bind_param($s, 'sisi', $new, $me, $reason, $pid); mysqli_stmt_execute($s);
                if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('That payment was already handled.'); }
                if ($new === 'Verified') { cl_apply_payment($conn, $id, (float) $p['amount']); }
                cl_audit($conn, $id, 'Payment ' . strtolower($new) . ': ' . cl_money($p['amount']) . ' ref ' . $p['reference'], null, null, $reason ?: null, $me);
                mysqli_commit($conn);
                portal_notify_customer($conn, (int) $a['customer_id'], $new === 'Verified' ? 'Repayment received: ' . cl_money($p['amount']) . ' (' . $ref . ')' : 'Repayment not confirmed (' . $ref . ')', $new === 'Verified' ? 'Outstanding: ' . cl_money(cl_outstanding($conn, $id)) : $reason, 'loan.php?id=' . $id);
                cl_refresh_overdue($conn, (int) $a['customer_id']);
                $msg = 'Payment ' . strtolower($new) . '.'; break;
            case 'payment_record':
                if (!in_array($st, ['Disbursed', 'Active', 'Overdue', 'Defaulted'], true)) { throw new RuntimeException('Repayments are recorded on active loans.'); }
                $amt = (float) $_POST['amount']; $date = (string) $_POST['paid_on']; $pref = mb_substr(trim((string) $_POST['reference']), 0, 80);
                if ($amt <= 0 || $amt > cl_outstanding($conn, $id) + 0.01 || !DateTime::createFromFormat('Y-m-d', $date) || $pref === '') { throw new RuntimeException('Enter an amount up to the outstanding balance, the date and the reference.'); }
                mysqli_begin_transaction($conn);
                $s = mysqli_prepare($conn, "INSERT INTO cl_payments (application_id, amount, paid_on, method, reference, status, by_customer, verified_by, verified_at) VALUES (?, ?, ?, ?, ?, 'Verified', 0, ?, NOW())");
                $m = mb_substr((string) ($_POST['method'] ?? ''), 0, 40); mysqli_stmt_bind_param($s, 'idsssi', $id, $amt, $date, $m, $pref, $me);
                try { mysqli_stmt_execute($s); } catch (mysqli_sql_exception $x) { throw new RuntimeException('A payment with this reference already exists.'); }
                cl_apply_payment($conn, $id, $amt);
                cl_audit($conn, $id, 'Repayment recorded by staff: ' . cl_money($amt) . ' ref ' . $pref, null, null, $reason ?: null, $me);
                mysqli_commit($conn);
                portal_notify_customer($conn, (int) $a['customer_id'], 'Repayment received: ' . cl_money($amt) . ' (' . $ref . ')', 'Outstanding: ' . cl_money(cl_outstanding($conn, $id)), 'loan.php?id=' . $id);
                cl_refresh_overdue($conn, (int) $a['customer_id']);
                $msg = 'Repayment recorded.'; break;
            case 'default':
                if (!in_array($st, ['Overdue'], true) || mb_strlen($reason) < 10) { throw new RuntimeException('Only an overdue loan can be declared in default, with the reason.'); }
                cl_set_status($conn, $id, 'Defaulted', $me, $reason, 'Declared in default'); $msg = 'Loan declared in default.'; break;
            case 'cancel':
                if (!in_array($st, ['Submitted', 'Under Review', 'Additional Information Required', 'Awaiting Customer Acceptance', 'Agreement Signed'], true) || mb_strlen($reason) < 10) { throw new RuntimeException('Write the reason for cancelling.'); }
                cl_set_status($conn, $id, 'Cancelled', $me, $reason, 'Cancelled by RISE MOTIVE'); $msg = 'Cancelled.'; break;
        }
        header('Location: application.php?id=' . $id . '&success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_applications WHERE id = $id"));
$el = cl_eligibility($conn, (int) $a['customer_id']);
$fin = json_decode((string) $a['financial'], true) ?: []; $col = json_decode((string) $a['collateral'], true) ?: []; $per = json_decode((string) $a['personal'], true) ?: [];
$docs = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM cl_documents WHERE application_id = $id ORDER BY id"), MYSQLI_ASSOC);
$sched = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM cl_schedule WHERE application_id = $id ORDER BY no"), MYSQLI_ASSOC);
$pays = mysqli_fetch_all(mysqli_query($conn, "SELECT p.*, u.names FROM cl_payments p LEFT JOIN users u ON u.id = p.verified_by WHERE p.application_id = $id ORDER BY p.id DESC"), MYSQLI_ASSOC);
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT l.*, u.names FROM cl_audit l LEFT JOIN users u ON u.id = l.user_id WHERE l.application_id = $id ORDER BY l.id DESC"), MYSQLI_ASSOC);
$history = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, status, requested_amount, approved_amount, created_at FROM cl_applications WHERE customer_id = ' . (int) $a['customer_id'] . " AND id <> $id AND status <> 'Draft' ORDER BY id DESC"), MYSQLI_ASSOC);
$st = $a['status'];
include '../../includes/header.php'; include '../../includes/sidebar.php';
cl_tabs('');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<a href="index.php" class="small text-muted text-decoration-none">&larr; Applications</a>
<h4 class="mt-1"><?= $ref; ?> · <?= $e($c['name']); ?> <?= cl_badge($st); ?></h4>
<div class="row g-3">
<div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <h6>Request</h6>
        <div class="row"><div class="col-6"><strong><?= cl_money($a['requested_amount']); ?></strong> for <?= (int) $a['period_months']; ?> month(s), <?= $e(strtolower((string) $a['frequency'])); ?></div>
            <div class="col-6">Purpose: <?= $e($a['purpose'] === 'Other' ? $a['purpose_other'] : $a['purpose']); ?></div></div>
        <div class="mt-1">Use: <?= $e($a['intended_use']); ?></div>
        <div>Proposed repayment date <?= $a['proposed_date'] ? date('d M Y', strtotime($a['proposed_date'])) : '—'; ?> · disbursement by <?= $e($a['disbursement_method']); ?> · submitted <?= $a['submitted_at'] ? date('d M Y, H:i', strtotime($a['submitted_at'])) : '—'; ?></div>
        <hr><h6>Eligibility</h6>
        <div>At submission: <?= number_format((int) $a['points_at_submit']); ?> verified points → limit available <strong><?= cl_money($a['limit_at_submit']); ?></strong>.
            Today: <?= number_format($el['points']); ?> points, profit <?= cl_money($el['profit']); ?>, owes <?= cl_money($el['owed_loans'] + $el['owed_invoices']); ?> → <strong><?= cl_money($el['limit']); ?></strong>.</div>
        <?php if ((float) $a['requested_amount'] > (float) $a['limit_at_submit']) { ?><div class="text-danger">Above the unsecured limit by <?= cl_money((float) $a['requested_amount'] - (float) $a['limit_at_submit']); ?>. Collateral offered: <strong><?= $a['has_collateral'] === 'yes' ? 'yes' : 'no'; ?></strong>.</div><?php } ?>
        <hr><h6>Personal information</h6>
        <div class="row"><?php foreach (['customer_id' => 'Customer ID', 'national_id' => 'National ID', 'date_of_birth' => 'Date of birth', 'gender' => 'Gender', 'phone' => 'Phone', 'email' => 'Email', 'marital_status' => 'Marital status'] as $k => $l) { ?><div class="col-6"><?= $l; ?>: <?= $e($per[$k] ?? ''); ?></div><?php } ?>
            <div class="col-12">Address: <?= $e(trim(($per['address'] ?? '') . ', ' . ($per['cell'] ?? '') . ', ' . ($per['sector'] ?? '') . ', ' . ($per['district'] ?? ''), ', ')); ?></div></div>
        <hr><h6>Financial information</h6>
        <div class="row"><?php foreach (['occupation' => 'Occupation', 'employer' => 'Employer / business', 'work_location' => 'Location', 'monthly_income' => 'Monthly income', 'other_income' => 'Other income', 'household_expenses' => 'Household expenses / month',
            'existing_loans' => 'Existing loans / month', 'other_debts' => 'Other debts', 'repayment_capacity' => 'Repayment capacity / month', 'repayment_source' => 'Source of repayment'] as $k => $l) { $v = $fin[$k] ?? ''; ?>
            <div class="col-6"><?= $l; ?>: <strong><?= is_numeric($v) && $v !== '' ? cl_money($v) : $e($v); ?></strong></div><?php } ?></div>
        <?php if ($col) { ?><hr><h6>Collateral (to be assessed; not accepted automatically)</h6><div class="row"><?php foreach (['type' => 'Type', 'description' => 'Description', 'value' => 'Estimated value', 'ownership' => 'Ownership', 'location' => 'Location'] as $k => $l) { ?><div class="col-6"><?= $l; ?>: <?= $k === 'value' ? cl_money($col[$k] ?? 0) : $e($col[$k] ?? ''); ?></div><?php } ?></div><?php } ?>
        <?php if ($docs) { ?><hr><h6>Documents</h6><?php foreach ($docs as $d) { ?><div><a href="file.php?id=<?= (int) $d['id']; ?>" target="_blank"><?= $e($d['original_name']); ?></a> <span class="text-muted">(<?= $e(str_replace('_', ' ', $d['kind'])); ?>, <?= date('d M Y', strtotime($d['created_at'])); ?>)</span></div><?php } ?><?php } ?>
        <?php if ($history) { ?><hr><h6>Customer's other applications</h6><?php foreach ($history as $h) { ?><div><a href="application.php?id=<?= (int) $h['id']; ?>"><?= cl_ref((int) $h['id'], $h['created_at']); ?></a> · <?= cl_money($h['approved_amount'] ?? $h['requested_amount']); ?> · <?= $e($h['status']); ?></div><?php } ?><?php } ?>
    </div></div>
    <?php if ($sched) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><table class="table table-sm mb-0 small align-middle">
        <tr><th class="ps-3">#</th><th>Due</th><th class="text-end">Principal</th><th class="text-end">Interest</th><th class="text-end">Fee</th><th class="text-end">Due</th><th class="text-end">Paid</th><th>Status</th></tr>
        <?php foreach ($sched as $r) { $late = (float) $r['paid'] < (float) $r['amount_due'] - 0.004 && $r['due_date'] < date('Y-m-d'); ?><tr<?= $late ? ' class="table-danger"' : ''; ?>><td class="ps-3"><?= (int) $r['no']; ?></td><td><?= date('d M Y', strtotime($r['due_date'])); ?></td>
            <td class="text-end"><?= number_format((float) $r['principal']); ?></td><td class="text-end"><?= number_format((float) $r['interest']); ?></td><td class="text-end"><?= number_format((float) $r['fee']); ?></td><td class="text-end"><?= number_format((float) $r['amount_due']); ?></td><td class="text-end"><?= number_format((float) $r['paid']); ?></td><td><?= $late ? 'Overdue' : $e($r['status']); ?></td></tr><?php } ?>
        <tr class="fw-bold"><td class="ps-3" colspan="5">Outstanding <?= cl_money(cl_outstanding($conn, $id)); ?></td><td class="text-end"><?= number_format(array_sum(array_column($sched, 'amount_due'))); ?></td><td class="text-end"><?= number_format(array_sum(array_column($sched, 'paid'))); ?></td><td></td></tr>
    </table><div class="p-2 small"><a href="agreement.php?id=<?= $id; ?>" target="_blank"><i class="bi bi-file-earmark-text"></i> Loan agreement</a></div></div></div>
    <?php } ?>
    <?php if ($pays) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Repayments</h6>
        <?php foreach ($pays as $p) { ?><div class="d-flex justify-content-between align-items-center border-bottom py-1 flex-wrap gap-1">
            <span><?= date('d M Y', strtotime($p['paid_on'])); ?> · <?= cl_money($p['amount']); ?> · <?= $e($p['method'] . ' · ' . $p['reference']); ?> · <?= $p['by_customer'] ? 'reported by the customer' : 'recorded by staff'; ?></span>
            <span><?= $e($p['status']); ?><?= $p['names'] ? ' by ' . $e($p['names']) : ''; ?>
            <?php if ($p['status'] === 'Pending verification' && $canEdit) { ?>
                <form method="POST" class="d-inline"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="payment_id" value="<?= (int) $p['id']; ?>"><input type="hidden" name="action" value="payment_verify"><button class="btn btn-sm btn-success">Verify</button></form>
                <form method="POST" class="d-inline" onsubmit="this.reason.value = prompt('Why is this payment not confirmed?') || ''; return this.reason.value.length > 3;"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="payment_id" value="<?= (int) $p['id']; ?>"><input type="hidden" name="action" value="payment_reject"><input type="hidden" name="reason"><button class="btn btn-sm btn-outline-danger">Reject</button></form>
            <?php } ?></span></div><?php } ?>
    </div></div><?php } ?>
</div>
<div class="col-lg-5">
    <?php $f = function ($action, $label, $cls, $body = '', $confirm = '') use ($id, $e) { echo '<form method="POST" class="mb-2"' . ($confirm ? ' onsubmit="return confirm(\'' . $e($confirm) . '\');"' : '') . '><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . $action . '">' . $body . '<button class="btn btn-sm ' . $cls . '">' . $label . '</button></form>'; }; ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Decisions</h6>
    <?php if ($st === 'Submitted' && $canEdit) { $f('review', 'Start review', 'btn-primary w-100'); } ?>
    <?php if (in_array($st, ['Submitted', 'Under Review'], true) && $canEdit) { $f('request_info', 'Request more information', 'btn-outline-primary', '<textarea name="reason" class="form-control form-control-sm mb-1" rows="2" placeholder="Exactly what the customer must provide"></textarea>'); } ?>
    <?php if (in_array($st, ['Submitted', 'Under Review'], true) && $canApprove) { ?>
        <form method="POST" class="border rounded p-2 mb-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="approve"><div class="fw-semibold mb-1">Approve and send the offer</div>
            <div class="row g-1"><div class="col-6"><label class="text-muted">Amount</label><input type="number" name="approved_amount" step="1000" class="form-control form-control-sm" value="<?= (int) $a['requested_amount']; ?>"></div>
                <div class="col-6"><label class="text-muted">Rate %/month</label><input type="number" name="monthly_rate" step="0.01" class="form-control form-control-sm" value="<?= $e(cl_setting($conn, 'monthly_rate')); ?>"></div>
                <div class="col-6"><label class="text-muted">Months</label><input type="number" name="period_months" min="1" class="form-control form-control-sm" value="<?= (int) $a['period_months']; ?>"></div>
                <div class="col-6"><label class="text-muted">Frequency</label><select name="frequency" class="form-select form-select-sm"><?php foreach (['Monthly', 'Weekly'] as $o) { ?><option <?= $a['frequency'] === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select></div>
                <div class="col-6"><label class="text-muted">Interest method</label><select name="interest_method" class="form-select form-select-sm"><option value="flat" <?= cl_setting($conn, 'interest_method') === 'flat' ? 'selected' : ''; ?>>Flat</option><option value="reducing" <?= cl_setting($conn, 'interest_method') === 'reducing' ? 'selected' : ''; ?>>Reducing balance</option></select></div>
                <div class="col-6"><label class="text-muted">Fee %</label><input type="number" name="fee_pct" step="0.1" min="0" class="form-control form-control-sm" value="<?= $e(cl_setting($conn, 'fee_pct')); ?>"></div>
                <div class="col-12"><textarea name="reason" class="form-control form-control-sm" rows="2" placeholder="Reason (required if the amount, rate or period is adjusted; also note the collateral assessment)"></textarea></div></div>
            <button class="btn btn-sm btn-success mt-1" onclick="return confirm('Approve and send this offer to the customer?');">Approve &amp; send offer</button></form>
    <?php } ?>
    <?php if (in_array($st, ['Submitted', 'Under Review', 'Additional Information Required'], true) && $canApprove) { $f('reject', 'Reject', 'btn-outline-danger', '<textarea name="reason" class="form-control form-control-sm mb-1" rows="2" placeholder="Reason for the decision (the customer sees it)"></textarea>', 'Reject this application?'); } ?>
    <?php if ($st === 'Agreement Signed' && $canApprove) { ?>
        <?php if (cl_testing($conn)) { ?><div class="alert alert-warning">Signed by the customer <?= $a['accepted_at'] ? date('d M Y', strtotime($a['accepted_at'])) : ''; ?>. <strong>Testing mode:</strong> disbursement is blocked until RISE MOTIVE has its lending authorisation or a licensed partner.</div>
        <?php } else { $f('disburse', 'Record disbursement', 'btn-success', '<input name="disbursement_ref" class="form-control form-control-sm mb-1" placeholder="Transfer reference"><input type="date" name="disbursed_on" class="form-control form-control-sm mb-1" value="' . date('Y-m-d') . '">', 'Confirm that the money was paid out?'); } ?>
    <?php } ?>
    <?php if (in_array($st, ['Disbursed', 'Active', 'Overdue', 'Defaulted'], true) && $canEdit) { $f('payment_record', 'Record a repayment', 'btn-primary', '<div class="row g-1 mb-1"><div class="col-6"><input type="number" name="amount" min="1" class="form-control form-control-sm" placeholder="Amount"></div><div class="col-6"><input type="date" name="paid_on" class="form-control form-control-sm" value="' . date('Y-m-d') . '"></div><div class="col-6"><input name="method" class="form-control form-control-sm" placeholder="Method" value="Mobile Money"></div><div class="col-6"><input name="reference" class="form-control form-control-sm" placeholder="Reference"></div></div>'); } ?>
    <?php if ($st === 'Overdue' && $canApprove) { $f('default', 'Declare in default', 'btn-outline-danger', '<textarea name="reason" class="form-control form-control-sm mb-1" rows="2" placeholder="Reason and steps taken"></textarea>', 'Declare this loan in default?'); } ?>
    <?php if (in_array($st, ['Submitted', 'Under Review', 'Additional Information Required', 'Awaiting Customer Acceptance', 'Agreement Signed'], true) && $canApprove) { $f('cancel', 'Cancel', 'btn-outline-secondary', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason for cancelling">', 'Cancel this application?'); } ?>
    <?php if (in_array($st, ['Rejected', 'Cancelled', 'Fully Repaid'], true)) { ?><div class="text-muted">No further action: <?= $e(strtolower($st)); ?>.</div><?php } ?>
    <?php if ($st === 'Awaiting Customer Acceptance') { ?><div class="text-muted">Waiting for the customer to accept the offer in the portal.</div><?php } ?>
    <?php if ($st === 'Additional Information Required') { ?><div class="text-muted">Waiting for the customer: <?= $e($a['info_request']); ?></div><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body small"><h6>Audit log</h6><div style="max-height:420px; overflow-y:auto;">
        <?php foreach ($log as $l) { ?><div class="border-bottom py-1"><span class="text-muted"><?= date('d M Y, H:i', strtotime($l['created_at'])); ?></span> · <strong><?= $e($l['names'] ?? ($l['customer_id'] ? 'Customer' : 'System')); ?></strong> · <?= $e($l['action']); ?>
            <?= $l['to_status'] ? '<span class="text-muted"> (' . $e($l['from_status']) . ' → ' . $e($l['to_status']) . ')</span>' : ''; ?><?= $l['reason'] ? '<div class="text-muted">' . $e($l['reason']) . '</div>' : ''; ?></div><?php } ?>
    </div></div></div>
</div></div>
<?php include '../../includes/footer.php'; ?>