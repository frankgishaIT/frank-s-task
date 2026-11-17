<?php
/**
 * NEW FILE: learn/course.php — one PUBLIC COURSE page with a permanent shareable address (/courses/<code>).
 * Shows only what is approved for publication (fees only when "show fees publicly" is on). Share buttons, QR code,
 * social previews (Open Graph), and the source of the visit is remembered for the application.
 */
require __DIR__ . '/lc_public.php';
lcp_track_source();
$c = lc_course_by_code($conn, (string) ($_GET['code'] ?? ''));
if (!$c || !in_array($c['status'], LC_PUBLIC_VISIBLE, true)) {
    http_response_code(404);
    lcp_header($conn, ['title' => 'Course not found']);
    echo '<div class="container py-5"><div class="card"><div class="card-body"><h1 class="h4">This course is not available</h1><p>It may have been closed or the link is incomplete.</p><a href="courses.php" class="btn btn-primary">See all courses</a></div></div></div>';
    lcp_footer($conn); exit;
}
lc_count_view($conn, (int) $c['id'], $_SESSION['lcp_source'] ?? 'website');
$mods = array_filter(lc_modules($conn, (int) $c['id']), function ($m) { return $m['status'] !== 'Retired'; });
$intakes = array_filter(lc_intakes($conn, (int) $c['id']), function ($i) { return $i['status'] === 'Open' && $i['branch_ok'] && $i['start_date'] >= date('Y-m-d'); });
[$canApply, $why] = lc_course_accepting($conn, $c);
$av = lc_availability($conn, $c);
$url = lc_course_url($conn, $c);
$img = $c['image_ref'] ? lc_public_base($conn) . '/image.php?c=' . rawurlencode(strtolower($c['code'])) : lc_public_base($conn) . '/logo.php';
$share = $c['title'] . ' — ' . lc_setting($conn, 'title') . '. ' . ($c['summary'] ?: '');
$acc = array_filter($intakes, function ($i) { return (int) $i['accommodation'] === 1; });
$applyUrl = 'apply.php?course=' . rawurlencode(strtolower($c['code']));
lcp_header($conn, ['title' => $c['title'] . ' (' . $c['code'] . ')', 'description' => $c['summary'] ?: mb_substr(strip_tags((string) $c['description']), 0, 250), 'image' => $img, 'url' => $url]);
$list = function (string $title, ?string $text) { $l = lc_lines($text); if (!$l) { return; } echo '<h2 class="sec mt-4">' . lc_e($title) . '</h2><ul>'; foreach ($l as $x) { echo '<li>' . lc_e(rtrim($x, '?*')) . '</li>'; } echo '</ul>'; };
?>
<section class="lc-hero"><div class="container">
    <div class="small opacity-75 mb-1"><a href="courses.php" class="text-white">Courses</a> / <?= lc_e($c['category']); ?></div>
    <h1 class="mb-1"><?= lc_e($c['title']); ?></h1>
    <div class="mb-2 opacity-90">Course code <strong><?= lc_e($c['code']); ?></strong><?= $c['duration_value'] ? ' · ' . (int) $c['duration_value'] . ' ' . lc_e($c['duration_unit']) : ''; ?> · <?= lc_e(str_replace(',', ', ', (string) $c['modes'])); ?></div>
    <span class="badge bg-<?= $av[1]; ?> fs-6"><?= lc_e($av[0]); ?></span> <span class="small opacity-90"><?= lc_e($av[2]); ?></span>
