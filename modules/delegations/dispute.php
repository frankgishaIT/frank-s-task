<?php
/** NEW FILE: modules/delegations/dispute.php — investigate and resolve one payment dispute (Management and Finance). */
require __DIR__ . '/common.php';
if (!dlc($conn, 'finance') && !dlc($conn, 'approve')) { dl_require($conn, 'finance'); }
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$x = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_disputes WHERE id = ' . $id));
if (!$x) { dl_go('disputes.php', 'error', 'Dispute not found.'); }
$ev = json_decode((string) $x['evidence'], true) ?: [];
if (isset($_GET['file'])) {
    $f = $ev[(int) $_GET['file']] ?? null;
    if (!$f) { http_response_code(404); exit('Not found.'); }
    require_once __DIR__ . '/../../includes/evidence_store.php';
    evidence_output($conn, $f['ref'], pathinfo((string) $f['name'], PATHINFO_FILENAME) ?: 'dispute_file');
    exit;
}
$d = dl_get($conn, (int) $x['delegation_id']);
$ref = dl_dispute_ref($id);
$back = 'dispute.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dl_csrf_ok()) { dl_go($back, 'error', 'The form expired. Please try again.'); }
    $act = (string) ($_POST['act'] ?? '');
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000);
    $now = dl_now(); $u = dl_uid();
    $open = in_array($x['status'], DL_DISPUTE_OPEN, true);
    if (!$open && $act !== 'note') { dl_go($back, 'error', 'This dispute is closed.'); }
    $addNote = function (string $txt, int $visible) use ($conn, $id, $now, $u) {
        $s = mysqli_prepare($conn, "INSERT INTO dl_dispute_notes (dispute_id, actor, user_id, note, visible_to_tasker, created_at) VALUES (?, 'staff', ?, ?, ?, ?)");
        mysqli_stmt_bind_param($s, 'iisis', $id, $u, $txt, $visible, $now); mysqli_stmt_execute($s);
    };
    if ($act === 'assign') {
        $to = (int) ($_POST['assigned_to'] ?? 0);
        mysqli_query($conn, "UPDATE dl_disputes SET assigned_to = " . ($to ?: 'NULL') . ", status = IF(status = 'Open', 'Under Review', status), updated_at = '$now' WHERE id = $id");
        dl_audit($conn, 'dispute', $id, 'assign', $ref . ' assigned to ' . (dl_users($conn)[$to] ?? '—'));
        if ($to) { dl_notify_staff($conn, [$to], 'Payment dispute ' . $ref . ' assigned to you', $x['kind'] . ' · ' . $d['ref'] . ': ' . mb_substr($x['description'], 0, 200)); }
        dl_go($back, 'success', 'Reviewer assigned.');
    }
    if ($act === 'status') {
        $to = in_array($_POST['to'] ?? '', ['Under Review', 'Awaiting Tasker Information', 'Awaiting Finance Review'], true) ? $_POST['to'] : '';
        if (!$to) { dl_go($back, 'error', 'Choose the status.'); }
        if ($to === 'Awaiting Tasker Information' && $note === '') { dl_go($back, 'error', 'Say what the Tasker must send.'); }
        mysqli_query($conn, "UPDATE dl_disputes SET status = '$to', updated_at = '$now' WHERE id = $id");
        if ($note !== '') { $addNote($note, $to === 'Awaiting Tasker Information' || !empty($_POST['visible']) ? 1 : 0); }
        dl_audit($conn, 'dispute', $id, 'status', $ref . ': ' . $x['status'] . ' → ' . $to, $note ?: null);
        if ($to === 'Awaiting Tasker Information') { dl_notify_tasker($conn, $d, 'update', 'About your payment issue ' . $ref . ': ' . mb_substr($note, 0, 200) . ' Please answer in the portal (My Payments).'); }
        dl_go($back, 'success', 'Status: ' . $to . '.');
    }
    if ($act === 'note') {
        if ($note === '') { dl_go($back, 'error', 'Write the note.'); }
        $addNote($note, !empty($_POST['visible']) ? 1 : 0);
        dl_go($back, 'success', 'Note added.');
    }
    if ($act === 'resolve' || $act === 'reject') {
        if ($note === '') { dl_go($back, 'error', $act === 'resolve' ? 'Write the resolution (what was found and done).' : 'Write the explanation for the Tasker.'); }
        $to = $act === 'resolve' ? 'Resolved' : 'Rejected with Explanation';
        $s = mysqli_prepare($conn, 'UPDATE dl_disputes SET status = ?, resolution = ?, resolved_by = ?, resolved_at = ?, updated_at = ? WHERE id = ?');
        mysqli_stmt_bind_param($s, 'ssissi', $to, $note, $u, $now, $now, $id); mysqli_stmt_execute($s);
        dl_audit($conn, 'dispute', $id, $act, $ref . ' ' . strtolower($to), $note);
        dl_event($conn, (int) $d['id'], 'dispute_closed', 'Payment dispute ' . $ref . ' ' . strtolower($to) . ': ' . mb_substr($note, 0, 300), 'staff', 0, null, 1);
        dl_notify_tasker($conn, $d, 'update', 'Your payment issue ' . $ref . ' is ' . strtolower($to) . ': ' . mb_substr($note, 0, 200));
        dl_sync_after_payment($conn, (int) $d['id']);
        dl_go($back, 'success', $to . '. If the Tasker now confirms receipt, record it on the payment so the delegation can be reconciled.');
    }
    dl_go($back, 'error', 'Unknown action.');
}

