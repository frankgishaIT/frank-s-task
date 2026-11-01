<?php
/**
 * NEW FILE: modules/rm_invest/application.php — one investment application: review, more information, approval
 * (creates the contribution reference and payment instructions), rejection, contribution verification, refund.
 */
require __DIR__ . '/invest_common.php';
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_applications WHERE id = $id AND status <> 'Draft'"));
if (!$a) { header('Location: applications.php?error=' . urlencode('Application not found.')); exit; }
$p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM inv_projects WHERE id = ' . (int) $a['project_id']));
$c = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM customers WHERE id = ' . (int) $a['customer_id']));
$canEdit = access_can('rm_invest.edit', $conn); $canApprove = access_can('rm_invest.approve', $conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $reason = trim((string) ($_POST['reason'] ?? '')); $st = $a['status'];
    try {
        $need = ['review' => 'edit', 'info' => 'edit', 'approve' => 'approve', 'reject' => 'approve', 'verify' => 'approve', 'reject_payment' => 'edit', 'cancel' => 'approve', 'refund' => 'approve'][$act] ?? null;
        if (!$need || !access_can('rm_invest.' . $need, $conn)) { throw new RuntimeException('You are not allowed to do that.'); }
        switch ($act) {
            case 'review': if ($st !== 'Submitted') { throw new RuntimeException('Not possible now.'); } inv_set_app_status($conn, $id, 'Under Review', $me, null, 'Review started'); $msg = 'Under review.'; break;
            case 'info':
                if (!in_array($st, ['Submitted', 'Under Review'], true) || mb_strlen($reason) < 10) { throw new RuntimeException('Write exactly what the investor must provide.'); }
                $s = mysqli_prepare($conn, 'UPDATE inv_applications SET info_request = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'si', $reason, $id); mysqli_stmt_execute($s);
                inv_set_app_status($conn, $id, 'Additional Information Required', $me, $reason, 'More information requested'); $msg = 'Request sent.'; break;
            case 'approve':
                if (!in_array($st, ['Submitted', 'Under Review'], true)) { throw new RuntimeException('Not possible now.'); }
                // Re-check the limits at approval time (other applications may have been approved meanwhile).
                $why = inv_check_application($conn, $p + ['status' => 'Published'], (int) $a['customer_id'], (float) $a['amount'], $id);
                if ($why && strpos($why, 'deadline') === false) { throw new RuntimeException('Cannot approve: ' . $why); }
                mysqli_begin_transaction($conn);
                $ref = 'IC-' . date('Y') . '-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT);
                $s = mysqli_prepare($conn, "INSERT IGNORE INTO inv_contributions (application_id, project_id, customer_id, contribution_ref, amount, method, status) VALUES (?, ?, ?, ?, ?, ?, 'Awaiting payment')");
                mysqli_stmt_bind_param($s, 'iiisds', $id, $a['project_id'], $a['customer_id'], $ref, $a['amount'], $a['payment_method']); mysqli_stmt_execute($s);
                inv_audit($conn, (int) $a['project_id'], $id, 'Approved for ' . cl_money($a['amount']) . '; contribution reference ' . $ref, $reason ?: null, $me);
                mysqli_query($conn, "UPDATE inv_applications SET status = 'Approved' WHERE id = $id");
                inv_set_app_status($conn, $id, 'Awaiting Payment', $me, 'Payment instructions sent', 'Approved');
                mysqli_commit($conn);
                $msg = 'Approved. The investor sees the payment instructions with reference ' . $ref . '.'; break;
            case 'reject':
                if (!in_array($st, ['Submitted', 'Under Review', 'Additional Information Required'], true) || mb_strlen($reason) < 10) { throw new RuntimeException('Write the reason (the investor sees it).'); }
                inv_set_app_status($conn, $id, 'Rejected', $me, $reason, 'Rejected'); $msg = 'Rejected.'; break;
            case 'verify': case 'reject_payment':
                if ($st !== 'Payment Pending Verification') { throw new RuntimeException('No payment to verify.'); }
                if ($act === 'verify' && inv_testing($conn)) { throw new RuntimeException('Testing mode is ON: contributions cannot be confirmed until RISE MOTIVE has legal clearance.'); }
                $k = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_contributions WHERE application_id = $id"));
                mysqli_begin_transaction($conn);
                if ($act === 'verify') {
                    $s = mysqli_prepare($conn, "UPDATE inv_contributions SET status = 'Verified', verified_by = ?, verified_at = NOW(), note = ? WHERE application_id = ? AND status = 'Pending verification'");
                    mysqli_stmt_bind_param($s, 'isi', $me, $reason, $id); mysqli_stmt_execute($s);
                    if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('This payment was already handled.'); }
                    inv_set_app_status($conn, $id, $p['status'] === 'Active' ? 'Active Investor' : 'Confirmed', $me, 'Contribution ' . $k['contribution_ref'] . ' verified', 'Contribution verified');
                    $f = inv_funding($conn, (int) $p['id']);
                    if ($f['raised'] >= $f['required'] - 0.5 && $p['status'] === 'Published') {
                        mysqli_query($conn, "UPDATE inv_projects SET status = 'Funding Closed' WHERE id = " . (int) $p['id']);
                        inv_audit($conn, (int) $p['id'], null, 'Fully funded: funding closed automatically', null, $me);
                        foreach (inv_project_investors($conn, (int) $p['id']) as $cid) { portal_notify_customer($conn, $cid, 'Project funding completed: ' . $p['name'], '', 'my_investments.php', false); }
                    }
                    $msg = 'Contribution verified: the investment is confirmed.';
                } else {
                    if (mb_strlen($reason) < 5) { throw new RuntimeException('Write why the payment could not be verified.'); }
                    mysqli_query($conn, "UPDATE inv_contributions SET status = 'Awaiting payment', payer_reference = NULL, paid_on = NULL WHERE application_id = $id");
                    inv_set_app_status($conn, $id, 'Awaiting Payment', $me, 'Payment not verified: ' . $reason, 'Payment not verified');
                    $msg = 'The investor was asked to check the payment.';
                }
                mysqli_commit($conn); break;
            case 'cancel':
                if (!in_array($st, ['Submitted', 'Under Review', 'Additional Information Required', 'Approved', 'Awaiting Payment'], true) || mb_strlen($reason) < 5) { throw new RuntimeException('Write the reason.'); }
                mysqli_query($conn, "DELETE FROM inv_contributions WHERE application_id = $id AND status = 'Awaiting payment'");
                inv_set_app_status($conn, $id, 'Cancelled', $me, $reason, 'Cancelled by RISE MOTIVE'); $msg = 'Cancelled.'; break;
            case 'refund':
                if (!in_array($st, ['Confirmed', 'Active Investor', 'Payment Pending Verification'], true) || mb_strlen($reason) < 5 || trim((string) $_POST['refund_ref']) === '') { throw new RuntimeException('Write the reason and the refund payment reference.'); }
                mysqli_query($conn, "UPDATE inv_contributions SET status = 'Refunded', note = '" . mysqli_real_escape_string($conn, 'Refund ref ' . trim((string) $_POST['refund_ref'])) . "' WHERE application_id = $id");
                inv_set_app_status($conn, $id, 'Refunded', $me, $reason . ' (refund ref ' . trim((string) $_POST['refund_ref']) . ')', 'Refunded'); $msg = 'Refund recorded.'; break;
        }
        header('Location: application.php?id=' . $id . '&success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_applications WHERE id = $id"));
$k = mysqli_fetch_assoc(mysqli_query($conn, "SELECT k.*, u.names FROM inv_contributions k LEFT JOIN users u ON u.id = k.verified_by WHERE k.application_id = $id"));
$per = json_decode((string) $a['personal'], true) ?: [];
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT l.*, u.names FROM inv_audit l LEFT JOIN users u ON u.id = l.user_id WHERE l.application_id = $id ORDER BY l.id DESC"), MYSQLI_ASSOC);
$st = $a['status'];
include '../../includes/header.php'; include '../../includes/sidebar.php';
inv_tabs('applications.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<a href="applications.php" class="small text-muted text-decoration-none">&larr; Applications</a>
<h4 class="mt-1"><?= inv_app_ref($a); ?> · <?= $e($c['name']); ?> <?= inv_badge($st); ?></h4>
<div class="row g-3"><div class="col-lg-7"><div class="card border-0 shadow-sm mb-3"><div class="card-body small">
    <div>Project: <a href="project.php?id=<?= (int) $p['id']; ?>"><?= $e($p['name']); ?></a> (<?= inv_project_ref($p); ?>, <?= $e($p['status']); ?>)</div>
    <div class="fs-6 my-1">Amount: <strong><?= cl_money($a['amount']); ?></strong> · proposed contribution date <?= $a['proposed_date'] ? date('d M Y', strtotime($a['proposed_date'])) : '—'; ?></div>
    <div>Source of funds: <?= $e($a['source_of_funds']); ?> · payment by <?= $e($a['payment_method']); ?></div>
    <?php if ($a['comments']) { ?><div>Comments: <?= $e($a['comments']); ?></div><?php } ?>
    <div class="mt-1">Risk acknowledgement <?= $a['risk_ack_at'] ? 'given ' . date('d M Y, H:i', strtotime($a['risk_ack_at'])) : '—'; ?> · agreement accepted <?= $a['agreement_accepted_at'] ? 'by <strong>' . $e($a['accepted_name']) . '</strong> on ' . date('d M Y, H:i', strtotime($a['agreement_accepted_at'])) : '—'; ?></div>
    <hr><h6>Investor</h6><div class="row"><?php foreach (['customer_id' => 'Customer ID', 'national_id' => 'National ID', 'date_of_birth' => 'Date of birth', 'nationality' => 'Nationality', 'phone' => 'Phone', 'email' => 'Email'] as $kk => $l) { ?><div class="col-6"><?= $l; ?>: <?= $e($per[$kk] ?? ''); ?></div><?php } ?>
        <div class="col-12">Address: <?= $e(trim(($per['address'] ?? '') . ', ' . ($per['cell'] ?? '') . ', ' . ($per['sector'] ?? '') . ', ' . ($per['district'] ?? ''), ', ')); ?></div></div>
    <?php if ($k) { ?><hr><h6>Contribution</h6><div>Reference <strong><?= $e($k['contribution_ref']); ?></strong> · <?= cl_money($k['amount']); ?> · <?= $e($k['status']); ?>
        <?= $k['payer_reference'] ? ' · paid ' . ($k['paid_on'] ? date('d M Y', strtotime($k['paid_on'])) : '') . ' by ' . $e($k['method']) . ', transaction ' . $e($k['payer_reference']) : ''; ?><?= $k['names'] ? ' · verified by ' . $e($k['names']) : ''; ?></div><?php } ?>
</div></div></div>
<div class="col-lg-5"><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Decisions</h6>
    <?php $f = function ($action, $label, $cls, $body = '') use ($id) { echo '<form method="POST" class="mb-2"><input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="action" value="' . $action . '">' . $body . '<button class="btn btn-sm ' . $cls . '">' . $label . '</button></form>'; }; ?>
    <?php if ($st === 'Submitted' && $canEdit) { $f('review', 'Start review', 'btn-primary w-100'); } ?>
    <?php if (in_array($st, ['Submitted', 'Under Review'], true) && $canEdit) { $f('info', 'Request more information', 'btn-outline-primary', '<textarea name="reason" class="form-control form-control-sm mb-1" rows="2" placeholder="What the investor must provide"></textarea>'); } ?>
    <?php if (in_array($st, ['Submitted', 'Under Review'], true) && $canApprove) { $f('approve', 'Approve (send payment instructions)', 'btn-success', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Note (optional)">'); $f('reject', 'Reject', 'btn-outline-danger', '<textarea name="reason" class="form-control form-control-sm mb-1" rows="2" placeholder="Reason (the investor sees it)"></textarea>'); } ?>
    <?php if ($st === 'Payment Pending Verification') { ?>
        <?php if (inv_testing($conn)) { ?><div class="alert alert-warning">Testing mode: contributions cannot be confirmed.</div><?php } elseif ($canApprove) { $f('verify', 'Payment received: verify', 'btn-success', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Where it was checked (bank statement, MoMo report…)">'); } ?>
        <?php if ($canEdit) { $f('reject_payment', 'Payment not found', 'btn-outline-danger', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Why it could not be verified">'); } ?>
    <?php } ?>
    <?php if (in_array($st, ['Submitted', 'Under Review', 'Additional Information Required', 'Approved', 'Awaiting Payment'], true) && $canApprove) { $f('cancel', 'Cancel application', 'btn-outline-secondary', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason">'); } ?>
    <?php if (in_array($st, ['Confirmed', 'Active Investor'], true) && $canApprove) { $f('refund', 'Record a refund', 'btn-outline-danger', '<input name="refund_ref" class="form-control form-control-sm mb-1" placeholder="Refund payment reference"><input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (per the agreement)">'); } ?>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-body small"><h6>Audit log</h6>
    <?php foreach ($log as $l) { ?><div class="border-bottom py-1"><span class="text-muted"><?= date('d M Y, H:i', strtotime($l['created_at'])); ?></span> · <strong><?= $e($l['names'] ?? ($l['customer_id'] ? 'Investor' : 'System')); ?></strong> · <?= $e($l['action']); ?><?= $l['reason'] ? '<div class="text-muted">' . $e($l['reason']) . '</div>' : ''; ?></div><?php } ?>
</div></div></div></div>
<?php include '../../includes/footer.php'; ?>