<?php
/**
 * NEW FILE: portal/learning_doc.php — the student's OWN documents in the Customer Portal: transcript (provisional
 * until results are published, then final) and certificate PDF. Only for registered students, only their own records.
 */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/lc_academic.php';
lc_acad_ensure($conn);
if (!lc_is_student($conn, $cid)) { header('Location: learning.php?tab=learning'); exit; }
$type = (string) ($_GET['type'] ?? '');
if ($type === 'transcript') {
    $eid = (int) ($_GET['enrollment'] ?? 0);
    $own = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM lc_enrollments WHERE id = $eid AND customer_id = $cid"));
    if (!$own) { header('Location: learning.php?tab=learning'); exit; }
    lc_audit($conn, 'transcript_download', 'enrollment', $eid, ['portal' => true], $cid);
    lc_pdf_output(lc_transcript_html($conn, $eid), 'transcript-' . lc_customer_id_label($conn, $cid) . '.pdf');
    exit;
}
if ($type === 'certificate') {
    $c = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM lc_certificates WHERE id = ' . (int) ($_GET['id'] ?? 0) . " AND customer_id = $cid AND status = 'Valid'"));
    if (!$c) { header('Location: learning.php?tab=learning'); exit; }
    lc_audit($conn, 'certificate_download', 'certificate', (int) $c['id'], ['portal' => true], $cid);
    lc_pdf_output(lc_certificate_html($conn, $c), 'certificate-' . $c['number'] . '.pdf', true);
    exit;
}
header('Location: learning.php?tab=learning');