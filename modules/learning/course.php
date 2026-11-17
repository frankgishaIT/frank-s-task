<?php
/**
 * NEW FILE: modules/learning/course.php — one COURSE: details, modules, intakes, publication & delivery status, sharing.
 * Publication (what the public sees) is separate from the delivery status (is the course being taught).
 */
require __DIR__ . '/common.php';
lcs_require($conn, 'view');
$id = (int) ($_GET['id'] ?? 0);
$c = lc_course($conn, $id);
if (!$c) { lcs_go('courses.php', 'error', 'Course not found.'); }
$back = 'course.php?id=' . $id;
$users = lcs_users($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go($back, 'error', 'The page had expired.'); }
    lcs_require($conn, 'courses');
    $do = $_POST['do'] ?? '';
    if ($do === 'status') {
        $new = (string) ($_POST['status'] ?? '');
        if (!in_array($new, LC_PUB_STATUSES, true)) { lcs_go($back, 'error', 'Unknown status.'); }
        if (in_array($new, LC_PUBLIC_VISIBLE, true) && (trim((string) $c['description']) === '' || trim((string) $c['entry_requirements']) === '')) {
            lcs_go($back, 'error', 'Before publishing, write the description and the entry requirements.');
        }
        mysqli_query($conn, "UPDATE lc_courses SET status = '" . mysqli_real_escape_string($conn, $new) . "', updated_at = NOW()" . (in_array($new, LC_PUBLIC_VISIBLE, true) && !$c['published_at'] ? ', published_at = NOW()' : '') . " WHERE id = $id");
        lc_audit($conn, 'course_publication', 'course', $id, ['from' => $c['status'], 'to' => $new, 'reason' => trim((string) ($_POST['reason'] ?? ''))]);
        lcs_go($back, 'success', 'Publication status: ' . $new . '.' . ($new === 'Applications Open' && !lc_intakes($conn, $id, true) ? ' Note: no intake is open yet, so the Apply button stays hidden.' : ''));
    }
    if ($do === 'delivery') {
        $new = (string) ($_POST['delivery_status'] ?? '');
        if (!in_array($new, LC_DELIVERY, true)) { lcs_go($back, 'error', 'Unknown status.'); }
        mysqli_query($conn, "UPDATE lc_courses SET delivery_status = '$new', updated_at = NOW() WHERE id = $id");
        lc_audit($conn, 'course_delivery', 'course', $id, ['from' => $c['delivery_status'], 'to' => $new]);
        lcs_go($back, 'success', 'Delivery status: ' . $new . '.');
    }
    if ($do === 'module') {
        $mid = (int) ($_POST['module_id'] ?? 0);
        $f = ['name' => mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 160), 'description' => trim((string) ($_POST['description'] ?? '')), 'outcomes' => trim((string) ($_POST['outcomes'] ?? '')),
              'duration' => mb_substr(trim((string) ($_POST['duration'] ?? '')), 0, 60), 'hours' => trim((string) ($_POST['hours'] ?? '')) === '' ? null : max(0, (float) $_POST['hours']),
              'instructor_user_id' => isset($users[(int) ($_POST['instructor_user_id'] ?? 0)]) ? (int) $_POST['instructor_user_id'] : null, 'fee' => max(0, (float) ($_POST['fee'] ?? 0)),
              'in_bundle' => !empty($_POST['in_bundle']) ? 1 : 0, 'optional' => !empty($_POST['optional']) ? 1 : 0, 'assessment' => trim((string) ($_POST['assessment'] ?? '')),
              'timetable' => trim((string) ($_POST['timetable'] ?? '')), 'status' => in_array($_POST['status'] ?? '', ['Planned', 'Running', 'Completed', 'Retired'], true) ? $_POST['status'] : 'Planned',
              'sort' => (int) ($_POST['sort'] ?? 0)];
        if (mb_strlen($f['name']) < 2) { lcs_go($back, 'error', 'Give the module a name.'); }
        if ($c['fee_structure'] === 'course') { $f['in_bundle'] = 1; }
        if ($f['in_bundle']) { $f['fee'] = 0.0; }   // included in the course fee: never charged twice
        $types = 'ssssdidiisssi';
        if ($mid) {
            $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_modules WHERE id = $mid AND course_id = $id"));
            if (!$old) { lcs_go($back, 'error', 'Module not found.'); }
            $s = mysqli_prepare($conn, 'UPDATE lc_modules SET name = ?, description = ?, outcomes = ?, duration = ?, hours = ?, instructor_user_id = ?, fee = ?, in_bundle = ?, optional = ?, assessment = ?, timetable = ?, status = ?, sort = ? WHERE id = ?');
            $vals = array_values($f); $vals[] = $mid;
            mysqli_stmt_bind_param($s, $types . 'i', ...$vals);
            mysqli_stmt_execute($s);
            lc_audit($conn, 'module_edit', 'course', $id, ['module' => $old['code'], 'fields' => array_keys(array_filter($f, function ($x, $k) use ($old) { return (string) $old[$k] !== (string) $x; }, ARRAY_FILTER_USE_BOTH))]);
            lcs_go($back . '#modules', 'success', 'Module saved.');
        }
        $n = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_modules WHERE course_id = $id"))[0] + 1;
        do { $code = $c['code'] . '-M' . str_pad((string) $n, 2, '0', STR_PAD_LEFT); $n++; } while ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_modules WHERE code = '$code'"))[0]);
        $s = mysqli_prepare($conn, 'INSERT INTO lc_modules (name, description, outcomes, duration, hours, instructor_user_id, fee, in_bundle, optional, assessment, timetable, status, sort, course_id, code) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $vals = array_values($f); $vals[] = $id; $vals[] = $code;
        mysqli_stmt_bind_param($s, $types . 'is', ...$vals);
        mysqli_stmt_execute($s);
        lc_audit($conn, 'module_create', 'course', $id, ['module' => $code, 'name' => $f['name']]);
        lcs_go($back . '#modules', 'success', 'Module ' . $code . ' added.');
    }
    if ($do === 'module_delete') {
        $mid = (int) ($_POST['module_id'] ?? 0);
        $used = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_applications WHERE course_id = $id AND FIND_IN_SET($mid, modules)"))[0];
        if ($used) { mysqli_query($conn, "UPDATE lc_modules SET status = 'Retired' WHERE id = $mid AND course_id = $id"); lcs_go($back . '#modules', 'success', 'The module is used by applications: it was retired (kept in the history), not deleted.'); }
        mysqli_query($conn, "DELETE FROM lc_modules WHERE id = $mid AND course_id = $id");
        lc_audit($conn, 'module_delete', 'course', $id, ['module_id' => $mid]);
        lcs_go($back . '#modules', 'success', 'Module deleted.');
    }
    lcs_go($back, 'error', 'Unknown action.');
}