$notes = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM dl_dispute_notes WHERE dispute_id = ' . $id . ' ORDER BY id'), MYSQLI_ASSOC);
$names = dl_users($conn);
$reviewers = array_unique(array_merge(dl_users_with($conn, 'delegations.finance'), dl_approvers($conn)));
$pay = $x['payment_id'] ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_payments WHERE id = ' . (int) $x['payment_id'])) : null;
$open = in_array($x['status'], DL_DISPUTE_OPEN, true);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'disputes.php', 'Payment dispute ' . $ref, '<span class="dl-st ' . ($open ? 'dl-bad' : 'dl-ok') . '">' . dl_e($x['status']) . '</span>');
?>
<div class="row g-3">
<div class="col-lg-7">
    <div class="card mb-3"><div class="card-body py-2 small">
        <table class="table table-sm dl-tbl mb-0"><tbody>
            <tr><th style="width:36%">RM Tasker</th><td><a href="tasker.php?id=<?= (int) $x['party_id']; ?>"><?= dl_e($d['tasker']['display'] ?? ''); ?></a> <?= dl_e($d['tasker']['code'] ?? ''); ?></td></tr>
            <tr><th>Delegation</th><td><a href="pay.php?id=<?= (int) $d['id']; ?>"><?= dl_e($d['ref']); ?></a> · <?= dl_e($d['product_name']); ?></td></tr>
            <tr><th>Payment reference</th><td><?= $pay ? dl_e(dl_pay_ref((int) $pay['id']) . ' · ' . $pay['reference'] . ' · ' . $pay['method'] . ' · ' . date('d M Y', strtotime($pay['paid_on']))) : '—'; ?></td></tr>
            <tr><th>Issue</th><td><?= dl_e($x['kind']); ?></td></tr>
            <tr><th>Expected / received / disputed</th><td><?= $x['expected_amount'] !== null ? dl_money($x['expected_amount']) : '—'; ?> / <?= $x['amount_received'] !== null ? dl_money($x['amount_received']) : '—'; ?> / <b><?= $x['disputed_amount'] !== null ? dl_money($x['disputed_amount']) : '—'; ?></b></td></tr>
            <tr><th>Description</th><td><?= nl2br(dl_e($x['description'])); ?></td></tr>
            <tr><th>Reported</th><td><?= dl_e(date('d M Y H:i', strtotime($x['created_at']))); ?> · <?= $x['reported_by'] === 'tasker' ? 'by the Tasker in the portal' : 'by ' . dl_e($names[(int) $x['reported_user']] ?? 'staff') . ' for the Tasker'; ?></td></tr>
            <tr><th>Evidence</th><td><?php foreach ($ev as $i => $f) { ?><a href="?id=<?= $id; ?>&file=<?= $i; ?>" target="_blank" class="me-2"><i class="bi bi-paperclip"></i><?= dl_e($f['name']); ?></a><?php } ?><?= $ev ? '' : '—'; ?></td></tr>
            <tr><th>Reviewer</th><td><?= dl_e($names[(int) $x['assigned_to']] ?? 'not assigned'); ?></td></tr>
            <?php if (!$open) { ?><tr><th>Resolution</th><td><?= nl2br(dl_e($x['resolution'])); ?><div class="text-muted"><?= dl_e($names[(int) $x['resolved_by']] ?? ''); ?> · <?= dl_e(date('d M Y', strtotime($x['resolved_at']))); ?></div></td></tr><?php } ?>
        </tbody></table>
    </div></div>
    <h6 class="mb-1">Investigation notes</h6>
    <div class="card"><div class="card-body py-2 small">
        <?php foreach ($notes as $n) { ?><div class="border-bottom py-1"><span class="text-muted"><?= dl_e(date('d M H:i', strtotime($n['created_at']))); ?> · <?= $n['actor'] === 'tasker' ? 'RM Tasker' : dl_e($names[(int) $n['user_id']] ?? 'Staff'); ?><?= $n['actor'] === 'staff' && !(int) $n['visible_to_tasker'] ? ' · internal' : ''; ?></span><div><?= nl2br(dl_e($n['note'])); ?></div></div><?php } ?>
        <?php if (!$notes) { ?><div class="text-muted">No note yet.</div><?php } ?>
        <form method="post" class="mt-2"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="note"><textarea name="note" rows="2" class="form-control form-control-sm mb-1"></textarea><label class="small me-2"><input type="checkbox" name="visible" value="1"> the Tasker can see it</label><button class="btn btn-outline-primary btn-sm py-0">Add note</button></form>
    </div></div>
