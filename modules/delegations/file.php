<?php
/** NEW FILE: modules/delegations/file.php — opens a delegation file (instructions or completion evidence) for staff. */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$f = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_files WHERE id = ' . (int) ($_GET['id'] ?? 0)));
if (!$f) { http_response_code(404); exit('File not found.'); }
require_once __DIR__ . '/../../includes/evidence_store.php';
evidence_output($conn, $f['ref'], pathinfo((string) $f['name'], PATHINFO_FILENAME) ?: 'delegation_file');