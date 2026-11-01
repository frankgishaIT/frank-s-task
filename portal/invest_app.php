<?php
// NEW FILE: portal/invest_app.php — one investment application: status, more information, payment instructions and payment reporting.
require __DIR__ . '/invest_start.php';
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_applications WHERE id = $id AND customer_id = $cid"));
if (!$a) { header('Location: my_investments.php'); exit; }
if ($a['status'] === 'Draft') { header('Location: invest_apply.php?id=' . $id); exit; }
$p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM inv_projects WHERE id = ' . (int) $a['project_id']));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    try {
        if (!portal_csrf_ok()) { throw new RuntimeException('Your session expired. Please try again.'); }
        if ($act === 'info' && $a['status'] === 'Additional Information Required') {
            $r = trim((string) $_POST['reply']); if ($r === '') { throw new RuntimeException('Write your answer.'); }
            inv_set_app_status($conn, $id, 'Submitted', null, 'Investor answered: ' . mb_substr($r, 0, 900), 'Information provided');
            portal_notify_staff($conn, 'Investment ' . inv_app_ref($a) . ': information provided', $me['name'] . ' answered.'); $msg = 'Sent.';
        } elseif ($act === 'paid' && $a['status'] === 'Awaiting Payment') {
            if (inv_testing($conn)) { throw new RuntimeException('Pilot phase: please do not send money yet. RISE MOTIVE will tell you when contributions open.'); }
            $ref = mb_substr(trim((string) $_POST['payer_reference']), 0, 80); $date = (string) $_POST['paid_on']; $method = in_array($_POST['method'] ?? '', INV_PAY_METHODS, true) ? $_POST['method'] : $a['payment_method'];
            if ($ref === '' || !DateTime::createFromFormat('Y-m-d', $date) || $date > date('Y-m-d')) { throw new RuntimeException('Enter the transaction ID and the payment date.'); }
            $s = mysqli_prepare($conn, "UPDATE inv_contributions SET payer_reference = ?, paid_on = ?, method = ?, status = 'Pending verification' WHERE application_id = ? AND status = 'Awaiting payment'");
            mysqli_stmt_bind_param($s, 'sssi', $ref, $date, $method, $id); mysqli_stmt_execute($s);
            if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('This payment was already reported.'); }
            inv_set_app_status($conn, $id, 'Payment Pending Verification', null, 'Paid ' . $date . ' by ' . $method . ', transaction ' . $ref, 'Payment reported');
            portal_notify_staff($conn, 'Investment payment to verify (' . inv_app_ref($a) . ')', $me['name'] . ' reported paying ' . cl_money($a['amount']) . ', transaction ' . $ref . '.'); $msg = 'Thank you. Your contribution counts once RISE MOTIVE has verified it.';
        } elseif ($act === 'withdraw' && in_array($a['status'], ['Submitted', 'Under Review', 'Additional Information Required', 'Approved', 'Awaiting Payment'], true)) {
            mysqli_query($conn, "DELETE FROM inv_contributions WHERE application_id = $id AND status = 'Awaiting payment'");
            inv_set_app_status($conn, $id, 'Cancelled', null, 'Withdrawn by the investor', 'Application withdrawn'); $msg = 'Application withdrawn.';
        } else { throw new RuntimeException('That action is not available now.'); }
        header('Location: invest_app.php?id=' . $id . '&success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$k = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_contributions WHERE application_id = $id"));
$hist = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM inv_audit WHERE application_id = $id ORDER BY id"), MYSQLI_ASSOC);
portal_header(inv_app_ref($a), $me);
portal_alerts();
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= portal_e($error); ?></div><?php } ?>
<a href="my_investments.php" class="small">&larr; My investments</a>
<h1 class="h5 mt-1"><?= inv_app_ref($a); ?> · <span class="badge bg-primary"><?= portal_e($a['status']); ?></span></h1>
<div class="small text-muted mb-3"><?= portal_e($p['name']); ?> · <?= cl_money($a['amount']); ?> · submitted <?= $a['submitted_at'] ? date('d M Y, H:i', strtotime($a['submitted_at'])) : '—'; ?> · <a href="invest_agreement.php?app=<?= $id; ?>" target="_blank">agreement</a></div>
<?php if ($a['status'] === 'Additional Information Required') { ?><div class="card mb-3"><div class="card-body"><h2 class="h6">RISE MOTIVE needs more information</h2><p class="small"><?= portal_e($a['info_request']); ?></p>
    <form method="POST"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="info"><textarea name="reply" class="form-control form-control-sm mb-2" rows="3"></textarea><button class="btn btn-primary btn-sm">Send</button></form></div></div><?php } ?>
<?php if ($a['status'] === 'Awaiting Payment' && $k) { ?>
<div class="card mb-3" style="border-left:4px solid #0FA968 !important;"><div class="card-body"><h2 class="h6">Your application is approved: payment instructions</h2>
    <p>Contribution reference: <strong class="fs-5"><?= portal_e($k['contribution_ref']); ?></strong> · amount <strong><?= cl_money($k['amount']); ?></strong></p>
    <?php if (inv_testing($conn)) { ?><div class="alert alert-warning small mb-0"><strong>Pilot phase: please do not send any money yet.</strong> RISE MOTIVE will notify you when contributions open.</div>
    <?php } else { ?><div class="small mb-3" style="white-space:pre-wrap;"><?= portal_e(inv_setting($conn, 'payment_instructions')); ?></div>
    <form method="POST" class="row g-2 align-items-end"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="paid">
        <div class="col-md-4"><label class="small text-muted">Transaction ID</label><input name="payer_reference" maxlength="80" class="form-control form-control-sm" required></div>
        <div class="col-md-3"><label class="small text-muted">Date paid</label><input type="date" name="paid_on" max="<?= date('Y-m-d'); ?>" value="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" required></div>
        <div class="col-md-3"><label class="small text-muted">Method</label><select name="method" class="form-select form-select-sm"><?php foreach (INV_PAY_METHODS as $o) { ?><option <?= $a['payment_method'] === $o ? 'selected' : ''; ?>><?= $o; ?></option><?php } ?></select></div>
        <div class="col-md-2"><button class="btn btn-success btn-sm w-100">I have paid</button></div></form><?php } ?>
</div></div>
<?php } ?>
<?php if ($k && $k['status'] !== 'Awaiting payment') { ?><div class="card mb-3"><div class="card-body small">Contribution <?= portal_e($k['contribution_ref']); ?>: <?= cl_money($k['amount']); ?> · <?= portal_e($k['status']); ?><?= $k['payer_reference'] ? ' · transaction ' . portal_e($k['payer_reference']) : ''; ?></div></div><?php } ?>
<div class="card mb-3"><div class="card-body small"><h2 class="h6">History</h2><?php foreach ($hist as $h) { ?><div class="border-bottom py-1"><span class="text-muted"><?= date('d M Y, H:i', strtotime($h['created_at'])); ?></span> · <?= portal_e($h['action']); ?></div><?php } ?></div></div>
<?php if (in_array($a['status'], ['Submitted', 'Under Review', 'Additional Information Required', 'Approved', 'Awaiting Payment'], true)) { ?>
<form method="POST" onsubmit="return confirm('Withdraw this application?');"><?= portal_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="withdraw"><button class="btn btn-link text-danger btn-sm p-0">Withdraw this application</button></form>
<?php } ?>
<?php portal_footer();