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

// Safety check: never permanently delete a department that still has
// employees attached to it — they must be moved or removed first.
$employeeCheck = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM users WHERE department_id = ?');
mysqli_stmt_bind_param($employeeCheck, 'i', $id);
mysqli_stmt_execute($employeeCheck);
$employeeCount = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($employeeCheck))['c'];

if ($employeeCount > 0) {
    $message = 'Cannot delete this department — it still has ' . $employeeCount . ' employee(s) assigned to it. Reassign or remove them first.';
    header('Location: index.php?success=' . urlencode($message));
    exit;
}

$statement = mysqli_prepare($conn, 'DELETE FROM departments WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);

$message = mysqli_stmt_affected_rows($statement) > 0
    ? 'Department deleted permanently.'
    : 'Department not found.';

header('Location: index.php?success=' . urlencode($message));
exit;