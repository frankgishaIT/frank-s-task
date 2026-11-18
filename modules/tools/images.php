<?php
/** NEW FILE: modules/tools/images.php — IMAGE GUIDELINES (every signed-in employee): recommended size and shape per kind of picture. */
require '../../config/db.php';
require_once '../../includes/image_kit.php';
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<h3 class="mb-1">Image guidelines</h3>
<div class="small text-muted mb-3">Each kind of picture has its own size and shape. When you upload one, MY MOTIVE shows a preview in the right shape: move and zoom the picture so the important part stays visible. Pictures are then resized and compressed automatically, and shown without stretching on computers, tablets and phones.</div>
<div class="row g-3">
<?php foreach (IMG_SPECS as $k => $s) { $r = img_ratio($s['ratios'][0]); ?>
    <div class="col-md-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex gap-3">
        <div style="width:84px;flex-shrink:0;"><div style="width:<?= $r ? ($r >= 1 ? 84 : (int) round(84 * $r)) : 60; ?>px;height:<?= $r ? ($r >= 1 ? (int) round(84 / $r) : 84) : 80; ?>px;background:#EEF3FF;border:1.5px dashed #1E2FE0;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:11px;color:#0B1A8C;font-weight:700;"><?= $h($s['ratios'][0] === 'free' ? 'any' : $s['ratios'][0]); ?></div></div>
        <div style="font-size:13px;"><div class="fw-semibold"><?= $h($s['label']); ?></div>
            <div><?= $h($s['h'] ? $s['w'] . ' × ' . $s['h'] . ' px' : 'at least ' . $s['min'][0] . ' px wide (saved up to ' . $s['w'] . ' px)'); ?><?= count($s['ratios']) > 1 ? ' · shapes: ' . $h(implode(', ', $s['ratios'])) : ''; ?></div>
            <div class="text-muted">Minimum <?= $h($s['min'][0] . ($s['min'][1] ? ' × ' . $s['min'][1] : '') . ' px'); ?> · <?= $h(strtoupper(implode(', ', $s['types']))); ?> · max <?= (int) $s['mb']; ?> MB</div>
            <div class="text-muted mt-1"><?= $h($s['use']); ?></div></div>
    </div></div></div>
<?php } ?>
</div>
<div class="card border-0 shadow-sm mt-3"><div class="card-body small"><div class="fw-semibold mb-1">Tips</div>
    <ul class="mb-0"><li>Use the largest, sharpest original you have: a small picture cannot be made sharp later.</li><li>Keep faces, text and logos away from the edges — phones may show a narrower part of wide banners.</li>
        <li>Photos are compressed to JPG automatically; logos and signatures keep a transparent PNG background.</li><li>Scans and receipts are never cropped: use PDF for documents with several pages.</li></ul></div></div>
<?php include '../../includes/footer.php'; ?>