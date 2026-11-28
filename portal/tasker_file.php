<?php
// NEW FILE: portal/tasker_file.php — a file of one of MY tasks (RM Tasker). Never another Tasker's.
require_once __DIR__ . '/../includes/portal_helpers.php';
$conn = portal_boot();
require_once __DIR__ . '/../includes/dl_portal.php';
$t = dlp_require($conn);
$d = dlp_own($conn, $t, (string) ($_GET['ref'] ?? ''));
$f = $d ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_files WHERE id = ' . (int) ($_GET['f'] ?? 0) . ' AND delegation_id = ' . (int) $d['id'])) : null;
if (!$f) { http_response_code(404); exit('File not found.'); }
require_once __DIR__ . '/../includes/evidence_store.php';
evidence_output($conn, $f['ref'], pathinfo((string) $f['name'], PATHINFO_FILENAME) ?: 'task_file');