</div></section>
<div class="container" style="margin-top:-20px;"><div class="row g-4">
<div class="col-lg-8"><div class="card"><div class="card-body p-4">
    <?php if ($c['image_ref']) { ?><img src="<?= lc_e($img); ?>" alt="" class="img-fluid rounded mb-3" style="max-height:320px;width:100%;object-fit:cover;"><?php } ?>
    <?php if ($c['summary']) { ?><p class="lead"><?= lc_e($c['summary']); ?></p><?php } ?>
    <div style="white-space:pre-line;"><?= lc_e($c['description']); ?></div>
    <?php $list('Objectives', $c['objectives']); $list('What you will learn', $c['outcomes']); $list('Entry requirements', $c['entry_requirements']); ?>
    <?php if ($mods) { ?><h2 class="sec mt-4">Modules</h2><div class="list-group mb-2"><?php foreach ($mods as $m) { ?><div class="list-group-item"><div class="d-flex justify-content-between"><strong><?= lc_e($m['name']); ?></strong><span class="small text-muted"><?= lc_e($m['duration']); ?><?= $m['hours'] !== null ? ' · ' . (float) $m['hours'] . ' h' : ''; ?></span></div>
        <?php if ($m['description']) { ?><div class="small text-muted"><?= lc_e($m['description']); ?></div><?php } ?><?php if ($m['optional']) { ?><span class="lc-chip">Optional</span><?php } ?>
        <?php if ($c['fees_public'] && !$m['in_bundle'] && (float) $m['fee'] > 0) { ?><span class="lc-chip"><?= lc_money($m['fee']); ?></span><?php } ?></div><?php } ?></div><?php } ?>
    <h2 class="sec mt-4">Assessment and certification</h2>
    <?php if ($c['assessment_info']) { ?><div style="white-space:pre-line;"><?= lc_e($c['assessment_info']); ?></div><?php } ?>
    <ul class="mb-0"><li>Pass mark: <?= (float) $c['pass_mark']; ?>%</li><li>Minimum attendance: <?= (float) $c['attendance_min']; ?>%</li><?php if ($c['certificate_type']) { ?><li>Successful students receive: <?= lc_e($c['certificate_type']); ?></li><?php } ?></ul>
    <?php $list('Documents to prepare', $c['required_documents']); ?>
