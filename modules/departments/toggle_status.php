<?php
require '../../config/db.php';
require_role(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?success=Invalid department selected.');
    exit;
}

$statement = mysqli_prepare($conn, 'SELECT name, is_active FROM departments WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$department = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$department) {
    header('Location: index.php?success=Department not found.');
    exit;
}

$newStatus = $department['is_active'] ? 0 : 1;

$update = mysqli_prepare($conn, 'UPDATE departments SET is_active = ? WHERE id = ?');
mysqli_stmt_bind_param($update, 'ii', $newStatus, $id);
mysqli_stmt_execute($update);

$message = $newStatus
    ? '"' . $department['name'] . '" has been reactivated.'
    : '"' . $department['name'] . '" has been deactivated.';

header('Location: index.php?success=' . urlencode($message));
exit;