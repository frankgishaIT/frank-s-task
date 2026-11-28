<?php
/**
 * NEW FILE: modules/delegations/issue.php — one service issue: investigation, the Tasker's side, responsibility
 * finding, corrective action, resolution, financial impact and any recovery. Everything is kept in the issue's
 * history and in the audit trail.
 *  - Rating: only a finding of "RM Tasker" or "Shared" on a resolved issue counts against the Tasker. The investigator
 *    may revise the delegation's quality rating with the finding (its performance score is recalculated, old value kept).
 *  - Recovery: never automatic. Proposed with the reason, authorized by ANOTHER approver; Finance then reduces the
 *    approved payment (Payments) or records how it was recovered. Nothing is deducted by this page.
 */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$i = dlq_get($conn, $id);
if (!$i) { dl_go('issues.php', 'error', 'Issue not found.'); }
$back = 'issue.php?id=' . $id;
$canQ = dlc($conn, 'quality');
$canApprove = dlc($conn, 'approve');
$fin = dlc($conn, 'finance');
$names = dl_users($conn);
$d = dl_get($conn, (int) $i['delegation_id']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dl_csrf_ok()) { dl_go($back, 'error', 'The form expired. Please try again.'); }
    $act = (string) ($_POST['act'] ?? '');
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 4000);
    $now = dl_now(); $u = dl_uid();
    $need = function (bool $ok, string $msg) use ($back) { if (!$ok) { dl_go($back, 'error', $msg); } };
    $upd = function (array $set) use ($conn, $id, $now) {
        $set['updated_at'] = $now;
        $parts = []; $types = ''; $vals = [];
        foreach ($set as $k => $v) { $parts[] = "`$k` = ?"; $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's'); $vals[] = $v; }
        $s = mysqli_prepare($conn, 'UPDATE dl_issues SET ' . implode(', ', $parts) . ' WHERE id = ?');
        $types .= 'i'; $vals[] = $id;
        mysqli_stmt_bind_param($s, $types, ...$vals);
        mysqli_stmt_execute($s);
    };
    $open = in_array($i['status'], DLQ_OPEN, true);
    switch ($act) {
        case 'note':
            $need($canQ || dlc($conn, 'create'), 'Not allowed.');
            $need($note !== '', 'Write the note.');
            $vis = $canQ && !empty($_POST['visible']) ? 1 : 0;
            dlq_note($conn, $id, 'note', $note, $vis);
            if ($vis && $d) { dl_event($conn, (int) $d['id'], 'issue_message', 'About the issue reported on this task: ' . $note, 'staff', 0, null, 1); dl_notify_tasker($conn, $d, 'update', 'RISE MOTIVE about the issue on ' . $d['ref'] . ': ' . mb_substr($note, 0, 200)); }
            dl_go($back, 'success', $vis ? 'Note added and sent to the RM Tasker.' : 'Note added.');
        case 'investigate':
            $need($canQ && $open, 'Not possible now.');
            $to = (int) ($_POST['assigned_to'] ?? 0) ?: $u;
            $upd(['status' => 'Under Investigation', 'assigned_to' => $to]);
            dlq_note($conn, $id, 'status', 'Investigation started · investigator ' . ($names[$to] ?? '#' . $to));
            dl_audit($conn, 'issue', $id, 'investigation', $i['ref'] . ' under investigation by ' . ($names[$to] ?? '#' . $to), null, 'staff', $i['party_id'] ? (int) $i['party_id'] : null);
            if ($to !== $u) { dl_notify_staff($conn, [$to], 'Investigate ' . $i['ref'], $i['type'] . ' on ' . $i['dref'] . '.'); }
            dl_go($back, 'success', 'Investigation started.');
        case 'impact':
            $need($canQ, 'Not allowed.');
            $ik = in_array($_POST['impact_kind'] ?? '', DLQ_IMPACT, true) ? $_POST['impact_kind'] : 'None';
            $amt = round(max(0, (float) str_replace(',', '', (string) ($_POST['impact_amount'] ?? '0'))), 2);
            $need($note !== '', 'Give the reason for changing the financial impact.');
            $need($ik === 'None' || $amt > 0, 'Give the amount.');
            $need((float) $i['recovery_amount'] <= $amt + 0.5 || !in_array($i['recovery_status'], ['Proposed', 'Approved', 'Recovered'], true), 'The impact cannot be lower than the recovery already proposed or approved.');
            $upd(['impact_kind' => $ik, 'impact_amount' => $amt, 'impact_note' => mb_substr(trim((string) ($_POST['impact_note'] ?? $i['impact_note'])), 0, 500)]);
            $txt = 'Financial impact ' . dl_money($i['impact_amount']) . ' (' . $i['impact_kind'] . ') → ' . dl_money($amt) . ' (' . $ik . ')';
            dlq_note($conn, $id, 'impact', $txt . '. Reason: ' . $note);
            dl_audit($conn, 'issue', $id, 'impact', $i['ref'] . ' · ' . $txt, $note, 'staff', $i['party_id'] ? (int) $i['party_id'] : null);
            dl_go($back, 'success', 'Financial impact updated.');
        case 'resolve':
            $need($canQ && $open, 'Not possible now.');
            $resp = in_array($_POST['responsibility'] ?? '', DLQ_RESP, true) ? $_POST['responsibility'] : '';
            $findings = mb_substr(trim((string) ($_POST['findings'] ?? '')), 0, 4000);
            $corr = mb_substr(trim((string) ($_POST['corrective_action'] ?? '')), 0, 4000);
            $res = mb_substr(trim((string) ($_POST['resolution'] ?? '')), 0, 4000);
            $need($resp !== '' && $resp !== 'Not yet investigated', 'Record the responsibility finding of the investigation.');
            $need(mb_strlen($findings) >= 10, 'Write the findings: what the investigation established and on what evidence.');
            $need($res !== '', 'Write the resolution for the customer / RISE MOTIVE.');
            $against = in_array($resp, ['RM Tasker', 'Shared'], true);
            $heard = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM dl_issue_notes WHERE issue_id = $id AND (actor = 'tasker' OR kind = 'tasker_side')"))[0];
            $need(!$against || $heard || trim((string) ($_POST['tasker_side'] ?? '')) !== '', 'A finding against the RM Tasker needs their side first: record what they said (or that they did not answer when asked).');
            if (trim((string) ($_POST['tasker_side'] ?? '')) !== '') { dlq_note($conn, $id, 'tasker_side', 'RM Tasker\'s side (recorded by staff): ' . mb_substr(trim((string) $_POST['tasker_side']), 0, 2000)); }
            $set = ['status' => 'Resolved', 'responsibility' => $resp, 'findings' => $findings, 'corrective_action' => $corr ?: null, 'resolution' => $res, 'resolved_by' => $u, 'resolved_at' => $now];
            $rq = (float) ($_POST['revised_quality'] ?? 0);
            $revTxt = '';
            if ($against && $rq >= 1 && $rq <= 5 && $d && $d['verified_at'] && abs($rq - (float) $d['quality_rating']) > 0.001) {
                $score = dl_perf_score($conn, $rq, (float) $d['satisfaction_rating'], (float) $d['instructions_rating'], (float) $d['communication_rating'], (bool) $d['ontime']);
                $set['revised_quality'] = $rq; $set['old_perf'] = (float) $d['perf_score'];
                mysqli_query($conn, 'UPDATE dl_delegations SET quality_rating = ' . $rq . ', perf_score = ' . $score . ' WHERE id = ' . (int) $d['id']);
                $revTxt = ' Quality rating ' . (float) $d['quality_rating'] . ' → ' . $rq . '/5, performance ' . (float) $d['perf_score'] . ' → ' . $score . '/100.';
                dl_event($conn, (int) $d['id'], 'rating_revised', 'Rating revised after investigation of ' . $i['ref'] . ':' . $revTxt, 'staff', 0, $findings, 1);
            }
            $upd($set);
            dlq_note($conn, $id, 'resolved', 'Resolved · responsibility: ' . $resp . '. Findings: ' . $findings . ($corr ? ' Corrective action: ' . $corr : '') . ' Resolution: ' . $res . $revTxt, 1);
            dl_audit($conn, 'issue', $id, 'resolved', $i['ref'] . ' · ' . $i['dref'] . ' · responsibility ' . $resp . '.' . $revTxt, $findings, 'staff', $i['party_id'] ? (int) $i['party_id'] : null);
            if ($d) {
                dl_event($conn, (int) $d['id'], 'issue_resolved', 'Issue (' . $i['type'] . ') reviewed. Finding: ' . ($against ? ($resp === 'Shared' ? 'shared responsibility' : 'your responsibility') : 'not your responsibility') . '. ' . $res, 'staff', 0, null, 1);
                dl_notify_tasker($conn, $d, 'update', 'The issue on ' . $d['ref'] . ' was reviewed. Finding: ' . ($against ? ($resp === 'Shared' ? 'shared responsibility' : 'your responsibility') : 'not your responsibility') . '. Details in your task page.');
            }
            $lifted = dlq_release($conn, dlq_get($conn, $id));
            dl_go($back, 'success', 'Resolved.' . ($against ? ' It now counts in the RM Tasker\'s rating.' : ' It does not count against the RM Tasker.') . ($lifted ? ' The payment hold is lifted.' : ''));
        case 'close':
            $need($canQ && $i['status'] === 'Resolved', 'Resolve it first.');
            $need(!in_array($i['recovery_status'], ['Proposed'], true), 'Decide on the proposed recovery first.');
            $upd(['status' => 'Closed']);
            dlq_note($conn, $id, 'status', 'Closed' . ($note ? ': ' . $note : ''));
            dl_audit($conn, 'issue', $id, 'closed', $i['ref'] . ' closed', $note ?: null, 'staff', $i['party_id'] ? (int) $i['party_id'] : null);
            dl_go($back, 'success', 'Closed.');
        case 'reopen':
            $need($canQ && !$open, 'Not possible now.');
            $need($note !== '', 'Give the reason for reopening it (new information…).');
            $set = ['status' => 'Under Investigation'];
            if ($i['revised_quality'] !== null && $i['old_perf'] !== null && $d) {
                // the earlier revision is undone until a new finding is made
                $oldQ = mysqli_fetch_row(mysqli_query($conn, "SELECT note FROM dl_issue_notes WHERE issue_id = $id AND kind = 'resolved' ORDER BY id DESC LIMIT 1"));
                if (preg_match('/Quality rating ([\d.]+) →/', (string) ($oldQ[0] ?? ''), $m)) {
                    mysqli_query($conn, 'UPDATE dl_delegations SET quality_rating = ' . (float) $m[1] . ', perf_score = ' . (float) $i['old_perf'] . ' WHERE id = ' . (int) $d['id']);
                    dl_event($conn, (int) $d['id'], 'rating_restored', 'Issue ' . $i['ref'] . ' reopened: rating restored to quality ' . (float) $m[1] . '/5, performance ' . (float) $i['old_perf'] . '/100 until a new finding.', 'staff', 0, $note, 1);
                }
                $set['revised_quality'] = null; $set['old_perf'] = null;
            }
            $upd($set);
            mysqli_query($conn, "UPDATE dl_issues SET responsibility = 'Not yet investigated' WHERE id = $id");
            dlq_note($conn, $id, 'status', 'Reopened: ' . $note);
            dl_audit($conn, 'issue', $id, 'reopened', $i['ref'] . ' reopened (finding withdrawn until the new investigation ends)', $note, 'staff', $i['party_id'] ? (int) $i['party_id'] : null);
            dl_go($back, 'success', 'Reopened. The earlier finding no longer counts.');
        case 'recovery_propose':
            $need($canQ && $i['status'] === 'Resolved' && in_array($i['responsibility'], ['RM Tasker', 'Shared'], true), 'A recovery can only follow a resolved finding against the RM Tasker.');
            $need(!in_array($i['recovery_status'], ['Proposed', 'Approved', 'Recovered'], true), 'A recovery is already proposed or decided.');
            $amt = round((float) str_replace(',', '', (string) ($_POST['recovery_amount'] ?? '0')), 2);
            $need($amt > 0 && $amt <= (float) $i['impact_amount'] + 0.5, 'The recovery must be more than 0 and not more than the documented cost (' . dl_money($i['impact_amount']) . ').');
            $need(mb_strlen($note) >= 10, 'Explain the basis for the recovery (evidence, agreement with the Tasker…).');
            $upd(['recovery_amount' => $amt, 'recovery_status' => 'Proposed', 'recovery_note' => mb_substr($note, 0, 500), 'recovery_by' => $u, 'recovery_at' => $now]);
            dlq_note($conn, $id, 'recovery', 'Recovery of ' . dl_money($amt) . ' proposed: ' . $note);
            dl_audit($conn, 'issue', $id, 'recovery_proposed', $i['ref'] . ' · recovery ' . dl_money($amt) . ' proposed (needs another approver)', $note, 'staff', $i['party_id'] ? (int) $i['party_id'] : null);
            dl_notify_staff($conn, array_values(array_diff(dl_approvers($conn), [$u])), 'Recovery to authorize on ' . $i['ref'], dl_money($amt) . ' from ' . $i['tasker'] . ': ' . mb_substr($note, 0, 200));
            dl_go($back, 'success', 'Recovery proposed. Another approver must authorize it; nothing is deducted until then.');
        case 'recovery_decide':
            $need($canApprove && $i['recovery_status'] === 'Proposed', 'Not possible now.');
            $need((int) $i['recovery_by'] !== $u, 'You proposed this recovery: another approver must decide.');
            $ok = ($_POST['decision'] ?? '') === 'approve';
            $need($ok || $note !== '', 'Give the reason for rejecting it.');
            $upd(['recovery_status' => $ok ? 'Approved' : 'Rejected', 'recovery_approved_by' => $u]);
            dlq_note($conn, $id, 'recovery', 'Recovery ' . ($ok ? 'authorized' : 'rejected') . ($note ? ': ' . $note : ''));
            dl_audit($conn, 'issue', $id, $ok ? 'recovery_approved' : 'recovery_rejected', $i['ref'] . ' · recovery ' . dl_money($i['recovery_amount']), $note ?: null, 'staff', $i['party_id'] ? (int) $i['party_id'] : null);
            if ($ok && $d) {
                dl_event($conn, (int) $d['id'], 'recovery_authorized', 'Authorized recovery of ' . dl_money($i['recovery_amount']) . ' (' . $i['ref'] . '). Finance applies it by changing the approved payment amount, with this reference.', 'staff', 0, $note ?: null, 1);
                dl_notify_staff($conn, dl_users_with($conn, 'delegations.finance'), 'Authorized recovery ' . $i['ref'], dl_money($i['recovery_amount']) . ' from ' . $i['tasker'] . ' on ' . $i['dref'] . '.');
            }
            dl_go($back, 'success', $ok ? 'Recovery authorized. Finance applies it (Payments: change the approved amount with reference ' . $i['ref'] . ').' : 'Recovery rejected.');
        case 'recovery_done':
            $need($fin && $i['recovery_status'] === 'Approved', 'Not possible now.');
            $need($note !== '', 'Say how it was recovered (approved payment reduced on DL…, refund by the Tasker with reference…).');
            $upd(['recovery_status' => 'Recovered']);
            dlq_note($conn, $id, 'recovery', 'Recovered: ' . $note);
            dl_audit($conn, 'issue', $id, 'recovered', $i['ref'] . ' · ' . dl_money($i['recovery_amount']) . ' recovered', $note, 'staff', $i['party_id'] ? (int) $i['party_id'] : null);
            dl_go($back, 'success', 'Recovery recorded.');
    }
    dl_go($back, 'error', 'Unknown action.');
}

