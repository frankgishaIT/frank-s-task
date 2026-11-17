<?php
/** NEW FILE: modules/learning/file.php — opens an application document (admissions staff of the branch only; logged). */
require __DIR__ . '/common.php';
lcs_require($conn, 'admissions');
require_once __DIR__ . '/../../includes/evidence_store.php';
$id = (int) ($_GET['id'] ?? 0);
$d = mysqli_fetch_assoc(mysqli_query($conn, "SELECT d.*, a.branch_id, a.ref FROM lc_application_docs d JOIN lc_applications a ON a.id = d.application_id WHERE d.id = $id"));
if (!$d || !lcs_branch_ok($conn, (int) $d['branch_id'] ?: null)) { http_response_code(404); echo 'Document not found.'; exit; }
lc_audit($conn, 'document_open', 'application', (int) $d['application_id'], ['document' => $d['label']]);
evidence_output($conn, $d['file_ref'], $d['ref'] . '-' . $d['label']);