<?php
// NEW FILE: portal/tasker_task.php — RM Tasker: one of MY tasks (accept, decline, concern, start, delay, deliver, feedback, raise an issue).
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/portal_layout.php';
$conn = portal_boot();
require_once __DIR__ . '/../includes/dl_portal.php';
$t = dlp_require($conn);
$pid = (int) $t['id'];
$ref = (string) ($_GET['ref'] ?? $_POST['ref'] ?? '');
$d = dlp_own($conn, $t, $ref);
if (!$d) { header('Location: tasker.php?error=' . urlencode('Task not found.')); exit; }
$id = (int) $d['id'];
$self = 'tasker_task.php?ref=' . $d['ref'];
$go = function ($type, $msg) use ($self) { header('Location: ' . $self . '&' . $type . '=' . urlencode($msg)); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_csrf_ok()) { $go('error', 'Your session expired. Please try again.'); }
    $act = (string) ($_POST['act'] ?? '');
    $msg = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 1000);
    $eta = (string) ($_POST['eta'] ?? '');
    $eta = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $eta) ? str_replace('T', ' ', $eta) . ':00' : null;
    $now = dl_now();
    $staff = [(int) $d['created_by']];
    $who = $t['display'] . ' (' . $t['code'] . ')';
    switch ($act) {
        case 'accept':
            if ($d['status'] !== 'Awaiting Tasker Acceptance' || $d['response']) { $go('error', 'This task can no longer be accepted.'); }
            if (($_POST['time'] ?? '') === 'later' && !$eta) { $go('error', 'Choose the time you can deliver.'); }
            $late = ($_POST['time'] ?? '') === 'later' && $eta && strtotime($eta) > strtotime($d['deadline']);
            dl_set_status($conn, $id, 'Accepted', ['response' => 'Tasker-confirmed', 'response_at' => $now, 'response_note' => $msg ?: null, 'tasker_eta' => ($_POST['time'] ?? '') === 'later' ? $eta : $d['deadline']]);
            dl_event($conn, $id, 'tasker_accepted', 'Accepted by the RM Tasker' . (($_POST['time'] ?? '') === 'later' ? ', proposes delivery on ' . dl_deadline_text($eta) : ', confirms the deadline ' . dl_deadline_text($d['deadline'])) . ($msg ? ' · ' . $msg : ''), 'tasker', 0, null, 1, $pid);
            dl_notify_staff($conn, $staff, $d['ref'] . ' accepted by ' . $who, $late ? 'The Tasker proposes a LATER time: ' . dl_deadline_text($eta) . ' (deadline ' . dl_deadline_text($d['deadline']) . '). Decide whether to keep, change the deadline or reassign.' : 'The deadline ' . dl_deadline_text($d['deadline']) . ' is confirmed.');
            $go('success', $late ? 'Accepted. You proposed a later time: RISE MOTIVE will confirm whether it suits the customer.' : 'Thank you, you accepted the task.');
        case 'decline':
            if (!in_array($d['status'], ['Awaiting Tasker Acceptance', 'Accepted'], true)) { $go('error', 'This task can no longer be declined here: contact RISE MOTIVE.'); }
            if ($msg === '') { $go('error', 'Tell RISE MOTIVE why you cannot do it.'); }
            dl_set_status($conn, $id, 'Rejected', ['response' => 'Tasker-declined', 'response_at' => $now, 'response_note' => $msg]);
            dl_event($conn, $id, 'tasker_declined', 'Declined by the RM Tasker', 'tasker', 0, $msg, 1, $pid);
            dl_notify_staff($conn, $staff, $d['ref'] . ' DECLINED by ' . $who, $msg . '. Reassign it to another RM Tasker so the customer is served on time.');
            $go('success', 'RISE MOTIVE has been told you cannot do this task.');
        case 'concern':
            if ($msg === '') { $go('error', 'Write your question or concern.'); }
            dl_event($conn, $id, 'tasker_concern', 'Question / concern from the RM Tasker: ' . $msg, 'tasker', 0, null, 1, $pid);
            dl_notify_staff($conn, $staff, 'Question from ' . $who . ' on ' . $d['ref'], $msg);
            $go('success', 'Sent to RISE MOTIVE. Someone will contact you.');
        case 'start':
            if ($d['status'] !== 'Accepted') { $go('error', 'Not possible now.'); }
            dl_set_status($conn, $id, 'In Progress', ['started_at' => $now]);
            dl_event($conn, $id, 'started', 'Work started (RM Tasker)', 'tasker', 0, null, 1, $pid);
            $go('success', 'Recorded: you started the work.');
        case 'delay':
            if (!in_array($d['status'], ['Accepted', 'In Progress'], true)) { $go('error', 'Not possible now.'); }
            if ($msg === '' || !$eta) { $go('error', 'Give the reason and when you can deliver.'); }
            dl_set_status($conn, $id, $d['status'], ['tasker_eta' => $eta]);
            dl_event($conn, $id, 'delay_reported', 'Delay reported by the RM Tasker: new expected delivery ' . dl_deadline_text($eta) . ' (deadline ' . dl_deadline_text($d['deadline']) . ')', 'tasker', 0, $msg, 1, $pid);
            dl_notify_staff($conn, array_merge($staff, dl_approvers($conn)), 'Delay on ' . $d['ref'] . ' (' . $who . ')', $msg . ' · expected ' . dl_deadline_text($eta) . ' instead of ' . dl_deadline_text($d['deadline']) . '. Inform the customer, change the deadline or reassign.');
            $go('success', 'Delay reported. The deadline stays ' . dl_deadline_text($d['deadline']) . ' unless RISE MOTIVE changes it.');
        case 'complete':
            if (!in_array($d['status'], ['Accepted', 'In Progress'], true)) { $go('error', 'Not possible now.'); }
            if ($msg === '') { $go('error', 'Describe what you delivered and how (files below, e-mail, hand delivery…).'); }
            [$nf, $ferr] = dl_save_files($conn, $id, 'evidence', 'evidence', $pid);
            if ($ferr && !$nf) { $go('error', $ferr); }
            dl_set_status($conn, $id, 'Completed – Awaiting Verification', ['completed_at' => $now, 'completion_note' => $msg, 'started_at' => $d['started_at'] ?: $now]);
            dl_event($conn, $id, 'completed', 'Delivered by the RM Tasker' . ($nf ? ' with ' . $nf . ' file(s)' : '') . ': ' . $msg, 'tasker', 0, null, 1, $pid);
            dl_notify_staff($conn, $staff, $d['ref'] . ' delivered by ' . $who, 'Check the work and verify it (or return it for correction).');
            $go('success', 'Thank you. RISE MOTIVE will check the work' . (strtotime($now) <= strtotime($d['deadline']) ? ' (delivered on time).' : '.'));
        case 'issue':
            if ($msg === '') { $go('error', 'Describe the problem.'); }
            dl_event($conn, $id, 'tasker_issue', 'Issue / dispute raised by the RM Tasker: ' . $msg, 'tasker', 0, null, 1, $pid);
            dl_notify_staff($conn, array_merge($staff, dl_approvers($conn)), 'Issue raised by ' . $who . ' on ' . $d['ref'], $msg);
            $go('success', 'Your issue was sent to RISE MOTIVE management.');
    }
    $go('error', 'Unknown action.');
}

