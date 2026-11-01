<?php
/**
 * NEW FILE: portal/loan.php — one loan application of the signed-in customer: status, more information when asked,
 * the offer (full schedule and charges) to accept or decline, the repayment schedule, payments made.
 */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/cl_helpers.php';
require_once __DIR__ . '/../includes/evidence_store.php';
cl_ensure($conn);
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_applications WHERE id = $id AND customer_id = $cid"));
if (!$a) { header('Location: loans.php'); exit; }
if ($a['status'] === 'Draft') { header('Location: loan_apply.php?id=' . $id); exit; }
cl_refresh_overdue($conn, $cid);
$a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_applications WHERE id = $id"));
$ref = cl_ref($id, $a['created_at']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (!portal_csrf_ok()) { throw new RuntimeException('Your session expired. Please try again.'); }
        if ($action === 'more_info' && $a['status'] === 'Additional Information Required') {
            $reply = trim((string) ($_POST['reply'] ?? ''));
            $n = 0; $files = $_FILES['files'] ?? null;
            if ($files && is_array($files['name'])) { foreach ($files['name'] as $i => $nm) { if (($files['error'][$i] ?? 4) === UPLOAD_ERR_NO_FILE) { continue; }
                $r = evidence_save($conn, ['name' => $nm, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]], null, CL_DOC_TYPES, 10 * 1024 * 1024);
                $bn = mb_substr(basename($nm), 0, 255); $s = mysqli_prepare($conn, "INSERT INTO cl_documents (application_id, kind, file_ref, original_name) VALUES (?, 'additional', ?, ?)");
                mysqli_stmt_bind_param($s, 'iss', $id, $r, $bn); mysqli_stmt_execute($s); $n++; } }
            if ($reply === '' && !$n) { throw new RuntimeException('Write your answer or attach the requested documents.'); }
            cl_set_status($conn, $id, 'Submitted', null, 'Customer answered: ' . mb_substr($reply, 0, 900) . ($n ? " ($n file(s) attached)" : ''), 'Additional information provided');
            portal_notify_staff($conn, 'Loan ' . $ref . ': information provided', $me['name'] . ' answered the request for more information.');
            $msg = 'Thank you. Your application is back with RISE MOTIVE.';
        } elseif ($action === 'accept' && $a['status'] === 'Awaiting Customer Acceptance') {
            $name = trim((string) ($_POST['full_name'] ?? ''));
            if (empty($_POST['agree']) || mb_strtolower($name) !== mb_strtolower(trim($me['name']))) { throw new RuntimeException('Tick the agreement and type your full name exactly as shown (' . $me['name'] . ') to sign.'); }
            $s = mysqli_prepare($conn, "UPDATE cl_applications SET accepted_at = NOW(), accepted_name = ? WHERE id = ? AND status = 'Awaiting Customer Acceptance'");
            mysqli_stmt_bind_param($s, 'si', $name, $id); mysqli_stmt_execute($s);
            if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('This offer was already answered.'); }
            cl_set_status($conn, $id, 'Agreement Signed', null, 'Offer accepted and signed electronically by ' . $name . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? ''), 'Offer accepted');
            portal_notify_staff($conn, 'Loan ' . $ref . ' accepted', $me['name'] . ' accepted and signed the loan offer.');
            $msg = 'Offer accepted and signed. RISE MOTIVE will contact you about the disbursement.';
        } elseif ($action === 'decline' && $a['status'] === 'Awaiting Customer Acceptance') {
            cl_set_status($conn, $id, 'Cancelled', null, 'Offer declined by the customer', 'Offer declined');
            $msg = 'You declined the offer.';
        } elseif ($action === 'cancel' && in_array($a['status'], ['Submitted', 'Under Review', 'Additional Information Required'], true)) {
            cl_set_status($conn, $id, 'Cancelled', null, 'Withdrawn by the customer', 'Application withdrawn');
            $msg = 'Application withdrawn.';
        } elseif ($action === 'payment' && in_array($a['status'], ['Disbursed', 'Active', 'Overdue', 'Defaulted'], true)) {
            $amt = (float) ($_POST['amount'] ?? 0); $date = (string) ($_POST['paid_on'] ?? ''); $refNo = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 80); $method = mb_substr(trim((string) ($_POST['method'] ?? '')), 0, 40);
            if ($amt <= 0 || $amt > cl_outstanding($conn, $id) + 0.01) { throw new RuntimeException('Enter an amount up to your outstanding balance (' . cl_money(cl_outstanding($conn, $id)) . ').'); }
            if (!DateTime::createFromFormat('Y-m-d', $date) || $date > date('Y-m-d') || $refNo === '') { throw new RuntimeException('Enter the payment date and the transaction reference (e.g. the Mobile Money ID).'); }
            $s = mysqli_prepare($conn, 'INSERT IGNORE INTO cl_payments (application_id, amount, paid_on, method, reference) VALUES (?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'idsss', $id, $amt, $date, $method, $refNo); mysqli_stmt_execute($s);
            if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('A payment with this reference was already recorded.'); }
            cl_audit($conn, $id, 'Payment declared by the customer: ' . cl_money($amt) . ' ref ' . $refNo, null, null, null, null, $cid);
            portal_notify_staff($conn, 'Loan repayment to verify (' . $ref . ')', $me['name'] . ' reported a payment of ' . cl_money($amt) . ', reference ' . $refNo . '.');
            $msg = 'Payment recorded. It counts once RISE MOTIVE has verified it.';
        } else { throw new RuntimeException('That action is not available now.'); }
        header('Location: loan.php?id=' . $id . '&success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$sched = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM cl_schedule WHERE application_id = $id ORDER BY no"), MYSQLI_ASSOC);
$pays = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM cl_payments WHERE application_id = $id ORDER BY id DESC"), MYSQLI_ASSOC);
$docs = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM cl_documents WHERE application_id = $id ORDER BY id"), MYSQLI_ASSOC);
$hist = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM cl_audit WHERE application_id = $id AND to_status IS NOT NULL ORDER BY id"), MYSQLI_ASSOC);
$tot = ['principal' => array_sum(array_column($sched, 'principal')), 'interest' => array_sum(array_column($sched, 'interest')), 'fee' => array_sum(array_column($sched, 'fee')), 'total' => array_sum(array_column($sched, 'amount_due')), 'paid' => array_sum(array_column($sched, 'paid'))];
portal_header('Loan ' . $ref, $me);
portal_alerts();
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= portal_e($error); ?></div><?php } ?>
<a href="loans.php" class="small">&larr; RM Loans</a>
<h1 class="h5 mt-1"><?= $ref; ?> · <span class="badge bg-primary"><?= portal_e($a['status']); ?></span></h1>
<div class="small text-muted mb-3">Requested <?= cl_money($a['requested_amount']); ?> · <?= portal_e($a['purpose'] === 'Other' ? $a['purpose_other'] : $a['purpose']); ?> · submitted <?= $a['submitted_at'] ? date('d M Y, H:i', strtotime($a['submitted_at'])) : '—'; ?></div>
<div class="d-flex flex-wrap gap-1 mb-3"><?php foreach ($hist as $h) { ?><span class="badge bg-light text-dark border"><?= date('d M', strtotime($h['created_at'])); ?> · <?= portal_e($h['to_status']); ?></span><?php } ?></div>