$mods = lc_modules($conn, $id);
$intakes = lc_intakes($conn, $id);
$apps = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) n, SUM(status = 'Accepted') acc, SUM(registered_at IS NOT NULL) reg FROM lc_applications WHERE course_id = $id AND status NOT IN ('Draft', 'Withdrawn')"));
$views = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(views), 0) FROM lc_views WHERE course_id = $id"))[0];
$edit = (int) ($_GET['module'] ?? 0);
$em = $edit ? (array_values(array_filter($mods, function ($m) use ($edit) { return (int) $m['id'] === $edit; }))[0] ?? null) : null;
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'courses.php', $c['code'] . ' · ' . $c['title'], lcs_can($conn, 'courses') ? '<a href="course_edit.php?id=' . $id . '" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit course</a>' : '');
$ok = lc_course_accepting($conn, $c);
?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
        <div class="col"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">Publication</div><div><?= lcs_badge($c['status']); ?></div><div class="small text-muted"><?= $ok[0] ? 'Apply button shown' : lc_e($ok[1]); ?></div></div></div></div>
        <div class="col"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">Delivery</div><div><?= lcs_badge($c['delivery_status']); ?></div></div></div></div>
        <div class="col"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">Applications</div><div class="val"><?= (int) $apps['n']; ?></div><div class="small text-muted"><?= (int) $apps['acc']; ?> admitted · <?= (int) $apps['reg']; ?> registered</div></div></div></div>
        <div class="col"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">Public page views</div><div class="val"><?= number_format($views); ?></div></div></div></div>
    </div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <div class="row g-3">
            <div class="col-md-6"><div class="text-muted">Category</div><?= lc_e($c['category']); ?></div>
            <div class="col-md-6"><div class="text-muted">Duration · modes</div><?= lc_e(trim($c['duration_value'] . ' ' . $c['duration_unit'])); ?> · <?= lc_e(str_replace(',', ', ', (string) $c['modes'])); ?></div>
            <div class="col-md-6"><div class="text-muted">Fees <?= $c['fees_public'] ? '(public)' : '(not public)'; ?></div>Tuition <?= lc_money($c['course_fee']); ?> · application <?= lc_money($c['application_fee']); ?> · registration <?= lc_money($c['registration_fee']); ?> · exam <?= lc_money($c['exam_fee']); ?><br>Structure: <?= ['course' => 'one course fee', 'module' => 'fee per module', 'combined' => 'course fee + extra modules'][$c['fee_structure']]; ?><?= $c['installments_allowed'] ? ' · instalments allowed' : ''; ?></div>
            <div class="col-md-6"><div class="text-muted">Assessment</div>Pass mark <?= (float) $c['pass_mark']; ?>% · attendance ≥ <?= (float) $c['attendance_min']; ?>% · <?= lc_e($c['certificate_type']); ?></div>
            <div class="col-12"><div class="text-muted">Summary</div><?= lc_e($c['summary'] ?: '—'); ?></div>
            <div class="col-md-6"><div class="text-muted">Entry requirements</div><ul class="mb-0 ps-3"><?php foreach (lc_lines($c['entry_requirements']) as $x) { ?><li><?= lc_e($x); ?></li><?php } ?></ul></div>
            <div class="col-md-6"><div class="text-muted">Required documents</div><ul class="mb-0 ps-3"><?php foreach (lc_lines($c['required_documents']) as $x) { ?><li><?= lc_e(rtrim($x, '?')); ?><?= substr($x, -1) === '?' ? ' <span class="text-muted">(optional)</span>' : ''; ?></li><?php } ?></ul></div>
            <div class="col-12"><div class="text-muted">Application form asks for</div>Name, telephone, intake & branch, mode<?= $c['form_fields'] ? ', ' . lc_e(implode(', ', array_map(function ($k) { return strtolower(LC_FORM_FIELDS[$k] ?? $k); }, lc_form_fields($c)))) : ''; ?><?= $c['custom_questions'] ? ' + ' . count(lc_lines($c['custom_questions'])) . ' question(s)' : ''; ?></div>
        </div>
    </div></div>

    <div class="card border-0 shadow-sm mb-3" id="modules"><div class="card-body">
        <h6>Modules</h6>
        <table class="table table-sm small align-middle"><tr><th>Code</th><th>Module</th><th>Duration / hours</th><th>Instructor</th><th class="text-end">Fee</th><th>Status</th><th></th></tr>
        <?php foreach ($mods as $m) { ?><tr><td><?= lc_e($m['code']); ?></td><td><strong><?= lc_e($m['name']); ?></strong><?= $m['optional'] ? ' <span class="badge bg-light text-dark border">optional</span>' : ''; ?></td>
            <td><?= lc_e($m['duration']); ?><?= $m['hours'] !== null ? ' · ' . (float) $m['hours'] . ' h' : ''; ?></td><td><?= lc_e($m['instructor'] ?: '—'); ?></td>
            <td class="text-end"><?= $m['in_bundle'] ? '<span class="text-muted">included</span>' : number_format((float) $m['fee']); ?></td><td><?= lcs_badge($m['status']); ?></td>
            <td class="text-end text-nowrap"><?php if (lcs_can($conn, 'courses')) { ?><a href="?id=<?= $id; ?>&module=<?= (int) $m['id']; ?>#mform">Edit</a>
                <form method="POST" class="d-inline"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="module_delete"><input type="hidden" name="module_id" value="<?= (int) $m['id']; ?>"><button class="btn btn-link btn-sm text-danger p-0 ms-2" onclick="return confirm('Delete this module?');">Delete</button></form><?php } ?></td></tr><?php } ?>
        <?php if (!$mods) { ?><tr><td colspan="7" class="text-muted">No module yet.</td></tr><?php } ?></table>
        <?php if (lcs_can($conn, 'courses')) { $mv = $em ?: ['id' => 0, 'name' => '', 'description' => '', 'outcomes' => '', 'duration' => '', 'hours' => '', 'instructor_user_id' => null, 'fee' => 0, 'in_bundle' => 1, 'optional' => 0, 'assessment' => '', 'timetable' => '', 'status' => 'Planned', 'sort' => count($mods) + 1]; ?>
        <form method="POST" class="row g-2 border-top pt-2" id="mform"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="module"><input type="hidden" name="module_id" value="<?= (int) $mv['id']; ?>">
            <div class="small fw-semibold"><?= $em ? 'Edit module ' . lc_e($em['code']) : 'Add a module'; ?></div>
            <div class="col-md-6"><input name="name" class="form-control form-control-sm" placeholder="Module name" value="<?= lc_e($mv['name']); ?>" required></div>
            <div class="col-md-3"><input name="duration" class="form-control form-control-sm" placeholder="Duration (e.g. 3 weeks)" value="<?= lc_e($mv['duration']); ?>"></div>
            <div class="col-md-3"><input name="hours" type="number" step="0.5" min="0" class="form-control form-control-sm" placeholder="Training hours" value="<?= lc_e($mv['hours']); ?>"></div>
            <div class="col-md-6"><textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Description"><?= lc_e($mv['description']); ?></textarea></div>
            <div class="col-md-6"><textarea name="outcomes" class="form-control form-control-sm" rows="2" placeholder="Learning outcomes"><?= lc_e($mv['outcomes']); ?></textarea></div>
            <div class="col-md-4"><select name="instructor_user_id" class="form-select form-select-sm"><option value="">Instructor…</option><?php foreach ($users as $uid => $n) { ?><option value="<?= $uid; ?>" <?= (int) $mv['instructor_user_id'] === $uid ? 'selected' : ''; ?>><?= lc_e($n); ?></option><?php } ?></select></div>
            <div class="col-md-2"><input name="fee" type="number" min="0" step="1" class="form-control form-control-sm" placeholder="Module fee" value="<?= lc_e((float) $mv['fee']); ?>"></div>
            <div class="col-md-3 small pt-1"><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="in_bundle" value="1" <?= $mv['in_bundle'] ? 'checked' : ''; ?> id="ib"><label class="form-check-label" for="ib">Included in the course fee</label></div></div>
            <div class="col-md-3 small pt-1"><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="optional" value="1" <?= $mv['optional'] ? 'checked' : ''; ?> id="op"><label class="form-check-label" for="op">Optional (applicant chooses)</label></div></div>
            <div class="col-md-6"><textarea name="assessment" class="form-control form-control-sm" rows="1" placeholder="Assessment requirements"><?= lc_e($mv['assessment']); ?></textarea></div>
            <div class="col-md-6"><textarea name="timetable" class="form-control form-control-sm" rows="1" placeholder="Timetable (e.g. Mon & Wed 17:30-19:30)"><?= lc_e($mv['timetable']); ?></textarea></div>
            <div class="col-md-3"><select name="status" class="form-select form-select-sm"><?php foreach (['Planned', 'Running', 'Completed', 'Retired'] as $s) { ?><option <?= $mv['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
            <div class="col-md-2"><input name="sort" type="number" class="form-control form-control-sm" value="<?= (int) $mv['sort']; ?>" title="Order"></div>
            <div class="col-md-7"><button class="btn btn-sm btn-primary"><?= $em ? 'Save module' : 'Add module'; ?></button><?= $em ? ' <a href="?id=' . $id . '#modules" class="btn btn-sm btn-light border">Cancel</a>' : ''; ?></div>
        </form>
        <?php } ?>
    </div></div>

    <div class="card border-0 shadow-sm"><div class="card-body">
        <div class="d-flex justify-content-between"><h6>Intakes</h6><?php if (lcs_can($conn, 'courses')) { ?><a href="intakes.php?course=<?= $id; ?>#form" class="btn btn-sm btn-outline-primary">Add an intake</a><?php } ?></div>
        <table class="table table-sm small mb-0"><tr><th>Intake</th><th>Branch</th><th>Starts</th><th>Applications</th><th class="text-end">Seats</th><th>Status</th><th>Apply now?</th></tr>
        <?php foreach ($intakes as $i) { ?><tr><td><?= lc_e($i['name']); ?></td><td><?= lc_e($i['branch_name']); ?><?= $i['branch_ok'] ? '' : ' <span class="text-danger">(not a training centre)</span>'; ?></td><td><?= lc_e($i['start_date']); ?></td>
            <td><?= lc_e(($i['apply_open'] ?: '…') . ' → ' . ($i['apply_close'] ?: '…')); ?></td><td class="text-end"><?= $i['capacity'] === null ? '—' : (int) $i['taken'] . ' / ' . (int) $i['capacity']; ?></td><td><?= lcs_badge($i['status']); ?></td>
            <td><?= $i['can_apply'] ? '<span class="text-success">yes</span>' : '<span class="text-muted">no</span>'; ?></td></tr><?php } ?>
        <?php if (!$intakes) { ?><tr><td colspan="7" class="text-muted">No intake yet: people cannot apply until an intake is open.</td></tr><?php } ?></table>
    </div></div>
</div>
<div class="col-xl-4">
    <?php if (lcs_can($conn, 'courses')) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6>Publication (public website)</h6>
        <form method="POST" class="d-flex gap-2 mb-1"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="status"><select name="status" class="form-select form-select-sm"><?php foreach (LC_PUB_STATUSES as $s) { ?><option <?= $c['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select><button class="btn btn-sm btn-primary">Set</button></form>
        <div class="small text-muted">Draft and Archived are never shown publicly. "Published" and "Applications Open" accept applications when an intake is open.</div>
        <h6 class="mt-3">Delivery (internal)</h6>
        <form method="POST" class="d-flex gap-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="delivery"><select name="delivery_status" class="form-select form-select-sm"><?php foreach (LC_DELIVERY as $s) { ?><option <?= $c['delivery_status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select><button class="btn btn-sm btn-outline-primary">Set</button></form>
    </div></div>
    <?php } ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6>Share this course</h6>
        <?php if (in_array($c['status'], LC_PUBLIC_VISIBLE, true)) { lcs_share_box($conn, $c); } else { ?><div class="small text-muted">Publish the course to get its public link, share buttons and QR code.</div><?php } ?>
    </div></div>
</div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>