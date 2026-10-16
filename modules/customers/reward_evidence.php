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
// CHANGED: documents are read from the database (older ones from their folder).
evidence_output($conn, $r['evidence_file'] ?? null, reward_code((int) $id), REWARD_EVIDENCE_DIR);