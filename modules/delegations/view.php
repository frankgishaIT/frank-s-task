<?php
/**
 * NEW FILE: modules/delegations/view.php — Delegation Details: the work, the RM Tasker's response, staff follow-up,
 * actions on the Tasker's behalf (always recorded as such), deadline changes, reassignment, verification and ratings,
 * expected and actual figures, and the full history.
 */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
if (!$id && !empty($_GET['ref'])) { $id = dl_id_from_ref((string) $_GET['ref']); }
$d = dl_get($conn, $id);
if (!$d) { dl_go('list.php', 'error', 'Delegation not found.'); }
$back = 'view.php?id=' . $id;
$isCreator = (int) $d['created_by'] === dl_uid();
$canAct = dlc($conn, 'create');
$canApprove = dlc($conn, 'approve');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dl_csrf_ok()) { dl_go($back, 'error', 'The form expired. Please try again.'); }
    $act = (string) ($_POST['act'] ?? '');
    $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 500);
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 2000);
    $now = dl_now();
    $need = function (bool $ok, string $msg) use ($back) { if (!$ok) { dl_go($back, 'error', $msg); } };
    $sendToTasker = function () use ($conn, $id) {
        $urgent = (bool) mysqli_fetch_row(mysqli_query($conn, 'SELECT urgent FROM dl_delegations WHERE id = ' . $id))[0];
        $dd = dl_get($conn, $id);
        dl_set_status($conn, $id, 'Awaiting Tasker Acceptance', ['notified_at' => dl_now(), 'followup_due_at' => dl_followup_due($conn, $urgent, $dd['deadline']), 'followup_notified_at' => null]);
        return dl_notify_tasker($conn, dl_get($conn, $id), 'assigned');
    };
    switch ($act) {
        case 'approve':
            $need($canApprove, 'Only an approver can approve.');
            $need($d['status'] === 'Pending Approval', 'It is not waiting for approval.');
            $need(!$isCreator || ($_SESSION['user_role'] ?? '') === 'Admin', 'You created this delegation: another approver must approve it.');
            dl_set_status($conn, $id, 'Pending Approval', ['approved_by' => dl_uid(), 'approved_at' => $now, 'approval_note' => $reason ?: null]);
            dl_event($conn, $id, 'approved', 'Approved: ' . $d['approval_reasons'], 'staff', 0, $reason ?: null);
            $n = $sendToTasker();
            dl_notify_staff($conn, [(int) $d['created_by']], $d['ref'] . ' approved', 'Sent to ' . ($d['tasker']['display'] ?? '') . '.');
            dl_go($back, 'success', 'Approved and sent to the RM Tasker (SMS ' . ($n['sms'] ? 'sent' : 'NOT sent') . ').');
        case 'refuse':
            $need($canApprove && $d['status'] === 'Pending Approval', 'Not possible now.');
            $need($reason !== '', 'Give the reason for refusing it.');
            dl_set_status($conn, $id, 'Cancelled', ['cancel_reason' => 'Not approved: ' . $reason]);
            dl_event($conn, $id, 'refused', 'Not approved', 'staff', 0, $reason);
            dl_notify_staff($conn, [(int) $d['created_by']], $d['ref'] . ' not approved', $reason . '. Choose another RM Tasker or fulfilment option for the customer.');
            dl_go($back, 'success', 'Not approved. The sale line can be delegated again.');
        case 'resend':
            $need($canAct && $d['status'] === 'Awaiting Tasker Acceptance', 'Not possible now.');
            $n = dl_notify_tasker($conn, $d, 'assigned');
            dl_go($back, 'success', 'Notification sent again (SMS ' . ($n['sms'] ? 'sent' : 'NOT sent') . '). This is not an acceptance.');
        case 'contact':
            $need($canAct, 'Not allowed.');
            $ch = in_array($_POST['channel'] ?? '', ['WhatsApp', 'Phone call', 'SMS', 'In person'], true) ? $_POST['channel'] : 'Phone call';
            $out = in_array($_POST['outcome'] ?? '', ['Reached: confirmed', 'Reached: cannot do it', 'Reached: needs more time', 'No answer', 'Left a message', 'Wrong number'], true) ? $_POST['outcome'] : 'No answer';
            $need($note !== '' || $out !== 'Reached: confirmed', 'Write what was agreed.');
            dl_event($conn, $id, 'contact', $ch . ' · ' . $out . ($note !== '' ? ' · ' . $note : ''), 'staff', 0, $reason ?: null);
            dl_go($back, 'success', 'Contact attempt recorded. ' . ($out === 'Reached: confirmed' ? 'If the Tasker agreed, record "Confirm for the Tasker".' : ($out === 'Reached: cannot do it' ? 'Record the decline, then reassign.' : 'Try again, or reassign to protect the customer\'s deadline.')));
        case 'confirm_for':
            $need($canAct && $d['status'] === 'Awaiting Tasker Acceptance' && !$d['response'], 'Not possible now.');
            $need($reason !== '' && $note !== '', 'Give the reason and how the Tasker confirmed (contact notes).');
            dl_set_status($conn, $id, 'Accepted', ['response' => 'Staff-confirmed', 'response_at' => $now, 'response_by' => dl_uid(), 'response_note' => mb_substr($note, 0, 500)]);
            dl_event($conn, $id, 'staff_confirmed', 'STAFF-CONFIRMED on the Tasker\'s behalf · contact notes: ' . $note, 'staff', 1, $reason, 1);
            dl_go($back, 'success', 'Recorded as Staff-confirmed (not as the Tasker\'s own acceptance).');
        case 'decline_for':
            $need($canAct && in_array($d['status'], ['Awaiting Tasker Acceptance', 'Accepted'], true), 'Not possible now.');
            $need($reason !== '', 'Give what the Tasker said and why.');
            dl_set_status($conn, $id, 'Rejected', ['response' => 'Staff-recorded decline', 'response_at' => $now, 'response_by' => dl_uid(), 'response_note' => $reason]);
            dl_event($conn, $id, 'staff_declined', 'Decline recorded by staff for the Tasker', 'staff', 1, $reason, 1);
            dl_go($back, 'success', 'Decline recorded. Reassign it to another RM Tasker below.');
        case 'start_for':
            $need($canAct && $d['status'] === 'Accepted', 'Not possible now.');
            $need($reason !== '', 'Say how you know the work started (call, message…).');
            dl_set_status($conn, $id, 'In Progress', ['started_at' => $now]);
            dl_event($conn, $id, 'started', 'Work started (recorded by staff for the Tasker)', 'staff', 1, $reason, 1);
            dl_go($back, 'success', 'Recorded: work started.');
        case 'complete_for':
            $need($canAct && in_array($d['status'], ['Accepted', 'In Progress'], true), 'Not possible now.');
            $need($reason !== '', 'Say how the work was delivered (for example "received the files by e-mail").');
            dl_set_status($conn, $id, 'Completed – Awaiting Verification', ['completed_at' => $now, 'completed_by_staff' => dl_uid(), 'completion_note' => $note ?: $reason, 'started_at' => $d['started_at'] ?: $now]);
            [$nf] = dl_save_files($conn, $id, 'evidence', 'evidence');
            dl_event($conn, $id, 'completed', 'Completion recorded by staff for the Tasker' . ($nf ? ' · ' . $nf . ' evidence file(s)' : ''), 'staff', 1, $reason, 1);
            dl_go($back, 'success', 'Completion recorded. Verify the work next.');
        case 'deadline':
            $need(($canApprove || $isCreator) && in_array($d['status'], DL_LIVE, true), 'Not allowed.');
            $nd = (string) ($_POST['deadline'] ?? '');
            $need((bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $nd), 'Choose the new date and time.');
            $nd = str_replace('T', ' ', $nd) . ':00';
            $need($reason !== '', 'Give the reason for the new deadline.');
            dl_set_status($conn, $id, $d['status'], ['deadline' => $nd, 'deadline_reason' => mb_substr($reason, 0, 255), 'reminder_sent_at' => null, 'overdue_alert_at' => null]);
            dl_event($conn, $id, 'deadline_changed', 'Deadline ' . dl_deadline_text($d['deadline']) . ' → ' . dl_deadline_text($nd), 'staff', 0, $reason, 1);
            if ($d['status'] !== 'Pending Approval') { dl_notify_tasker($conn, dl_get($conn, $id), 'update', 'New deadline for ' . $d['product_name'] . ': ' . dl_deadline_text($nd) . '.'); }
            dl_go($back, 'success', 'Deadline changed; the RM Tasker was informed.');
        case 'reassign':
            $need($canAct && in_array($d['status'], ['Pending Approval', 'Awaiting Tasker Acceptance', 'Accepted', 'In Progress', 'Rejected', 'On Hold'], true), 'Not possible now.');
            $need($reason !== '', 'Give the reason for reassigning.');
            $c = dl_candidates($conn, (int) $d['product_id'], (float) $d['revenue']);
            $to = null;
            foreach ($c['eligible'] as $r) { if ((int) $r['party'] === (int) ($_POST['party_id'] ?? 0) && (int) $r['party'] !== (int) $d['party_id']) { $to = $r; } }
            $need((bool) $to, 'Choose another eligible RM Tasker.');
            $o = dl_offering($conn, (int) $d['product_id']);
            $f = dl_profit((float) $d['revenue'], (float) $d['cogs_exp'], (float) $d['direct_exp'], (float) $to['rate'], $d['basis']);
            $reasons = dl_approval_reasons($o, $c['picks']['best'] ?? null, $to, max(0, (strtotime($d['deadline']) - time()) / 3600), $f);
            $status = $reasons && !$canApprove ? 'Pending Approval' : 'Awaiting Tasker Acceptance';
            $u = dl_uid(); $rt = $reasons ? implode('; ', $reasons) : null; $best = $c['picks']['best']['party'] ?? null; $rate = (float) $to['rate']; $pty = (int) $to['party'];
            $s = mysqli_prepare($conn, "INSERT INTO dl_delegations (sale_id, sale_item_id, customer_id, product_id, party_id, quantity, revenue, rate_pct, basis, cogs_exp, direct_exp, pool_exp, pay_exp, retained_exp, final_exp,
                status, urgent, deadline, deadline_auto, deadline_reason, instructions, delivery, recommended_party_id, override_reason, approval_reasons, reassigned_from, created_by, created_at, updated_at)
                SELECT sale_id, sale_item_id, customer_id, product_id, ?, quantity, revenue, ?, basis, cogs_exp, direct_exp, ?, ?, ?, ?, ?, urgent, deadline, deadline_auto, deadline_reason, instructions, delivery, ?, ?, ?, id, ?, ?, ? FROM dl_delegations WHERE id = ?");
            mysqli_stmt_bind_param($s, 'iddddds' . 'iss' . 'issi', $pty, $rate, $f['pool'], $f['tasker'], $f['retained'], $f['final'], $status, $best, $reason, $rt, $u, $now, $now, $id);
            mysqli_stmt_execute($s);
            $nid = (int) mysqli_insert_id($conn);
            mysqli_query($conn, "INSERT INTO dl_costs (delegation_id, stage, kind, product_id, description, qty, unit_cost, amount, created_by, created_at) SELECT $nid, stage, kind, product_id, description, qty, unit_cost, amount, created_by, created_at FROM dl_costs WHERE delegation_id = $id AND stage = 'expected'");
            mysqli_query($conn, "INSERT INTO dl_files (delegation_id, kind, ref, name, user_id, party_id, created_at) SELECT $nid, kind, ref, name, user_id, party_id, created_at FROM dl_files WHERE delegation_id = $id AND kind = 'instruction'");
            dl_set_status($conn, $id, 'Reassigned', ['reassigned_to' => $nid, 'cancel_reason' => 'Reassigned to ' . $to['display'] . ': ' . $reason]);
            dl_event($conn, $id, 'reassigned', 'Reassigned to ' . $to['display'] . ' as ' . dl_ref($nid), 'staff', 0, $reason, 1);
            dl_event($conn, $nid, 'created', 'Reassigned from ' . $d['ref'] . ' (' . ($d['tasker']['display'] ?? '') . ') to ' . $to['display'] . ' at ' . dl_pct($rate) . ': expected payment ' . dl_money($f['tasker']), 'staff', 0, $reason);
            if ($d['status'] !== 'Pending Approval' && $d['status'] !== 'Rejected') { dl_notify_tasker($conn, $d, 'update', 'RISE MOTIVE has reassigned ' . $d['product_name'] . ' (' . $d['ref'] . '). You do not need to do this work. Thank you.'); }
            if ($status === 'Pending Approval') {
                dl_notify_staff($conn, dl_approvers($conn), 'Delegation waiting for approval: ' . dl_ref($nid), $rt);
                dl_go('view.php?id=' . $nid, 'success', 'Reassigned as ' . dl_ref($nid) . '; it needs approval (' . $rt . ').');
            }
            if ($reasons) { dl_set_status($conn, $nid, $status, ['approved_by' => $u, 'approved_at' => $now, 'approval_note' => 'Approved when reassigned (approver)']); }
            $id = $nid;
            $n = $sendToTasker();
            dl_go('view.php?id=' . $nid, 'success', 'Reassigned as ' . dl_ref($nid) . ' and sent to ' . $to['display'] . ' (SMS ' . ($n['sms'] ? 'sent' : 'NOT sent') . ').');
        case 'verify':
            $need($canAct && $d['status'] === 'Completed – Awaiting Verification', 'Not possible now.');
            $o = dl_offering($conn, (int) $d['product_id']);
            if (($_POST['outcome'] ?? '') === 'rework') {
                $need($note !== '', 'Say what must be corrected.');
                dl_set_status($conn, $id, 'In Progress', ['completed_at' => null]);
                dl_event($conn, $id, 'rework', 'Returned for correction: ' . $note, 'staff', 0, null, 1);
                dl_notify_tasker($conn, $d, 'update', 'Your work on ' . $d['product_name'] . ' needs correction: ' . mb_substr($note, 0, 200) . ' Deadline: ' . dl_deadline_text($d['deadline']) . '.');
                dl_go($back, 'success', 'Returned to the RM Tasker for correction.');
            }
            $r = function ($k) { $v = (float) ($_POST[$k] ?? 0); return $v >= 1 && $v <= 5 ? $v : null; };
            [$q, $sat, $ins, $com] = [$r('quality'), $r('satisfaction'), $r('instructions'), $r('communication')];
            $need($q && $sat && $ins && $com, 'Rate the four points from 1 to 5.');
            $cc = !empty($_POST['customer_confirmed']) ? 1 : 0;
            $need(!(int) $o['customer_verification'] || $cc, 'This service needs the customer\'s confirmation before verification.');
            $ontime = $d['completed_at'] && strtotime($d['completed_at']) <= strtotime($d['deadline']) ? 1 : 0;
            $score = dl_perf_score($conn, $q, $sat, $ins, $com, (bool) $ontime);
            // Actual figures: the actual costs if recorded, else the expected ones (kept apart from the expected figures).
            $act = dl_costs($conn, $id, 'actual');
            if (!$act) {
                mysqli_query($conn, "INSERT INTO dl_costs (delegation_id, stage, kind, product_id, description, qty, unit_cost, amount, created_by, created_at) SELECT delegation_id, 'actual', kind, product_id, description, qty, unit_cost, amount, " . (dl_uid() ?: 'NULL') . ", '$now' FROM dl_costs WHERE delegation_id = $id AND stage = 'expected'");
                $act = dl_costs($conn, $id, 'actual');
            }
            [$cm, $cd] = dl_cost_totals($act);
            $f = dl_profit((float) $d['revenue'], $cm, $cd, (float) $d['rate_pct'], $d['basis']);
            $final = date('Y-m-d H:i:s', time() + (int) dl_setting($conn, 'verify_days') * 86400);
            dl_set_status($conn, $id, 'Verified', ['verified_at' => $now, 'verified_by' => dl_uid(), 'quality_rating' => $q, 'satisfaction_rating' => $sat, 'instructions_rating' => $ins, 'communication_rating' => $com,
                'perf_score' => $score, 'ontime' => $ontime, 'customer_confirmed' => $cc, 'verification_note' => $note ?: null, 'rating_final_at' => $final,
                'cogs_act' => $f['cogs'], 'direct_act' => $f['direct'], 'pool_act' => $f['pool'], 'pay_act' => $f['tasker'], 'final_act' => $f['final'], 'pay_due' => $f['tasker']]);
            dl_event($conn, $id, 'verified', 'Verified: quality ' . $q . '/5, customer satisfaction ' . $sat . '/5, instructions ' . $ins . '/5, communication ' . $com . '/5, ' . ($ontime ? 'on time' : 'late') . ' → performance ' . $score . '/100. Actual Tasker entitlement ' . dl_money($f['tasker']) . ' (pool ' . dl_money($f['pool']) . ').' . ($note ? ' Note: ' . $note : ''), 'staff', 0, null, 1);
            dl_go($back, 'success', 'Verified. Performance ' . $score . '/100 (final after ' . (int) dl_setting($conn, 'verify_days') . ' days without a complaint). Tasker entitlement: ' . dl_money($f['tasker']) . '.');
        case 'costs':
            $need($canAct && !in_array($d['status'], array_merge(DL_ENDED, ['Paid', 'Closed – Financially Reconciled']), true), 'Costs can no longer be changed.');
            $stage = ($_POST['stage'] ?? '') === 'actual' ? 'actual' : 'expected';
            $need($stage === 'actual' || in_array($d['status'], ['Pending Approval', 'Awaiting Tasker Acceptance', 'Accepted', 'In Progress'], true), 'Expected costs can only change before completion.');
            $need($reason !== '', 'Give the reason for the cost change.');
            [$mats, $dirs] = dl_costs_from_post($conn, '');
            $old = dl_cost_totals(dl_costs($conn, $id, $stage));
            dl_save_costs($conn, $id, $stage, $mats, $dirs);
            [$cm, $cd] = dl_cost_totals(dl_costs($conn, $id, $stage));
            $f = dl_profit((float) $d['revenue'], $cm, $cd, (float) $d['rate_pct'], $d['basis']);
            if ($stage === 'expected') { dl_set_status($conn, $id, $d['status'], ['cogs_exp' => $f['cogs'], 'direct_exp' => $f['direct'], 'pool_exp' => $f['pool'], 'pay_exp' => $f['tasker'], 'retained_exp' => $f['retained'], 'final_exp' => $f['final']]); }
            else { dl_set_status($conn, $id, $d['status'], ['cogs_act' => $f['cogs'], 'direct_act' => $f['direct'], 'pool_act' => $f['pool'], 'pay_act' => $f['tasker'], 'final_act' => $f['final']] + ($d['verified_at'] ? ['pay_due' => $f['tasker']] : [])); }
            dl_event($conn, $id, 'costs_' . $stage, ucfirst($stage) . ' costs: materials ' . dl_money($old[0]) . ' → ' . dl_money($cm) . ', direct ' . dl_money($old[1]) . ' → ' . dl_money($cd) . '; Tasker ' . dl_money($f['tasker']), 'staff', 0, $reason);
            dl_go($back, 'success', ucfirst($stage) . ' costs saved.');
        case 'hold':
            $need($canAct && in_array($d['status'], ['Awaiting Tasker Acceptance', 'Accepted', 'In Progress'], true) && $reason !== '', 'Give the reason.');
            dl_set_status($conn, $id, 'On Hold', ['hold_reason' => mb_substr($reason, 0, 255)]);
            dl_event($conn, $id, 'on_hold', 'Put on hold (was ' . $d['status'] . ')', 'staff', 0, $reason, 1);
            dl_notify_tasker($conn, $d, 'update', $d['product_name'] . ' is ON HOLD: ' . $reason . '. Please wait for RISE MOTIVE before continuing.');
            dl_go($back, 'success', 'On hold.');
        case 'resume':
            $need($canAct && $d['status'] === 'On Hold', 'Not possible now.');
            $to = $d['started_at'] ? 'In Progress' : ($d['response'] ? 'Accepted' : 'Awaiting Tasker Acceptance');
            dl_set_status($conn, $id, $to, ['hold_reason' => null]);
            dl_event($conn, $id, 'resumed', 'Resumed (' . $to . ')', 'staff', 0, $reason ?: null, 1);
            dl_notify_tasker($conn, $d, 'update', $d['product_name'] . ' can continue. Deadline: ' . dl_deadline_text($d['deadline']) . '.');
            dl_go($back, 'success', 'Resumed.');
        case 'cancel':
            $need($canAct && in_array($d['status'], DL_LIVE, true), 'Not possible now.');
            $need($reason !== '', 'Give the reason for cancelling.');
            dl_set_status($conn, $id, 'Cancelled', ['cancel_reason' => mb_substr($reason, 0, 255)]);
            dl_event($conn, $id, 'cancelled', 'Cancelled (was ' . $d['status'] . ')', 'staff', 0, $reason, 1);
            if (!in_array($d['status'], ['Pending Approval'], true)) { dl_notify_tasker($conn, $d, 'update', 'RISE MOTIVE has cancelled ' . $d['product_name'] . ' (' . $d['ref'] . '): ' . $reason); }
            dl_go($back, 'success', 'Cancelled.');
        case 'files':
            $need($canAct, 'Not allowed.');
            [$nf, $ferr] = dl_save_files($conn, $id, 'files', ($_POST['kind'] ?? '') === 'evidence' ? 'evidence' : 'instruction');
            if ($nf) { dl_event($conn, $id, 'files', $nf . ' file(s) added', 'staff', 0, null, 1); }
            dl_go($back, $ferr ? 'error' : 'success', $ferr ?: $nf . ' file(s) added.');
    }
    dl_go($back, 'error', 'Unknown action.');
}

