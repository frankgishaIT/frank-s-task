<?php
/**
 * NEW FILE: modules/marketing/activities.php — MARKETING ACTIVITIES. Each activity IS a task in Tasks (the person
 * sees it in My Tasks and works it there); marketing adds the campaign, type, expected vs actual output, evidence,
 * and a review with a quality score (1–5) by a manager.
 */
require __DIR__ . '/mkt_common.php';
mkt_ensure_team($conn);
$L = mkt_lists($conn);
$camps = mysqli_fetch_all(mysqli_query($conn, "SELECT id, name, created_at FROM mkt_campaigns WHERE status IN ('Approved', 'Active', 'Paused') ORDER BY name"), MYSQLI_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    try {
        if ($act === 'create') {
            if (!access_can('marketing.create', $conn)) { throw new RuntimeException('You cannot assign activities.'); }
            $d = ['title' => mb_substr(trim((string) $_POST['title']), 0, 150), 'description' => trim((string) ($_POST['description'] ?? '')), 'activity_type' => in_array($_POST['activity_type'] ?? '', MKT_ACTIVITY_TYPES, true) ? $_POST['activity_type'] : '',
                  'expected_output' => mb_substr(trim((string) ($_POST['expected_output'] ?? '')), 0, 500) ?: null, 'assigned_to' => (int) ($_POST['assigned_to'] ?? 0),
                  'priority' => in_array($_POST['priority'] ?? '', ['Low', 'Medium', 'High'], true) ? $_POST['priority'] : 'Medium', 'due_date' => (string) ($_POST['due_date'] ?? ''),
                  'campaign_id' => (int) ($_POST['campaign_id'] ?? 0) ?: null, 'lead_id' => null];
            if ($d['title'] === '' || $d['activity_type'] === '') { throw new RuntimeException('Give the activity a title and a type.'); }
            if (!in_array($d['assigned_to'], array_map('intval', array_column($L['users'], 'id')), true)) { throw new RuntimeException('Choose who does it.'); }
            if ($d['assigned_to'] !== $me && !mkt_is_manager()) { throw new RuntimeException('Only Managers assign activities to other people (you can plan your own).'); }
            if (!DateTime::createFromFormat('Y-m-d', $d['due_date']) || $d['due_date'] < date('Y-m-d')) { throw new RuntimeException('Set a due date (today or later).'); }
            mkt_activity_create($conn, $d, $me); $msg = 'Activity assigned: it is now a task in My Tasks.';
        } else {
            $id = (int) ($_POST['id'] ?? 0); $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT a.*, t.assigned_to, t.status AS task_status FROM mkt_activities a JOIN tasks t ON t.id = a.task_id WHERE a.id = $id"));
            if (!$a) { throw new RuntimeException('Activity not found.'); }
            if ($act === 'report') {
                if ((int) $a['assigned_to'] !== $me) { throw new RuntimeException('Only the assigned person reports the result.'); }
                $out = trim((string) ($_POST['actual_output'] ?? '')); $ev = mb_substr(trim((string) ($_POST['evidence'] ?? '')), 0, 500) ?: null;
                if (mb_strlen($out) < 5) { throw new RuntimeException('Describe what was actually done or achieved.'); }
                $s = mysqli_prepare($conn, "UPDATE mkt_activities SET actual_output = ?, evidence = ?, review_status = 'Waiting for review' WHERE id = ?"); mysqli_stmt_bind_param($s, 'ssi', $out, $ev, $id); mysqli_stmt_execute($s);
                mkt_audit($conn, 'Activity result reported', 'ACT-' . $id, null, $out, null, $me); $msg = 'Result reported. Mark the task Completed in My Tasks when it is done.';
            } elseif ($act === 'review') {
                if (!mkt_is_manager()) { throw new RuntimeException('Only Managers / Administrators review activities.'); }
                if ((int) $a['assigned_to'] === $me && !$isAdmin) { throw new RuntimeException('You cannot review your own activity.'); }
                $q = (int) ($_POST['quality'] ?? 0); $res = in_array($_POST['result'] ?? '', ['Accepted', 'Needs rework'], true) ? $_POST['result'] : '';
                if ($q < 1 || $q > 5 || $res === '') { throw new RuntimeException('Give a quality score from 1 to 5 and the decision.'); }
                $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500) ?: null;
                if ($res === 'Needs rework' && !$note) { throw new RuntimeException('Say what needs to be redone.'); }
                $s = mysqli_prepare($conn, 'UPDATE mkt_activities SET review_status = ?, quality_score = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?');
                mysqli_stmt_bind_param($s, 'sisii', $res, $q, $note, $me, $id); mysqli_stmt_execute($s);
                mkt_audit($conn, 'Activity reviewed: ' . $res . ', quality ' . $q . '/5', 'ACT-' . $id, $a['review_status'], $res, $note, $me);
                mkt_notify($conn, [(int) $a['assigned_to']], 'Activity reviewed: ' . $res, 'Quality ' . $q . '/5' . ($note ? ': ' . $note : '')); $msg = 'Review saved.';
            } else { throw new RuntimeException('Unknown action.'); }
        }
        header('Location: activities.php?success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$mine = !empty($_GET['mine']) || !mkt_is_manager();
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT a.*, t.title, t.status AS task_status, t.due_date, t.priority, t.assigned_to, u.names AS person, c.name AS campaign, r.names AS reviewer FROM mkt_activities a
    JOIN tasks t ON t.id = a.task_id LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN mkt_campaigns c ON c.id = a.campaign_id LEFT JOIN users r ON r.id = a.reviewed_by'
    . ($mine ? " WHERE t.assigned_to = $me" : '') . ' ORDER BY (t.status IN (\'Completed\', \'Approved\')), t.due_date, a.id DESC LIMIT 300'), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('activities.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if (access_can('marketing.create', $conn)) { ?>
<details class="card border-0 shadow-sm mb-3" <?= isset($error) ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold">+ Assign a marketing activity</summary>
<form method="POST" class="card-body pt-0 row g-2"><input type="hidden" name="action" value="create">
    <div class="col-md-5"><label class="form-label small text-muted mb-1">Title *</label><input name="title" maxlength="150" required class="form-control form-control-sm" placeholder="e.g. Visit 10 schools in Kicukiro"></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Type *</label><select name="activity_type" required class="form-select form-select-sm"><option value=""></option><?php foreach (MKT_ACTIVITY_TYPES as $t) { ?><option><?= $t; ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Campaign</label><select name="campaign_id" class="form-select form-select-sm"><option value="">— none —</option><?php foreach ($camps as $c) { ?><option value="<?= (int) $c['id']; ?>"><?= $e(mkt_code('campaign', $c) . ' ' . $c['name']); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Assigned to *</label><select name="assigned_to" class="form-select form-select-sm" <?= mkt_is_manager() ? '' : 'disabled'; ?>><?php foreach ($L['users'] as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (int) $u['id'] === $me ? 'selected' : ''; ?>><?= $e($u['names']); ?></option><?php } ?></select><?php if (!mkt_is_manager()) { ?><input type="hidden" name="assigned_to" value="<?= $me; ?>"><?php } ?></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Priority</label><select name="priority" class="form-select form-select-sm"><option>Low</option><option selected>Medium</option><option>High</option></select></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Due date *</label><input type="date" name="due_date" min="<?= date('Y-m-d'); ?>" required class="form-control form-control-sm"></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Expected output</label><input name="expected_output" maxlength="500" class="form-control form-control-sm" placeholder="e.g. 10 visits, 5 qualified leads"></div>
    <div class="col-12"><label class="form-label small text-muted mb-1">Instructions</label><textarea name="description" rows="2" class="form-control form-control-sm"></textarea></div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Assign (creates a task)</button></div>
</form></details>
<?php } ?>
<?php if (mkt_is_manager()) { ?><div class="btn-group btn-group-sm mb-2"><a href="activities.php" class="btn <?= !$mine ? 'btn-primary' : 'btn-outline-primary'; ?>">Team</a><a href="activities.php?mine=1" class="btn <?= $mine ? 'btn-primary' : 'btn-outline-primary'; ?>">Mine</a></div><?php } ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:12.5px;">
    <tr><th class="ps-3">Activity</th><th>Person</th><th>Due</th><th>Task status</th><th>Expected → actual</th><th>Review</th><th style="min-width:230px;"></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-4">No marketing activities<?= $mine ? ' for you' : ''; ?>.</td></tr><?php } ?>
    <?php foreach ($rows as $a) { $late = $a['due_date'] && $a['due_date'] < date('Y-m-d') && !in_array($a['task_status'], ['Completed', 'Approved'], true); ?>
    <tr class="<?= $late ? 'table-danger' : ''; ?>"><td class="ps-3"><strong><?= $e($a['title']); ?></strong><div class="small text-muted">ACT-<?= (int) $a['id']; ?> · task #<?= (int) $a['task_id']; ?><?= $a['campaign'] ? ' · ' . $e($a['campaign']) : ''; ?></div></td>
        <td><?= $e($a['person']); ?></td><td class="text-nowrap <?= $late ? 'text-danger fw-bold' : ''; ?>"><?= $a['due_date'] ? date('d M Y', strtotime($a['due_date'])) : '—'; ?></td><td><?= $e($a['task_status']); ?></td>
        <td class="small"><div class="text-muted"><?= $e($a['expected_output']); ?></div><?= $a['actual_output'] ? '→ ' . $e($a['actual_output']) : ''; ?><?= $a['evidence'] ? '<div>Evidence: ' . $e($a['evidence']) . '</div>' : ''; ?></td>
        <td class="small"><?= $e($a['review_status']); ?><?= $a['quality_score'] ? ' · <strong>' . (int) $a['quality_score'] . '/5</strong>' : ''; ?><?= $a['review_note'] ? '<div class="text-muted">' . $e($a['review_note']) . '</div>' : ''; ?></td>
        <td class="small"><?php if ((int) $a['assigned_to'] === $me && $a['review_status'] !== 'Accepted') { ?><details><summary class="text-primary">Report result</summary><form method="POST" class="mt-1"><input type="hidden" name="action" value="report"><input type="hidden" name="id" value="<?= (int) $a['id']; ?>">
                <textarea name="actual_output" rows="2" class="form-control form-control-sm mb-1" placeholder="What was done / achieved"></textarea><input name="evidence" class="form-control form-control-sm mb-1" placeholder="Evidence: Library reference, link, photo name…"><button class="btn btn-sm btn-primary">Save</button></form></details><?php } ?>
            <?php if (mkt_is_manager() && $a['actual_output'] && $a['review_status'] !== 'Accepted' && ((int) $a['assigned_to'] !== $me || $isAdmin)) { ?><details><summary class="text-primary">Review</summary><form method="POST" class="mt-1"><input type="hidden" name="action" value="review"><input type="hidden" name="id" value="<?= (int) $a['id']; ?>">
                <div class="d-flex gap-1 mb-1"><select name="quality" class="form-select form-select-sm"><option value="">Quality</option><?php for ($i = 5; $i >= 1; $i--) { ?><option value="<?= $i; ?>"><?= $i; ?>/5</option><?php } ?></select><select name="result" class="form-select form-select-sm"><option>Accepted</option><option>Needs rework</option></select></div>
                <input name="note" class="form-control form-control-sm mb-1" placeholder="Comment"><button class="btn btn-sm btn-success">Save review</button></form></details><?php } ?></td></tr><?php } ?>
</table></div></div></div>
<p class="small text-muted mt-2">Each activity is a task: the person accepts, starts and completes it in <strong>My Tasks</strong> as usual. On-time completion and the review score count in the team performance.</p>
<?php include '../../includes/footer.php'; ?>