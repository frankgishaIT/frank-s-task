<?php
// NEW FILE: portal/ebm_file.php — downloads the issued EBM receipt of the customer's OWN request.
ob_start();
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/ebm_helpers.php';
require_once __DIR__ . '/../includes/evidence_store.php';
ebm_ensure($conn);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$r = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM ebm_requests WHERE id = $id AND customer_id = $cid AND status = 'Issued' AND receipt_ref IS NOT NULL")) : null;
if (!$r) { ob_end_clean(); http_response_code(404); echo 'EBM receipt not found.'; exit; }
portal_log($conn, $cid, 'ebm_downloaded', ebm_ref((int) $r['id']));
evidence_output($conn, $r['receipt_ref'], 'EBM-receipt-' . portal_sale_number((int) $r['sale_id']), null, true);