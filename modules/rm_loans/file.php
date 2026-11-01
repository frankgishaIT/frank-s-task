<?php
// NEW FILE: modules/rm_loans/file.php — a document of a loan application (staff with RM Loan Management access).
require __DIR__ . '/loans_common.php';
require_once __DIR__ . '/../../includes/evidence_store.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$d = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_documents WHERE id = $id")) : null;
if (!$d) { http_response_code(404); echo 'Document not found.'; exit; }
cl_audit($conn, (int) $d['application_id'], 'Document opened: ' . $d['original_name'], null, null, null, $me);
evidence_output($conn, $d['file_ref'], pathinfo($d['original_name'], PATHINFO_FILENAME));