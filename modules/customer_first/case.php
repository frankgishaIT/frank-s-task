<?php
/**
 * NEW FILE: modules/customer_first/case.php — ONE CUSTOMER REQUEST: what was checked, the escalation(s) and their
 * answers, the outcome, the customer's feedback and the history. Actions: update the checks / outcome, link the
 * sale, escalate, answer an escalation (solution · more information · escalate further), record feedback, link to
 * RM Market Insights (once), create a follow-up task.
 */
require __DIR__ . '/common.php';
cf_require($conn, 'record');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0); $uid = (int) cf_uid();
$c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT c.*, cu.name cname, cu.phone cphone, u.names hname FROM cf_cases c LEFT JOIN customers cu ON cu.id = c.customer_id LEFT JOIN users u ON u.id = c.handled_by WHERE c.id = $id AND " . cf_scope_sql($conn)));
if (!$c) { cf_go('cases.php', 'error', 'Request not found.'); }
$self = 'case.php?id=' . $id; $isHandler = (int) $c['handled_by'] === $uid; $canManage = cfc($conn, 'manage');
$escs = mysqli_fetch_all(mysqli_query($conn, "SELECT e.*, f.names fname, t.names tname, r.names rname FROM cf_escalations e LEFT JOIN users f ON f.id = e.from_user LEFT JOIN users t ON t.id = e.to_user LEFT JOIN users r ON r.id = e.responded_by WHERE e.case_id = $id ORDER BY e.id"), MYSQLI_ASSOC);
$myEsc = null; foreach ($escs as $e) { if ((int) $e['to_user'] === $uid && in_array($e['status'], ['Open', 'More information'], true)) { $myEsc = $e; } }
$canEdit = $isHandler || $canManage || $myEsc;
$users = cf_users($conn); $depts = cf_depts($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!cf_csrf_ok()) { cf_go($self, 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? ''); $now = date('Y-m-d H:i:s');
    $t = function ($k, $n) { $v = trim((string) ($_POST[$k] ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); };
    try {
        if (!$canEdit && $do !== 'feedback') { throw new RuntimeException('Only the employee handling the request, the person it was escalated to or a manager can change it.'); }
        if ($do === 'update') {
            $outcome = isset(CF_OUTCOMES[$_POST['outcome'] ?? '']) ? $_POST['outcome'] : null; $reason = in_array($_POST['unresolved_reason'] ?? '', CF_REASONS, true) ? $_POST['unresolved_reason'] : null;
            if (in_array($outcome, ['Unresolved', 'Customer Declined'], true) && !$reason) { throw new RuntimeException('Give the reason the request was not fulfilled.'); }
            $open = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM cf_escalations WHERE case_id = $id AND status IN ('Open', 'More information')"))[0];
            $final = $outcome && in_array($outcome, CF_FINAL, true);
            if ($final && $open && !$canManage) { throw new RuntimeException('An escalation is still waiting for an answer.'); }
            $status = $final ? 'Closed' : ($open ? 'Escalated' : 'Open');
            $sale = (int) ($_POST['sale_id'] ?? 0) ?: null;
            if ($sale && $c['customer_id'] && !mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM sales WHERE id = $sale AND customer_id = " . (int) $c['customer_id']))) { throw new RuntimeException('That sale is not a sale of this customer.'); }
            $exp = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expected_date'] ?? '') ? $_POST['expected_date'] : null;
            $f = ['stock_check' => $t('stock_check', 255), 'offering_check' => $t('offering_check', 255), 'capability_check' => $t('capability_check', 255), 'alternative' => $t('alternative', 255), 'sourcing' => $t('sourcing', 255), 'communication' => $t('communication', 500), 'outcome_note' => $t('outcome_note', 500)];
            $closedAt = $final ? ($c['closed_at'] ?: $now) : null; $closedBy = $final ? ($c['closed_by'] ?: $uid) : null;
            $s = mysqli_prepare($conn, 'UPDATE cf_cases SET stock_check = ?, offering_check = ?, capability_check = ?, alternative = ?, sourcing = ?, expected_date = ?, communication = ?, outcome = ?, unresolved_reason = ?, outcome_note = ?, sale_id = ?, status = ?, closed_at = ?, closed_by = ?, updated_at = ? WHERE id = ?');
            mysqli_stmt_bind_param($s, 'ssssssssssissisi', $f['stock_check'], $f['offering_check'], $f['capability_check'], $f['alternative'], $f['sourcing'], $exp, $f['communication'], $outcome, $reason, $f['outcome_note'], $sale, $status, $closedAt, $closedBy, $now, $id);
            mysqli_stmt_execute($s);
            if ($outcome !== $c['outcome'] || $reason !== $c['unresolved_reason']) { cf_log($conn, $id, 'outcome', ($c['outcome'] ?: 'none') . ' → ' . ($outcome ?: 'none') . ($reason ? ' (' . $reason . ')' : '') . ($f['outcome_note'] ? ': ' . $f['outcome_note'] : '')); } else { cf_log($conn, $id, 'updated', 'Checks / communication updated'); }
            if ($final && (int) $c['handled_by'] !== $uid && $c['handled_by']) { notifyUser($conn, (int) $c['handled_by'], 'Customer request closed', cf_case_no($id) . ': ' . $outcome . '.'); }
            if ($c['mi_request_id'] && $outcome && $outcome !== $c['outcome']) { // keep the linked Market Insights request in step
                $map = ['Product to Be Sourced' => 'Product to be sourced', 'Alternative Accepted' => 'Alternative product offered', 'Customer Declined' => 'Customer will return later'];
                if (isset($map[$outcome])) { $o2 = $map[$outcome]; $s2 = mysqli_prepare($conn, 'UPDATE mi_requests SET outcome = ? WHERE id = ?'); $mid = (int) $c['mi_request_id']; mysqli_stmt_bind_param($s2, 'si', $o2, $mid); mysqli_stmt_execute($s2); }
            }
            cf_go($self, 'success', $final ? 'Outcome recorded: the request is closed.' : 'Saved.');
        }
        if ($do === 'escalate') {
            $to = (int) ($_POST['esc_to'] ?? 0); if (!$to || $to === $uid || !in_array($to, array_map('intval', array_column($users, 'id')), true)) { throw new RuntimeException('Choose the person to escalate to.'); }
            $from = $myEsc; // escalating further: the current escalation is passed on
            cf_escalate($conn, $id, $to, (int) ($_POST['esc_dept'] ?? 0) ?: null, in_array($_POST['esc_reason'] ?? '', CF_ESC_REASONS, true) ? $_POST['esc_reason'] : 'Other', (string) $t('esc_note', 1000), (string) ($_POST['esc_priority'] ?? 'Normal'), preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['esc_due'] ?? '') ? $_POST['esc_due'] . ' 17:00:00' : null);
            if ($from) { $s = mysqli_prepare($conn, "UPDATE cf_escalations SET status = 'Escalated further', response = ?, responded_by = ?, responded_at = ? WHERE id = ?"); $r = (string) $t('esc_note', 1000); $eid = (int) $from['id']; mysqli_stmt_bind_param($s, 'sisi', $r, $uid, $now, $eid); mysqli_stmt_execute($s);
                if ($from['from_user']) { notifyUser($conn, (int) $from['from_user'], 'Your escalation was passed on', cf_case_no($id) . ' was escalated further.'); } }
            cf_go($self, 'success', 'Escalated.');
        }
        if ($do === 'respond') {
            if (!$myEsc) { throw new RuntimeException('Nothing is escalated to you on this request.'); }
            $resp = $t('response', 1000); $act = ($_POST['action'] ?? '') === 'more' ? 'More information' : 'Answered';
            if (!$resp) { throw new RuntimeException('Write your answer.'); }
            $s = mysqli_prepare($conn, 'UPDATE cf_escalations SET status = ?, response = ?, responded_by = ?, responded_at = ? WHERE id = ?'); $eid = (int) $myEsc['id']; mysqli_stmt_bind_param($s, 'ssisi', $act, $resp, $uid, $now, $eid); mysqli_stmt_execute($s);
            $open = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM cf_escalations WHERE case_id = $id AND status = 'Open'"))[0];
            if ($act === 'Answered' && !$open && $c['status'] === 'Escalated') { mysqli_query($conn, "UPDATE cf_cases SET status = 'Open', updated_at = '$now' WHERE id = $id"); }
            if ($myEsc['from_user']) { notifyUser($conn, (int) $myEsc['from_user'], $act === 'Answered' ? 'Answer to your escalated customer request' : 'More information needed', cf_case_no($id) . ': ' . mb_strimwidth($resp, 0, 160, '…') . ' — tell the customer and record the outcome.'); }
            cf_log($conn, $id, $act === 'Answered' ? 'answered' : 'more_info', $resp);
            cf_go($self, 'success', $act === 'Answered' ? 'Answer sent to ' . ($myEsc['fname'] ?: 'the employee') . '.' : 'Question sent.');
        }
        if ($do === 'info') { // the employee answers "more information"
            $e = null; foreach ($escs as $x) { if ($x['status'] === 'More information' && (int) $x['from_user'] === $uid) { $e = $x; } }
            if (!$e) { throw new RuntimeException('No question is waiting for you.'); }
            $info = $t('info', 1000); if (!$info) { throw new RuntimeException('Write the information.'); }
            $s = mysqli_prepare($conn, "UPDATE cf_escalations SET status = 'Open', checked = CONCAT(COALESCE(checked, ''), ?), overdue_notified = 0 WHERE id = ?"); $add = "\n[" . date('d M H:i') . '] ' . $info; $eid = (int) $e['id']; mysqli_stmt_bind_param($s, 'si', $add, $eid); mysqli_stmt_execute($s);
            notifyUser($conn, (int) $e['to_user'], 'Information added to an escalated request', cf_case_no($id) . ': ' . mb_strimwidth($info, 0, 160, '…'));
            cf_log($conn, $id, 'info', $info); cf_go($self, 'success', 'Information sent.');
        }
        if ($do === 'feedback') {
            if (!$isHandler && !$canManage) { throw new RuntimeException('Only the employee handling the request records the feedback.'); }
            $rating = max(1, min(5, (int) ($_POST['rating'] ?? 0))) ?: null; $fb = $t('feedback', 500);
            $s = mysqli_prepare($conn, 'UPDATE cf_cases SET feedback_rating = ?, feedback = ?, updated_at = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'issi', $rating, $fb, $now, $id); mysqli_stmt_execute($s);
            cf_log($conn, $id, 'feedback', $rating . '/5' . ($fb ? ': ' . $fb : '')); cf_go($self, 'success', 'Feedback recorded.');
        }
        if ($do === 'market') { cf_link_market($conn, $c); cf_go($self, 'success', 'Recorded in RM Market Insights as unmet demand.'); }
        if ($do === 'task') {
            $pj = (int) ($_POST['project_id'] ?? 0); $as = (int) ($_POST['assigned_to'] ?? 0) ?: null; $title = $t('title', 200); $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['due_date'] ?? '') ? $_POST['due_date'] : '';
            if (!$pj || !$title) { throw new RuntimeException('Choose the project and give the task a title.'); }
            $desc = 'RM Customer First ' . cf_case_no($id) . ': ' . $c['request_text'] . ($c['cname'] ? ' — customer ' . $c['cname'] : ''); $st = 'Pending'; $pr = 'High';
            $s = mysqli_prepare($conn, "INSERT INTO tasks (project_id, title, description, assigned_to, priority, status, due_date) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''))"); mysqli_stmt_bind_param($s, 'ississs', $pj, $title, $desc, $as, $pr, $st, $due); mysqli_stmt_execute($s); $tid = (int) mysqli_insert_id($conn);
            mysqli_query($conn, "UPDATE cf_cases SET task_id = $tid WHERE id = $id");
            if ($as) { notifyUser($conn, $as, 'New task assigned', 'You have been assigned "' . $title . '" (customer request ' . cf_case_no($id) . ').'); }
            cf_log($conn, $id, 'task', 'Task #' . $tid . ': ' . $title); cf_go($self, 'success', 'Task created.');
        }
    } catch (RuntimeException $e) { cf_go($self, 'error', $e->getMessage()); }
}
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT l.*, u.names FROM cf_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.case_id = $id ORDER BY l.id DESC"), MYSQLI_ASSOC);
$sales = $c['customer_id'] ? mysqli_fetch_all(mysqli_query($conn, 'SELECT id, sale_date, total_amount FROM sales WHERE customer_id = ' . (int) $c['customer_id'] . " AND status <> 'Cancelled' ORDER BY id DESC LIMIT 10"), MYSQLI_ASSOC) : [];
$prod = $c['product_id'] ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM products WHERE id = ' . (int) $c['product_id'])) : null;
$loss = cf_loss_indicator($c);
$infoWait = null; foreach ($escs as $x) { if ($x['status'] === 'More information' && (int) $x['from_user'] === $uid) { $infoWait = $x; } }
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
cf_tabs($conn, 'cases.php', cf_case_no($id) . ' · ' . $c['request_text']);
$v = function ($k) use ($c) { return cf_e($c[$k]); };
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3 small"><?= cf_outcome_badge($c['outcome']); ?> <span class="badge bg-light text-dark border"><?= cf_e($c['status']); ?></span><?= $c['unresolved_reason'] ? '<span class="text-danger">' . cf_e($c['unresolved_reason']) . '</span>' : ''; ?>
    <?= $loss ? '<span class="badge" style="background:#FDECEC;color:#9B1C1C">Possible lost opportunity: ' . cf_e($loss) . '</span>' : ''; ?>
    <span class="text-muted"><?= $c['kind'] === 'service' ? 'Service' : 'Product'; ?> · recorded <?= date('d M Y H:i', strtotime($c['created_at'])); ?> by <?= cf_e($c['hname']); ?></span></div>
<div class="row g-3">
<div class="col-xl-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="row g-2">
        <div class="col-md-6"><div class="text-muted">Customer</div><strong><?= $c['customer_id'] ? '<a href="../customers/edit.php?id=' . (int) $c['customer_id'] . '">' . cf_e($c['cname']) . '</a> · RMC' . str_pad((string) $c['customer_id'], 6, '0', STR_PAD_LEFT) : cf_e($c['walkin_name'] ?: 'Walk-in'); ?></strong><div><?= cf_e($c['contact'] ?: $c['cphone']); ?></div></div>
        <div class="col-md-6"><div class="text-muted">Request</div><strong><?= $v('request_text'); ?></strong><div><?= $c['quantity'] ? 'Quantity / scope: ' . $v('quantity') : ''; ?><?= $c['budget'] !== null ? ' · budget RWF ' . number_format((float) $c['budget']) : ''; ?><?= $c['needed_by'] ? ' · needed by ' . date('d M Y', strtotime($c['needed_by'])) : ''; ?></div>
            <?= $prod ? '<div>RM Offerings: ' . cf_e($prod['product_name']) . ' — ' . cf_e(cf_stock_text($prod)) . '</div>' : ''; ?><?= $c['portal_request_id'] ? '<div>From Customer Portal request <a href="../portal/request.php?id=' . (int) $c['portal_request_id'] . '">#' . (int) $c['portal_request_id'] . '</a></div>' : ''; ?></div>
    </div></div></div>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body small row g-2"><?= cf_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="update">
        <div class="col-12 fw-semibold">What was checked</div>
        <?php foreach (['stock_check' => '2. Stock', 'offering_check' => '3. RM Offerings', 'capability_check' => '4. Other employees', 'alternative' => '5. Alternative offered', 'sourcing' => '6. Sourcing / scheduling', 'communication' => '8. Told to the customer'] as $k => $l) { ?>
        <div class="col-md-6"><label class="form-label mb-0 text-muted"><?= $l; ?></label><input name="<?= $k; ?>" class="form-control form-control-sm" maxlength="<?= $k === 'communication' ? 500 : 255; ?>" value="<?= $v($k); ?>" <?= $canEdit ? '' : 'readonly'; ?>></div><?php } ?>
        <div class="col-md-6"><label class="form-label mb-0 text-muted">Expected date</label><input type="date" name="expected_date" class="form-control form-control-sm" value="<?= $v('expected_date'); ?>" <?= $canEdit ? '' : 'readonly'; ?>></div>
        <div class="col-md-6"><label class="form-label mb-0 text-muted">Sale (if fulfilled)</label><select name="sale_id" class="form-select form-select-sm" <?= $canEdit ? '' : 'disabled'; ?>><option value="">—</option><?php foreach ($sales as $s) { ?><option value="<?= (int) $s['id']; ?>" <?= (int) $c['sale_id'] === (int) $s['id'] ? 'selected' : ''; ?>>#<?= (int) $s['id']; ?> · <?= date('d M', strtotime($s['sale_date'])); ?> · RWF <?= number_format((float) $s['total_amount']); ?></option><?php } ?></select></div>
        <div class="col-12 fw-semibold mt-2">9. Outcome</div>
        <div class="col-md-5"><select name="outcome" class="form-select form-select-sm" id="cfOut" <?= $canEdit ? '' : 'disabled'; ?>><option value="">Not decided yet</option><?php foreach (CF_OUTCOMES as $k => [$l]) { ?><option value="<?= cf_e($k); ?>" title="<?= cf_e($l); ?>" <?= $c['outcome'] === $k ? 'selected' : ''; ?>><?= cf_e($k); ?></option><?php } ?></select></div>
        <div class="col-md-7"><select name="unresolved_reason" class="form-select form-select-sm" <?= $canEdit ? '' : 'disabled'; ?>><option value="">Reason (if not fulfilled)…</option><?php foreach (CF_REASONS as $r) { ?><option <?= $c['unresolved_reason'] === $r ? 'selected' : ''; ?>><?= $r; ?></option><?php } ?></select></div>
        <div class="col-12"><input name="outcome_note" class="form-control form-control-sm" maxlength="500" placeholder="Note" value="<?= $v('outcome_note'); ?>" <?= $canEdit ? '' : 'readonly'; ?>></div>
        <?php if ($canEdit) { ?><div class="col-12"><button class="btn btn-primary btn-sm">Save</button> <span class="text-muted">Fulfilled, Alternative Accepted, Referred, Unresolved and Customer Declined close the request.</span></div><?php } ?>
    </div></form>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Escalations</div>
        <?php foreach ($escs as $e) { $over = in_array($e['status'], ['Open', 'More information'], true) && $e['due_at'] && strtotime($e['due_at']) < time(); ?>
        <div class="border rounded p-2 mb-2 <?= $over ? 'border-danger' : ''; ?>"><div class="d-flex justify-content-between flex-wrap gap-1"><span><strong><?= cf_e($e['fname']); ?> → <?= cf_e($e['tname']); ?></strong><?= $e['department_id'] && isset($depts[$e['department_id']]) ? ' · ' . cf_e($depts[$e['department_id']]) : ''; ?> · <?= cf_e($e['reason']); ?></span>
            <span><span class="badge bg-<?= ['Urgent' => 'danger', 'High' => 'warning text-dark', 'Normal' => 'secondary', 'Low' => 'light text-dark border'][$e['priority']] ?? 'secondary'; ?>"><?= cf_e($e['priority']); ?></span> <span class="badge bg-light text-dark border"><?= cf_e($e['status']); ?></span> <span class="<?= $over ? 'text-danger fw-semibold' : 'text-muted'; ?>">due <?= $e['due_at'] ? date('d M H:i', strtotime($e['due_at'])) : '—'; ?><?= $over ? ' · OVERDUE' : ''; ?></span></span></div>
            <?php if ($e['checked']) { ?><div class="text-muted" style="white-space:pre-line">Checked: <?= cf_e($e['checked']); ?></div><?php } ?>
            <?php if ($e['response']) { ?><div class="mt-1"><strong><?= cf_e($e['rname']); ?>:</strong> <?= cf_e($e['response']); ?> <span class="text-muted"><?= $e['responded_at'] ? date('d M H:i', strtotime($e['responded_at'])) : ''; ?></span></div><?php } ?></div><?php } ?>
        <?php if (!$escs) { ?><div class="text-muted">Not escalated.</div><?php } ?>
        <?php if ($myEsc && $myEsc['status'] === 'Open') { ?>
        <form method="POST" class="border-top pt-2 mt-2"><?= cf_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="respond"><div class="fw-semibold">Your answer</div>
            <textarea name="response" rows="2" maxlength="1000" class="form-control form-control-sm" placeholder="Solution, decision, or question"></textarea>
            <div class="d-flex gap-1 mt-1"><button name="action" value="answer" class="btn btn-sm btn-primary">Send the solution</button><button name="action" value="more" class="btn btn-sm btn-light border">Ask for more information</button></div></form><?php } ?>
        <?php if ($infoWait) { ?><form method="POST" class="border-top pt-2 mt-2"><?= cf_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="info"><div class="fw-semibold">Information requested by <?= cf_e($infoWait['tname']); ?></div>
            <textarea name="info" rows="2" maxlength="1000" class="form-control form-control-sm"></textarea><button class="btn btn-sm btn-primary mt-1">Send</button></form><?php } ?>
        <?php if ($canEdit && $c['status'] !== 'Closed') { ?><details class="mt-2"><summary class="text-primary"><?= $myEsc ? 'Escalate further' : 'Escalate'; ?></summary><form method="POST" class="row g-1 mt-1"><?= cf_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="escalate">
            <div class="col-md-6"><select name="esc_to" class="form-select form-select-sm" required><option value="">To…</option><?php foreach ($users as $u) { if ((int) $u['id'] === $uid) { continue; } ?><option value="<?= (int) $u['id']; ?>"><?= cf_e($u['names']); ?> (<?= cf_e($u['role']); ?>)</option><?php } ?></select></div>
            <div class="col-md-6"><select name="esc_dept" class="form-select form-select-sm"><option value="">Department…</option><?php foreach ($depts as $k => $d) { ?><option value="<?= (int) $k; ?>"><?= cf_e($d); ?></option><?php } ?></select></div>
            <div class="col-md-5"><select name="esc_reason" class="form-select form-select-sm"><?php foreach (CF_ESC_REASONS as $r) { ?><option><?= $r; ?></option><?php } ?></select></div>
            <div class="col-md-3"><select name="esc_priority" class="form-select form-select-sm"><?php foreach (CF_PRIORITY as $k => $h) { ?><option <?= $k === 'Normal' ? 'selected' : ''; ?>><?= $k; ?></option><?php } ?></select></div>
            <div class="col-md-4"><input type="date" name="esc_due" class="form-control form-control-sm"></div>
            <div class="col-12"><input name="esc_note" class="form-control form-control-sm" maxlength="1000" placeholder="What was already checked / what is needed"></div><div class="col-12"><button class="btn btn-sm btn-primary">Escalate</button></div></form></details><?php } ?>
    </div></div>
</div>
<div class="col-xl-5">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Customer feedback</div>
        <?php if ($c['feedback_rating'] || $c['feedback']) { ?><div><?= str_repeat('★', (int) $c['feedback_rating']) . str_repeat('☆', 5 - (int) $c['feedback_rating']); ?> <?= cf_e($c['feedback']); ?></div><?php } ?>
        <?php if ($isHandler || $canManage) { ?><form method="POST" class="d-flex gap-1 mt-1"><?= cf_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="feedback"><select name="rating" class="form-select form-select-sm" style="width:80px"><?php for ($i = 5; $i >= 1; $i--) { ?><option value="<?= $i; ?>" <?= (int) $c['feedback_rating'] === $i ? 'selected' : ''; ?>><?= $i; ?> ★</option><?php } ?></select><input name="feedback" class="form-control form-control-sm" maxlength="500" placeholder="What the customer said" value="<?= $v('feedback'); ?>"><button class="btn btn-sm btn-light border">Save</button></form><?php } ?></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Links</div>
        <div>RM Market Insights: <?= $c['mi_request_id'] ? '<a href="../market/requests.php?q=' . rawurlencode($c['request_text']) . '&period=all">recorded as unmet demand</a>' : ($canEdit ? '<form method="POST" class="d-inline">' . cf_csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="do" value="market"><button class="btn btn-link btn-sm p-0">record as unmet demand</button></form>' : '—'); ?></div>
        <div>Sale: <?= $c['sale_id'] ? '<a href="../sales/invoice.php?id=' . (int) $c['sale_id'] . '">#' . (int) $c['sale_id'] . '</a>' : '—'; ?> · Task: <?= $c['task_id'] ? '<a href="../tasks/view.php?id=' . (int) $c['task_id'] . '">#' . (int) $c['task_id'] . '</a>' : '—'; ?></div>
        <?php if ($canEdit && !$c['task_id'] && $c['status'] !== 'Closed') { $projects = mysqli_fetch_all(mysqli_query($conn, "SELECT id, project_name FROM projects WHERE status != 'Completed' ORDER BY project_name"), MYSQLI_ASSOC); ?>
        <details class="mt-1"><summary class="text-primary">Create a follow-up task</summary><form method="POST" class="row g-1 mt-1"><?= cf_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="task">
            <div class="col-12"><input name="title" class="form-control form-control-sm" maxlength="200" value="<?= cf_e('Follow up ' . cf_case_no($id) . ': ' . $c['request_text']); ?>"></div>
            <div class="col-md-6"><select name="project_id" class="form-select form-select-sm" required><option value="">Project…</option><?php foreach ($projects as $p) { ?><option value="<?= (int) $p['id']; ?>"><?= cf_e($p['project_name']); ?></option><?php } ?></select></div>
            <div class="col-md-6"><select name="assigned_to" class="form-select form-select-sm"><option value="">Assign to…</option><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>"><?= cf_e($u['names']); ?></option><?php } ?></select></div>
            <div class="col-md-6"><input type="date" name="due_date" class="form-control form-control-sm" value="<?= $v('expected_date'); ?>"></div><div class="col-md-6"><button class="btn btn-sm btn-primary w-100">Create</button></div></form></details><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body small"><div class="fw-semibold mb-1">History</div>
        <?php foreach ($log as $l) { ?><div class="border-bottom py-1"><span class="text-muted"><?= date('d M H:i', strtotime($l['created_at'])); ?></span> · <strong><?= cf_e($l['names']); ?></strong> · <?= cf_e($l['details'] ?: $l['action']); ?></div><?php } ?></div></div>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>