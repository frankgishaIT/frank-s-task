<?php
/**
 * NEW FILE: modules/learning/classes.php — CLASSES (cohorts) and ACADEMIC YEARS.
 * A class = one group of students of a course / intake / branch, with classroom, lead instructor, timetable,
 * attendance, assessments and results. Instructors see only their own classes.
 */
require __DIR__ . '/common.php';
if (!lcs_classes_visible($conn)) { lcs_deny(); }
$manage = lcs_can($conn, 'academic');
$me = lc_my_instructor_id($conn);
$courses = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_courses WHERE status <> 'Archived' ORDER BY title"), MYSQLI_ASSOC);
$cById = array_column($courses, null, 'id');
$years = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_years ORDER BY start_date DESC'), MYSQLI_ASSOC);
$ins = lc_instructors($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('classes.php', 'error', 'The page had expired.'); }
    if (!$manage) { lcs_deny(); }
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'year') {
        $n = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 40); $a = (string) ($_POST['start_date'] ?? ''); $b = (string) ($_POST['end_date'] ?? '');
        if ($n === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $a) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $b) || $b <= $a) { lcs_go('classes.php', 'error', 'Academic year: name, start and end dates (end after start).'); }
        $cur = !empty($_POST['is_current']) ? 1 : 0;
        if ($cur) { mysqli_query($conn, 'UPDATE lc_years SET is_current = 0'); }
        $s = mysqli_prepare($conn, 'INSERT INTO lc_years (name, start_date, end_date, is_current) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'sssi', $n, $a, $b, $cur); mysqli_stmt_execute($s);
        lc_audit($conn, 'year_add', 'year', (int) mysqli_insert_id($conn), ['name' => $n]);
        lcs_go('classes.php', 'success', 'Academic year added.');
    }
    if ($do === 'class') {
        $id = (int) ($_POST['id'] ?? 0);
        $course = $cById[(int) ($_POST['course_id'] ?? 0)] ?? null;
        if (!$course) { lcs_go('classes.php', 'error', 'Choose the course.'); }
        $intakes = lc_intakes($conn, (int) $course['id']);
        $iid = (int) ($_POST['intake_id'] ?? 0); $intake = $intakes[$iid] ?? null; $iid = $intake ? $iid : null;
        $bid = $intake ? ((int) $intake['branch_id'] ?: null) : ((int) ($_POST['branch_id'] ?? 0) ?: null);
        if (!lcs_branch_ok($conn, $bid)) { lcs_go('classes.php', 'error', 'You cannot manage classes of this branch.'); }
        $yid = (int) ($_POST['year_id'] ?? 0) ?: null;
        $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120) ?: $course['title'] . ($intake ? ' — ' . $intake['name'] : '');
        $room = mb_substr(trim((string) ($_POST['classroom'] ?? '')), 0, 80) ?: null;
        $lead = isset($ins[(int) ($_POST['instructor_id'] ?? 0)]) ? (int) $_POST['instructor_id'] : null;
        $cap = (int) ($_POST['capacity'] ?? 0) ?: null;
        $st = in_array($_POST['status'] ?? '', LC_CLASS_STATUSES, true) ? $_POST['status'] : 'Planned';
        $cid = (int) $course['id'];
        if ($id) {
            $old = lc_class($conn, $id);
            if (!$old || lcs_class_role($conn, $old) !== 'manage') { lcs_deny(); }
            $s = mysqli_prepare($conn, 'UPDATE lc_classes SET name = ?, intake_id = ?, branch_id = ?, year_id = ?, classroom = ?, instructor_id = ?, capacity = ?, status = ? WHERE id = ?');
            mysqli_stmt_bind_param($s, 'siiisiisi', $name, $iid, $bid, $yid, $room, $lead, $cap, $st, $id);
            mysqli_stmt_execute($s);
            lc_audit($conn, 'class_update', 'class', $id, ['name' => $name, 'status' => $st, 'classroom' => $room, 'instructor' => $lead]);
        } else {
            $code = lc_class_code($conn, $course, $intake); $u = (int) current_user_id();
            $s = mysqli_prepare($conn, 'INSERT INTO lc_classes (code, name, course_id, intake_id, branch_id, year_id, classroom, instructor_id, capacity, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'ssiiiisiisi', $code, $name, $cid, $iid, $bid, $yid, $room, $lead, $cap, $st, $u);
            mysqli_stmt_execute($s);
            $id = (int) mysqli_insert_id($conn);
            lc_audit($conn, 'class_create', 'class', $id, ['code' => $code, 'course' => $course['code']]);
        }
        lcs_go('class.php?id=' . $id, 'success', 'Class saved.');
    }
}

