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

// Look up the current status first, so we know which way to flip it.
$statusStatement = mysqli_prepare($conn, 'SELECT is_active FROM departments WHERE id = ?');
mysqli_stmt_bind_param($statusStatement, 'i', $id);
mysqli_stmt_execute($statusStatement);
$department = mysqli_fetch_assoc(mysqli_stmt_get_result($statusStatement));

if (!$department) {
    header('Location: index.php?success=Department not found.');
    exit;
}

$newStatus = $department['is_active'] ? 0 : 1;

$statement = mysqli_prepare($conn, 'UPDATE departments SET is_active = ? WHERE id = ?');
mysqli_stmt_bind_param($statement, 'ii', $newStatus, $id);
mysqli_stmt_execute($statement);

$message = $newStatus
    ? 'Department reactivated successfully.'
    : 'Department deactivated successfully.';

header('Location: index.php?success=' . urlencode($message));
exit;