$names = dl_users($conn);
$ev = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM dl_events WHERE delegation_id = ' . $id . ' ORDER BY id DESC'), MYSQLI_ASSOC);
$sale = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT s.id, s.status, s.total_amount, s.amount_paid, s.sale_date, c.name customer, c.phone FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ' . (int) $d['sale_id']));
$exp = dl_costs($conn, $id, 'expected'); $act = dl_costs($conn, $id, 'actual');
$files = dl_files($conn, $id);
$o = dl_offering($conn, (int) $d['product_id']);
$items = mysqli_fetch_all(mysqli_query($conn, "SELECT id, product_name, buying_price FROM products WHERE item_type = 'Item' AND is_active = 1 ORDER BY product_name"), MYSQLI_ASSOC);
$overdue = in_array($d['status'], DL_WORKING, true) && $d['deadline'] && strtotime($d['deadline']) < time();
$noAnswer = $d['status'] === 'Awaiting Tasker Acceptance' && !$d['response'];
$chain = [];
if ($d['reassigned_from']) { $chain[] = ['from', (int) $d['reassigned_from']]; }
if ($d['reassigned_to']) { $chain[] = ['to', (int) $d['reassigned_to']]; }
$wa = dl_setting($conn, 'whatsapp_link');
$t = $d['tasker'];
$phoneIntl = $t ? '250' . substr(preg_replace('/\D/', '', (string) $t['phone']), -9) : '';
$form = function ($act, $inner, $btn, $cls = 'btn-outline-primary', $confirm = '') { return '<form method="post" class="mb-2">' . dl_csrf_field() . '<input type="hidden" name="act" value="' . $act . '">' . $inner . '<button class="btn btn-sm ' . $cls . '"' . ($confirm ? ' onclick="return confirm(\'' . dl_e($confirm) . '\')"' : '') . '>' . $btn . '</button></form>'; };
$costTable = function (array $rows) { if (!$rows) { return '<div class="text-muted small">None.</div>'; } $h = '<table class="table table-sm dl-tbl mb-1"><tbody>'; foreach ($rows as $r) { $h .= '<tr><td>' . ($r['kind'] === 'material' ? dl_e($r['product_name']) . ' × ' . dl_e(rtrim(rtrim(number_format((float) $r['qty'], 3), '0'), '.')) . ' @ ' . dl_money($r['unit_cost']) : dl_e($r['description']) . ' <span class="text-muted">(direct)</span>') . '</td><td class="text-end">' . dl_money($r['amount']) . '</td></tr>'; } return $h . '</tbody></table>'; };

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'list.php', $d['ref'] . ' · ' . $d['product_name'], dl_status_badge($d['status']));
?>
<?php if ($d['status'] === 'Pending Approval') { ?><div class="alert alert-warning py-2 small">Waiting for a manager's approval: <?= dl_e($d['approval_reasons']); ?><?= $d['override_reason'] ? '. Staff reason: ' . dl_e($d['override_reason']) : ''; ?>. The RM Tasker has not been told yet.</div><?php } ?>
<?php if ($noAnswer && $d['followup_notified_at']) { ?><div class="alert alert-danger py-2 small"><b>No answer from the RM Tasker</b> since <?= dl_e(dl_deadline_text($d['notified_at'])); ?>. Contact them on WhatsApp or by phone, record the contact, then confirm for them or reassign. Do not keep the customer waiting.</div><?php } ?>
<?php if ($overdue) { ?><div class="alert alert-danger py-2 small"><b>Deadline missed</b> (<?= dl_e(dl_deadline_text($d['deadline'])); ?>).</div><?php } ?>
<?php foreach ($chain as [$k, $x]) { ?><div class="small mb-1"><?= $k === 'from' ? 'Reassigned from' : 'Reassigned to'; ?> <a href="view.php?id=<?= $x; ?>"><?= dl_ref($x); ?></a></div><?php } ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="card mb-3"><div class="card-body py-2 small">
        <div class="row g-2">
            <div class="col-md-6"><div class="text-muted">RM Tasker</div><div class="fw-semibold"><?= $t ? '<a href="tasker.php?id=' . (int) $t['id'] . '">' . dl_e($t['display']) . '</a> · ' . dl_e($t['code']) : '—'; ?></div>
                <?php if ($t) { ?><div><?= dl_e($t['phone']); ?> · <a href="https://wa.me/<?= dl_e($phoneIntl); ?>" target="_blank" rel="noopener">WhatsApp</a> · <a href="tel:<?= dl_e($t['phone']); ?>">Call</a></div><?php } ?></div>
            <div class="col-md-6"><div class="text-muted">Tasker response</div><div class="fw-semibold <?= $d['response'] === 'Staff-confirmed' ? 'text-warning' : ''; ?>"><?= dl_e(dl_response_text($d['response'])); ?></div>
                <?php if ($d['response']) { ?><div><?= dl_e(dl_deadline_text($d['response_at'])); ?><?= $d['response_by'] ? ' by staff ' . dl_e($names[(int) $d['response_by']] ?? '') : ''; ?><?= $d['response_note'] ? ' · ' . dl_e($d['response_note']) : ''; ?></div><?php } ?></div>
            <div class="col-md-6"><div class="text-muted">Sale</div><div><a href="../sales/invoice.php?id=<?= (int) $d['sale_id']; ?>" target="_blank">#<?= (int) $d['sale_id']; ?></a> · <?= dl_e($sale['customer'] ?? 'Walk-in'); ?> · <?= dl_e($sale['status'] ?? ''); ?> · paid <?= dl_money($sale['amount_paid'] ?? 0); ?> of <?= dl_money($sale['total_amount'] ?? 0); ?></div></div>
            <div class="col-md-6"><div class="text-muted">Deadline</div><div class="fw-semibold <?= $overdue ? 'text-danger' : ''; ?>"><?= dl_e(dl_deadline_text($d['deadline'])); ?><?= (int) $d['urgent'] ? ' · urgent' : ''; ?></div><?php if ($d['deadline_reason']) { ?><div class="text-muted">Changed: <?= dl_e($d['deadline_reason']); ?></div><?php } ?><?php if ($d['tasker_eta']) { ?><div>Tasker expects: <?= dl_e(dl_deadline_text($d['tasker_eta'])); ?></div><?php } ?></div>
            <div class="col-12"><div class="text-muted">Instructions</div><div><?= nl2br(dl_e($d['instructions'])); ?></div></div>
            <?php if ($d['delivery']) { ?><div class="col-12"><div class="text-muted">Location / delivery</div><div><?= nl2br(dl_e($d['delivery'])); ?></div></div><?php } ?>
            <?php if ($files) { ?><div class="col-12"><div class="text-muted">Files</div><?php foreach ($files as $fl) { ?><a href="file.php?id=<?= (int) $fl['id']; ?>" target="_blank" class="me-2"><i class="bi bi-paperclip"></i><?= dl_e($fl['name']); ?></a><span class="text-muted me-3">(<?= $fl['kind'] === 'evidence' ? 'evidence' : 'instruction'; ?><?= $fl['party_id'] ? ', from the Tasker' : ''; ?>)</span><?php } ?></div><?php } ?>
        </div>
    </div></div>
    <div class="row g-2 mb-3">
        <?php foreach ([['Assigned', $d['notified_at']], ['Accepted', $d['response_at']], ['Started', $d['started_at']], ['Completed', $d['completed_at']], ['Verified', $d['verified_at']]] as [$l, $v]) { ?>
        <div class="col"><div class="dl-kpi py-1"><div class="l"><?= $l; ?></div><div class="small fw-semibold"><?= $v ? dl_e(date('d M H:i', strtotime($v))) : '—'; ?></div></div></div>
        <?php } ?>
        <div class="col"><div class="dl-kpi py-1"><div class="l">On time</div><div class="small fw-semibold"><?= $d['completed_at'] ? (strtotime($d['completed_at']) <= strtotime($d['deadline']) ? 'Yes' : '<span class="text-danger">No, ' . dl_hours_text(round((strtotime($d['completed_at']) - strtotime($d['deadline'])) / 3600, 1)) . ' late</span>') : '—'; ?></div></div></div>
    </div>
    <div class="card mb-3"><div class="card-body py-2 small">
        <div class="fw-semibold mb-1">Financial summary <span class="text-muted fw-normal">· basis: <?= dl_e(dl_basis_text($d['basis'])); ?> · rate <?= dl_pct($d['rate_pct']); ?> (agreed at creation)</span></div>
        <table class="table table-sm dl-tbl mb-2"><thead><tr><th></th><th class="text-end">Expected</th><th class="text-end">Actual</th></tr></thead><tbody>
            <tr><td>Customer price (the sale's revenue)</td><td class="text-end"><?= dl_money($d['revenue']); ?></td><td class="text-end"><?= dl_money($d['revenue']); ?></td></tr>
            <tr><td>Cost of sales (materials)</td><td class="text-end">− <?= dl_money($d['cogs_exp']); ?></td><td class="text-end"><?= $d['cogs_act'] !== null ? '− ' . dl_money($d['cogs_act']) : '—'; ?></td></tr>
            <?php if ($d['basis'] === 'cogs_direct') { ?><tr><td>Other direct costs</td><td class="text-end">− <?= dl_money($d['direct_exp']); ?></td><td class="text-end"><?= $d['direct_act'] !== null ? '− ' . dl_money($d['direct_act']) : '—'; ?></td></tr><?php } ?>
            <tr class="fw-semibold"><td>Profit pool</td><td class="text-end"><?= dl_money($d['pool_exp']); ?></td><td class="text-end"><?= $d['pool_act'] !== null ? dl_money($d['pool_act']) : '—'; ?></td></tr>
            <tr><td>RM Tasker payment (<?= dl_pct($d['rate_pct']); ?> of the pool)</td><td class="text-end">− <?= dl_money($d['pay_exp']); ?></td><td class="text-end"><?= $d['pay_act'] !== null ? '− ' . dl_money($d['pay_act']) : '—'; ?></td></tr>
            <?php if ($d['basis'] !== 'cogs_direct') { ?><tr><td>Other direct costs</td><td class="text-end">− <?= dl_money($d['direct_exp']); ?></td><td class="text-end"><?= $d['direct_act'] !== null ? '− ' . dl_money($d['direct_act']) : '—'; ?></td></tr><?php } ?>
            <tr class="fw-semibold"><td>RISE MOTIVE profit</td><td class="text-end <?= (float) $d['final_exp'] < 0 ? 'text-danger' : ''; ?>"><?= dl_money($d['final_exp']); ?></td><td class="text-end <?= $d['final_act'] !== null && (float) $d['final_act'] < 0 ? 'text-danger' : ''; ?>"><?= $d['final_act'] !== null ? dl_money($d['final_act']) : '—'; ?></td></tr>
        </tbody></table>
        <div class="row g-2"><div class="col-md-6"><div class="text-muted">Expected costs</div><?= $costTable($exp); ?></div><div class="col-md-6"><div class="text-muted">Actual costs</div><?= $costTable($act); ?></div></div>
        <div class="text-muted">The Tasker payment itself is recorded in Tasker Payments (next step); it is not paid automatically.</div>
    </div></div>
    <?php if ($d['verified_at']) { ?>
    <div class="card mb-3"><div class="card-body py-2 small"><div class="fw-semibold mb-1">Verification</div>
        Quality <?= dl_e((float) $d['quality_rating']); ?>/5 · customer satisfaction <?= dl_e((float) $d['satisfaction_rating']); ?>/5 · instructions <?= dl_e((float) $d['instructions_rating']); ?>/5 · communication <?= dl_e((float) $d['communication_rating']); ?>/5 · <?= (int) $d['ontime'] ? 'on time' : 'late'; ?>
        → <b><?= dl_e((float) $d['perf_score']); ?>/100</b><?= (int) $d['customer_confirmed'] ? ' · customer confirmed' : ''; ?>. Rating final on <?= dl_e(date('d M Y', strtotime($d['rating_final_at']))); ?> if no complaint.<?= $d['verification_note'] ? '<div>' . nl2br(dl_e($d['verification_note'])) . '</div>' : ''; ?>
    </div></div>
    <?php } ?>
    <h6 class="mb-1">History</h6>
    <div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0"><tbody>
    <?php foreach ($ev as $e) { $who = $e['actor'] === 'tasker' ? 'RM Tasker' : ($e['actor'] === 'system' ? 'MY MOTIVE' : ($names[(int) $e['user_id']] ?? 'Staff')); ?>
        <tr><td class="text-nowrap small text-muted"><?= dl_e(date('d M H:i', strtotime($e['created_at']))); ?></td>
            <td class="small"><span class="dl-st <?= $e['actor'] === 'tasker' ? 'dl-blue' : ((int) $e['on_behalf'] ? 'dl-warn' : ''); ?>"><?= dl_e($who); ?><?= (int) $e['on_behalf'] ? ' for the Tasker' : ''; ?></span></td>
            <td class="small"><?= dl_e($e['details']); ?><?= $e['reason'] ? '<div class="text-muted">Reason: ' . dl_e($e['reason']) . '</div>' : ''; ?></td></tr>
    <?php } ?>
    </tbody></table></div></div>
</div>
<div class="col-xl-4">
<?php if ($canApprove && $d['status'] === 'Pending Approval') { ?>
    <div class="card mb-3 border-warning"><div class="card-body py-2"><div class="fw-semibold small mb-1">Approval</div>
        <?php if ($isCreator && ($_SESSION['user_role'] ?? '') !== 'Admin') { ?><div class="small text-muted">You created it: another approver must decide.</div><?php } else { ?>
        <?= $form('approve', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Note (optional)">', 'Approve and send to the Tasker', 'btn-success'); ?>
        <?= $form('refuse', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (required)">', 'Do not approve', 'btn-outline-danger'); ?>
        <?php } ?></div></div>
<?php } ?>
<?php if ($canAct && in_array($d['status'], ['Awaiting Tasker Acceptance', 'Accepted', 'In Progress', 'On Hold', 'Rejected'], true)) { ?>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1">Follow-up with the RM Tasker</div>
        <div class="small text-muted mb-1">Official WhatsApp chat in the SMS: <?= $wa ? '<a href="' . dl_e($wa) . '" target="_blank" rel="noopener">' . dl_e($wa) . '</a>' : '<span class="text-danger">not set (Settings)</span>'; ?></div>
        <?= $form('contact', '<div class="d-flex gap-1 mb-1"><select name="channel" class="form-select form-select-sm"><option>WhatsApp</option><option>Phone call</option><option>SMS</option><option>In person</option></select><select name="outcome" class="form-select form-select-sm"><option>Reached: confirmed</option><option>Reached: cannot do it</option><option>Reached: needs more time</option><option>No answer</option><option>Left a message</option><option>Wrong number</option></select></div><input name="note" class="form-control form-control-sm mb-1" placeholder="What was said / agreed">', 'Record contact attempt'); ?>
        <?php if ($d['status'] === 'Awaiting Tasker Acceptance') { ?>
        <?= $form('resend', '', 'Send the notification again', 'btn-outline-secondary'); ?>
        <hr class="my-2"><div class="small text-muted mb-1">Acting for the Tasker is recorded as <b>Staff-confirmed</b>, with your name, never as the Tasker's own acceptance.</div>
        <?= $form('confirm_for', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Why confirm for them (e.g. customer waiting)"><input name="note" class="form-control form-control-sm mb-1" placeholder="How they confirmed (call at 10:20, WhatsApp…)">', 'Confirm for the Tasker', 'btn-warning'); ?>
        <?php } ?>
        <?php if (in_array($d['status'], ['Awaiting Tasker Acceptance', 'Accepted'], true)) { ?><?= $form('decline_for', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="What the Tasker said">', 'Record that the Tasker cannot do it', 'btn-outline-danger'); ?><?php } ?>
        <?php if ($d['status'] === 'Accepted') { ?><?= $form('start_for', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="How you know it started">', 'Record work started (for the Tasker)', 'btn-outline-primary'); ?><?php } ?>
        <?php if (in_array($d['status'], ['Accepted', 'In Progress'], true)) { ?>
        <form method="post" enctype="multipart/form-data" class="mb-2"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="complete_for"><input name="reason" class="form-control form-control-sm mb-1" placeholder="How the work was delivered"><input name="note" class="form-control form-control-sm mb-1" placeholder="Completion note"><input type="file" name="evidence[]" multiple class="form-control form-control-sm mb-1"><button class="btn btn-sm btn-outline-success">Record completion (for the Tasker)</button></form>
        <?php } ?>
    </div></div>
    <?php $alt = array_values(array_filter(dl_candidates($conn, (int) $d['product_id'], (float) $d['revenue'])['eligible'], function ($r) use ($d) { return (int) $r['party'] !== (int) $d['party_id']; })); ?>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1">Reassign to another RM Tasker</div>
        <?php if ($alt) { ?><?= $form('reassign', '<select name="party_id" class="form-select form-select-sm mb-1">' . implode('', array_map(function ($r) { return '<option value="' . (int) $r['party'] . '">' . dl_e($r['display']) . ' · ' . dl_pct($r['rate']) . ' · ' . dl_hours_text($r['hours']) . ' · score ' . $r['score'] . '</option>'; }, $alt)) . '</select><input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (required)">', 'Reassign', 'btn-outline-primary', 'Reassign this work? The current Tasker is told they need not do it.'); ?>
        <?php } else { ?><div class="small text-muted">No other eligible RM Tasker. <a href="lost.php?product=<?= (int) $d['product_id']; ?>">Record an unavailable request</a> if the customer cannot be served.</div><?php } ?>
    </div></div>
<?php } ?>
<?php if ($canAct && $d['status'] === 'Completed – Awaiting Verification') { ?>
    <div class="card mb-3 border-success"><div class="card-body py-2"><div class="fw-semibold small mb-1">Verify the completed service</div>
    <form method="post"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="verify">
        <?php foreach (['quality' => 'Service quality', 'satisfaction' => 'Customer satisfaction', 'instructions' => 'Followed the instructions', 'communication' => 'Communication & reliability'] as $k => $l) { ?>
        <div class="d-flex justify-content-between align-items-center small mb-1"><span><?= $l; ?></span><select name="<?= $k; ?>" class="form-select form-select-sm" style="width:90px"><option value="">–</option><?php for ($i = 5; $i >= 1; $i--) { ?><option value="<?= $i; ?>"><?= $i; ?>/5</option><?php } ?></select></div>
        <?php } ?>
        <label class="small d-block mb-1"><input type="checkbox" name="customer_confirmed" value="1"> The customer confirmed the service<?= (int) $o['customer_verification'] ? ' <span class="text-danger">(required)</span>' : ''; ?></label>
        <textarea name="note" rows="2" class="form-control form-control-sm mb-1" placeholder="Feedback (the Tasker sees it)"></textarea>
        <div class="d-flex gap-1"><button name="outcome" value="ok" class="btn btn-sm btn-success">Verify</button><button name="outcome" value="rework" class="btn btn-sm btn-outline-warning">Return for correction</button></div>
    </form></div></div>
<?php } ?>
<?php if ($canAct && !in_array($d['status'], array_merge(DL_ENDED, ['Paid', 'Closed – Financially Reconciled']), true)) { $stage = $d['completed_at'] ? 'actual' : 'expected'; $cur = $stage === 'actual' ? ($act ?: $exp) : $exp; ?>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1"><?= $stage === 'actual' ? 'Actual costs' : 'Expected costs'; ?></div>
    <form method="post"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="costs"><input type="hidden" name="stage" value="<?= $stage; ?>">
        <?php foreach (array_merge(array_values(array_filter($cur, function ($r) { return $r['kind'] === 'material'; })), [['product_id' => 0, 'qty' => '', 'unit_cost' => '']]) as $m) { ?>
        <div class="d-flex gap-1 mb-1"><select name="mat_product[]" class="form-select form-select-sm"><option value="">— material —</option><?php foreach ($items as $it) { ?><option value="<?= (int) $it['id']; ?>" <?= (int) $m['product_id'] === (int) $it['id'] ? 'selected' : ''; ?>><?= dl_e($it['product_name']); ?></option><?php } ?></select><input name="mat_qty[]" class="form-control form-control-sm" style="max-width:64px" placeholder="qty" value="<?= $m['qty'] !== '' ? dl_e((float) $m['qty']) : ''; ?>"><input name="mat_cost[]" class="form-control form-control-sm" style="max-width:90px" placeholder="unit cost" value="<?= $m['unit_cost'] !== '' ? dl_e((float) $m['unit_cost']) : ''; ?>"></div>
        <?php } ?>
        <?php foreach (array_merge(array_values(array_filter($cur, function ($r) { return $r['kind'] === 'direct'; })), [['description' => '', 'amount' => '']]) as $x) { ?>
        <div class="d-flex gap-1 mb-1"><input name="dir_desc[]" class="form-control form-control-sm" placeholder="direct cost" value="<?= dl_e($x['description']); ?>"><input name="dir_amount[]" class="form-control form-control-sm" style="max-width:100px" placeholder="RWF" value="<?= $x['amount'] !== '' ? dl_e((float) $x['amount']) : ''; ?>"></div>
        <?php } ?>
        <input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason for the change (required)"><button class="btn btn-sm btn-outline-primary">Save costs</button>
    </form></div></div>
<?php } ?>
<?php if (($canApprove || $isCreator) && in_array($d['status'], DL_LIVE, true)) { ?>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1">Deadline</div>
        <?= $form('deadline', '<input type="datetime-local" name="deadline" class="form-control form-control-sm mb-1" value="' . dl_e(date('Y-m-d\TH:i', strtotime($d['deadline']))) . '"><input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (required)">', 'Change the deadline'); ?>
    </div></div>
<?php } ?>
<?php if ($canAct && in_array($d['status'], DL_LIVE, true)) { ?>
    <div class="card mb-3"><div class="card-body py-2"><div class="fw-semibold small mb-1">Other actions</div>
        <form method="post" enctype="multipart/form-data" class="mb-2"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="files"><div class="d-flex gap-1 mb-1"><input type="file" name="files[]" multiple class="form-control form-control-sm"><select name="kind" class="form-select form-select-sm" style="max-width:120px"><option value="instruction">instruction</option><option value="evidence">evidence</option></select></div><button class="btn btn-sm btn-outline-secondary">Add files</button></form>
        <?php if ($d['status'] === 'On Hold') { ?><?= $form('resume', '', 'Resume', 'btn-outline-primary'); ?><?php } elseif (in_array($d['status'], ['Awaiting Tasker Acceptance', 'Accepted', 'In Progress'], true)) { ?><?= $form('hold', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason">', 'Put on hold', 'btn-outline-secondary'); ?><?php } ?>
        <?= $form('cancel', '<input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason (required)">', 'Cancel the delegation', 'btn-outline-danger', 'Cancel this delegation?'); ?>
    </div></div>
<?php } ?>
</div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>