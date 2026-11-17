<?php
/**
 * NEW FILE: modules/learning/certificates.php — CERTIFICATES register: search, open (PDF with QR code), revoke with a
 * reason, reissue (new number; the old one becomes "Replaced"). Every action is in the audit trail; the public
 * verification page shows the current status of any certificate.
 */
require __DIR__ . '/common.php';
lcs_require($conn, 'academic');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('certificates.php', 'error', 'The page had expired.'); }
    $cid = (int) ($_POST['cert_id'] ?? 0);
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_certificates WHERE id = $cid"));
    if (!$c) { lcs_go('certificates.php', 'error', 'Certificate not found.'); }
    $k = lc_class($conn, (int) $c['class_id']);
    if (!$k || lcs_class_role($conn, $k) !== 'manage') { lcs_deny(); }
    try {
        if (($_POST['do'] ?? '') === 'revoke') { lc_revoke_certificate($conn, $c, (string) ($_POST['reason'] ?? '')); lcs_go('certificates.php?q=' . rawurlencode($c['number']), 'success', 'Certificate ' . $c['number'] . ' revoked.'); }
        if (($_POST['do'] ?? '') === 'reissue') {
            if ($c['status'] === 'Replaced') { throw new RuntimeException('This certificate was already replaced.'); }
            $why = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 300);
            if (mb_strlen($why) < 5) { throw new RuntimeException('Please give the reason of the reissue (e.g. name corrected).'); }
            if ($c['status'] === 'Valid') { mysqli_query($conn, "UPDATE lc_certificates SET reason = '" . mysqli_real_escape_string($conn, $why) . "' WHERE id = $cid"); }
            $n = lc_issue_certificate($conn, $k, (int) $c['customer_id'], $c);
            lcs_go('certificates.php?q=' . rawurlencode($n['number']), 'success', 'New certificate ' . $n['number'] . ' issued; ' . $c['number'] . ' is now marked Replaced.');
        }
    } catch (RuntimeException $e) { lcs_go('certificates.php?q=' . rawurlencode($c['number']), 'error', $e->getMessage()); }
}
$q = trim((string) ($_GET['q'] ?? ''));
$w = [lcs_branch_sql($conn, 'k.branch_id')];
if ($q !== '') { $x = mysqli_real_escape_string($conn, $q); $w[] = "(z.number LIKE '%$x%' OR z.student_name LIKE '%$x%' OR z.customer_id = " . (int) preg_replace('/\D/', '', $q) . ')'; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT z.*, k.code class_code FROM lc_certificates z JOIN lc_classes k ON k.id = z.class_id WHERE ' . implode(' AND ', $w) . ' ORDER BY z.id DESC LIMIT 500'), MYSQLI_ASSOC);
$byId = array_column($rows, 'number', 'id');
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'certificates.php', 'Certificates');
?>
<form class="row g-2 mb-3"><div class="col-md-6"><input name="q" value="<?= lc_e($q); ?>" class="form-control form-control-sm" placeholder="Certificate number, student name or Student ID"></div><div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Search</button></div></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Number</th><th>Student</th><th>Course</th><th>Class</th><th>Grade</th><th>Issued</th><th>Status</th><th>Actions</th></tr>
    <?php foreach ($rows as $r) { ?><tr><td class="ps-3 fw-semibold"><a href="document.php?type=certificate&id=<?= (int) $r['id']; ?>" target="_blank"><?= lc_e($r['number']); ?></a></td>
        <td><?= lc_e($r['student_name']); ?><div class="small text-muted"><?= lc_e(lc_customer_id_label($conn, (int) $r['customer_id'])); ?></div></td><td class="small"><?= lc_e($r['course_title']); ?></td><td class="small"><a href="class.php?id=<?= (int) $r['class_id']; ?>&tab=results"><?= lc_e($r['class_code']); ?></a></td>
        <td><?= lc_e($r['grade']); ?></td><td class="small"><?= lc_e(substr($r['issued_at'], 0, 10)); ?></td>
        <td><?= lcs_badge($r['status']); ?><?php if ($r['reason']) { ?><div class="small text-muted"><?= lc_e($r['reason']); ?></div><?php } ?><?php if ($r['replaced_by_id']) { ?><div class="small">→ <?= lc_e($byId[(int) $r['replaced_by_id']] ?? '#' . $r['replaced_by_id']); ?></div><?php } ?><?php if ($r['replaces_id']) { ?><div class="small text-muted">replaces <?= lc_e($byId[(int) $r['replaces_id']] ?? '#' . $r['replaces_id']); ?></div><?php } ?></td>
        <td class="text-nowrap"><a class="small" href="<?= lc_e(lc_cert_verify_url($conn, $r)); ?>" target="_blank">Verify page</a>
            <?php if ($r['status'] !== 'Replaced') { ?>
            <form method="POST" class="d-inline" onsubmit="var x=prompt('Reason of the reissue (e.g. name corrected):');if(!x)return false;this.reason.value=x;return true;"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="reissue"><input type="hidden" name="cert_id" value="<?= (int) $r['id']; ?>"><input type="hidden" name="reason"><button class="btn btn-link btn-sm p-0 ms-2">Reissue</button></form><?php } ?>
            <?php if ($r['status'] === 'Valid') { ?>
            <form method="POST" class="d-inline" onsubmit="var x=prompt('Reason of the revocation (shown on the verification page):');if(!x)return false;this.reason.value=x;return true;"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="revoke"><input type="hidden" name="cert_id" value="<?= (int) $r['id']; ?>"><input type="hidden" name="reason"><button class="btn btn-link btn-sm p-0 ms-2 text-danger">Revoke</button></form><?php } ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">No certificate yet. Certificates are issued from a class's Results tab after the results are published.</td></tr><?php } ?>
</table></div></div></div>
<div class="small text-muted mt-2">Anyone can check a certificate on the public verification page (QR code on the certificate, or number + verification code). National accreditation is shown on certificates only when "Accreditation verified" is ticked in Settings.</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>