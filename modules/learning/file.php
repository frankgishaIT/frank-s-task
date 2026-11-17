<?php
/**
 * NEW FILE: modules/learning/file.php — opens an application document (admissions staff of the branch only; logged).
 * CHANGED (stage 4): also an internship applicant's CV (?internship=ID) for HR / internship officers and the supervisor.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/evidence_store.php';
if (!empty($_GET['internship'])) {
    require_once __DIR__ . '/../../includes/lc_internship.php';
    lc_int_ensure($conn);
    $a = lc_int_app($conn, (int) $_GET['internship']);
    $ok = $a && $a['cv_ref'] && ((lcs_can($conn, 'internship') && lcs_branch_ok($conn, (int) $a['branch_id'] ?: null)) || (int) $a['supervisor_user_id'] === (int) current_user_id());
    if (!$ok) { http_response_code(404); echo 'Document not found.'; exit; }
    lc_audit($conn, 'internship_cv_open', 'internship', (int) $a['id']);
    evidence_output($conn, $a['cv_ref'], $a['ref'] . '-CV');
    exit;
}
lcs_require($conn, 'admissions');
$id = (int) ($_GET['id'] ?? 0);
$d = mysqli_fetch_assoc(mysqli_query($conn, "SELECT d.*, a.branch_id, a.ref FROM lc_application_docs d JOIN lc_applications a ON a.id = d.application_id WHERE d.id = $id"));
if (!$d || !lcs_branch_ok($conn, (int) $d['branch_id'] ?: null)) { http_response_code(404); echo 'Document not found.'; exit; }
lc_audit($conn, 'document_open', 'application', (int) $d['application_id'], ['document' => $d['label']]);
evidence_output($conn, $d['file_ref'], $d['ref'] . '-' . $d['label']);