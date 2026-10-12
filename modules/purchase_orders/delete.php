<?php
/**
 * NEW FILE: modules/purchase_orders/delete.php
 * Deletes a Purchase Order (rules in po_delete(), includes/purchase_order_helpers.php).
 */
require '../../config/db.php';
require_once '../../includes/purchase_order_helpers.php';
require_role(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?error=' . urlencode('Invalid Purchase Order selected.'));
    exit;
}

$userId = current_user_id();
$result = po_delete($conn, (int) $id, $userId ? (int) $userId : null);
header('Location: index.php?' . ($result['ok'] ? 'success=' . urlencode($result['message']) : 'error=' . urlencode($result['error'])));
exit;