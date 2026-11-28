<?php
// NEW FILE: portal/tasker_payments.php — RM Tasker: MY payments (only my own): amounts due, payments, confirm receipt, report a payment problem, my disputes.
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/portal_layout.php';
$conn = portal_boot();
require_once __DIR__ . '/../includes/dl_portal.php';
require_once __DIR__ . '/../includes/dl_payments.php';
$t = dlp_require($conn);
dlp_ensure($conn);
$pid = (int) $t['id'];
$go = function ($type, $msg) { header('Location: tasker_payments.php?' . $type . '=' . urlencode($msg)); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!portal_csrf_ok()) { $go('error', 'Your session expired. Please try again.'); }
    $act = (string) ($_POST['act'] ?? '');
    $msg = mb_substr(trim((string) ($_POST['message'] ?? '')), 0, 2000);
    $now = dl_now();
    $saveEvidence = function () use ($conn) {
        $out = [];
        require_once __DIR__ . '/../includes/evidence_store.php';
        $f = $_FILES['evidence'] ?? null;
        if ($f && is_array($f['name'])) {
            foreach ($f['name'] as $i => $n) {
                if (($f['error'][$i] ?? 4) === UPLOAD_ERR_NO_FILE || count($out) >= 5) { continue; }
                $one = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
                $r = evidence_save($conn, $one, null, ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 5 * 1048576);
                if ($r) { $out[] = ['ref' => $r, 'name' => mb_substr(basename((string) $n), 0, 150)]; }
            }
        }
        return $out;
    };
    if ($act === 'confirm' || $act === 'problem') {
        $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_payments WHERE id = ' . (int) ($_POST['pay'] ?? 0) . " AND party_id = $pid AND status = 'Recorded'"));
        if (!$p) { $go('error', 'Payment not found.'); }
        $d = dl_get($conn, (int) $p['delegation_id']);
        if ($act === 'confirm') {
            if ($p['confirm_status'] === 'Confirmed') { $go('success', 'Already confirmed.'); }
            $s = mysqli_prepare($conn, "UPDATE dl_payments SET confirm_status = 'Confirmed', confirmed_at = ?, confirmed_by_staff = NULL, confirm_note = 'Confirmed by the Tasker in the portal' WHERE id = ?");
            $ppid = (int) $p['id']; mysqli_stmt_bind_param($s, 'si', $now, $ppid); mysqli_stmt_execute($s);
            dl_event($conn, (int) $d['id'], 'payment_confirmed', 'The RM Tasker CONFIRMED receipt of ' . $p['reference'] . ' (' . dl_money($p['amount']) . ')', 'tasker', 0, null, 1, $pid);
            dl_audit($conn, 'payment', (int) $p['id'], 'confirmed', dl_pay_ref((int) $p['id']) . ' confirmed by ' . $t['code'], null, 'tasker', $pid);
            $go('success', 'Thank you: receipt of RWF ' . number_format((float) $p['amount']) . ' confirmed.');
        }
        $kind = in_array($_POST['kind'] ?? '', DL_DISPUTE_KINDS, true) ? $_POST['kind'] : '';
        if ($kind === '' || $msg === '') { $go('error', 'Choose the problem and describe it.'); }
        $rec = trim((string) ($_POST['received'] ?? '')) === '' ? null : max(0, (float) str_replace(',', '', (string) $_POST['received']));
        if ($kind === 'Payment not received') { $rec = 0.0; }
        try { $evid = $saveEvidence(); } catch (Throwable $e) { $go('error', 'File: ' . $e->getMessage()); }
        $did = dl_create_dispute($conn, $d, $p, $kind, $rec, $msg, 'tasker', $evid);
        $go('success', 'Your payment issue ' . dl_dispute_ref($did) . ' was sent to RISE MOTIVE.');
    }
    if ($act === 'issue_general') {
        $d = dlp_own($conn, $t, (string) ($_POST['ref'] ?? ''));
        if (!$d) { $go('error', 'Task not found.'); }
        if ($msg === '') { $go('error', 'Describe the problem.'); }
        try { $evid = $saveEvidence(); } catch (Throwable $e) { $go('error', 'File: ' . $e->getMessage()); }
        $did = dl_create_dispute($conn, $d, null, 'Other payment issue', null, $msg, 'tasker', $evid);
        $go('success', 'Your payment issue ' . dl_dispute_ref($did) . ' was sent to RISE MOTIVE.');
    }
    if ($act === 'reply') {
        $x = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_disputes WHERE id = ' . (int) ($_POST['dispute'] ?? 0) . " AND party_id = $pid"));
        if (!$x || !in_array($x['status'], DL_DISPUTE_OPEN, true)) { $go('error', 'This issue is closed.'); }
        if ($msg === '') { $go('error', 'Write your answer.'); }
        try { $evid = $saveEvidence(); } catch (Throwable $e) { $go('error', 'File: ' . $e->getMessage()); }
        $xid = (int) $x['id'];
        $s = mysqli_prepare($conn, "INSERT INTO dl_dispute_notes (dispute_id, actor, note, visible_to_tasker, created_at) VALUES (?, 'tasker', ?, 1, ?)");
        mysqli_stmt_bind_param($s, 'iss', $xid, $msg, $now); mysqli_stmt_execute($s);
        if ($evid) { $all = array_merge(json_decode((string) $x['evidence'], true) ?: [], $evid); $j = json_encode($all); $s = mysqli_prepare($conn, 'UPDATE dl_disputes SET evidence = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'si', $j, $xid); mysqli_stmt_execute($s); }
        if ($x['status'] === 'Awaiting Tasker Information') { mysqli_query($conn, "UPDATE dl_disputes SET status = 'Under Review', updated_at = '$now' WHERE id = $xid"); }
        dl_notify_staff($conn, $x['assigned_to'] ? [(int) $x['assigned_to']] : dl_users_with($conn, 'delegations.finance'), 'Tasker answered on dispute ' . dl_dispute_ref($xid), mb_substr($msg, 0, 300));
        $go('success', 'Your answer was sent.');
    }
    $go('error', 'Unknown action.');
}

$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT d.*, p.product_name FROM dl_delegations d LEFT JOIN products p ON p.id = d.product_id WHERE d.party_id = $pid AND (d.verified_at IS NOT NULL OR EXISTS (SELECT 1 FROM dl_payments x WHERE x.delegation_id = d.id)) ORDER BY d.verified_at DESC LIMIT 300"), MYSQLI_ASSOC);
$sum = ['due' => 0, 'paid' => 0, 'out' => 0];
foreach ($rows as &$d) { $d['ref'] = dl_ref((int) $d['id']); $d['pos'] = dl_pay_position($conn, $d); $sum['due'] += $d['pos']['approved'] ?? $d['pos']['entitled']; $sum['paid'] += $d['pos']['paid']; $sum['out'] += $d['pos']['outstanding']; }
unset($d);
$disputes = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM dl_disputes WHERE party_id = $pid ORDER BY id DESC LIMIT 100"), MYSQLI_ASSOC);
$e = 'portal_e';
$csrf = portal_csrf_field();
$statusText = function (array $pos) { // what the Tasker sees: no internal wording
    $s = $pos['status'];
    if ($s === 'Pending Payment Approval') { return 'Being approved'; }
    if ($s === 'Fully Paid – Awaiting Tasker Confirmation') { return 'Paid – please confirm'; }
    if ($s === 'Reconciliation Required') { return 'Paid – being checked'; }
    if ($s === 'Partially Paid') { return 'Partially paid – RWF ' . number_format($pos['outstanding']) . ' outstanding'; }
    return $s;
};
dlp_header('My Payments', $t);
portal_alerts();
?>
<h1 class="mb-1">My Payments</h1>
<p class="text-muted">What RISE MOTIVE owes you and what it has paid. Please confirm every payment you receive, or report a problem.</p>
<div class="row g-2 mb-4">
    <div class="col-4"><div class="card"><div class="card-body py-2"><div class="small text-muted">Earned</div><div class="fw-bold">RWF <?= number_format($sum['due']); ?></div></div></div></div>
    <div class="col-4"><div class="card"><div class="card-body py-2"><div class="small text-muted">Paid</div><div class="fw-bold">RWF <?= number_format($sum['paid']); ?></div></div></div></div>
    <div class="col-4"><div class="card"><div class="card-body py-2"><div class="small text-muted">Outstanding</div><div class="fw-bold <?= $sum['out'] > 0.5 ? 'text-danger' : ''; ?>">RWF <?= number_format($sum['out']); ?></div></div></div></div>
</div>
<?php foreach ($rows as $d) { $pos = $d['pos']; ?>
<div class="card mb-3"><div class="card-body">
    <div class="d-flex justify-content-between flex-wrap gap-2"><div><div class="fw-bold"><?= $e($d['product_name']); ?></div><div class="small text-muted"><a href="tasker_task.php?ref=<?= $e($d['ref']); ?>"><?= $e($d['ref']); ?></a><?= $d['completed_at'] ? ' · completed ' . $e(date('d M Y', strtotime($d['completed_at']))) : ''; ?></div></div>
        <div class="text-end"><div class="small text-muted">Due to you</div><div class="fw-bold">RWF <?= number_format((float) ($pos['approved'] ?? $pos['entitled'])); ?></div><span class="badge bg-<?= in_array($pos['status'], ['Confirmed'], true) ? 'success' : (in_array($pos['status'], ['Payment Disputed', 'Unpaid'], true) ? 'danger' : 'secondary'); ?>"><?= $e($statusText($pos)); ?></span>
            <?php if ($pos['due_date'] && $pos['outstanding'] > 0.5) { ?><div class="small text-muted">Payment due by <?= $e(date('d M Y', strtotime($pos['due_date']))); ?></div><?php } ?></div></div>
    <?php foreach ($pos['pays'] as $p) { if ($p['status'] !== 'Recorded') { continue; } ?>
    <div class="border rounded-3 p-2 mt-2">
        <div class="d-flex justify-content-between flex-wrap gap-2"><div><b>RWF <?= number_format((float) $p['amount']); ?></b> · <?= $e(date('d M Y', strtotime($p['paid_on']))); ?> · <?= $e($p['method']); ?><div class="small text-muted">Reference <?= $e($p['reference']); ?></div></div>
            <div><?php if ($p['confirm_status'] === 'Confirmed') { ?><span class="badge bg-success">Confirmed</span><?php } elseif ($p['confirm_status'] === 'Reported issue') { ?><span class="badge bg-danger">Problem reported</span><?php } else { ?>
                <form method="post" class="d-inline"><?= $csrf; ?><input type="hidden" name="act" value="confirm"><input type="hidden" name="pay" value="<?= (int) $p['id']; ?>"><button class="btn btn-success btn-sm">I received it</button></form>
                <button class="btn btn-outline-danger btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#pb<?= (int) $p['id']; ?>">Report a problem</button>
                <?php if ($p['confirm_due']) { ?><div class="small text-muted">Please answer by <?= $e(dl_deadline_text($p['confirm_due'])); ?></div><?php } ?><?php } ?></div></div>
        <?php if (!in_array($p['confirm_status'], ['Confirmed', 'Reported issue'], true)) { ?>
        <form method="post" enctype="multipart/form-data" class="collapse mt-2" id="pb<?= (int) $p['id']; ?>"><?= $csrf; ?><input type="hidden" name="act" value="problem"><input type="hidden" name="pay" value="<?= (int) $p['id']; ?>">
            <select name="kind" class="form-select form-select-sm mb-1"><?php foreach (DL_DISPUTE_KINDS as $k) { ?><option><?= $e($k); ?></option><?php } ?></select>
            <input name="received" inputmode="numeric" class="form-control form-control-sm mb-1" placeholder="Amount you actually received (RWF)">
            <textarea name="message" rows="2" class="form-control form-control-sm mb-1" placeholder="Explain the problem" required></textarea>
            <input type="file" name="evidence[]" multiple accept="image/*,application/pdf" class="form-control form-control-sm mb-1"><button class="btn btn-danger btn-sm">Send</button></form>
        <?php } ?>
    </div>
    <?php } ?>
    <?php if ($d['verified_at'] && !$pos['pays']) { ?><form method="post" enctype="multipart/form-data" class="mt-2"><?= $csrf; ?><input type="hidden" name="act" value="issue_general"><input type="hidden" name="ref" value="<?= $e($d['ref']); ?>">
        <button class="btn btn-link btn-sm px-0" type="button" data-bs-toggle="collapse" data-bs-target="#gi<?= (int) $d['id']; ?>">Payment problem on this task?</button>
        <div class="collapse" id="gi<?= (int) $d['id']; ?>"><textarea name="message" rows="2" class="form-control form-control-sm mb-1" placeholder="Explain"></textarea><input type="file" name="evidence[]" multiple class="form-control form-control-sm mb-1"><button class="btn btn-outline-danger btn-sm">Send</button></div></form><?php } ?>
</div></div>
<?php } ?>
<?php if (!$rows) { ?><div class="alert alert-light border">No payment yet: payments appear here once your work is verified.</div><?php } ?>
<?php if ($disputes) { ?>
<h2 class="mt-4">My payment issues</h2>
<?php foreach ($disputes as $x) { $notes = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM dl_dispute_notes WHERE dispute_id = ' . (int) $x['id'] . ' AND visible_to_tasker = 1 ORDER BY id'), MYSQLI_ASSOC); $open = in_array($x['status'], DL_DISPUTE_OPEN, true); ?>
<div class="card mb-2"><div class="card-body py-2">
    <div class="d-flex justify-content-between"><b><?= $e(dl_dispute_ref((int) $x['id'])); ?> · <?= $e($x['kind']); ?></b><span class="badge bg-<?= $open ? 'warning text-dark' : 'success'; ?>"><?= $e($x['status']); ?></span></div>
    <div class="small"><?= $e(dl_ref((int) $x['delegation_id'])); ?><?= $x['payment_reference'] ? ' · ' . $e($x['payment_reference']) : ''; ?> · <?= $e(date('d M Y', strtotime($x['created_at']))); ?></div>
    <div class="small"><?= nl2br($e($x['description'])); ?></div>
    <?php foreach ($notes as $n) { ?><div class="small border-top mt-1 pt-1"><span class="text-muted"><?= $n['actor'] === 'tasker' ? 'You' : 'RISE MOTIVE'; ?> · <?= $e(date('d M H:i', strtotime($n['created_at']))); ?>:</span> <?= nl2br($e($n['note'])); ?></div><?php } ?>
    <?php if (!$open && $x['resolution']) { ?><div class="small border-top mt-1 pt-1"><b>Decision:</b> <?= nl2br($e($x['resolution'])); ?></div><?php } ?>
    <?php if ($open) { ?><form method="post" enctype="multipart/form-data" class="mt-2"><?= $csrf; ?><input type="hidden" name="act" value="reply"><input type="hidden" name="dispute" value="<?= (int) $x['id']; ?>"><textarea name="message" rows="2" class="form-control form-control-sm mb-1" placeholder="<?= $x['status'] === 'Awaiting Tasker Information' ? 'RISE MOTIVE is waiting for your information' : 'Add information'; ?>"></textarea><input type="file" name="evidence[]" multiple class="form-control form-control-sm mb-1"><button class="btn btn-outline-primary btn-sm">Send</button></form><?php } ?>
</div></div>
<?php } ?>
<?php } ?>
<?php portal_footer();