$files = dl_files($conn, $id);
$ev = mysqli_fetch_all(mysqli_query($conn, "SELECT created_at, details, reason, actor, on_behalf FROM dl_events WHERE delegation_id = $id AND (visible_to_tasker = 1 OR (actor = 'tasker' AND party_id = $pid)) ORDER BY id DESC"), MYSQLI_ASSOC);
$o = dl_offering($conn, (int) $d['product_id']);
$e = 'portal_e';
$late = in_array($d['status'], ['Awaiting Tasker Acceptance', 'Accepted', 'In Progress'], true) && strtotime($d['deadline']) < time();
$csrf = portal_csrf_field();
$box = function ($act, $title, $inner, $btn, $cls = 'btn-primary', $multipart = false) use ($csrf, $d) {
    return '<form method="post"' . ($multipart ? ' enctype="multipart/form-data"' : '') . ' class="card mb-3"><div class="card-body">' . $csrf . '<input type="hidden" name="ref" value="' . $d['ref'] . '"><input type="hidden" name="act" value="' . $act . '"><h2>' . $title . '</h2>' . $inner . '<button class="btn ' . $cls . '">' . $btn . '</button></div></form>';
};
dlp_header($d['ref'], $t);
portal_alerts();
?>
<a href="tasker.php" class="small">← My Tasks</a>
<h1 class="mb-1 mt-2"><?= $e($d['product_name']); ?></h1>
<div class="mb-3"><span class="badge bg-primary"><?= $e(dlp_status($d)); ?></span> <span class="text-muted small"><?= $e($d['ref']); ?></span><?= (int) $d['urgent'] ? ' <span class="badge bg-danger">Urgent</span>' : ''; ?></div>
<div class="row g-3">
<div class="col-lg-7">
    <div class="card mb-3"><div class="card-body">
        <div class="row g-2">
            <div class="col-sm-6"><div class="small text-muted">Deadline</div><div class="fw-bold <?= $late ? 'text-danger' : ''; ?>"><?= $e(dl_deadline_text($d['deadline'])); ?></div><?php if ($d['tasker_eta'] && $d['tasker_eta'] !== $d['deadline']) { ?><div class="small">You expect: <?= $e(dl_deadline_text($d['tasker_eta'])); ?></div><?php } ?></div>
            <div class="col-sm-6"><div class="small text-muted"><?= $d['verified_at'] ? 'Payment due to you' : 'Expected payment'; ?></div><div class="fw-bold">RWF <?= number_format((float) ($d['pay_due'] ?? $d['pay_exp'])); ?></div></div>
            <div class="col-12"><div class="small text-muted">Instructions</div><div><?= nl2br($e($d['instructions'])); ?></div></div>
            <?php if ($o['quality_requirements']) { ?><div class="col-12"><div class="small text-muted">Quality requirements</div><div><?= nl2br($e($o['quality_requirements'])); ?></div></div><?php } ?>
            <?php if ($d['delivery']) { ?><div class="col-12"><div class="small text-muted">Location / delivery</div><div><?= nl2br($e($d['delivery'])); ?></div></div><?php } ?>
            <?php if ($files) { ?><div class="col-12"><div class="small text-muted">Files</div><?php foreach ($files as $f) { ?><div><a href="tasker_file.php?ref=<?= $e($d['ref']); ?>&f=<?= (int) $f['id']; ?>" target="_blank"><i class="bi bi-paperclip"></i> <?= $e($f['name']); ?></a> <span class="small text-muted"><?= $f['kind'] === 'evidence' ? '(delivery)' : ''; ?></span></div><?php } ?></div><?php } ?>
        </div>
    </div></div>
    <?php if ($d['verified_at']) { ?>
    <div class="card mb-3"><div class="card-body"><h2>Feedback from RISE MOTIVE</h2>
        <div>Quality <?= $e((float) $d['quality_rating']); ?>/5 · customer satisfaction <?= $e((float) $d['satisfaction_rating']); ?>/5 · instructions <?= $e((float) $d['instructions_rating']); ?>/5 · communication <?= $e((float) $d['communication_rating']); ?>/5 · <?= (int) $d['ontime'] ? 'on time' : 'late'; ?></div>
        <div class="fw-bold">Performance: <?= $e((float) $d['perf_score']); ?>/100</div><?= $d['verification_note'] ? '<div class="mt-1">' . nl2br($e($d['verification_note'])) . '</div>' : ''; ?>
    </div></div>
    <?php } ?>
    <h2>History</h2>
    <div class="list-group mb-3"><?php foreach ($ev as $x) { ?><div class="list-group-item small"><span class="text-muted"><?= $e(date('d M Y H:i', strtotime($x['created_at']))); ?></span> · <?= $x['actor'] === 'tasker' ? 'You' : 'RISE MOTIVE'; ?>: <?= $e(preg_replace('/^Update: | · SMS (not )?sent.*$/u', '', (string) $x['details'])); ?><?= $x['reason'] && $x['actor'] !== 'tasker' ? ' — ' . $e($x['reason']) : ''; ?></div><?php } ?>
        <?php if (!$ev) { ?><div class="list-group-item small text-muted">Assigned on <?= $e(dl_deadline_text($d['notified_at'] ?: $d['created_at'])); ?>.</div><?php } ?></div>
