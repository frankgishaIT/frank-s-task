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
<section class="lc-hero"><div class="container"><div class="row align-items-center g-3 g-lg-5">
    <div class="col-lg-7"><div class="eyebrow">RISE MOTIVE · <?= lc_e(lc_setting($conn, 'title')); ?></div>
        <h1><?= lc_e(lc_setting($conn, 'tagline')); ?></h1><p class="lead lc-clamp-3 mb-3"><?= lc_e(lc_setting($conn, 'intro')); ?></p>
        <div class="d-flex gap-2"><a href="courses.php" class="btn btn-light text-primary flex-fill flex-sm-grow-0">Explore courses</a><a href="courses.php?open=1" class="btn btn-outline-light flex-fill flex-sm-grow-0">Apply now</a></div></div>
    <?php $ben = array_slice(lc_lines(lc_setting($conn, 'benefits')), 0, 5); if ($ben) { ?>
    <div class="col-lg-5 d-none d-lg-block"><div class="card text-dark"><div class="card-body"><div class="fw-bold mb-2">Why learn with us</div>
        <ul class="lc-list"><?php foreach ($ben as $b) { ?><li><?= lcp_i('check'); ?><span><?= lc_e($b); ?></span></li><?php } ?></ul></div></div></div><?php } ?>
</div></div></section>
<div class="container lc-pull">
    <?php if ($intakes) { ?><div class="card mb-4"><div class="card-body"><div class="d-flex justify-content-between align-items-baseline"><h2 class="sec">Upcoming intakes</h2><a href="courses.php?open=1" class="small">See all</a></div>
        <div class="d-none d-md-block"><table class="table align-middle mb-0">
        <tr><th>Course</th><th>Intake</th><th>Branch</th><th>Starts</th><th>Apply by</th><th></th></tr>
        <?php foreach ($intakes as $i) { ?><tr><td><a href="<?= lc_e(lcp_course_href($conn, $i['code'])); ?>" class="fw-semibold text-decoration-none"><?= lc_e($i['title']); ?></a></td><td><?= lc_e($i['name']); ?></td><td><?= lc_e(lc_branch_name($conn, (int) $i['branch_id'] ?: null)); ?></td>
            <td class="text-nowrap"><?= date('d M Y', strtotime($i['start_date'])); ?></td><td class="text-nowrap"><?= $i['apply_close'] ? date('d M Y', strtotime($i['apply_close'])) : '—'; ?></td><td class="text-end"><a href="apply.php?course=<?= lc_e(strtolower($i['code'])); ?>&amp;intake=<?= (int) $i['id']; ?>" class="btn btn-sm btn-primary">Apply</a></td></tr><?php } ?>
        </table></div>
        <div class="d-md-none"><?php foreach (array_slice($intakes, 0, 4) as $i) { ?><div class="lc-intake"><div style="min-width:0"><a href="<?= lc_e(lcp_course_href($conn, $i['code'])); ?>" class="fw-semibold text-decoration-none d-block text-truncate"><?= lc_e($i['title']); ?></a>
            <div class="lc-meta"><?= lcp_i('calendar'); ?><?= date('d M Y', strtotime($i['start_date'])); ?></div><div class="lc-meta"><?= lcp_i('pin'); ?><span class="text-truncate"><?= lc_e(lc_branch_name($conn, (int) $i['branch_id'] ?: null)); ?></span></div></div>
            <a href="apply.php?course=<?= lc_e(strtolower($i['code'])); ?>&amp;intake=<?= (int) $i['id']; ?>" class="btn btn-sm btn-primary flex-shrink-0">Apply</a></div><?php } ?></div>
    </div></div><?php } ?>
    <div class="d-flex justify-content-between align-items-baseline mb-2 <?= $intakes ? '' : 'pt-3'; ?>"><h2 class="sec mb-0">Courses</h2><a href="courses.php" class="small">All courses <?= lcp_i('arrow-right'); ?></a></div>
    <div class="row g-3 mb-4"><?php foreach ($courses as $c) { lcp_course_card($conn, $c); } ?><?php if (!$courses) { ?><div class="col-12 text-muted">New courses are coming soon.</div><?php } ?></div>
    <div class="row g-3">
        <div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="sec">How to join</h2>
            <ol class="lc-steps mb-2"><li>Choose a course and an intake.</li><li>Apply online — no account needed.</li><li>Keep your reference to track your application.</li><li>When admitted, accept your offer and register.</li></ol>
            <a href="track.php" class="btn btn-outline-primary btn-sm mt-1">Track my application</a></div></div></div>
        <div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="sec">Where we train</h2>
            <?php foreach ($branches as $b) { ?><div class="d-flex gap-2 py-1"><span class="text-primary"><?= lcp_i('pin'); ?></span><div><div class="fw-semibold"><?= lc_e($b['name']); ?></div><div class="small text-muted"><?= lc_e($b['district']); ?><?= !empty($b['settings']['accommodation']) ? ' · accommodation available' : ''; ?></div></div></div><?php } ?>
            <?php if (!$branches) { ?><div class="text-muted">Training locations will be announced with the next intake.</div><?php } ?>
            <?php if ($modes) { ?><div class="small text-muted mt-2">Training modes: <?= lc_e(implode(', ', array_keys($modes))); ?></div><?php } ?></div></div></div>
    </div>
    <?php if ($ben) { ?><div class="card mt-3 d-lg-none"><div class="card-body"><h2 class="sec">Why learn with us</h2><ul class="lc-list"><?php foreach ($ben as $b) { ?><li><?= lcp_i('check'); ?><span><?= lc_e($b); ?></span></li><?php } ?></ul></div></div><?php } ?>
</div>
<?php lcp_footer($conn); ?>