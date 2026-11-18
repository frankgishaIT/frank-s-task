<?php
/**
 * NEW FILE: modules/sms/settings.php — SMS MANAGEMENT SETTINGS and the full AUDIT TRAIL: duplicate safeguard, daily maximum
 * per number, sending hours, price per segment (for the costs), the queue of messages waiting for the sending hours.
 * The provider (Pindo) and the sender ID RISEMOTIVE stay in config/sms.php and are only shown here.
 */
require __DIR__ . '/sms_common.php';
if (!smsm_can('templates', $conn)) { smsm_go('index.php', 'error', 'You may not change the SMS settings.'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!smsm_csrf_ok()) { smsm_go('settings.php', 'error', 'The page had expired.'); }
    if (($_POST['do'] ?? '') === 'queue') { $n = sms_queue_run($conn, 200); smsm_go('settings.php', 'success', $n . ' queued message(s) whose time had come were sent.'); }
    if (($_POST['do'] ?? '') === 'cancel_queue') {
        mysqli_query($conn, "UPDATE sms_log SET status = 'cancelled', error = 'Cancelled in SMS Management' WHERE id IN (SELECT log_id FROM sms_queue WHERE status = 'pending')");
        mysqli_query($conn, "UPDATE sms_queue SET status = 'cancelled' WHERE status = 'pending'");
        sms_audit($conn, null, 'queue_cancelled', 'Queued messages cancelled');
        smsm_go('settings.php', 'success', 'Queued messages cancelled.');
    }
    $hm = function ($v, $d) { return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v) ? (string) $v : $d; };
    $new = ['duplicate_minutes' => (string) max(0, min(1440, (int) ($_POST['duplicate_minutes'] ?? 10))), 'daily_cap' => (string) max(0, min(100, (int) ($_POST['daily_cap'] ?? 0))),
            'cost_per_segment' => (string) max(0, round((float) ($_POST['cost_per_segment'] ?? 0), 2)), 'window_start' => $hm($_POST['window_start'] ?? '', '07:00'), 'window_end' => $hm($_POST['window_end'] ?? '', '20:00')];
    $chg = [];
    foreach ($new as $k => $v) { $old = sms_setting($conn, $k); if ($old !== $v) { sms_setting_set($conn, $k, $v); $chg[] = $k . ': ' . $old . ' → ' . $v; } }
    if ($chg) { sms_audit($conn, null, 'settings', implode('; ', $chg)); }
    smsm_go('settings.php', 'success', $chg ? 'Saved.' : 'Nothing changed.');
}
$pending = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM sms_queue WHERE status = 'pending'"))[0];
$due = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM sms_queue WHERE status = 'pending' AND send_after <= '" . date('Y-m-d H:i:s') . "'"))[0];
$fe = (string) ($_GET['event'] ?? '');
$audit = mysqli_fetch_all(mysqli_query($conn, 'SELECT a.*, u.names FROM sms_audit a LEFT JOIN users u ON u.id = a.user_id' . ($fe !== '' ? " WHERE a.event = '" . mysqli_real_escape_string($conn, $fe) . "'" : '') . ' ORDER BY a.id DESC LIMIT 200'), MYSQLI_ASSOC);
$cat = sms_catalog();
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
smsm_tabs($conn, 'settings.php', 'SMS settings and audit trail');
?>
<div class="row g-3">
<div class="col-xl-5">
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body small"><?= smsm_csrf(); ?>
        <div class="fw-semibold mb-2">Safeguards</div>
        <label class="d-block mb-2">Block the same text to the same number within <input type="number" name="duplicate_minutes" min="0" max="1440" value="<?= smsm_e(sms_setting($conn, 'duplicate_minutes')); ?>" class="form-control form-control-sm d-inline-block mx-1" style="width:80px"> minutes <span class="text-muted">(0 = off; security codes and tests are never blocked)</span></label>
        <label class="d-block mb-3">Maximum automatic SMS per number per day <input type="number" name="daily_cap" min="0" max="100" value="<?= smsm_e(sms_setting($conn, 'daily_cap')); ?>" class="form-control form-control-sm d-inline-block mx-1" style="width:80px"> <span class="text-muted">(0 = no maximum)</span></label>
        <div class="fw-semibold mb-2">Sending hours</div>
        <div class="mb-1">From <input type="time" name="window_start" value="<?= smsm_e(sms_setting($conn, 'window_start')); ?>" class="form-control form-control-sm d-inline-block" style="width:110px"> to <input type="time" name="window_end" value="<?= smsm_e(sms_setting($conn, 'window_end')); ?>" class="form-control form-control-sm d-inline-block" style="width:110px"></div>
        <div class="text-muted mb-3">Used by the notifications set to "only during the sending hours" (e.g. reminders). Receipts and codes normally go immediately.</div>
        <div class="fw-semibold mb-2">Costs</div>
        <label class="d-block mb-3">Price of one SMS segment: RWF <input type="number" step="0.01" min="0" name="cost_per_segment" value="<?= smsm_e(sms_setting($conn, 'cost_per_segment')); ?>" class="form-control form-control-sm d-inline-block mx-1" style="width:100px"> <span class="text-muted">(from your Pindo price; used for the estimated costs)</span></label>
        <button class="btn btn-primary btn-sm">Save</button>
    </div></form>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <div class="fw-semibold mb-1">Provider (not changed by SMS Management)</div>
        <div>Provider: <strong>Pindo</strong> · Sender ID: <strong><?= smsm_e(PINDO_SENDER); ?></strong> · SMS <?= SMS_ENABLED ? '<span class="text-success fw-semibold">on</span>' : '<span class="text-danger fw-semibold">off</span>'; ?> (master switch in config/sms.php)</div>
        <div class="text-muted">Daily messages (credit reminders, loyalty) and the queue run with the daily task <code>modules/sms/sms_cron.php</code>.</div>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body small">
        <div class="fw-semibold mb-1">Queue</div>
        <div class="mb-2"><?= $pending; ?> message(s) waiting for the sending hours, <?= $due; ?> due now.</div>
        <form method="POST" class="d-inline"><?= smsm_csrf(); ?><input type="hidden" name="do" value="queue"><button class="btn btn-sm btn-outline-primary" <?= $due ? '' : 'disabled'; ?>>Send the due messages now</button></form>
        <form method="POST" class="d-inline" onsubmit="return confirm('Cancel every queued message?');"><?= smsm_csrf(); ?><input type="hidden" name="do" value="cancel_queue"><button class="btn btn-sm btn-outline-secondary" <?= $pending ? '' : 'disabled'; ?>>Cancel the queue</button></form>
    </div></div>