$w = [lcs_branch_sql($conn, 'k.branch_id')];
if (!$manage && !lcs_can($conn, 'view')) { $ids = $me ? lc_instructor_class_ids($conn, $me) : []; $w[] = 'k.id IN (' . implode(',', array_map('intval', $ids ?: [0])) . ')'; }
$fc = (int) ($_GET['course'] ?? 0); if ($fc) { $w[] = 'k.course_id = ' . $fc; }
$fs = (string) ($_GET['status'] ?? ''); if (in_array($fs, LC_CLASS_STATUSES, true)) { $w[] = "k.status = '$fs'"; }
if (!empty($_GET['mine']) && $me) { $w[] = 'k.id IN (' . implode(',', array_map('intval', lc_instructor_class_ids($conn, $me) ?: [0])) . ')'; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT k.*, c.code ccode, c.title, i.name intake_name, y.name year_name, (SELECT COUNT(*) FROM lc_enrollments e WHERE e.class_id = k.id) students,
    (SELECT COUNT(*) FROM lc_sessions s WHERE s.class_id = k.id AND s.status = \'Held\') held, (SELECT COUNT(*) FROM lc_sessions s WHERE s.class_id = k.id AND s.status <> \'Cancelled\') sessions
    FROM lc_classes k JOIN lc_courses c ON c.id = k.course_id LEFT JOIN lc_intakes i ON i.id = k.intake_id LEFT JOIN lc_years y ON y.id = k.year_id WHERE ' . implode(' AND ', $w) . ' ORDER BY CASE k.status WHEN \'Running\' THEN 0 WHEN \'Planned\' THEN 1 WHEN \'Completed\' THEN 2 ELSE 3 END, k.id DESC'), MYSQLI_ASSOC);
