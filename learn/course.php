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
$list = function (string $title, ?string $text, bool $first = false) { $l = lc_lines($text); if (!$l) { return; } echo '<details class="lc-sec"' . ($first ? ' open' : ' data-m') . '><summary><h2>' . lc_e($title) . '</h2>' . lcp_i('chevron') . '</summary><div><ul class="lc-list">'; foreach ($l as $x) { echo '<li>' . lcp_i('check') . '<span>' . lc_e(rtrim($x, '?*')) . '</span></li>'; } echo '</ul></div></details>'; };
require_once __DIR__ . '/../includes/lc_qr.php';
$shareCards = function () use ($conn, $c, $url, $share) { ?>
    <div class="card mb-3"><div class="card-body">
        <div class="fw-bold mb-2">Share this course</div>
        <div class="d-flex gap-2 mb-2"><button type="button" class="btn btn-sm btn-primary d-none" data-native><?= lcp_i('share'); ?>Share</button><button type="button" class="btn btn-sm btn-outline-primary" data-copy data-url="<?= lc_e($url); ?>"><?= lcp_i('link'); ?>Copy link</button></div>
        <div class="d-flex flex-wrap gap-1">
            <a class="btn btn-sm btn-light border" target="_blank" rel="noopener" href="https://wa.me/?text=<?= rawurlencode($share . ' ' . lc_course_url($conn, $c, 'wa')); ?>">WhatsApp</a>
            <a class="btn btn-sm btn-light border" target="_blank" rel="noopener" href="https://www.facebook.com/sharer/sharer.php?u=<?= rawurlencode(lc_course_url($conn, $c, 'fb')); ?>">Facebook</a>
            <a class="btn btn-sm btn-light border" target="_blank" rel="noopener" href="https://www.linkedin.com/sharing/share-offsite/?url=<?= rawurlencode(lc_course_url($conn, $c, 'li')); ?>">LinkedIn</a>
            <a class="btn btn-sm btn-light border" target="_blank" rel="noopener" href="https://twitter.com/intent/tweet?text=<?= rawurlencode($share); ?>&amp;url=<?= rawurlencode(lc_course_url($conn, $c, 'x')); ?>">X</a>
            <a class="btn btn-sm btn-light border" href="mailto:?subject=<?= rawurlencode($c['title']); ?>&amp;body=<?= rawurlencode($share . ' ' . lc_course_url($conn, $c, 'email')); ?>">Email</a>
        </div>
        <?php $qr = lc_qr_data_uri(lc_course_url($conn, $c, 'qr'), 4); ?>
        <div class="d-none d-lg-flex align-items-center gap-2 mt-3"><img src="<?= $qr; ?>" alt="QR code of this page" width="96" height="96" class="border rounded bg-white" style="image-rendering:pixelated"><div class="small text-muted">Scan to open this page on a phone.<br><a href="<?= $qr; ?>" download="<?= lc_e(strtolower($c['code'])); ?>-qr.png">Download QR code</a></div></div>
    </div></div>
    <?php if (lc_setting($conn, 'contact_phone') || lc_setting($conn, 'contact_whatsapp')) { ?><div class="card mb-3"><div class="card-body small"><div class="fw-bold mb-1">Questions?</div>
        <?php if (lc_setting($conn, 'contact_phone')) { ?><div><a href="tel:<?= lc_e(preg_replace('/\s/', '', lc_setting($conn, 'contact_phone'))); ?>" class="text-decoration-none"><?= lcp_i('phone'); ?> <?= lc_e(lc_setting($conn, 'contact_phone')); ?></a></div><?php } ?>
        <?php if (lc_setting($conn, 'contact_whatsapp')) { ?><div><a href="https://wa.me/<?= lc_e(preg_replace('/\D/', '', lc_setting($conn, 'contact_whatsapp'))); ?>?text=<?= rawurlencode('Hello, I have a question about ' . $c['title'] . ' (' . $c['code'] . ').'); ?>" target="_blank" rel="noopener" class="text-decoration-none"><?= lcp_i('chat'); ?> WhatsApp us</a></div><?php } ?></div></div><?php } ?>
<?php };
$fee = $c['fees_public'] ? ((float) $c['course_fee'] > 0 ? lc_money($c['course_fee']) : ($c['fee_structure'] === 'module' ? 'Per module' : 'Free')) : '';
?>
<section class="lc-hero"><div class="container">
    <div class="small mb-2" style="opacity:.8"><a href="courses.php" class="text-white">Courses</a> / <?= lc_e($c['category']); ?></div>
    <h1><?= lc_e($c['title']); ?></h1>
    <div class="d-flex flex-wrap align-items-center gap-1 mb-1"><span class="badge bg-<?= $av[1]; ?>"><?= lc_e($av[0]); ?></span>
        <span class="small" style="opacity:.9"><?= lc_e($c['code']); ?><?= $c['duration_value'] ? ' · ' . (int) $c['duration_value'] . ' ' . lc_e($c['duration_unit']) : ''; ?> · <?= lc_e(str_replace(',', ', ', (string) $c['modes'])); ?></span></div>
    <?php if ($av[2]) { ?><div class="small" style="opacity:.85"><?= lc_e($av[2]); ?></div><?php } ?>
