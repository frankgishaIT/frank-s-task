<?php
/**
 * NEW FILE: modules/learning/intakes.php — INTAKES per course and branch: start/end dates, application window, capacity,
 * modes, accommodation, fee overrides. Applicants only see branches with an open intake of the course.
 */
require __DIR__ . '/common.php';
lcs_require($conn, 'view');
$fCourse = (int) ($_GET['course'] ?? 0);
$branches = lc_training_branches($conn);
$mine = array_filter($branches, function ($b) use ($conn) { return lcs_branch_ok($conn, $b['id'] ?: null); });
$courses = mysqli_fetch_all(mysqli_query($conn, "SELECT id, code, title, capacity, modes FROM lc_courses WHERE status <> 'Archived' ORDER BY title"), MYSQLI_ASSOC);
$cById = array_column($courses, null, 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('intakes.php', 'error', 'The page had expired.'); }
    lcs_require($conn, 'courses');
    $iid = (int) ($_POST['intake_id'] ?? 0);
    $f = ['course_id' => (int) $_POST['course_id'], 'branch_id' => (int) $_POST['branch_id'], 'name' => mb_substr(trim((string) $_POST['name']), 0, 80),
          'start_date' => (string) $_POST['start_date'], 'end_date' => ($_POST['end_date'] ?? '') ?: null, 'apply_open' => ($_POST['apply_open'] ?? '') ?: null, 'apply_close' => ($_POST['apply_close'] ?? '') ?: null,
          'capacity' => (int) ($_POST['capacity'] ?? 0) ?: null, 'modes' => implode(',', array_intersect((array) ($_POST['modes'] ?? []), LC_MODES)) ?: null,
          'accommodation' => !empty($_POST['accommodation']) ? 1 : 0, 'course_fee' => trim((string) ($_POST['course_fee'] ?? '')) === '' ? null : max(0, (float) $_POST['course_fee']),
          'application_fee' => trim((string) ($_POST['application_fee'] ?? '')) === '' ? null : max(0, (float) $_POST['application_fee']),
          'status' => in_array($_POST['status'] ?? '', ['Open', 'Closed', 'Cancelled'], true) ? $_POST['status'] : 'Open', 'notes' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 300)];
    $back = 'intakes.php?course=' . $f['course_id'];
    if (!isset($cById[$f['course_id']])) { lcs_go($back, 'error', 'Choose the course.'); }
    if (!isset($mine[$f['branch_id']])) { lcs_go($back, 'error', 'Choose a branch you manage.'); }
    if (!$mine[$f['branch_id']]['enabled'] && $f['status'] === 'Open') { lcs_go($back, 'error', 'This branch is not enabled as a training centre (Training Centres tab).'); }
    if ($f['name'] === '' || !strtotime($f['start_date'])) { lcs_go($back, 'error', 'Give the intake a name and a start date.'); }
    if (($f['end_date'] && $f['end_date'] < $f['start_date']) || ($f['apply_close'] && $f['apply_open'] && $f['apply_close'] < $f['apply_open']) || ($f['apply_close'] && $f['apply_close'] > $f['start_date'])) {
        lcs_go($back, 'error', 'Check the dates: applications must close before the start, and the end after the start.');
    }
    if ($f['accommodation'] && empty($mine[$f['branch_id']]['settings']['accommodation'])) { lcs_go($back, 'error', 'This branch does not offer accommodation (Training Centres tab).'); }
    if ($iid) {
        $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_intakes WHERE id = $iid"));
        if (!$old || !lcs_branch_ok($conn, (int) $old['branch_id'] ?: null)) { lcs_deny(); }
        if ($f['capacity'] !== null && $f['capacity'] < lc_intake_taken($conn, $iid)) { lcs_go($back, 'error', 'The capacity cannot be lower than the number of people already admitted (' . lc_intake_taken($conn, $iid) . ').'); }
        $s = mysqli_prepare($conn, 'UPDATE lc_intakes SET course_id = ?, branch_id = ?, name = ?, start_date = ?, end_date = ?, apply_open = ?, apply_close = ?, capacity = ?, modes = ?, accommodation = ?, course_fee = ?, application_fee = ?, status = ?, notes = ? WHERE id = ?');
        $vals = array_values($f); $vals[] = $iid;
        // types: course i, branch i, name s, start s, end s, open s, close s, capacity i, modes s, acc i, fee d, appfee d, status s, notes s
        mysqli_stmt_bind_param($s, 'iisssssisiddss' . 'i', ...$vals);
        mysqli_stmt_execute($s);
        lc_audit($conn, 'intake_edit', 'intake', $iid, ['from' => array_intersect_key($old, $f), 'to' => $f]);
        lcs_go($back, 'success', 'Intake saved.');
    }
    $s = mysqli_prepare($conn, 'INSERT INTO lc_intakes (course_id, branch_id, name, start_date, end_date, apply_open, apply_close, capacity, modes, accommodation, course_fee, application_fee, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $vals = array_values($f); $vals[] = (int) current_user_id();
    mysqli_stmt_bind_param($s, 'iisssssisiddss' . 'i', ...$vals);
    mysqli_stmt_execute($s);
    lc_audit($conn, 'intake_create', 'intake', (int) mysqli_insert_id($conn), $f);
    lcs_go($back, 'success', 'Intake added.');
}