$notes = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM dl_issue_notes WHERE issue_id = ' . $id . ' ORDER BY id DESC'), MYSQLI_ASSOC);
$open = in_array($i['status'], DLQ_OPEN, true);
$against = in_array($i['responsibility'], ['RM Tasker', 'Shared'], true);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'issues.php', 'Issue ' . $i['ref']);
$csrf = dl_csrf_field();
?>
<div class="mb-2"><a href="issues.php" class="small">← Quality &amp; issues</a></div>
<div class="row g-3">
<div class="col-lg-7">
<div class="card mb-3"><div class="card-body py-2">
    <div class="d-flex flex-wrap gap-2 align-items-center mb-1"><b><?= dl_e($i['ref']); ?></b> <?= dlq_badge($i['status']); ?> <?= dlq_resp_badge($i['responsibility']); ?>
        <?= (int) $i['held_payment'] && $open ? '<span class="dl-st dl-gold">Tasker payment held</span>' : ''; ?></div>
    <table class="table table-sm small mb-0"><tbody>
        <tr><th style="width:32%">Delegation</th><td><a href="view.php?id=<?= (int) $i['delegation_id']; ?>"><?= dl_e($i['dref']); ?></a> · <?= dl_e($i['product_name']); ?><?= $d ? ' · ' . dl_status_badge($d['status']) : ''; ?></td></tr>
        <tr><th>RM Tasker</th><td><?= $i['party_id'] ? '<a href="tasker.php?id=' . (int) $i['party_id'] . '">' . dl_e($i['tasker']) . '</a>' : '—'; ?></td></tr>
        <tr><th>Issue type</th><td><?= dl_e($i['type']); ?></td></tr>
        <tr><th>Description</th><td><?= nl2br(dl_e($i['description'])); ?></td></tr>
        <tr><th>Reported by</th><td><?= dl_e($i['reported_by_kind'] . ($i['reporter_name'] ? ' – ' . $i['reporter_name'] : '')); ?> · <?= dl_e(date('d M Y H:i', strtotime($i['reported_at']))); ?> · recorded by <?= dl_e($names[(int) $i['created_by']] ?? '—'); ?></td></tr>
        <tr><th>Financial impact</th><td><b><?= dl_money($i['impact_amount']); ?></b> · <?= dl_e($i['impact_kind']); ?><?= $i['impact_note'] ? ' · ' . dl_e($i['impact_note']) : ''; ?></td></tr>
        <tr><th>Investigator</th><td><?= dl_e($i['assigned_to'] ? ($names[(int) $i['assigned_to']] ?? '#' . $i['assigned_to']) : '—'); ?></td></tr>
        <?php if ($i['findings']) { ?><tr><th>Responsibility findings</th><td><?= nl2br(dl_e($i['findings'])); ?></td></tr><?php } ?>
        <?php if ($i['corrective_action']) { ?><tr><th>Corrective action</th><td><?= nl2br(dl_e($i['corrective_action'])); ?></td></tr><?php } ?>
        <?php if ($i['resolution']) { ?><tr><th>Resolution</th><td><?= nl2br(dl_e($i['resolution'])); ?> <span class="text-muted">(<?= dl_e(($names[(int) $i['resolved_by']] ?? '') . ', ' . date('d M Y', strtotime($i['resolved_at']))); ?>)</span></td></tr><?php } ?>
        <?php if ($i['revised_quality'] !== null) { ?><tr><th>Rating revised</th><td>Quality → <?= (float) $i['revised_quality']; ?>/5 (performance was <?= (float) $i['old_perf']; ?>/100)</td></tr><?php } ?>
        <tr><th>Recovery</th><td><?= $i['recovery_status'] === 'None' ? 'None – nothing is deducted from the Tasker' : dl_e($i['recovery_status']) . ' · ' . dl_money($i['recovery_amount']) . ' · ' . dl_e($i['recovery_note']) . ($i['recovery_approved_by'] ? ' · decided by ' . dl_e($names[(int) $i['recovery_approved_by']] ?? '') : ''); ?></td></tr>
        <tr><th>Counts in the Tasker's rating</th><td><?= dlq_upheld($i) ? '<b class="text-danger">Yes</b> (finding against the Tasker)' : 'No' . ($open ? ' – not investigated yet' : ''); ?></td></tr>
    </tbody></table>