</div></section>
<div class="container lc-pull"><div class="row g-3 g-lg-4">
<div class="col-lg-4 order-lg-2"><div class="lc-sticky">
    <div class="card mb-3" id="apply"><div class="card-body">
        <?php if ($c['fees_public']) { ?>
        <div class="small text-muted">Tuition</div><div class="fs-3 fw-bold" style="color:var(--d)"><?= $fee; ?></div>
        <div class="small text-muted"><?php foreach (['application_fee' => 'Application fee', 'registration_fee' => 'Registration fee', 'exam_fee' => 'Examination fee'] as $k => $l) { if ((float) $c[$k] > 0) { echo lc_e($l) . ': ' . lc_money($c[$k]) . '<br>'; } } ?></div>
        <?php if ($c['installments_allowed'] || $c['payment_terms']) { ?><div class="small mt-2 d-flex gap-2"><span class="text-primary"><?= lcp_i('wallet'); ?></span><span><?= $c['installments_allowed'] ? 'Payment by instalments possible. ' : ''; ?><?= lc_e($c['payment_terms']); ?></span></div><?php } ?>
        <?php } else { ?><div class="small text-muted">Fees: please contact us.</div><?php } ?>
        <div class="fw-bold mt-3 mb-2">Intakes</div>
        <?php foreach ($intakes as $i) { ?><div class="lc-intake d-block small"><div class="fw-semibold"><?= lc_e($i['name']); ?></div>
            <div class="lc-meta"><?= lcp_i('pin'); ?><?= lc_e($i['branch_name']); ?></div>
            <div class="lc-meta"><?= lcp_i('calendar'); ?>Starts <?= date('d M Y', strtotime($i['start_date'])); ?><?= $i['end_date'] ? ' · ends ' . date('d M Y', strtotime($i['end_date'])) : ''; ?></div>
            <div class="mt-1"><?= $i['can_apply'] ? '<span class="text-success fw-semibold">Applications open</span>' . ($i['apply_close'] ? ' until ' . date('d M Y', strtotime($i['apply_close'])) : '') : '<span class="text-muted">' . ($i['seats_left'] === 0 ? 'Full' : ($i['apply_open'] && $i['apply_open'] > date('Y-m-d') ? 'Applications open on ' . date('d M Y', strtotime($i['apply_open'])) : 'Applications closed')) . '</span>'; ?></div>
            <?= $i['accommodation'] ? '<span class="lc-chip mt-1">' . lcp_i('bed') . 'Accommodation available</span>' : ''; ?>
            <?php if ($c['fees_public'] && $i['course_fee'] !== null && (float) $i['course_fee'] !== (float) $c['course_fee']) { ?><div>Tuition for this intake: <?= lc_money($i['course_fee']); ?></div><?php } ?></div><?php } ?>
        <?php if (!$intakes) { ?><div class="small text-muted">The next intake will be announced soon.</div><?php } ?>
        <?php if ($canApply) { ?><a href="<?= lc_e($applyUrl); ?>" class="btn btn-primary btn-lg w-100 mt-3">Apply now</a><div class="small text-muted text-center mt-1">No account needed · about 5 minutes</div>
        <?php } else { ?><div class="alert alert-secondary small mt-3 mb-0"><?= lc_e($why); ?></div><?php } ?>
    </div></div>
    <div class="d-none d-lg-block"><?php $shareCards(); ?></div>