<?php if ($a['status'] === 'Additional Information Required') { ?>
<div class="card mb-3" style="border-left:4px solid #1E2FE0 !important;"><div class="card-body"><h2 class="h6">RISE MOTIVE needs more information</h2>
    <p class="small" style="white-space:pre-wrap;"><?= portal_e($a['info_request']); ?></p>
    <form method="POST" enctype="multipart/form-data"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="more_info">
        <textarea name="reply" class="form-control form-control-sm mb-2" rows="3" placeholder="Your answer"></textarea>
        <input type="file" name="files[]" multiple class="form-control form-control-sm mb-2" accept=".pdf,.jpg,.jpeg,.png,.webp,.docx,.xlsx">
        <button class="btn btn-primary btn-sm">Send to RISE MOTIVE</button></form></div></div>
<?php } ?>

<?php if ($a['status'] === 'Awaiting Customer Acceptance') { ?>
<div class="card mb-3" style="border-left:4px solid #0FA968 !important;"><div class="card-body"><h2 class="h6">Your loan offer</h2>
    <table class="table table-sm small"><tr><td>Approved amount</td><td class="text-end fw-bold"><?= cl_money($a['approved_amount']); ?></td></tr>
        <tr><td>Interest (<?= (float) $a['monthly_rate']; ?>% per month, <?= $a['interest_method'] === 'reducing' ? 'reducing balance' : 'flat'; ?>)</td><td class="text-end"><?= cl_money($tot['interest']); ?></td></tr>
        <?php if ($tot['fee'] > 0) { ?><tr><td>Fees</td><td class="text-end"><?= cl_money($tot['fee']); ?></td></tr><?php } ?>
        <tr class="fw-bold"><td>Total to repay</td><td class="text-end"><?= cl_money($tot['total']); ?></td></tr>
        <tr><td>Instalments</td><td class="text-end"><?= count($sched); ?> · <?= portal_e(strtolower((string) $a['frequency'])); ?> · first on <?= $sched ? date('d M Y', strtotime($sched[0]['due_date'])) : '—'; ?></td></tr>
        <?php if ((float) cl_setting($conn, 'late_fee_pct') > 0) { ?><tr><td>Late payment fee</td><td class="text-end"><?= (float) cl_setting($conn, 'late_fee_pct'); ?>% of the late amount</td></tr><?php } ?></table>
    <?php if ((float) $a['approved_amount'] !== (float) $a['requested_amount']) { ?><div class="alert alert-info small">The approved amount differs from the <?= cl_money($a['requested_amount']); ?> you requested.</div><?php } ?>
    <p class="small">Read the full schedule below and the <a href="loan_agreement.php?id=<?= $id; ?>" target="_blank">loan agreement</a> before accepting.</p>
    <form method="POST" class="mb-2"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="accept">
        <label class="d-block small mb-2"><input type="checkbox" name="agree" value="1" class="form-check-input me-1" required>I accept this offer, the repayment schedule and the loan agreement, and I understand the consequences of late payment or default.</label>
        <input name="full_name" class="form-control form-control-sm mb-2" required placeholder="Type your full name to sign: <?= portal_e($me['name']); ?>">
        <button class="btn btn-success btn-sm">Accept and sign</button></form>
    <form method="POST" onsubmit="return confirm('Decline this offer?');"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="decline"><button class="btn btn-outline-danger btn-sm">Decline</button></form>
</div></div>
<?php } ?>

