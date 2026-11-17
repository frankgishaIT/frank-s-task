<?php
/** NEW FILE: learn/index.php — RM Learning Centre public HOME page (no login). */
require __DIR__ . '/lc_public.php';
lcp_track_source();
$courses = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_courses WHERE status IN ('" . implode("','", LC_PUBLIC_VISIBLE) . "') ORDER BY (status = 'Applications Open') DESC, published_at DESC LIMIT 6"), MYSQLI_ASSOC);
$intakes = mysqli_fetch_all(mysqli_query($conn, "SELECT i.*, c.title, c.code FROM lc_intakes i JOIN lc_courses c ON c.id = i.course_id WHERE i.status = 'Open' AND c.status IN ('Published', 'Applications Open')
    AND i.start_date >= '" . date('Y-m-d') . "' AND (i.apply_close IS NULL OR i.apply_close >= '" . date('Y-m-d') . "') ORDER BY i.start_date LIMIT 8"), MYSQLI_ASSOC);
$branches = array_filter(lc_training_branches($conn), function ($b) { return $b['enabled']; });
$modes = [];
foreach ($courses as $c) { foreach (array_filter(explode(',', (string) $c['modes'])) as $m) { $modes[$m] = true; } }
lcp_header($conn, ['url' => lc_public_base($conn) . '/', 'image' => lc_public_base($conn) . '/logo.php']);
?>
<section class="lc-hero"><div class="container"><div class="row align-items-center g-4">
    <div class="col-lg-7"><div class="small text-uppercase fw-bold opacity-75 mb-2">RISE MOTIVE · <?= lc_e(lc_setting($conn, 'title')); ?></div>
        <h1><?= lc_e(lc_setting($conn, 'tagline')); ?></h1><p class="lead opacity-90"><?= lc_e(lc_setting($conn, 'intro')); ?></p>
        <div class="d-flex flex-wrap gap-2"><a href="courses.php" class="btn btn-light btn-lg text-primary">Explore courses</a><a href="courses.php?open=1" class="btn btn-outline-light btn-lg">Apply now</a></div></div>
    <div class="col-lg-5"><div class="card text-dark"><div class="card-body"><div class="fw-bold mb-2"><i class="bi bi-stars text-primary"></i> Why learn with us</div>
        <?php foreach (lc_lines(lc_setting($conn, 'benefits')) as $b) { ?><div class="d-flex gap-2 mb-1"><i class="bi bi-check-circle-fill text-success"></i><span><?= lc_e($b); ?></span></div><?php } ?></div></div></div>
</div></div></section>
<div class="container" style="margin-top:-28px;">
    <?php if ($intakes) { ?><div class="card mb-4"><div class="card-body"><h2 class="sec mb-2">Upcoming intakes</h2><div class="table-responsive"><table class="table table-sm align-middle mb-0">
        <tr><th>Course</th><th>Intake</th><th>Branch</th><th>Starts</th><th>Apply by</th><th></th></tr>
        <?php foreach ($intakes as $i) { ?><tr><td><a href="courses/<?= lc_e(strtolower($i['code'])); ?>"><?= lc_e($i['title']); ?></a></td><td><?= lc_e($i['name']); ?></td><td><?= lc_e(lc_branch_name($conn, (int) $i['branch_id'] ?: null)); ?></td>
            <td><?= date('d M Y', strtotime($i['start_date'])); ?></td><td><?= $i['apply_close'] ? date('d M Y', strtotime($i['apply_close'])) : '—'; ?></td><td class="text-end"><a href="apply.php?course=<?= lc_e(strtolower($i['code'])); ?>&intake=<?= (int) $i['id']; ?>" class="btn btn-sm btn-primary">Apply</a></td></tr><?php } ?>
    </table></div></div></div><?php } ?>
    <div class="d-flex justify-content-between align-items-end mb-2"><h2 class="sec mb-0">Courses</h2><a href="courses.php">All courses <i class="bi bi-arrow-right"></i></a></div>
    <div class="row g-3 mb-4"><?php foreach ($courses as $c) { lcp_course_card($conn, $c); } ?><?php if (!$courses) { ?><div class="col-12 text-muted">New courses are coming soon.</div><?php } ?></div>
    <div class="row g-3">
        <div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="sec">Where we train</h2>
            <?php foreach ($branches as $b) { ?><div class="mb-1"><i class="bi bi-geo-alt-fill text-primary"></i> <strong><?= lc_e($b['name']); ?></strong> <span class="text-muted small"><?= lc_e($b['district']); ?></span><?= !empty($b['settings']['accommodation']) ? ' <span class="lc-chip">Accommodation available</span>' : ''; ?></div><?php } ?>
            <?php if (!$branches) { ?><div class="text-muted">Training locations will be announced with the next intake.</div><?php } ?></div></div></div>
        <div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="sec">How to join</h2>
            <ol class="mb-2"><li>Choose a course and an intake.</li><li>Apply online (no account needed) and upload the documents asked.</li><li>Keep your application reference: track it any time.</li><li>When admitted, accept your offer and complete your registration.</li></ol>
            <?php if ($modes) { ?><div class="small text-muted">Training modes: <?= lc_e(implode(', ', array_keys($modes))); ?></div><?php } ?>
            <a href="track.php" class="btn btn-outline-primary btn-sm mt-2">Track my application</a></div></div></div>
    </div>
</div>
<?php lcp_footer($conn); ?>