</div>
<div class="col-lg-5">
<?php if ($d['status'] === 'Awaiting Tasker Acceptance' && !$d['response']) { ?>
    <?= $box('accept', 'Accept this task', '<div class="form-check"><input class="form-check-input" type="radio" name="time" value="deadline" id="t1" checked><label class="form-check-label" for="t1">I will deliver by ' . $e(dl_deadline_text($d['deadline'])) . '</label></div>'
        . '<div class="form-check mb-2"><input class="form-check-input" type="radio" name="time" value="later" id="t2"><label class="form-check-label" for="t2">I can deliver on:</label></div><input type="datetime-local" name="eta" class="form-control mb-2"><input name="message" class="form-control mb-2" placeholder="Message (optional)">', 'Accept', 'btn-success'); ?>
    <?= $box('decline', 'I cannot do it', '<textarea name="message" rows="2" class="form-control mb-2" placeholder="Why (busy, not my skill, too far…)"></textarea>', 'Decline', 'btn-outline-danger'); ?>
<?php } elseif ($d['status'] === 'Accepted') { ?>
    <?= $box('start', 'Started the work?', '<p class="small text-muted">Let RISE MOTIVE know you began.</p>', 'I have started'); ?>
<?php } ?>
<?php if (in_array($d['status'], ['Accepted', 'In Progress'], true)) { ?>
    <?= $box('complete', 'Deliver the work', '<textarea name="message" rows="2" class="form-control mb-2" placeholder="What you delivered and how" required></textarea><input type="file" name="evidence[]" multiple class="form-control mb-2"><div class="small text-muted mb-2">Photos, PDF or files of the finished work (up to 10).</div>', 'Submit as completed', 'btn-success', true); ?>
    <?= $box('delay', 'Report a delay', '<input name="message" class="form-control mb-2" placeholder="What is delaying the work"><label class="small">I can deliver on</label><input type="datetime-local" name="eta" class="form-control mb-2">', 'Report delay', 'btn-outline-warning'); ?>
    <?php if ($d['status'] === 'Accepted') { ?><?= $box('decline', 'Can no longer do it?', '<input name="message" class="form-control mb-2" placeholder="Why">', 'Tell RISE MOTIVE', 'btn-outline-danger'); ?><?php } ?>
<?php } ?>
<?php if (!in_array($d['status'], ['Reassigned', 'Cancelled'], true)) { ?>
    <?= $box('concern', 'Question or concern', '<textarea name="message" rows="2" class="form-control mb-2"></textarea>', 'Send', 'btn-outline-primary'); ?>
    <?= $box('issue', 'Raise an issue or dispute about this task', '<textarea name="message" rows="2" class="form-control mb-2" placeholder="For example: the feedback is not fair, the instructions changed…"></textarea>', 'Raise issue', 'btn-outline-secondary'); ?>
<?php } ?>
<?php $wa = dl_setting($conn, 'whatsapp_link'); if ($wa) { ?><div class="small text-muted">Need to talk? <a href="<?= $e($wa); ?>" target="_blank" rel="noopener">RISE MOTIVE on WhatsApp</a></div><?php } ?>
</div>
</div>
<?php portal_footer();