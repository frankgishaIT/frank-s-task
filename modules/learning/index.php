<?php
/** NEW FILE: modules/learning/index.php — RM Learning Centre dashboard (actual figures only; nothing estimated). */
require __DIR__ . '/common.php';
lcs_require($conn, 'view');
$bs = lcs_branch_sql($conn, 'a.branch_id');
$n = function (string $sql) use ($conn) { return (int) mysqli_fetch_row(mysqli_query($conn, $sql))[0]; };
$d90 = date('Y-m-d', strtotime('-90 days')); $m1 = date('Y-m-01');
$k = [
    'published' => $n("SELECT COUNT(*) FROM lc_courses WHERE status IN ('" . implode("','", LC_PUBLIC_VISIBLE) . "')"),
    'intakes' => $n("SELECT COUNT(*) FROM lc_intakes i WHERE status = 'Open' AND (apply_close IS NULL OR apply_close >= '" . date('Y-m-d') . "') AND " . lcs_branch_sql($conn, 'i.branch_id')),
    'month' => $n("SELECT COUNT(*) FROM lc_applications a WHERE $bs AND status <> 'Draft' AND submitted_at >= '$m1'"),
    'review' => $n("SELECT COUNT(*) FROM lc_applications a WHERE $bs AND status IN ('Submitted', 'Under Review', 'Application Fee Paid')"),
    'info' => $n("SELECT COUNT(*) FROM lc_applications a WHERE $bs AND status = 'Additional Information Required'"),
    'admitted' => $n("SELECT COUNT(*) FROM lc_applications a WHERE $bs AND status = 'Accepted' AND registered_at IS NULL AND COALESCE(offer_status, '') <> 'Declined'"),
    'students' => $n("SELECT COUNT(DISTINCT e.customer_id) FROM lc_enrollments e WHERE " . lcs_branch_sql($conn, 'e.branch_id') . " AND e.status IN ('Registered', 'Active')"),
    'views' => $n("SELECT COALESCE(SUM(views), 0) FROM lc_views WHERE day >= '" . date('Y-m-d', strtotime('-30 days')) . "'"),
];
$src = mysqli_fetch_all(mysqli_query($conn, "SELECT source, COUNT(*) n, SUM(status = 'Accepted' OR registered_at IS NOT NULL) adm FROM lc_applications a WHERE $bs AND status <> 'Draft' AND submitted_at >= '$d90' GROUP BY source ORDER BY n DESC"), MYSQLI_ASSOC);
$byCourse = mysqli_fetch_all(mysqli_query($conn, "SELECT c.code, c.title, COUNT(a.id) n, SUM(a.status = 'Accepted' OR a.registered_at IS NOT NULL) adm, SUM(a.registered_at IS NOT NULL) reg, SUM(a.status IN ('Rejected', 'Withdrawn')) lost,
    (SELECT COALESCE(SUM(v.views), 0) FROM lc_views v WHERE v.course_id = c.id AND v.day >= '$d90') views
    FROM lc_courses c LEFT JOIN lc_applications a ON a.course_id = c.id AND a.status <> 'Draft' AND a.submitted_at >= '$d90' AND $bs WHERE c.status <> 'Archived' GROUP BY c.id, c.code, c.title ORDER BY n DESC LIMIT 15"), MYSQLI_ASSOC);
$recent = mysqli_fetch_all(mysqli_query($conn, "SELECT a.id, a.ref, a.full_name, a.status, a.registered_at, a.submitted_at, a.source, c.code FROM lc_applications a JOIN lc_courses c ON c.id = a.course_id WHERE $bs AND a.status <> 'Draft' ORDER BY a.id DESC LIMIT 10"), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'index.php', 'RM Learning Centre', lcs_can($conn, 'courses') ? '<a href="course_edit.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-circle"></i> New course</a>' : '');
$tile = function ($l, $v, $h = '', $href = '') { echo '<div class="col"><div class="card border-0 shadow-sm h-100 lc-k"><div class="card-body py-2"><div class="lbl">' . $l . '</div><div class="val">' . ($href ? '<a href="' . $href . '" class="text-decoration-none">' . $v . '</a>' : $v) . '</div>' . ($h ? '<div class="small text-muted">' . $h . '</div>' : '') . '</div></div></div>'; };
?>
<div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
    <?php $tile('Courses on the public website', $k['published'], '', 'courses.php'); $tile('Intakes open for applications', $k['intakes'], '', 'intakes.php');
    $tile('Applications this month', $k['month'], 'public website + Customer Portal', 'applications.php?status=all'); $tile('Waiting for review', $k['review'], $k['info'] . ' waiting for the applicant', 'applications.php');
    $tile('Admitted, not yet registered', $k['admitted'], '', 'applications.php?status=Accepted'); $tile('Registered students', $k['students'], '', 'students.php');
    $tile('Course page views (30 days)', number_format($k['views']), 'counted on the public pages'); ?>
</div>
<div class="row g-3">
    <div class="col-xl-7"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Applications by course (90 days)</h6>
        <table class="table table-sm small mb-0"><tr><th>Course</th><th class="text-end">Page views</th><th class="text-end">Applications</th><th class="text-end">Admitted</th><th class="text-end">Registered</th><th class="text-end">Rejected / withdrawn</th><th class="text-end">Conversion</th></tr>
        <?php foreach ($byCourse as $r) { ?><tr><td><?= lc_e($r['code']); ?> <span class="text-muted"><?= lc_e($r['title']); ?></span></td><td class="text-end"><?= (int) $r['views']; ?></td><td class="text-end"><?= (int) $r['n']; ?></td><td class="text-end"><?= (int) $r['adm']; ?></td><td class="text-end"><?= (int) $r['reg']; ?></td><td class="text-end"><?= (int) $r['lost']; ?></td>
            <td class="text-end"><?= (int) $r['n'] ? round($r['adm'] / $r['n'] * 100) . '%' : '—'; ?></td></tr><?php } ?></table>
        <div class="small text-muted mt-1">Conversion = admitted / applications. Page views come from the public course pages.</div></div></div></div>
    <div class="col-xl-5"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6>Where applications come from (90 days)</h6>
        <table class="table table-sm small mb-0"><tr><th>Source</th><th class="text-end">Applications</th><th class="text-end">Admitted</th></tr>
        <?php foreach ($src as $r) { ?><tr><td><?= lc_e(LC_SOURCES[$r['source']] ?? $r['source']); ?></td><td class="text-end"><?= (int) $r['n']; ?></td><td class="text-end"><?= (int) $r['adm']; ?></td></tr><?php } ?>
        <?php if (!$src) { ?><tr><td colspan="3" class="text-muted">No application yet.</td></tr><?php } ?></table>
        <div class="small text-muted mt-1">Shared links carry ?src=wa / fb / li / qr…; campaigns can add &amp;utm_campaign=name.</div></div></div></div>
    <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body"><h6>Latest applications</h6>
        <table class="table table-sm small mb-0"><?php foreach ($recent as $r) { ?><tr><td><a href="application.php?id=<?= (int) $r['id']; ?>"><?= lc_e($r['ref']); ?></a></td><td><?= lc_e($r['full_name']); ?></td><td><?= lc_e($r['code']); ?></td><td><?= lc_e(LC_SOURCES[$r['source']] ?? $r['source']); ?></td><td><?= lcs_badge($r["registered_at"] ? "Registered" : $r["status"]); ?></td><td class="text-muted"><?= lc_e($r['submitted_at']); ?></td></tr><?php } ?>
        <?php if (!$recent) { ?><tr><td class="text-muted">No application yet. Publish a course, open an intake and share its link.</td></tr><?php } ?></table></div></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>