</div></div>
<div class="col-lg-8 order-lg-1"><div class="card"><div class="card-body">
    <?php if ($c['image_ref']) { ?><div class="lc-ratio rounded-3 mb-3"><img src="<?= lc_e($img); ?>" alt="<?= lc_e($c['title']); ?>" decoding="async"></div><?php } ?>
    <?php if ($c['summary']) { ?><p class="lead mb-2"><?= lc_e($c['summary']); ?></p><?php } ?>
    <?php if (trim((string) $c['description']) !== '') { ?><div style="white-space:pre-line;" class="mb-2"><?= lc_e($c['description']); ?></div><?php } ?>
    <?php $list('What you will learn', $c['outcomes'], true); $list('Objectives', $c['objectives']); $list('Entry requirements', $c['entry_requirements']); ?>
    <?php if ($mods) { ?><details class="lc-sec" data-m><summary><h2>Modules (<?= count($mods); ?>)</h2><?= lcp_i('chevron'); ?></summary><div>
        <?php foreach ($mods as $m) { ?><div class="py-2 border-bottom"><div class="d-flex justify-content-between gap-2"><strong><?= lc_e($m['name']); ?></strong><span class="small text-muted text-nowrap"><?= lc_e($m['duration']); ?><?= $m['hours'] !== null ? ' · ' . (float) $m['hours'] . ' h' : ''; ?></span></div>
        <?php if ($m['description']) { ?><div class="small text-muted"><?= lc_e($m['description']); ?></div><?php } ?><?php if ($m['optional']) { ?><span class="lc-chip">Optional</span><?php } ?>
        <?php if ($c['fees_public'] && !$m['in_bundle'] && (float) $m['fee'] > 0) { ?><span class="lc-chip"><?= lc_money($m['fee']); ?></span><?php } ?></div><?php } ?></div></details><?php } ?>
    <details class="lc-sec" data-m><summary><h2>Assessment and certificate</h2><?= lcp_i('chevron'); ?></summary><div>
        <?php if ($c['assessment_info']) { ?><div style="white-space:pre-line;" class="mb-2"><?= lc_e($c['assessment_info']); ?></div><?php } ?>
        <ul class="lc-list"><li><?= lcp_i('check'); ?><span>Pass mark: <?= (float) $c['pass_mark']; ?>%</span></li><li><?= lcp_i('check'); ?><span>Minimum attendance: <?= (float) $c['attendance_min']; ?>%</span></li><?php if ($c['certificate_type']) { ?><li><?= lcp_i('award'); ?><span>Successful students receive: <?= lc_e($c['certificate_type']); ?></span></li><?php } ?></ul></div></details>
    <?php $list('Documents to prepare', $c['required_documents']); ?>
</div></div></div>
<div class="col-12 d-lg-none order-lg-3"><?php $shareCards(); ?></div>
</div></div>
<?php if ($canApply) { ?><div class="lc-applybar"><div class="p"><?= $fee !== '' ? '<strong>' . $fee . '</strong>' : '<strong>' . lc_e($c['code']) . '</strong>'; ?><?= lc_e($av[2] ?: 'Applications open'); ?></div><a href="<?= lc_e($applyUrl); ?>" class="btn btn-primary">Apply now</a></div><?php } ?>
<script>
(function () {
    if (<?= $canApply ? 'true' : 'false'; ?>) document.body.classList.add('has-applybar');
    // Desktop: every section open. Phone: short page, sections open on tap.
    if (window.matchMedia('(min-width: 992px)').matches) document.querySelectorAll('details.lc-sec[data-m]').forEach(function (d) { d.open = true; });
    var url = <?= json_encode($url); ?>;
    document.querySelectorAll('[data-native]').forEach(function (n) { if (!navigator.share) return; n.classList.remove('d-none'); n.addEventListener('click', function () { navigator.share({ title: <?= json_encode($c['title']); ?>, text: <?= json_encode($share); ?>, url: url }).catch(function () {}); }); });
    document.querySelectorAll('[data-copy]').forEach(function (cp) { cp.addEventListener('click', function () { var done = function () { cp.lastChild.textContent = 'Copied'; }; if (navigator.clipboard) navigator.clipboard.writeText(url).then(done); else { var t = document.createElement('input'); t.value = url; document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove(); done(); } }); });
})();
</script>
<?php lcp_footer($conn); ?>