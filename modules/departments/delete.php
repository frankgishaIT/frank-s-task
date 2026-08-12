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

$statement = mysqli_prepare($conn, 'UPDATE departments SET is_active = 0 WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);

$message = mysqli_stmt_affected_rows($statement) > 0
    ? 'Department deactivated successfully.'
    : 'Department not found or already inactive.';

header('Location: index.php?success=' . urlencode($message));
exit;
