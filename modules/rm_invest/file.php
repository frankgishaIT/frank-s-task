<?php
// NEW FILE: modules/rm_invest/file.php — a project photo / document (staff).
require __DIR__ . '/invest_common.php';
require_once __DIR__ . '/../../includes/evidence_store.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$f = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_project_files WHERE id = $id")) : null;
if (!$f) { http_response_code(404); echo 'File not found.'; exit; }
evidence_output($conn, $f['file_ref'], pathinfo($f['original_name'], PATHINFO_FILENAME));