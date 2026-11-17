<?php
/**
 * NEW FILE: learn/verify.php — PUBLIC CERTIFICATE VERIFICATION.
 * From the QR code (number + secret token) or by hand (certificate number + 8-character verification code).
 * Shows only what is printed on the certificate, and its current status (Valid / Revoked / Replaced).
 */
require __DIR__ . '/lc_public.php';
require_once __DIR__ . '/../includes/lc_academic.php';
lc_acad_ensure($conn);
$no = strtoupper(trim((string) ($_GET['c'] ?? $_POST['c'] ?? '')));
$tok = trim((string) ($_GET['t'] ?? '')); $code = strtoupper(preg_replace('/\s/', '', (string) ($_POST['code'] ?? '')));
$cert = null; $err = '';
if ($no !== '' && ($tok !== '' || $code !== '')) {
    if (!lc_rate_ok($conn, 'verify', 30, 15)) { $err = 'Too many attempts. Please wait 15 minutes.'; }
    else {
        lc_rate_hit($conn, 'verify');
        $s = mysqli_prepare($conn, 'SELECT * FROM lc_certificates WHERE number = ?'); mysqli_stmt_bind_param($s, 's', $no); mysqli_stmt_execute($s);
        $c = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        $ok = $c && ($tok !== '' ? hash_equals($c['token'], $tok) : hash_equals(lc_cert_code($c), $code));
        if ($ok) { $cert = $c; } else { $err = 'No certificate matches this number and code. Check both, or contact us.'; }
    }
}
lcp_header($conn, ['title' => 'Verify a certificate', 'url' => lc_public_base($conn) . '/verify.php']);
?>
<div class="container py-4" style="max-width:720px;">
<?php if ($cert) {
    $ok = $cert['status'] === 'Valid';
    $new = $cert['replaced_by_id'] ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT number FROM lc_certificates WHERE id = ' . (int) $cert['replaced_by_id'])) : null; ?>
    <div class="card"><div class="card-body p-4">
        <div class="text-center mb-3"><div class="display-6 <?= $ok ? 'text-success' : 'text-danger'; ?>"><i class="bi bi-<?= $ok ? 'patch-check-fill' : 'x-octagon-fill'; ?>"></i></div>
            <h1 class="h4 fw-bold mb-0 <?= $ok ? 'text-success' : 'text-danger'; ?>"><?= $ok ? 'Valid certificate' : ($cert['status'] === 'Revoked' ? 'This certificate was REVOKED' : 'This certificate was REPLACED and is no longer valid'); ?></h1>
            <div class="small text-muted">Issued by RISE MOTIVE Ltd · <?= lc_e(lc_setting($conn, 'title')); ?></div></div>
        <table class="table table-sm mb-0">
            <tr><th style="width:40%">Certificate number</th><td class="font-monospace"><?= lc_e($cert['number']); ?></td></tr>
            <tr><th>Awarded to</th><td><?= lc_e($cert['student_name']); ?></td></tr>
            <tr><th>Course</th><td><?= lc_e($cert['course_title']); ?></td></tr>
            <?php if ($cert['grade']) { ?><tr><th>Grade</th><td><?= lc_e($cert['grade']); ?></td></tr><?php } ?>
            <tr><th>Completed on</th><td><?= $cert['completed_on'] ? date('d F Y', strtotime($cert['completed_on'])) : '—'; ?></td></tr>
            <tr><th>Issued on</th><td><?= date('d F Y', strtotime($cert['issued_at'])); ?></td></tr>
            <tr><th>Status</th><td><strong class="<?= $ok ? 'text-success' : 'text-danger'; ?>"><?= lc_e($cert['status']); ?></strong><?= $cert['status'] === 'Revoked' && $cert['revoked_at'] ? ' on ' . date('d M Y', strtotime($cert['revoked_at'])) : ''; ?>
                <?= $new ? '<div class="small">Replaced by certificate ' . lc_e($new['number']) . '.</div>' : ''; ?><?= $cert['status'] === 'Revoked' && $cert['reason'] ? '<div class="small">Reason: ' . lc_e($cert['reason']) . '</div>' : ''; ?></td></tr>
        </table>
        <?php if (lc_setting($conn, 'accreditation_verified') === '1' && lc_setting($conn, 'accreditation_note') !== '') { ?><div class="small text-muted mt-2"><?= lc_e(lc_setting($conn, 'accreditation_note')); ?></div><?php } ?>
        <div class="text-center mt-3"><a href="verify.php" class="btn btn-sm btn-light border">Verify another certificate</a></div>
    </div></div>
<?php } else { ?>
    <div class="card"><div class="card-body p-4">
        <h1 class="h4 fw-bold">Verify a certificate</h1>
        <p class="small text-muted">Scan the QR code on the certificate, or enter its number and the 8-character verification code printed under it.</p>
        <?php if ($err) { ?><div class="alert alert-danger small"><?= lc_e($err); ?></div><?php } ?>
        <form method="POST" class="row g-2"><div class="col-md-6"><label class="form-label small fw-semibold">Certificate number</label><input name="c" class="form-control font-monospace text-uppercase" required maxlength="40" value="<?= lc_e($no); ?>" placeholder="RMLC-C<?= date('Y'); ?>-00001"></div>
            <div class="col-md-4"><label class="form-label small fw-semibold">Verification code</label><input name="code" class="form-control font-monospace text-uppercase" required maxlength="10"></div>
            <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100">Verify</button></div></form>
    </div></div>
<?php } ?>
</div>
<?php lcp_footer($conn); ?>