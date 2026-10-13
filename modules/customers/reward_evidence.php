<?php
/**
 * NEW FILE: modules/customers/reward_evidence.php
 * Shows a reward's evidence file to Admins and Managers only (files are not served directly).
 */
require '../../config/db.php';
require_once '../../includes/reward_helpers.php';
require_role(['Admin', 'Manager']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$r = $id ? reward_get($conn, (int) $id) : null;
$file = $r && $r['evidence_file'] ? basename($r['evidence_file']) : null;
$path = $file ? REWARD_EVIDENCE_DIR . '/' . $file : null;
if (!$path || !is_file($path)) { http_response_code(404); echo 'Evidence not found.'; exit; }

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . reward_code((int) $id) . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;