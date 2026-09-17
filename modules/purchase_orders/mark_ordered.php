<?php
require '../../config/db.php';
require_role(['Admin', 'Manager']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=Invalid purchase order.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT status FROM purchase_orders WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$po = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$po) { header('Location: index.php?error=Purchase order not found.'); exit; }
if ($po['status'] !== 'Draft') { header('Location: view.php?id=' . $id . '&error=Only Draft purchase orders can be marked as Ordered.'); exit; }

$userId = current_user_id();
$update = mysqli_prepare($conn, "UPDATE purchase_orders SET status = 'Ordered', ordered_by = ?, ordered_at = NOW() WHERE id = ?");
mysqli_stmt_bind_param($update, 'ii', $userId, $id);
mysqli_stmt_execute($update);

header('Location: view.php?id=' . $id . '&success=' . urlencode('Purchase Order marked as Ordered.'));
exit;