</div>
<div class="col-lg-5">
<?php if ($open) { ?>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1">Reviewer</div>
        <form method="post" class="d-flex gap-1"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="assign"><select name="assigned_to" class="form-select form-select-sm"><option value="">—</option><?php foreach ($reviewers as $r) { ?><option value="<?= (int) $r; ?>" <?= (int) $x['assigned_to'] === (int) $r ? 'selected' : ''; ?>><?= dl_e($names[(int) $r] ?? ''); ?></option><?php } ?></select><button class="btn btn-outline-primary btn-sm">Assign</button></form>
    </div></div>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1">Investigation status</div>
        <form method="post"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="status"><select name="to" class="form-select form-select-sm mb-1"><option>Under Review</option><option>Awaiting Tasker Information</option><option>Awaiting Finance Review</option></select><input name="note" class="form-control form-control-sm mb-1" placeholder="Note / what the Tasker must send"><label class="small d-block mb-1"><input type="checkbox" name="visible" value="1"> the Tasker can see the note</label><button class="btn btn-outline-primary btn-sm">Update</button></form>
    </div></div>
    <div class="card mb-3 border-success"><div class="card-body py-2"><div class="fw-semibold small mb-1">Decision</div>
        <form method="post"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><textarea name="note" rows="3" class="form-control form-control-sm mb-1" placeholder="Resolution / explanation (the Tasker sees it)"></textarea>
            <div class="d-flex gap-1"><button name="act" value="resolve" class="btn btn-success btn-sm">Resolved</button><button name="act" value="reject" class="btn btn-outline-danger btn-sm">Reject with explanation</button></div></form>
        <div class="small text-muted mt-1">If money must be paid again or recovered, do it on the delegation's Payments page (with approval). Nothing is deducted from a Tasker automatically.</div>
    </div></div>
<?php } ?>
</div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>