<?php
/**
 * NEW FILE: portal/learning_doc.php — the student's OWN documents in the Customer Portal: transcript (provisional
 * until results are published, then final) and certificate PDF. Only for registered students, only their own records.
 */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/lc_finance.php'; // CHANGED (stage 3)
lc_fin_ensure($conn);
$type = (string) ($_GET['type'] ?? '');
if ($type === 'admission') {
    // NEW (stage 3): the applicant's own admission letter (application linked to this customer, status Accepted)
    $s = mysqli_prepare($conn, 'SELECT id FROM lc_applications WHERE ref = ? AND customer_id = ?'); $ref = (string) ($_GET['app'] ?? ''); mysqli_stmt_bind_param($s, 'si', $ref, $cid); mysqli_stmt_execute($s);
    $aid = (int) (mysqli_fetch_row(mysqli_stmt_get_result($s))[0] ?? 0); $a = $aid ? lc_application($conn, $aid) : null;
    if (!$a || $a['status'] !== 'Accepted') { header('Location: learning.php'); exit; }
    lc_audit($conn, 'admission_letter_download', 'application', $aid, ['portal' => true], $cid);
    lc_pdf_output(lc_admission_letter_html($conn, $a), 'admission-' . $a['ref'] . '.pdf');
    exit;
}
if (!lc_is_student($conn, $cid)) { header('Location: learning.php?tab=learning'); exit; }
if ($type === 'transcript') {
    $eid = (int) ($_GET['enrollment'] ?? 0);
    $own = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM lc_enrollments WHERE id = $eid AND customer_id = $cid"));
    if (!$own) { header('Location: learning.php?tab=learning'); exit; }
    // NEW (stage 3): clearance policy may hold back the FINAL transcript while fees are overdue (the provisional one stays available)
    $eRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT class_id FROM lc_enrollments WHERE id = $eid"));
    $isFinal = $eRow && $eRow['class_id'] && lc_published_result($conn, (int) $eRow['class_id'], $cid);
    if ($isFinal && !lc_clearance_ok($conn, $cid, 'documents')) { header('Location: learning.php?tab=learning&error=' . urlencode('Your final transcript is available once the overdue fees are paid.')); exit; }
    lc_audit($conn, 'transcript_download', 'enrollment', $eid, ['portal' => true], $cid);
    lc_pdf_output(lc_transcript_html($conn, $eid), 'transcript-' . lc_customer_id_label($conn, $cid) . '.pdf');
    exit;
}
if ($type === 'certificate') {
    $c = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM lc_certificates WHERE id = ' . (int) ($_GET['id'] ?? 0) . " AND customer_id = $cid AND status = 'Valid'"));
    if (!$c) { header('Location: learning.php?tab=learning'); exit; }
    if (!lc_clearance_ok($conn, $cid, 'documents')) { header('Location: learning.php?tab=learning&error=' . urlencode('Your certificate is available once the overdue fees are paid.')); exit; }
    lc_audit($conn, 'certificate_download', 'certificate', (int) $c['id'], ['portal' => true], $cid);
    lc_pdf_output(lc_certificate_html($conn, $c), 'certificate-' . $c['number'] . '.pdf', true);
    exit;
}
header('Location: learning.php?tab=learning');