</div>
<div class="col-xl-7"><div class="card border-0 shadow-sm"><div class="card-body small">
    <div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-semibold">Audit trail</div>
        <form><select name="event" class="form-select form-select-sm" onchange="this.form.submit()"><option value="">All notifications and settings</option><?php foreach ($cat as $k => $c) { ?><option value="<?= smsm_e($k); ?>" <?= $fe === $k ? 'selected' : ''; ?>><?= smsm_e($c['name']); ?></option><?php } ?></select></form></div>
    <?php foreach ($audit as $a) { ?><div class="border-bottom py-1"><span class="text-muted"><?= smsm_e(substr($a['created_at'], 0, 16)); ?></span> · <strong><?= smsm_e($a['names'] ?? 'System'); ?></strong> ·
        <?= $a['event'] ? '<a href="template.php?event=' . rawurlencode($a['event']) . '">' . smsm_e($cat[$a['event']]['name'] ?? $a['event']) . '</a> · ' : ''; ?><?= smsm_e($a['action']); ?> — <?= smsm_e($a['details']); ?>
        <?php if ($a['old_value'] && $a['new_value'] && $a['action'] !== 'activated' && $a['action'] !== 'deactivated') { $o = json_decode($a['old_value'], true); $n = json_decode($a['new_value'], true); if (is_array($o) && is_array($n) && ($o['body'] ?? '') !== ($n['body'] ?? '')) { ?>
        <details><summary class="text-primary">see the wording before / after</summary><div class="row g-2"><div class="col-6"><div class="text-muted">Before</div><div style="white-space:pre-wrap;background:#FFF4F4;padding:4px;border-radius:6px;"><?= smsm_e($o['body'] ?? ''); ?></div></div><div class="col-6"><div class="text-muted">After</div><div style="white-space:pre-wrap;background:#F0FBF5;padding:4px;border-radius:6px;"><?= smsm_e($n['body'] ?? ''); ?></div></div></div></details><?php } } ?></div><?php } ?>
    <?php if (!$audit) { ?><div class="text-muted">No change yet.</div><?php } ?>
</div></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>