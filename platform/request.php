<?php
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/signup.php';

$admin = platform_require_admin();
$id = (is_string($_GET['id'] ?? null) && ctype_digit($_GET['id'])) ? (int) $_GET['id'] : 0;
$req = $id > 0 ? signup_get($id) : null;
if (!$req) {
    http_response_code(404);
    platform_page_start('Not found', $admin, 'requests');
    echo '<div class="alert alert-warning">That request does not exist. <a href="requests.php">Back to the list</a></div>';
    platform_page_end();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $str = static fn(string $k): string => is_string($_POST[$k] ?? null) ? trim($_POST[$k]) : '';
    try {
        switch ($action) {
            case 'approve':
                signup_approve($id, $str('company_code'), $admin['email']);
                platform_flash('Approved. The applicant was emailed how to pay.');
                break;
            case 'reject':
                signup_reject($id, $str('reason'), $admin['email']);
                platform_flash('Rejected. The applicant was emailed the reason.');
                break;
            case 'payment':
                signup_record_payment($id, $str('method'), $str('reference'), (int) preg_replace('/[^0-9]/', '', $str('amount')), $admin['email']);
                platform_flash('Payment recorded. The company is being created: this takes up to a minute.');
                break;
            case 'retry':
                signup_retry($id, $admin['email']);
                platform_flash('Queued again.');
                break;
            case 'resend_pay_link':
                platform_flash(signup_resend_pay_link($id, $admin['email']) ? 'A new payment link was emailed. The old link no longer works.' : 'The email could not be sent. Check the Emails page.', 'warning');
                break;
            case 'resend_invite':
                $company = $req['company_id'] ? platform_company_by_id((int) $req['company_id']) : null;
                if (!$company || $req['status'] !== 'active') {
                    throw new SignupException('The company does not exist yet.');
                }
                platform_flash(signup_send_invite($req, $company) ? 'A new invite link was emailed. Older links no longer work.' : 'The email could not be sent. Check the mail settings.', 'warning');
                platform_audit((int) $company['id'], 'admin:' . $admin['email'], 'invite_resent', 'ref=' . $req['reference']);
                break;
            default:
                throw new SignupException('Unknown action.');
        }
    } catch (SignupException $e) {
        platform_flash($e->getMessage(), 'danger');
    }
    header('Location: request.php?id=' . $id);
    exit;
}

$nextCode = $req['company_code'] ?: 'RMCP' . str_pad((string) ((int) mysqli_fetch_row(mysqli_query(platform_conn(), "SELECT value + 1 FROM platform_counters WHERE name = 'company_code'"))[0]), 4, '0', STR_PAD_LEFT);
platform_page_start('Request ' . $req['reference'], $admin, 'requests');
?>
<p><a href="requests.php">&larr; All requests</a></p>
<div class="row g-3">
<div class="col-md-6"><div class="card p-3 h-100">
    <h5><?= h($req['reference']); ?> <span class="badge bg-secondary"><?= h($req['status']); ?></span></h5>
    <table class="table table-sm mb-0">
        <tr><th>Company</th><td><?= h($req['company_name']); ?></td></tr>
        <tr><th>Owner</th><td><?= h($req['owner_name']); ?></td></tr>
        <tr><th>Email</th><td><?= h($req['owner_email']); ?></td></tr>
        <tr><th>Phone</th><td><?= h($req['owner_phone']); ?></td></tr>
        <tr><th>TIN</th><td><?= h($req['tin'] ?? '-'); ?></td></tr>
        <tr><th>Package</th><td><?= h($req['package_name']); ?> / <?= h($req['billing_period']); ?></td></tr>
        <tr><th>Amount due</th><td><b><?= h(signup_money((int) $req['amount_due'])); ?></b></td></tr>
        <tr><th>Company code</th><td><?= h($req['company_code'] ?? '(chosen at approval)'); ?></td></tr>
        <tr><th>Terms accepted</th><td><?= h($req['terms_accepted_at']); ?> from <?= h($req['ip']); ?></td></tr>
        <tr><th>Sent</th><td><?= h($req['created_at']); ?></td></tr>
<?php if ($req['reviewed_by']) { ?><tr><th>Reviewed by</th><td><?= h($req['reviewed_by']); ?> on <?= h($req['reviewed_at']); ?></td></tr><?php } ?>
<?php if ($req['reject_reason']) { ?><tr><th>Reason</th><td><?= h($req['reject_reason']); ?></td></tr><?php } ?>
<?php if ($req['last_error']) { ?><tr><th>Last error</th><td class="text-danger"><?= h($req['last_error']); ?></td></tr><?php } ?>
    </table>
