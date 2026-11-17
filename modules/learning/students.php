<?php
/** NEW FILE: modules/learning/students.php — registered students. Student ID = Customer ID (one person, one record). */
require __DIR__ . '/common.php';
lcs_require($conn, 'view');
$q = trim((string) ($_GET['q'] ?? '')); $fc = (int) ($_GET['course'] ?? 0);
$w = [lcs_branch_sql($conn, 'e.branch_id')];
if ($fc) { $w[] = 'e.course_id = ' . $fc; }
if ($q !== '') { $x = mysqli_real_escape_string($conn, $q); $w[] = "(cu.name LIKE '%$x%' OR cu.phone LIKE '%$x%' OR cu.id = " . (int) preg_replace('/\D/', '', $q) . ')'; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT e.*, cu.name, cu.phone, c.code, c.title, i.name intake_name, a.ref, s.status sstatus, k.code class_code FROM lc_enrollments e LEFT JOIN lc_classes k ON k.id = e.class_id JOIN customers cu ON cu.id = e.customer_id
    JOIN lc_courses c ON c.id = e.course_id LEFT JOIN lc_intakes i ON i.id = e.intake_id JOIN lc_applications a ON a.id = e.application_id LEFT JOIN lc_students s ON s.customer_id = e.customer_id
    WHERE ' . implode(' AND ', $w) . ' ORDER BY e.registered_at DESC LIMIT 1000'), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'students.php', 'Students');
?>
<form class="row g-2 mb-3"><div class="col-md-5"><input name="q" value="<?= lc_e($q); ?>" class="form-control form-control-sm" placeholder="Name, telephone or Student / Customer ID"></div>
    <div class="col-md-5"><select name="course" class="form-select form-select-sm"><option value="0">All courses</option><?php foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, code, title FROM lc_courses ORDER BY title'), MYSQLI_ASSOC) as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $fc === (int) $c['id'] ? 'selected' : ''; ?>><?= lc_e($c['code'] . ' · ' . $c['title']); ?></option><?php } ?></select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Search</button></div></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Student ID</th><th>Student</th><th>Course</th><th>Intake · branch</th><th>Class</th><th>Registered</th><th>Status</th><th>Application</th><th></th></tr>
    <?php foreach ($rows as $r) { ?><tr><td class="ps-3 fw-semibold"><?= lc_e(lc_customer_id_label($conn, (int) $r['customer_id'])); ?></td><td><?= lc_e($r['name']); ?><div class="small text-muted"><?= lc_e($r['phone']); ?></div></td>
        <td><?= lc_e($r['code']); ?> <span class="small text-muted"><?= lc_e($r['title']); ?></span></td><td class="small"><?= lc_e($r['intake_name']); ?> · <?= lc_e(lc_branch_name($conn, (int) $r['branch_id'] ?: null)); ?></td>
        <td><?= $r['class_id'] ? '<a href="class.php?id=' . (int) $r['class_id'] . '">' . lc_e($r['class_code']) . '</a>' : '<span class="text-muted">' . lc_e($r['cohort'] ?: '—') . '</span>'; ?></td><td class="small"><?= lc_e(substr($r['registered_at'], 0, 10)); ?></td><td><?= lcs_badge($r['status']); ?></td><td><a href="application.php?id=<?= (int) $r['application_id']; ?>"><?= lc_e($r['ref']); ?></a></td><td><a class="small" href="document.php?type=transcript&enrollment=<?= (int) $r['id']; ?>" target="_blank">Transcript</a></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-4">No registered student yet.</td></tr><?php } ?>
</table></div></div></div>
<div class="small text-muted mt-2">The Student ID is the student's Customer ID: the same number on invoices, receipts and in the Customer Portal. Put students in a class (Classes) for timetable, attendance, marks and results.</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>