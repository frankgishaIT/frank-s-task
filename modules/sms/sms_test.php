<?php
/**
 * NEW FILE: modules/sms/sms_test.php
 * Admin page to send a test SMS through Pindo and see the latest SMS attempts.
 */
require '../../config/db.php';
require_once '../../includes/sms_helpers.php';
require_role(['Admin']);

if (isset($_POST['send_test'])) {
    $to = trim($_POST['phone'] ?? '');
    $text = trim($_POST['message'] ?? '');
    if (sms_normalize_phone($to) === null) {
        $error = 'Please enter a valid phone number, e.g. 0788123456.';
    } elseif ($text === '') {
        $error = 'Please type a message.';
    } else {
        $userId = current_user_id();
        $ok = sms_send($conn, $to, $text, 'TEST', null, null, $userId ? (int) $userId : null);
        header('Location: sms_test.php?' . ($ok ? 'success=' . urlencode('Test SMS sent to ' . sms_normalize_phone($to) . '.')
            : 'error=' . urlencode('The SMS was not sent. See the reason in the list below.')));
        exit;
    }
}

$recent = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM sms_log ORDER BY id DESC LIMIT 30'), MYSQLI_ASSOC);
$tokenSet = PINDO_API_TOKEN !== '' && PINDO_API_TOKEN !== 'PASTE_YOUR_PINDO_TOKEN_HERE';

include '../../includes/header.php'; include '../../includes/sidebar.php';
$badge = ['sent' => 'success', 'failed' => 'danger', 'skipped' => 'secondary'];
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>SMS (Pindo)</h2>
    <!-- NEW: runs the daily messages (credit reminders, weekly loyalty) by hand. Safe to click twice. -->
    <div class="d-flex gap-2">
    <!-- NEW: write your own message to a person or a group -->
    <a href="sms_compose.php" class="rm-btn rm-btn-primary"><i class="bi bi-send-fill me-1"></i>Send SMS</a>
    <a href="sms_cron.php" class="rm-btn rm-btn-light" onclick="return confirm('Send the credit reminders and weekly Loyalty messages that are due now?');">Run daily messages now</a>
    </div>
</div>

<?php if (!empty($_GET['success'])) { ?><div class="alert alert-success"><?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
<?php if (!empty($_GET['error']) || isset($error)) { ?><div class="alert alert-danger"><?= htmlspecialchars($error ?? $_GET['error'], ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <div class="small mb-3">
            Status:
            <?php if (!SMS_ENABLED) { ?><span class="badge bg-secondary">Switched off</span>
            <?php } elseif (!$tokenSet) { ?><span class="badge bg-warning text-dark">API token not set in config/sms.php</span>
            <?php } else { ?><span class="badge bg-success">Ready</span><?php } ?>
            &middot; Sender: <strong><?= htmlspecialchars(PINDO_SENDER, ENT_QUOTES, 'UTF-8'); ?></strong>
        </div>
        <form method="POST" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-muted">Phone</label>
                <input type="text" name="phone" class="form-control rm-input" placeholder="0788123456" required>
            </div>
            <div class="col-md-7">
                <label class="form-label small fw-semibold text-muted">Message</label>
                <input type="text" name="message" class="form-control rm-input" maxlength="<?= SMS_MAX_LENGTH; ?>" value="Test message from RISE MOTIVE." required>
            </div>
            <div class="col-md-2">
                <button type="submit" name="send_test" value="1" class="rm-btn rm-btn-primary w-100">Send Test</button>
            </div>
        </form>
    </div>
</div>

<h5 class="mb-3">Latest SMS</h5>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered bg-white mb-0 align-middle" style="font-size:13px;">
                <tr><th>Date</th><th>Phone</th><th>Event</th><th>Message</th><th>Status</th><th>Reason</th></tr>
                <?php if (!$recent) { ?><tr><td colspan="6" class="text-center text-muted py-4">No SMS yet.</td></tr><?php } ?>
                <?php foreach ($recent as $r) { ?>
                <tr>
                    <td class="text-nowrap"><?= date('d M Y H:i', strtotime($r['created_at'])); ?></td>
                    <td><?= htmlspecialchars($r['phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars($r['event'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars($r['message'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-<?= $badge[$r['status']] ?? 'secondary'; ?>"><?= htmlspecialchars(ucfirst($r['status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-muted"><?= htmlspecialchars($r['error'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>