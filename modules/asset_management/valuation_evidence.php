<?php
/**
 * NEW FILE: modules/asset_management/valuation_evidence.php
 * Shows the evidence document of an impairment / revaluation to Admins only
 * (files are kept outside public view and never served directly).
 */
require '../../config/db.php';
require_once '../../includes/asset_capital_helpers.php';
require_role(['Admin']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$row = null;
if ($id) {
    $s = mysqli_prepare($conn, 'SELECT v.evidence_file, v.valuation_type, a.asset_code FROM asset_valuations v JOIN assets a ON a.id = v.asset_id WHERE v.id = ?');
    mysqli_stmt_bind_param($s, 'i', $id);
    mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
}
$file = $row && $row['evidence_file'] ? basename($row['evidence_file']) : null;
$path = $file ? ASSET_EVIDENCE_DIR . '/' . $file : null;
if (!$path || !is_file($path)) { http_response_code(404); echo 'Evidence not found.'; exit; }

$ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
$mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'pdf' => 'application/pdf'][$ext] ?? 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9_-]/', '', $row['asset_code'] . '-' . $row['valuation_type']) . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
readfile($path);
exit;