<?php if ($sched) { ?>
<div class="card mb-3"><div class="card-body"><h2 class="h6">Repayment schedule</h2><div class="table-responsive"><table class="table table-sm mb-0 small align-middle">
    <tr><th>#</th><th>Due date</th><th class="text-end">Principal</th><th class="text-end">Interest</th><th class="text-end">Fees</th><th class="text-end">Amount due</th><th class="text-end">Paid</th><th>Status</th></tr>
    <?php foreach ($sched as $r) { $late = (float) $r['paid'] < (float) $r['amount_due'] - 0.004 && $r['due_date'] < date('Y-m-d'); ?>
    <tr<?= $late ? ' class="table-danger"' : ''; ?>><td><?= (int) $r['no']; ?></td><td><?= date('d M Y', strtotime($r['due_date'])); ?></td><td class="text-end"><?= number_format((float) $r['principal']); ?></td><td class="text-end"><?= number_format((float) $r['interest']); ?></td>
        <td class="text-end"><?= number_format((float) $r['fee']); ?></td><td class="text-end fw-semibold"><?= number_format((float) $r['amount_due']); ?></td><td class="text-end"><?= number_format((float) $r['paid']); ?></td><td><?= $late ? 'Overdue' : portal_e($r['status']); ?></td></tr>
    <?php } ?>
    <tr class="fw-bold"><td colspan="5">Total · outstanding <?= cl_money($tot['total'] - $tot['paid']); ?></td><td class="text-end"><?= number_format($tot['total']); ?></td><td class="text-end"><?= number_format($tot['paid']); ?></td><td></td></tr>
</table></div>
<?php if (in_array($a['status'], ['Agreement Signed', 'Disbursed', 'Active', 'Overdue', 'Defaulted', 'Fully Repaid'], true)) { ?><a href="loan_agreement.php?id=<?= $id; ?>" target="_blank" class="small">View / print the loan agreement</a><?php } ?>
</div></div>
<?php } ?>

<?php if (in_array($a['status'], ['Disbursed', 'Active', 'Overdue', 'Defaulted'], true)) { ?>
<div class="card mb-3"><div class="card-body"><h2 class="h6">Report a repayment</h2>
    <form method="POST" class="row g-2 align-items-end"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="payment">
        <div class="col-6 col-md-3"><label class="small text-muted">Amount (RWF)</label><input type="number" name="amount" min="1" step="1" class="form-control form-control-sm" required></div>
        <div class="col-6 col-md-3"><label class="small text-muted">Date paid</label><input type="date" name="paid_on" max="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" required value="<?= date('Y-m-d'); ?>"></div>
        <div class="col-6 col-md-2"><label class="small text-muted">Method</label><select name="method" class="form-select form-select-sm"><option>Mobile Money</option><option>Bank transfer</option><option>Cash</option></select></div>
        <div class="col-6 col-md-2"><label class="small text-muted">Transaction ref.</label><input name="reference" maxlength="80" class="form-control form-control-sm" required></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Report</button></div>
    </form><div class="small text-muted mt-1">A repayment counts once RISE MOTIVE has verified it.</div></div></div>
<?php } ?>
<?php if ($pays) { ?><div class="card mb-3"><div class="card-body"><h2 class="h6">Payments</h2>
    <?php foreach ($pays as $p) { ?><div class="d-flex justify-content-between small border-bottom py-1"><span><?= date('d M Y', strtotime($p['paid_on'])); ?> · <?= portal_e($p['method'] . ' · ' . $p['reference']); ?></span><span><?= cl_money($p['amount']); ?> <span class="badge <?= $p['status'] === 'Verified' ? 'bg-success' : ($p['status'] === 'Rejected' ? 'bg-danger' : 'bg-secondary'); ?>"><?= portal_e($p['status']); ?></span></span></div><?php } ?>
</div></div><?php } ?>
<?php if ($docs) { ?><div class="card mb-3"><div class="card-body small"><h2 class="h6">My documents</h2><?php foreach ($docs as $d) { ?><div><a href="loan_file.php?id=<?= (int) $d['id']; ?>" target="_blank"><?= portal_e($d['original_name']); ?></a> <span class="text-muted">(<?= portal_e(str_replace('_', ' ', $d['kind'])); ?>)</span></div><?php } ?></div></div><?php } ?>
<?php if (in_array($a['status'], ['Submitted', 'Under Review', 'Additional Information Required'], true)) { ?>
<form method="POST" onsubmit="return confirm('Withdraw this application?');"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-link text-danger btn-sm p-0">Withdraw this application</button></form>
<?php } ?>
<?php portal_footer();