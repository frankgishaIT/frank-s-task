<?php
/** NEW FILE: modules/delegations/pay_proof.php — opens the proof of a Tasker payment (Finance / approvers). */
require __DIR__ . '/common.php';
if (!dlc($conn, 'finance') && !dlc($conn, 'approve')) { dl_require($conn, 'finance'); }
$p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_payments WHERE id = ' . (int) ($_GET['id'] ?? 0)));
if (!$p || !$p['proof_ref']) { http_response_code(404); exit('Not found.'); }
require_once __DIR__ . '/../../includes/evidence_store.php';
evidence_output($conn, $p['proof_ref'], 'payment_proof_' . dl_pay_ref((int) $p['id']));