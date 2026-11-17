<?php
/**
 * NEW FILE: modules/learning/course_edit.php — create / edit a COURSE (spec 3). The code is generated once and never
 * changes (it is in the shared links). Publication status is changed on the course page, not here.
 */
require __DIR__ . '/common.php';
lcs_require($conn, 'courses');
require_once __DIR__ . '/../../includes/evidence_store.php';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$c = $id ? lc_course($conn, $id) : null;
if ($id && !$c) { lcs_go('courses.php', 'error', 'Course not found.'); }
$users = lcs_users($conn);
$offerings = [];
try { $offerings = mysqli_fetch_all(mysqli_query($conn, "SELECT id, product_name, selling_price FROM products WHERE item_type = 'Service' AND is_active = 1 ORDER BY product_name"), MYSQLI_ASSOC); } catch (Throwable $e) { }
$text = ['title' => 160, 'category' => 80, 'summary' => 300, 'description' => 20000, 'objectives' => 5000, 'outcomes' => 5000, 'entry_requirements' => 5000, 'payment_terms' => 3000,
         'required_documents' => 3000, 'assessment_info' => 5000, 'certificate_type' => 120, 'custom_questions' => 3000];
$num = ['course_fee', 'application_fee', 'registration_fee', 'exam_fee', 'attendance_min', 'pass_mark'];
$v = $c ?: ['title' => '', 'category' => lc_categories($conn)[0] ?? '', 'summary' => '', 'description' => '', 'objectives' => '', 'outcomes' => '', 'entry_requirements' => '',
    'duration_value' => 3, 'duration_unit' => 'months', 'modes' => 'In-person', 'fee_structure' => 'course', 'course_fee' => 0, 'application_fee' => 0, 'registration_fee' => 0,
    'exam_fee' => 0, 'fees_public' => 1, 'installments_allowed' => 0, 'payment_terms' => '', 'required_documents' => "Copy of ID or passport\nLatest school certificate?",
    'assessment_info' => '', 'attendance_min' => 75, 'pass_mark' => 50, 'certificate_type' => 'Certificate of Completion', 'form_fields' => 'email,dob,address,education',
    'custom_questions' => '', 'capacity' => null, 'offering_id' => null, 'instructors' => '', 'image_ref' => null];
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { $error = 'The page had expired. Please try again.'; }
    else {
        $in = [];
        foreach ($text as $k => $len) { $in[$k] = mb_substr(trim((string) ($_POST[$k] ?? '')), 0, $len); }
        foreach ($num as $k) { $in[$k] = round(max(0, (float) ($_POST[$k] ?? 0)), 2); }
        $in['duration_value'] = max(0, (int) ($_POST['duration_value'] ?? 0)) ?: null;
        $in['duration_unit'] = in_array($_POST['duration_unit'] ?? '', ['days', 'weeks', 'months', 'hours'], true) ? $_POST['duration_unit'] : 'weeks';
        $in['modes'] = implode(',', array_intersect((array) ($_POST['modes'] ?? []), LC_MODES));
        $in['fee_structure'] = in_array($_POST['fee_structure'] ?? '', ['course', 'module', 'combined'], true) ? $_POST['fee_structure'] : 'course';
        $in['fees_public'] = !empty($_POST['fees_public']) ? 1 : 0;
        $in['installments_allowed'] = !empty($_POST['installments_allowed']) ? 1 : 0;
        $in['form_fields'] = implode(',', array_intersect((array) ($_POST['form_fields'] ?? []), array_keys(LC_FORM_FIELDS)));
        $in['capacity'] = (int) ($_POST['capacity'] ?? 0) ?: null;
        $in['offering_id'] = (int) ($_POST['offering_id'] ?? 0) ?: null;
        $in['instructors'] = implode(',', array_map('intval', array_intersect(array_map('intval', (array) ($_POST['instructors'] ?? [])), array_keys($users))));
        if (mb_strlen($in['title']) < 3) { $error = 'Give the course a title.'; }
        elseif (!$in['modes']) { $error = 'Choose at least one training mode.'; }
        elseif ($in['pass_mark'] > 100 || $in['attendance_min'] > 100) { $error = 'Pass mark and attendance are percentages (0-100).'; }
        else {
            try { $img = evidence_save($conn, $_FILES['image'] ?? null, (int) current_user_id(), ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 3 * 1048576); }
            catch (RuntimeException $e) { $error = $e->getMessage(); $img = null; }
            if (!$error) {
                if ($img) { $in['image_ref'] = $img; }
                $cols = array_keys($in);
                $types = implode('', array_map(function ($k) use ($num) { return in_array($k, $num, true) ? 'd' : (in_array($k, ['duration_value', 'capacity', 'offering_id'], true) ? 'i' : 's'); }, $cols));
                if ($c) {
                    $s = mysqli_prepare($conn, 'UPDATE lc_courses SET ' . implode(', ', array_map(function ($k) { return "$k = ?"; }, $cols)) . ', updated_at = NOW() WHERE id = ?');
                    $vals = array_values($in); $vals[] = $id;
                    mysqli_stmt_bind_param($s, $types . 'i', ...$vals);
                    mysqli_stmt_execute($s);
                    $chg = []; foreach ($in as $k => $x) { if ((string) $c[$k] !== (string) $x) { $chg[$k] = ['from' => mb_substr((string) $c[$k], 0, 120), 'to' => mb_substr((string) $x, 0, 120)]; } }
                    if ($chg) { lc_audit($conn, 'course_edit', 'course', $id, $chg); }
                    lcs_go('course.php?id=' . $id, 'success', 'Course saved.');
                }
                $code = lc_course_code($conn, $in['title']);
                $cols[] = 'code'; $cols[] = 'created_by'; $types .= 'si';
                $vals = array_values($in); $vals[] = $code; $vals[] = (int) current_user_id();
                $s = mysqli_prepare($conn, 'INSERT INTO lc_courses (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
                mysqli_stmt_bind_param($s, $types, ...$vals);
                mysqli_stmt_execute($s);
                $id = (int) mysqli_insert_id($conn);
                lc_audit($conn, 'course_create', 'course', $id, ['code' => $code, 'title' => $in['title']]);
                lcs_go('course.php?id=' . $id, 'success', 'Course created with code ' . $code . ' (Draft). Add modules and intakes, then publish it.');
            }
        }
    }
    $v = $in + $v;
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'courses.php', $c ? 'Edit ' . $c['code'] . ' · ' . $c['title'] : 'New course');
$chk = function ($list, $x) { return in_array($x, array_filter(explode(',', (string) $list)), true) ? 'checked' : ''; };
$sel = function ($a, $b) { return (string) $a === (string) $b ? 'selected' : ''; };
?>
<?php if ($error) { ?><div class="alert alert-danger py-2"><?= lc_e($error); ?></div><?php } ?>
<form method="POST" enctype="multipart/form-data" class="row g-3"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>">
<div class="col-xl-8">
<div class="card border-0 shadow-sm mb-3"><div class="card-body row g-2">
    <h6 class="mb-0">Course</h6>
    <div class="col-md-8"><label class="form-label small">Course title</label><input name="title" class="form-control" required maxlength="160" value="<?= lc_e($v['title']); ?>"><?php if ($c) { ?><div class="form-text">Code <strong><?= lc_e($c['code']); ?></strong> (permanent, used in the shared links).</div><?php } else { ?><div class="form-text">The code is generated from the title (e.g. Web Development Fundamentals → WDF-001).</div><?php } ?></div>
    <div class="col-md-4"><label class="form-label small">Category</label><select name="category" class="form-select"><?php foreach (lc_categories($conn) as $x) { ?><option <?= $sel($v['category'], $x); ?>><?= lc_e($x); ?></option><?php } ?></select></div>
    <div class="col-12"><label class="form-label small">Short summary (shown in the catalogue and in shared previews)</label><input name="summary" class="form-control" maxlength="300" value="<?= lc_e($v['summary']); ?>"></div>
    <div class="col-12"><label class="form-label small">Description</label><textarea name="description" class="form-control" rows="4"><?= lc_e($v['description']); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small">Objectives (one per line)</label><textarea name="objectives" class="form-control" rows="4"><?= lc_e($v['objectives']); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small">Learning outcomes (one per line)</label><textarea name="outcomes" class="form-control" rows="4"><?= lc_e($v['outcomes']); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small">Entry requirements (one per line)</label><textarea name="entry_requirements" class="form-control" rows="3"><?= lc_e($v['entry_requirements']); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small">Required documents (one per line; end with ? when optional)</label><textarea name="required_documents" class="form-control" rows="3"><?= lc_e($v['required_documents']); ?></textarea></div>
    <div class="col-md-3"><label class="form-label small">Duration</label><input name="duration_value" type="number" min="0" class="form-control" value="<?= lc_e($v['duration_value']); ?>"></div>
    <div class="col-md-3"><label class="form-label small">&nbsp;</label><select name="duration_unit" class="form-select"><?php foreach (['hours', 'days', 'weeks', 'months'] as $x) { ?><option <?= $sel($v['duration_unit'], $x); ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-md-6"><label class="form-label small">Training modes</label><div><?php foreach (LC_MODES as $m) { ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="modes[]" value="<?= $m; ?>" id="m<?= md5($m); ?>" <?= $chk($v['modes'], $m); ?>><label class="form-check-label small" for="m<?= md5($m); ?>"><?= $m; ?></label></div><?php } ?></div></div>
</div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body row g-2">
    <h6 class="mb-0">Assessment and certification</h6>
    <div class="col-md-12"><label class="form-label small">Assessment requirements</label><textarea name="assessment_info" class="form-control" rows="2"><?= lc_e($v['assessment_info']); ?></textarea></div>
    <div class="col-md-4"><label class="form-label small">Minimum attendance (%)</label><input name="attendance_min" type="number" step="0.5" min="0" max="100" class="form-control" value="<?= lc_e($v['attendance_min']); ?>"></div>
    <div class="col-md-4"><label class="form-label small">Pass mark (%)</label><input name="pass_mark" type="number" step="0.5" min="0" max="100" class="form-control" value="<?= lc_e($v['pass_mark']); ?>"></div>
    <div class="col-md-4"><label class="form-label small">Certificate type</label><input name="certificate_type" class="form-control" maxlength="120" value="<?= lc_e($v['certificate_type']); ?>"><div class="form-text">Do not claim national accreditation unless it is verified.</div></div>
</div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body row g-2">
    <h6 class="mb-0">Application form</h6>
    <div class="small text-muted">Always asked: full name, telephone, intake & branch, training mode, and the required documents. Ask only what this course really needs:</div>
    <div><?php foreach (LC_FORM_FIELDS as $k => $l) { ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="form_fields[]" value="<?= $k; ?>" id="f<?= $k; ?>" <?= $chk($v['form_fields'], $k); ?>><label class="form-check-label small" for="f<?= $k; ?>"><?= $l; ?></label></div><?php } ?></div>
    <div class="col-12"><label class="form-label small">Extra questions for this course (one per line; end with * when required)</label><textarea name="custom_questions" class="form-control" rows="2" placeholder="Do you own a laptop? *"><?= lc_e($v['custom_questions']); ?></textarea></div>
</div></div>
</div>
<div class="col-xl-4">
<div class="card border-0 shadow-sm mb-3"><div class="card-body row g-2">
    <h6 class="mb-0">Fees</h6>
    <div class="col-12"><label class="form-label small">Fee structure</label><select name="fee_structure" class="form-select form-select-sm">
        <option value="course" <?= $sel($v['fee_structure'], 'course'); ?>>One total fee for the course (modules included)</option><option value="module" <?= $sel($v['fee_structure'], 'module'); ?>>Separate fee per module</option>
        <option value="combined" <?= $sel($v['fee_structure'], 'combined'); ?>>Course fee + fees of extra modules</option></select>
        <div class="form-text">Modules marked "included" are never charged again on top of the course fee.</div></div>
    <div class="col-6"><label class="form-label small">Tuition (course fee)</label><input name="course_fee" type="number" min="0" step="1" class="form-control form-control-sm" value="<?= lc_e((float) $v['course_fee']); ?>"></div>
    <div class="col-6"><label class="form-label small">Application fee</label><input name="application_fee" type="number" min="0" step="1" class="form-control form-control-sm" value="<?= lc_e((float) $v['application_fee']); ?>"></div>
    <div class="col-6"><label class="form-label small">Registration fee</label><input name="registration_fee" type="number" min="0" step="1" class="form-control form-control-sm" value="<?= lc_e((float) $v['registration_fee']); ?>"></div>
    <div class="col-6"><label class="form-label small">Examination fee</label><input name="exam_fee" type="number" min="0" step="1" class="form-control form-control-sm" value="<?= lc_e((float) $v['exam_fee']); ?>"></div>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="fees_public" value="1" id="fp" <?= $v['fees_public'] ? 'checked' : ''; ?>><label class="form-check-label small" for="fp">Show the fees on the public website</label></div>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="installments_allowed" value="1" id="ia" <?= $v['installments_allowed'] ? 'checked' : ''; ?>><label class="form-check-label small" for="ia">Payment by instalments allowed</label></div></div>
    <div class="col-12"><label class="form-label small">Payment and instalment options (public text)</label><textarea name="payment_terms" class="form-control form-control-sm" rows="2"><?= lc_e($v['payment_terms']); ?></textarea></div>
    <div class="col-12"><label class="form-label small">RM Offering used to invoice the tuition</label><select name="offering_id" class="form-select form-select-sm"><option value="">— choose later —</option>
        <?php foreach ($offerings as $o) { ?><option value="<?= (int) $o['id']; ?>" <?= $sel($v['offering_id'], $o['id']); ?>><?= lc_e($o['product_name']); ?> (<?= number_format((float) $o['selling_price']); ?>)</option><?php } ?></select><div class="form-text">Invoices and receipts go through RM Offerings and Sales (no separate ledger).</div></div>
</div></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body row g-2">
    <h6 class="mb-0">Delivery</h6>
    <div class="col-6"><label class="form-label small">Default capacity per intake</label><input name="capacity" type="number" min="0" class="form-control form-control-sm" value="<?= lc_e($v['capacity']); ?>"></div>
    <div class="col-12"><label class="form-label small">Instructors (employees)</label><select name="instructors[]" class="form-select form-select-sm" multiple size="5"><?php foreach ($users as $uid => $n) { ?><option value="<?= $uid; ?>" <?= in_array((string) $uid, explode(',', (string) $v['instructors']), true) ? 'selected' : ''; ?>><?= lc_e($n); ?></option><?php } ?></select><div class="form-text">Ctrl/Cmd + click for several. External instructors come in the academic stage.</div></div>
    <div class="col-12"><label class="form-label small">Course image (JPG/PNG/WebP, max 3 MB, used on the public page and in shared previews)</label><input type="file" name="image" accept="image/jpeg,image/png,image/webp" class="form-control form-control-sm"><?= $v['image_ref'] ? '<div class="form-text">An image is saved; choose a file to replace it.</div>' : ''; ?></div>
</div></div>
<button class="btn btn-primary w-100"><?= $c ? 'Save course' : 'Create course (Draft)'; ?></button>
</div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>