</div></div></div>
<div class="col-lg-4"><div class="lc-sticky">
    <div class="card mb-3"><div class="card-body">
        <?php if ($c['fees_public']) { ?>
        <div class="small text-muted">Tuition</div><div class="fs-3 fw-bold" style="color:var(--d)"><?= (float) $c['course_fee'] > 0 ? lc_money($c['course_fee']) : ($c['fee_structure'] === 'module' ? 'Per module' : 'Free'); ?></div>
        <div class="small"><?php foreach (['application_fee' => 'Application fee', 'registration_fee' => 'Registration fee', 'exam_fee' => 'Examination fee'] as $k => $l) { if ((float) $c[$k] > 0) { echo lc_e($l) . ': ' . lc_money($c[$k]) . '<br>'; } } ?></div>
        <?php if ($c['installments_allowed'] || $c['payment_terms']) { ?><div class="small mt-2"><i class="bi bi-wallet2"></i> <?= $c['installments_allowed'] ? 'Payment by instalments possible. ' : ''; ?><?= lc_e($c['payment_terms']); ?></div><?php } ?>
        <?php } else { ?><div class="small text-muted">Fees: please contact us.</div><?php } ?>
        <hr>
        <div class="fw-bold mb-1">Intakes</div>
        <?php foreach ($intakes as $i) { ?><div class="small border-top py-2"><strong><?= lc_e($i['name']); ?></strong> · <?= lc_e($i['branch_name']); ?><br>Starts <?= date('d M Y', strtotime($i['start_date'])); ?><?= $i['end_date'] ? ' – ends ' . date('d M Y', strtotime($i['end_date'])) : ''; ?>
            <br><?= $i['can_apply'] ? '<span class="text-success">Applications open</span>' . ($i['apply_close'] ? ' until ' . date('d M Y', strtotime($i['apply_close'])) : '') : '<span class="text-muted">' . ($i['seats_left'] === 0 ? 'Full' : ($i['apply_open'] && $i['apply_open'] > date('Y-m-d') ? 'Applications open on ' . date('d M Y', strtotime($i['apply_open'])) : 'Applications closed')) . '</span>'; ?>
            <?= $i['accommodation'] ? '<br><span class="lc-chip">Accommodation available</span>' : ''; ?>
            <?php if ($c['fees_public'] && $i['course_fee'] !== null && (float) $i['course_fee'] !== (float) $c['course_fee']) { ?><br>Tuition for this intake: <?= lc_money($i['course_fee']); ?><?php } ?></div><?php } ?>
        <?php if (!$intakes) { ?><div class="small text-muted">The next intake will be announced soon.</div><?php } ?>
        <?php if ($canApply) { ?><a href="<?= lc_e($applyUrl); ?>" class="btn btn-primary btn-lg w-100 mt-2"><i class="bi bi-pencil-square"></i> Apply now</a><div class="small text-muted text-center mt-1">No account needed</div>
        <?php } else { ?><div class="alert alert-secondary small mt-2 mb-0"><?= lc_e($why); ?></div><?php } ?>
    </div></div>
    <div class="card mb-3"><div class="card-body">
        <div class="fw-bold mb-2">Share this course</div>
        <div class="input-group input-group-sm mb-2"><input class="form-control" value="<?= lc_e($url); ?>" readonly id="lcUrl"><button class="btn btn-outline-primary" type="button" onclick="navigator.clipboard.writeText(document.getElementById('lcUrl').value).then(()=>{this.innerText='Copied';});">Copy link</button></div>
        <div class="d-flex flex-wrap gap-1">
            <a class="btn btn-sm btn-success" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($share . ' ' . lc_course_url($conn, $c, 'wa')); ?>"><i class="bi bi-whatsapp"></i> WhatsApp</a>
            <a class="btn btn-sm btn-primary" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode(lc_course_url($conn, $c, 'fb')); ?>"><i class="bi bi-facebook"></i> Facebook</a>
            <a class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener" href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode(lc_course_url($conn, $c, 'li')); ?>"><i class="bi bi-linkedin"></i> LinkedIn</a>
            <a class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=<?= rawurlencode($share); ?>&url=<?= rawurlencode(lc_course_url($conn, $c, 'x')); ?>"><i class="bi bi-twitter-x"></i></a>
            <a class="btn btn-sm btn-outline-secondary" href="mailto:?subject=<?= rawurlencode($c['title']); ?>&body=<?= rawurlencode($share . ' ' . lc_course_url($conn, $c, 'email')); ?>"><i class="bi bi-envelope"></i></a>
            <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="lcNative"><i class="bi bi-share"></i> More</button>
        </div>
        <div class="d-flex align-items-center gap-2 mt-3"><div id="lcQr" data-url="<?= lc_e(lc_course_url($conn, $c, 'qr')); ?>" class="border rounded bg-white" style="width:116px;height:116px;"></div><div class="small text-muted">Scan to open this page on a phone.<br><a href="#" id="lcQrDl">Download QR code</a></div></div>
    </div></div>
    <div class="card"><div class="card-body small"><div class="fw-bold mb-1">Questions?</div><?= lc_e(lc_setting($conn, 'contact_phone')); ?><?= lc_setting($conn, 'contact_whatsapp') ? ' · <a href="https://wa.me/' . lc_e(preg_replace('/\D/', '', lc_setting($conn, 'contact_whatsapp'))) . '?text=' . rawurlencode('Hello, I have a question about ' . $c['title'] . ' (' . $c['code'] . ').') . '" target="_blank" rel="noopener">WhatsApp us</a>' : ''; ?></div></div>
</div></div>
</div></div>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.js"></script>
<script>
(function () {
    try { var el = document.getElementById('lcQr'); var q = qrcode(0, 'M'); q.addData(el.dataset.url); q.make(); el.innerHTML = q.createImgTag(3, 6);
        document.getElementById('lcQrDl').addEventListener('click', function (e) { e.preventDefault(); var b = qrcode(0, 'M'); b.addData(el.dataset.url); b.make(); var a = document.createElement('a'); a.href = b.createDataURL(10, 20); a.download = '<?= lc_e(strtolower($c['code'])); ?>-qr.gif'; a.click(); });
    } catch (e) {}
    if (navigator.share) { var n = document.getElementById('lcNative'); n.classList.remove('d-none'); n.addEventListener('click', function () { navigator.share({ title: <?= json_encode($c['title']); ?>, text: <?= json_encode($share); ?>, url: <?= json_encode($url); ?> }).catch(function () {}); }); }
})();
</script>
<?php lcp_footer($conn); ?>