<?php
require '../../config/db.php';
require '../../includes/purchase_order_helpers.php';   // NEW: loads the Capital Fund integration
require_role(['Admin', 'Manager']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=Invalid purchase order.'); exit; }

// CHANGED: po_mark_ordered() checks the order is a Draft, marks it Ordered and takes the
// order total out of the RM Capital Fund, all in one transaction. If the Capital Fund
// cannot cover the amount, the order stays a Draft and the reason is shown.
$result = po_mark_ordered($conn, $id, current_user_id());

if ($result['ok']) {
    header('Location: view.php?id=' . $id . '&success=' . urlencode('Purchase Order marked as Ordered. The amount was taken from the RM Capital Fund.'));
} else {
    header('Location: view.php?id=' . $id . '&error=' . urlencode($result['error']));
}
exit;