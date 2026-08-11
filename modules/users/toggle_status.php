<?php
require '../../config/db.php';
require_role(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?success=Invalid employee selected.');
    exit;
}

$statement = mysqli_prepare($conn, 'SELECT names, is_active FROM users WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$user) {
    header('Location: index.php?success=Employee not found.');
    exit;
}

$newStatus = $user['is_active'] ? 0 : 1;

$update = mysqli_prepare($conn, 'UPDATE users SET is_active = ? WHERE id = ?');
mysqli_stmt_bind_param($update, 'ii', $newStatus, $id);
mysqli_stmt_execute($update);

$message = $newStatus
    ? '"' . $user['names'] . '" has been reactivated.'
    : '"' . $user['names'] . '" has been deactivated.';

header('Location: index.php?success=' . urlencode($message));
exit;