$w = [lcs_branch_sql($conn, 'i.branch_id')];
if ($fCourse) { $w[] = 'i.course_id = ' . $fCourse; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT i.*, c.code, c.title FROM lc_intakes i JOIN lc_courses c ON c.id = i.course_id WHERE ' . implode(' AND ', $w) . ' ORDER BY i.start_date DESC'), MYSQLI_ASSOC);
$edit = (int) ($_GET['edit'] ?? 0);
$ev = null; foreach ($rows as $r) { if ((int) $r['id'] === $edit) { $ev = $r; } }
$ev = $ev ?: ['id' => 0, 'course_id' => $fCourse, 'branch_id' => array_key_first(array_filter($mine, function ($b) { return $b['enabled']; })) ?? 0, 'name' => '', 'start_date' => '', 'end_date' => '', 'apply_open' => date('Y-m-d'),
    'apply_close' => '', 'capacity' => $cById[$fCourse]['capacity'] ?? '', 'modes' => $cById[$fCourse]['modes'] ?? '', 'accommodation' => 0, 'course_fee' => '', 'application_fee' => '', 'status' => 'Open', 'notes' => ''];
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'intakes.php', 'Intakes');
?>
<form class="d-flex gap-2 mb-2"><select name="course" class="form-select form-select-sm" style="max-width:380px;" onchange="this.form.submit()"><option value="0">All courses</option><?php foreach ($courses as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $fCourse === (int) $c['id'] ? 'selected' : ''; ?>><?= lc_e($c['code'] . ' · ' . $c['title']); ?></option><?php } ?></select></form>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Course</th><th>Intake</th><th>Branch</th><th>Dates</th><th>Applications</th><th class="text-end">Admitted / seats</th><th>Accommodation</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $r) { $taken = lc_intake_taken($conn, (int) $r['id']); ?><tr>
        <td class="ps-3"><a href="course.php?id=<?= (int) $r['course_id']; ?>"><?= lc_e($r['code']); ?></a> <span class="text-muted small"><?= lc_e($r['title']); ?></span></td><td><?= lc_e($r['name']); ?></td><td><?= lc_e($branches[(int) $r['branch_id']]['name'] ?? lc_branch_name($conn, (int) $r['branch_id'] ?: null)); ?></td>
        <td class="small"><?= lc_e($r['start_date']); ?><?= $r['end_date'] ? ' → ' . lc_e($r['end_date']) : ''; ?></td><td class="small"><?= lc_e(($r['apply_open'] ?: '…') . ' → ' . ($r['apply_close'] ?: '…')); ?></td>
        <td class="text-end"><?= $taken; ?> / <?= $r['capacity'] === null ? '∞' : (int) $r['capacity']; ?></td><td><?= $r['accommodation'] ? 'Offered' : '—'; ?></td><td><?= lcs_badge($r['status']); ?></td>
        <td><?php if (lcs_can($conn, 'courses')) { ?><a href="?course=<?= $fCourse; ?>&edit=<?= (int) $r['id']; ?>#form" class="small">Edit</a><?php } ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-4">No intake.</td></tr><?php } ?>
</table></div></div></div>
<?php if (lcs_can($conn, 'courses')) { $chk = function ($l, $x) { return in_array($x, explode(',', (string) $l), true) ? 'checked' : ''; }; ?>
<form method="POST" class="card border-0 shadow-sm" id="form"><div class="card-body row g-2"><?= lcs_csrf_field(); ?><input type="hidden" name="intake_id" value="<?= (int) $ev['id']; ?>">
    <h6><?= $ev['id'] ? 'Edit intake' : 'Add an intake'; ?></h6>
    <div class="col-md-5"><label class="form-label small">Course</label><select name="course_id" class="form-select form-select-sm" required><option value="">Choose…</option><?php foreach ($courses as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= (int) $ev['course_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?= lc_e($c['code'] . ' · ' . $c['title']); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small">Branch (training centre)</label><select name="branch_id" class="form-select form-select-sm"><?php foreach ($mine as $b) { ?><option value="<?= (int) $b['id']; ?>" <?= (int) $ev['branch_id'] === (int) $b['id'] ? 'selected' : ''; ?>><?= lc_e($b['name']); ?><?= $b['enabled'] ? '' : ' (not enabled)'; ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small">Intake name</label><input name="name" class="form-control form-control-sm" placeholder="January 2027" value="<?= lc_e($ev['name']); ?>" required></div>
    <div class="col-md-3"><label class="form-label small">Start date</label><input type="date" name="start_date" class="form-control form-control-sm" value="<?= lc_e($ev['start_date']); ?>" required></div>
    <div class="col-md-3"><label class="form-label small">End date</label><input type="date" name="end_date" class="form-control form-control-sm" value="<?= lc_e($ev['end_date']); ?>"></div>
    <div class="col-md-3"><label class="form-label small">Applications open</label><input type="date" name="apply_open" class="form-control form-control-sm" value="<?= lc_e($ev['apply_open']); ?>"></div>
    <div class="col-md-3"><label class="form-label small">Application deadline</label><input type="date" name="apply_close" class="form-control form-control-sm" value="<?= lc_e($ev['apply_close']); ?>"></div>
    <div class="col-md-2"><label class="form-label small">Capacity</label><input type="number" min="0" name="capacity" class="form-control form-control-sm" value="<?= lc_e($ev['capacity']); ?>"></div>
    <div class="col-md-5"><label class="form-label small">Training modes (empty = the course's modes)</label><div><?php foreach (LC_MODES as $m) { ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="modes[]" value="<?= $m; ?>" <?= $chk($ev['modes'], $m); ?>><label class="form-check-label small"><?= $m; ?></label></div><?php } ?></div></div>
    <div class="col-md-2"><label class="form-label small">Tuition override</label><input type="number" min="0" step="1" name="course_fee" class="form-control form-control-sm" placeholder="course fee" value="<?= lc_e($ev['course_fee']); ?>"></div>
    <div class="col-md-3"><label class="form-label small">Application fee override</label><input type="number" min="0" step="1" name="application_fee" class="form-control form-control-sm" placeholder="course fee" value="<?= lc_e($ev['application_fee']); ?>"></div>
    <div class="col-md-3 pt-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="accommodation" value="1" id="acc" <?= $ev['accommodation'] ? 'checked' : ''; ?>><label class="form-check-label small" for="acc">Accommodation offered</label></div></div>
    <div class="col-md-2"><label class="form-label small">Status</label><select name="status" class="form-select form-select-sm"><?php foreach (['Open', 'Closed', 'Cancelled'] as $s) { ?><option <?= $ev['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small">Notes</label><input name="notes" class="form-control form-control-sm" value="<?= lc_e($ev['notes']); ?>"></div>
    <div class="col-12"><button class="btn btn-sm btn-primary"><?= $ev['id'] ? 'Save intake' : 'Add intake'; ?></button></div>
</div></form>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>