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
// CHANGED: documents are read from the database (older ones from their folder).
evidence_output($conn, $row['evidence_file'] ?? null, ($row['asset_code'] ?? 'asset') . '-' . ($row['valuation_type'] ?? 'valuation'), ASSET_EVIDENCE_DIR);