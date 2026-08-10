<?php
require '../../config/db.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid task selected.'); exit; }

$userId = current_user_id();
$statement = mysqli_prepare($conn, 'SELECT * FROM tasks WHERE id = ? AND assigned_to = ?');
mysqli_stmt_bind_param($statement, 'ii', $id, $userId);
mysqli_stmt_execute($statement);
$task = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$task) { header('Location: index.php?success=Task not found or not assigned to you.'); exit; }

if ($task['status'] === 'Accepted') {
    $update = mysqli_prepare($conn, "UPDATE tasks SET status = 'In Progress' WHERE id = ?");
    mysqli_stmt_bind_param($update, 'i', $id);
    mysqli_stmt_execute($update);
    header('Location: index.php?success=Task marked as in progress.');
    exit;
}

header('Location: index.php?success=This task cannot be started from its current status.');
exit;
