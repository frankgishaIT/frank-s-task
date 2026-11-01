<?php
// NEW FILE: portal/document_file.php — opens a published document, ONLY for the customer it was published to.
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/evidence_store.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$s = mysqli_prepare($conn, 'SELECT * FROM portal_documents WHERE id = ? AND customer_id = ? AND unpublished_at IS NULL');
mysqli_stmt_bind_param($s, 'ii', $id, $cid);
mysqli_stmt_execute($s);
$d = $id ? mysqli_fetch_assoc(mysqli_stmt_get_result($s)) : null;
if (!$d) { http_response_code(404); echo 'Document not found.'; exit; }
portal_log($conn, $cid, 'document_opened', $d['title']);
evidence_output($conn, $d['file_ref'], pathinfo($d['original_name'], PATHINFO_FILENAME));