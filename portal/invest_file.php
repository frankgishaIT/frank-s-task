<?php
// NEW FILE: portal/invest_file.php — a photo / document of a PUBLISHED project (or one the customer invested in).
require __DIR__ . '/invest_start.php';
require_once __DIR__ . '/../includes/evidence_store.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$f = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT f.* FROM inv_project_files f JOIN inv_projects p ON p.id = f.project_id WHERE f.id = $id AND (p.status IN ('Published', 'Paused', 'Funding Closed', 'Active', 'Completed')
    OR EXISTS (SELECT 1 FROM inv_applications a WHERE a.project_id = p.id AND a.customer_id = $cid))")) : null;
if (!$f) { http_response_code(404); echo 'File not found.'; exit; }
evidence_output($conn, $f['file_ref'], pathinfo($f['original_name'], PATHINFO_FILENAME));