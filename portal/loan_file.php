<?php
// NEW FILE: portal/loan_file.php — opens a document of the customer's OWN loan application.
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/cl_helpers.php';
require_once __DIR__ . '/../includes/evidence_store.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$d = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT d.* FROM cl_documents d JOIN cl_applications a ON a.id = d.application_id WHERE d.id = $id AND a.customer_id = $cid")) : null;
if (!$d) { http_response_code(404); echo 'Document not found.'; exit; }
evidence_output($conn, $d['file_ref'], pathinfo($d['original_name'], PATHINFO_FILENAME));