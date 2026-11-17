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
lcs_go('index.php', 'error', 'Unknown document.');