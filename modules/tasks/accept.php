<?php
require '../../config/db.php';
// NEW: role check (the page had none of its own). Only the assigned employee can accept, see below.
require_role(['Admin', 'Manager', 'Employee']);

// CHANGED: accepting changes the task, so it only happens on a POST (the Accept button),
// never on a plain link that a browser preview or a shared address could open.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// CHANGED: problems are sent as "error" (they were sent as "success"), and messages are urlencoded.
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid task selected.')); exit; }

$userId = (int) current_user_id();
$statement = mysqli_prepare($conn, 'SELECT id, status FROM tasks WHERE id = ? AND assigned_to = ?');
mysqli_stmt_bind_param($statement, 'ii', $id, $userId);
mysqli_stmt_execute($statement);
$task = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$task) { header('Location: index.php?error=' . urlencode('Task not found or not assigned to you.')); exit; }

// CHANGED: the update itself checks that the task is still Pending and still assigned to this
// employee, so a double click or a reassignment at the same moment cannot change it wrongly.
$update = mysqli_prepare($conn, "UPDATE tasks SET status = 'Accepted', accepted_at = NOW() WHERE id = ? AND assigned_to = ? AND status = 'Pending'");
mysqli_stmt_bind_param($update, 'ii', $id, $userId);
mysqli_stmt_execute($update);

if (mysqli_stmt_affected_rows($update) === 1) {
    header('Location: index.php?success=' . urlencode('Task accepted. You can start working on it.'));
} else {
    header('Location: index.php?error=' . urlencode('This task is no longer pending your acceptance.'));
}
exit;