</div></div>
<div class="col-md-6"><div class="card p-3 h-100">
<?php if ($req['status'] === 'pending') { ?>
    <h5>Decide</h5>
    <form method="POST" class="mb-3"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="approve">
        <div class="alert alert-light border small mb-2">The <b>company code</b> is created automatically (the next one is <b><?= h($nextCode); ?></b>). It is emailed to the company with the payment button.</div>
        <button class="btn btn-success w-100" type="submit">Approve and ask for payment</button>
    </form>
    <form method="POST"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="reject">
        <label class="form-label">Or reject, with a reason the applicant will read</label>
        <input class="form-control mb-2" name="reason" maxlength="500" required>
        <button class="btn btn-outline-danger w-100" type="submit">Reject</button>
    </form>
<?php } elseif ($req['status'] === 'approved') { ?>
    <h5>Waiting for payment</h5>
    <div class="alert alert-info py-2 small mb-3">The company got a secure <b>Pay</b> link by email (valid until <?= h($req['pay_token_expires'] ?? '-'); ?>). When it pays with Mobile Money, the company is created <b>automatically</b>: you do nothing.</div>
    <form method="POST" class="mb-3"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="resend_pay_link"><button class="btn btn-sm btn-outline-primary" type="submit">Send the payment link again</button></form>
<?php $attempts = payment_attempts_for((int) $req['id']); if ($attempts) { ?>
    <h6 class="mt-2">Payment attempts</h6>
    <table class="table table-sm small"><thead><tr><th>When</th><th>Phone</th><th>Amount</th><th>Result</th></tr></thead><tbody>
<?php foreach ($attempts as $a) { $badge = $a['status'] === 'success' ? 'success' : (in_array($a['status'], ['failed', 'expired'], true) ? 'danger' : 'warning'); ?>
    <tr><td><?= h($a['created_at']); ?></td><td>+<?= h(substr($a['msisdn'], 0, 6)); ?>***<?= h(substr($a['msisdn'], -3)); ?></td><td><?= h(signup_money((int) $a['amount'])); ?></td><td><span class="badge bg-<?= $badge; ?>"><?= h($a['status']); ?></span><?= $a['failure_reason'] ? ' <span class="text-muted">' . h($a['failure_reason']) . '</span>' : ''; ?></td></tr>
<?php } ?>
    </tbody></table>
<?php } ?>
    <hr>
    <h6>The company paid another way (cash, bank)?</h6>
    <p class="text-muted small">Record it here. The company is created right after.</p>
    <form method="POST"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="payment">
        <label class="form-label">How did they pay?</label>
        <select class="form-select mb-2" name="method" required><?php foreach (SIGNUP_PAYMENT_METHODS as $m) { echo '<option>' . h($m) . '</option>'; } ?></select>
        <label class="form-label">Payment reference (e.g. MoMo transaction number)</label>
        <input class="form-control mb-2" name="reference" maxlength="100" required>
        <label class="form-label">Amount received (RWF)</label>
        <input class="form-control mb-3" name="amount" value="<?= (int) $req['amount_due']; ?>" inputmode="numeric" required>
        <button class="btn btn-primary w-100" type="submit">Payment received: create the company</button>
    </form>
    <hr>
    <form method="POST"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="reject">
        <input class="form-control mb-2" name="reason" maxlength="500" placeholder="Reason, if you cancel this request" required>
        <button class="btn btn-sm btn-outline-danger" type="submit">Cancel this request</button>
    </form>
<?php } elseif (in_array($req['status'], ['activating', 'provisioning'], true)) { ?>
    <h5>Being created</h5><p class="text-muted">The worker creates the company within a minute. Refresh this page.</p>
<?php } elseif ($req['status'] === 'failed') { ?>
    <h5>Failed</h5><p class="text-muted">Read the error on the left. When it is fixed, try again.</p>
    <form method="POST"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="retry"><button class="btn btn-warning" type="submit">Retry</button></form>
<?php } elseif ($req['status'] === 'active') { ?>
    <h5>Active</h5>
    <p>The company exists<?= $req['invite_sent_at'] ? ' and the owner was emailed on ' . h($req['invite_sent_at']) : ', but the invite email was <b>not</b> sent'; ?>.</p>
    <form method="POST"><?= platform_csrf_field(); ?><input type="hidden" name="action" value="resend_invite"><button class="btn btn-outline-primary" type="submit">Send a new set-password link</button></form>
<?php } else { ?>
    <h5>Closed</h5><p class="text-muted">This request was rejected.</p>
<?php } ?>
</div></div>
</div>
<?php platform_page_end();