$edit = isset($_GET['edit']) ? lc_class($conn, (int) $_GET['edit']) : null;
if ($edit && lcs_class_role($conn, $edit) !== 'manage') { $edit = null; }
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'classes.php', 'Classes', $manage ? '<a href="?new=1#form" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> New class</a>' : '');
$v = $edit ?: ['id' => 0, 'course_id' => (int) ($_GET['course'] ?? 0), 'intake_id' => null, 'branch_id' => null, 'year_id' => null, 'name' => '', 'classroom' => '', 'instructor_id' => null, 'capacity' => null, 'status' => 'Planned'];
?>
<form class="row g-2 mb-3"><div class="col-md-5"><select name="course" class="form-select form-select-sm"><option value="0">All courses</option><?php foreach ($courses as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $fc === (int) $c['id'] ? 'selected' : ''; ?>><?= lc_e($c['code'] . ' · ' . $c['title']); ?></option><?php } ?></select></div>
    <div class="col-md-3"><select name="status" class="form-select form-select-sm"><option value="">All statuses</option><?php foreach (LC_CLASS_STATUSES as $s) { ?><option <?= $fs === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
    <?php if ($me) { ?><div class="col-md-2 pt-1"><div class="form-check small"><input class="form-check-input" type="checkbox" name="mine" value="1" id="mine" <?= !empty($_GET['mine']) ? 'checked' : ''; ?>><label class="form-check-label" for="mine">My classes</label></div></div><?php } ?>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div></form>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Class</th><th>Course</th><th>Intake · branch</th><th>Year</th><th>Classroom</th><th>Lead instructor</th><th class="text-end">Students</th><th class="text-end">Sessions held</th><th>Results</th><th>Status</th></tr>
    <?php foreach ($rows as $r) { ?><tr><td class="ps-3"><a href="class.php?id=<?= (int) $r['id']; ?>" class="fw-semibold"><?= lc_e($r['code']); ?></a><div class="small text-muted"><?= lc_e($r['name']); ?></div></td>
        <td><?= lc_e($r['ccode']); ?></td><td class="small"><?= lc_e($r['intake_name'] ?? '—'); ?> · <?= lc_e(lc_branch_name($conn, (int) $r['branch_id'] ?: null)); ?></td><td class="small"><?= lc_e($r['year_name'] ?? '—'); ?></td>
        <td class="small"><?= lc_e($r['classroom']); ?></td><td class="small"><?= lc_e($ins[(int) $r['instructor_id']]['name'] ?? '—'); ?></td><td class="text-end"><?= (int) $r['students']; ?><?= $r['capacity'] ? ' / ' . (int) $r['capacity'] : ''; ?></td>
        <td class="text-end"><?= (int) $r['held']; ?> / <?= (int) $r['sessions']; ?></td><td class="small"><?= $r['results_published_at'] ? '<span class="text-success">Published</span>' : '<span class="text-muted">Provisional</span>'; ?></td><td><?= lcs_badge($r['status']); ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="10" class="text-center text-muted py-4">No class<?= $manage ? ' yet. Create one for a course and intake, then add its registered students.' : ' assigned to you.'; ?></td></tr><?php } ?>
</table></div></div></div>
<?php if ($manage && ($edit || !empty($_GET['new']) || !$rows)) { ?>
<div class="row g-3" id="form">
<div class="col-lg-8"><form method="POST" class="card border-0 shadow-sm"><div class="card-body row g-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="class"><input type="hidden" name="id" value="<?= (int) $v['id']; ?>">
    <div class="col-12 fw-semibold"><?= $edit ? 'Edit class ' . lc_e($edit['code']) : 'New class'; ?></div>
    <div class="col-md-6"><label class="form-label small mb-0">Course</label><select name="course_id" class="form-select form-select-sm" required <?= $edit ? 'disabled' : ''; ?> onchange="location='classes.php?new=1&course='+this.value+'#form'"><option value="">Choose…</option><?php foreach ($courses as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= (int) $v['course_id'] === (int) $c['id'] ? 'selected' : ''; ?>><?= lc_e($c['code'] . ' · ' . $c['title']); ?></option><?php } ?></select>
        <?php if ($edit) { ?><input type="hidden" name="course_id" value="<?= (int) $v['course_id']; ?>"><?php } ?></div>
    <div class="col-md-6"><label class="form-label small mb-0">Intake (sets the branch)</label><select name="intake_id" class="form-select form-select-sm"><option value="0">—</option><?php if ($v['course_id']) { foreach (lc_intakes($conn, (int) $v['course_id']) as $i) { ?><option value="<?= (int) $i['id']; ?>" <?= (int) $v['intake_id'] === (int) $i['id'] ? 'selected' : ''; ?>><?= lc_e($i['name'] . ' · ' . $i['branch_name']); ?></option><?php } } ?></select></div>
    <div class="col-md-6"><label class="form-label small mb-0">Class name</label><input name="name" class="form-control form-control-sm" value="<?= lc_e($v['name']); ?>" placeholder="Default: course — intake"></div>
    <div class="col-md-3"><label class="form-label small mb-0">Academic year</label><select name="year_id" class="form-select form-select-sm"><option value="0">—</option><?php foreach ($years as $y) { ?><option value="<?= (int) $y['id']; ?>" <?= ((int) $v['year_id'] === (int) $y['id'] || (!$edit && $y['is_current'])) ? 'selected' : ''; ?>><?= lc_e($y['name']); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm"><?php foreach (LC_CLASS_STATUSES as $s) { ?><option <?= $v['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small mb-0">Classroom</label><input name="classroom" class="form-control form-control-sm" value="<?= lc_e($v['classroom']); ?>"></div>
    <div class="col-md-5"><label class="form-label small mb-0">Lead instructor</label><select name="instructor_id" class="form-select form-select-sm"><option value="0">—</option><?php foreach ($ins as $i => $r) { ?><option value="<?= $i; ?>" <?= (int) $v['instructor_id'] === $i ? 'selected' : ''; ?>><?= lc_e($r['name']); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">Capacity</label><input type="number" min="0" name="capacity" class="form-control form-control-sm" value="<?= lc_e($v['capacity']); ?>"></div>
    <?php if (!$edit) { ?><div class="col-12 small text-muted">Classes without an intake: the branch is the Main branch. The class code is made from the course code and the start month (e.g. WDF-001-JAN27-A).</div><?php } ?>
    <div class="col-12"><button class="btn btn-sm btn-primary">Save class</button> <a href="classes.php" class="btn btn-sm btn-light border">Cancel</a></div>
</div></form></div>
<div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="fw-semibold mb-2">Academic years</div>
    <?php foreach ($years as $y) { ?><div class="small border-bottom py-1"><?= lc_e($y['name']); ?> <span class="text-muted"><?= lc_e($y['start_date'] . ' → ' . $y['end_date']); ?></span><?= $y['is_current'] ? ' <span class="badge bg-success">current</span>' : ''; ?></div><?php } ?>
    <form method="POST" class="mt-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="year"><input name="name" class="form-control form-control-sm mb-1" placeholder="e.g. 2027" required>
        <div class="d-flex gap-1 mb-1"><input type="date" name="start_date" class="form-control form-control-sm" required><input type="date" name="end_date" class="form-control form-control-sm" required></div>
        <div class="form-check small mb-1"><input class="form-check-input" type="checkbox" name="is_current" value="1" id="yc"><label class="form-check-label" for="yc">Current year</label></div><button class="btn btn-sm btn-outline-primary">Add year</button></form>
</div></div></div>
</div>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>