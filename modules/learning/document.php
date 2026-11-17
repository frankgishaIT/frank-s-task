<?php
/** NEW FILE: modules/learning/document.php — PDF: certificate (?type=certificate&id=) or transcript (?type=transcript&enrollment=), provisional until results are published. */
require __DIR__ . '/common.php';
$type = (string) ($_GET['type'] ?? '');
if ($type === 'certificate') {
    $c = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM lc_certificates WHERE id = ' . (int) ($_GET['id'] ?? 0)));
    $k = $c ? lc_class($conn, (int) $c['class_id']) : null;
    if (!$c || !$k || !in_array(lcs_class_role($conn, $k), ['manage', 'view'], true)) { lcs_deny(); }
    lc_audit($conn, 'certificate_open', 'certificate', (int) $c['id'], null, (int) $c['customer_id']);
    lc_pdf_output(lc_certificate_html($conn, $c), 'certificate-' . $c['number'] . '.pdf', true);
    exit;
}
if ($type === 'transcript') {
    $eid = (int) ($_GET['enrollment'] ?? 0);
    $e = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_enrollments WHERE id = $eid"));
    if (!$e) { lcs_deny(); }
    $k = $e['class_id'] ? lc_class($conn, (int) $e['class_id']) : null;
    $ok = $k ? lcs_class_role($conn, $k) !== null : (lcs_can($conn, 'view') && lcs_branch_ok($conn, $e['branch_id'] ? (int) $e['branch_id'] : null));
    if (!$ok) { lcs_deny(); }
    lc_audit($conn, 'transcript_open', 'enrollment', $eid, null, (int) $e['customer_id']);
    lc_pdf_output(lc_transcript_html($conn, $eid), 'transcript-' . lc_customer_id_label($conn, (int) $e['customer_id']) . '.pdf');
    exit;
}
if ($type === 'admission') {
    // NEW (stage 3): admission letter (admitted applicants only)
    lcs_require($conn, 'admissions');
    $a = lc_application($conn, (int) ($_GET['application'] ?? 0));
    if (!$a || !lcs_branch_ok($conn, (int) $a['branch_id'] ?: null)) { lcs_deny(); }
    try { $html = lc_admission_letter_html($conn, $a); } catch (RuntimeException $e) { lcs_go('application.php?id=' . (int) ($a['id'] ?? 0), 'error', $e->getMessage()); }
    lc_audit($conn, 'admission_letter', 'application', (int) $a['id']);
    lc_pdf_output($html, 'admission-' . $a['ref'] . '.pdf');
    exit;
}
lcs_go('index.php', 'error', 'Unknown document.');