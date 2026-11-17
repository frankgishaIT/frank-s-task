<?php
/** NEW FILE: modules/learning/courses.php — course list (all statuses), filters, quick links to the public pages. */
require __DIR__ . '/common.php';
lcs_require($conn, 'view');
$fS = (string) ($_GET['status'] ?? ''); $fC = (string) ($_GET['category'] ?? ''); $q = trim((string) ($_GET['q'] ?? ''));
$w = ['1=1'];
if (in_array($fS, LC_PUB_STATUSES, true)) { $w[] = "c.status = '" . mysqli_real_escape_string($conn, $fS) . "'"; } elseif ($fS === '') { $w[] = "c.status <> 'Archived'"; }
if ($fC !== '') { $w[] = "c.category = '" . mysqli_real_escape_string($conn, $fC) . "'"; }
if ($q !== '') { $e = mysqli_real_escape_string($conn, $q); $w[] = "(c.title LIKE '%$e%' OR c.code LIKE '%$e%')"; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.*, (SELECT COUNT(*) FROM lc_modules m WHERE m.course_id = c.id) nmod,
    (SELECT COUNT(*) FROM lc_intakes i WHERE i.course_id = c.id AND i.status = \'Open\') nint,
    (SELECT COUNT(*) FROM lc_applications a WHERE a.course_id = c.id AND a.status NOT IN (\'Withdrawn\', \'Draft\')) napp
    FROM lc_courses c WHERE ' . implode(' AND ', $w) . ' ORDER BY c.title'), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'courses.php', 'Courses', lcs_can($conn, 'courses') ? '<a href="course_edit.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> New course</a>' : '');
?>
<form class="row g-2 mb-3"><div class="col-md-4"><input name="q" value="<?= lc_e($q); ?>" class="form-control form-control-sm" placeholder="Search title or code"></div>
    <div class="col-md-3"><select name="status" class="form-select form-select-sm"><option value="">All except archived</option><option value="all" <?= $fS === 'all' ? 'selected' : ''; ?>>All</option><?php foreach (LC_PUB_STATUSES as $s) { ?><option <?= $fS === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
    <div class="col-md-3"><select name="category" class="form-select form-select-sm"><option value="">All categories</option><?php foreach (lc_categories($conn) as $c) { ?><option <?= $fC === $c ? 'selected' : ''; ?>><?= lc_e($c); ?></option><?php } ?></select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-outline-primary w-100">Filter</button></div></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Code</th><th>Course</th><th>Category</th><th>Publication</th><th>Delivery</th><th class="text-end">Fee</th><th class="text-end">Modules</th><th class="text-end">Open intakes</th><th class="text-end">Applications</th><th></th></tr>
    <?php foreach ($rows as $c) { ?><tr>
        <td class="ps-3 fw-semibold"><?= lc_e($c['code']); ?></td><td><a href="course.php?id=<?= (int) $c['id']; ?>"><?= lc_e($c['title']); ?></a></td><td><?= lc_e($c['category']); ?></td>
        <td><?= lcs_badge($c['status']); ?></td><td><?= lcs_badge($c['delivery_status']); ?></td><td class="text-end"><?= number_format((float) $c['course_fee']); ?></td>
        <td class="text-end"><?= (int) $c['nmod']; ?></td><td class="text-end"><?= (int) $c['nint']; ?></td><td class="text-end"><?= (int) $c['napp']; ?></td>
        <td class="text-nowrap"><?php if (in_array($c['status'], LC_PUBLIC_VISIBLE, true)) { ?><a href="<?= lc_e(lc_course_url($conn, $c)); ?>" target="_blank" rel="noopener" class="small">public page <i class="bi bi-box-arrow-up-right"></i></a><?php } ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="10" class="text-center text-muted py-4">No course yet.</td></tr><?php } ?>
</table></div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>