</div></div>
<h6>History</h6>
<div class="card"><div class="list-group list-group-flush">
<?php foreach ($notes as $n) { ?><div class="list-group-item small py-1"><span class="text-muted"><?= dl_e(date('d M Y H:i', strtotime($n['created_at']))); ?> · <?= dl_e($n['actor'] === 'tasker' ? 'RM Tasker' : ($names[(int) $n['user_id']] ?? 'System')); ?> · <?= dl_e(str_replace('_', ' ', $n['kind'])); ?><?= (int) $n['visible_to_tasker'] ? ' · shared with the Tasker' : ''; ?></span><div><?= nl2br(dl_e($n['note'])); ?></div></div><?php } ?>
</div></div>
</div>
<div class="col-lg-5">
<?php if ($canQ && $open) { ?>
    <?php if ($i['status'] === 'Open') { ?><form method="post" class="card mb-2"><div class="card-body py-2"><?= $csrf; ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="investigate">
        <div class="fw-semibold small mb-1">Start the investigation</div><div class="d-flex gap-1"><select name="assigned_to" class="form-select form-select-sm"><?php foreach ($names as $uid => $n) { ?><option value="<?= (int) $uid; ?>" <?= (int) $uid === dl_uid() ? 'selected' : ''; ?>><?= dl_e($n); ?></option><?php } ?></select><button class="btn btn-outline-primary btn-sm">Start</button></div></div></form><?php } ?>
    <form method="post" class="card mb-2"><div class="card-body py-2"><?= $csrf; ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="resolve">
        <div class="fw-semibold small mb-1">Finding and resolution</div>
        <select name="responsibility" class="form-select form-select-sm mb-1" required><?php foreach (DLQ_RESP as $x) { if ($x === 'Not yet investigated') { continue; } ?><option><?= $x; ?></option><?php } ?></select>
        <textarea name="findings" rows="2" class="form-control form-control-sm mb-1" placeholder="Findings: what was established, evidence" required></textarea>
        <input name="tasker_side" class="form-control form-control-sm mb-1" placeholder="The RM Tasker's side, if not already in the history">
        <textarea name="corrective_action" rows="2" class="form-control form-control-sm mb-1" placeholder="Corrective action (optional)"></textarea>
        <textarea name="resolution" rows="2" class="form-control form-control-sm mb-1" placeholder="Resolution (reprinted, refunded, apology…)" required></textarea>
        <?php if ($d && $d['verified_at']) { ?><div class="d-flex gap-1 align-items-center small mb-1"><label class="text-nowrap">Revised quality (finding against the Tasker only)</label><input name="revised_quality" type="number" min="1" max="5" step="0.5" class="form-control form-control-sm" style="width:80px" placeholder="<?= (float) $d['quality_rating']; ?>"></div><?php } ?>
        <button class="btn btn-primary btn-sm">Resolve</button></div></form>
<?php } ?>
<?php if ($canQ && $i['status'] === 'Resolved' && $against && $i['recovery_status'] === 'None' && (float) $i['impact_amount'] > 0) { ?>
    <form method="post" class="card mb-2"><div class="card-body py-2"><?= $csrf; ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="recovery_propose">
        <div class="fw-semibold small mb-1">Propose a recovery from the Tasker <span class="text-muted fw-normal">(optional, needs another approver)</span></div>
        <input name="recovery_amount" type="number" min="1" max="<?= (float) $i['impact_amount']; ?>" class="form-control form-control-sm mb-1" placeholder="Up to <?= dl_money($i['impact_amount']); ?>">
        <textarea name="note" rows="2" class="form-control form-control-sm mb-1" placeholder="Basis: evidence, agreement with the Tasker…"></textarea><button class="btn btn-outline-danger btn-sm">Propose</button></div></form>
<?php } ?>
<?php if ($canApprove && $i['recovery_status'] === 'Proposed' && (int) $i['recovery_by'] !== dl_uid()) { ?>
    <form method="post" class="card mb-2"><div class="card-body py-2"><?= $csrf; ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="recovery_decide">
        <div class="fw-semibold small mb-1">Authorize the recovery of <?= dl_money($i['recovery_amount']); ?>?</div><input name="note" class="form-control form-control-sm mb-1" placeholder="Note (required to reject)">
        <button name="decision" value="approve" class="btn btn-success btn-sm">Authorize</button> <button name="decision" value="reject" class="btn btn-outline-danger btn-sm">Reject</button></div></form>
<?php } ?>
<?php if ($fin && $i['recovery_status'] === 'Approved') { ?>
    <form method="post" class="card mb-2"><div class="card-body py-2"><?= $csrf; ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="recovery_done">
        <div class="fw-semibold small mb-1">Record how it was recovered</div><div class="small text-muted mb-1">To deduct it, change the approved amount on <a href="pay.php?id=<?= (int) $i['delegation_id']; ?>">the delegation's payment</a> with reference <?= dl_e($i['ref']); ?>.</div>
        <input name="note" class="form-control form-control-sm mb-1" placeholder="e.g. approved payment on DL… reduced by …"><button class="btn btn-outline-primary btn-sm">Recorded</button></div></form>
<?php } ?>
<?php if ($canQ) { ?>
    <form method="post" class="card mb-2"><div class="card-body py-2"><?= $csrf; ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="impact">
        <div class="fw-semibold small mb-1">Financial impact</div><div class="d-flex gap-1 mb-1"><select name="impact_kind" class="form-select form-select-sm"><?php foreach (DLQ_IMPACT as $x) { ?><option <?= $i['impact_kind'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select>
        <input name="impact_amount" type="number" min="0" value="<?= (float) $i['impact_amount']; ?>" class="form-control form-control-sm" style="width:130px"></div>
        <input name="note" class="form-control form-control-sm mb-1" placeholder="Reason for the change" required><button class="btn btn-outline-secondary btn-sm">Update</button></div></form>
    <?php if ($i['status'] === 'Resolved') { ?><form method="post" class="d-inline"><?= $csrf; ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="close"><button class="btn btn-outline-secondary btn-sm">Close the issue</button></form><?php } ?>
    <?php if (!$open) { ?><form method="post" class="d-inline" onsubmit="var r=prompt('Reason for reopening?');if(!r){return false;}this.note.value=r;"><?= $csrf; ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="reopen"><input type="hidden" name="note"><button class="btn btn-outline-warning btn-sm">Reopen</button></form><?php } ?>
<?php } ?>
<?php if ($canQ || dlc($conn, 'create')) { ?>
    <form method="post" class="card mt-2"><div class="card-body py-2"><?= $csrf; ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="act" value="note">
        <div class="fw-semibold small mb-1">Add a note</div><textarea name="note" rows="2" class="form-control form-control-sm mb-1"></textarea>
        <?php if ($canQ) { ?><label class="small d-block mb-1"><input type="checkbox" name="visible" value="1"> Send to the RM Tasker (ask for their side, share the outcome)</label><?php } ?>
        <button class="btn btn-outline-primary btn-sm">Add</button></div></form>
<?php } ?>
</div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>