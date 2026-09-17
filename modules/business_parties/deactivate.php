<?php
require '../../config/db.php';
require_role(['Admin', 'Manager']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid business party.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT is_active FROM business_parties WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$party = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$party) { header('Location: index.php?success=Business party not found.'); exit; }

$newStatus = $party['is_active'] ? 0 : 1;
$update = mysqli_prepare($conn, 'UPDATE business_parties SET is_active = ? WHERE id = ?');
mysqli_stmt_bind_param($update, 'ii', $newStatus, $id);
mysqli_stmt_execute($update);

header('Location: index.php?success=' . urlencode($newStatus ? 'Business party reactivated.' : 'Business party deactivated.'));
exit;