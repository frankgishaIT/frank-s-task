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

if ((int) $id === (int) current_user_id()) {
    header('Location: index.php?success=' . urlencode('You cannot delete your own account.'));
    exit;
}

/**
 * Every place an employee's history could live across the system.
 * If any of these have rows for this user, deleting them would either
 * fail on a foreign key or silently erase real business history — so
 * we check first and block with a clear reason instead.
 */
$checks = [
    'attendance record(s)' => "SELECT COUNT(*) AS c FROM attendance WHERE user_id = $id OR manager_id = $id OR confirmed_by = $id",
    'leave request(s)' => "SELECT COUNT(*) AS c FROM leave_requests WHERE user_id = $id OR manager_id = $id OR hr_id = $id",
    'task(s)' => "SELECT COUNT(*) AS c FROM tasks WHERE assigned_to = $id OR reviewed_by = $id",
    'project(s) created' => "SELECT COUNT(*) AS c FROM projects WHERE created_by = $id",
    'transaction(s)' => "SELECT COUNT(*) AS c FROM transactions WHERE recorded_by = $id",
    'payroll record(s)' => "SELECT COUNT(*) AS c FROM payroll WHERE user_id = $id",
    'sale(s)' => "SELECT COUNT(*) AS c FROM sales WHERE recorded_by = $id OR discount_requested_by = $id OR discount_approved_by = $id",
];

$reasons = [];
foreach ($checks as $label => $query) {
    $count = (int) mysqli_fetch_assoc(mysqli_query($conn, $query))['c'];
    if ($count > 0) {
        $reasons[] = $count . ' ' . $label;
    }
}

if (!empty($reasons)) {
    $message = 'Cannot delete this employee — they still have ' . implode(', ', $reasons) . ' in the system. Use Deactivate instead to preserve this history.';
    header('Location: index.php?success=' . urlencode($message));
    exit;
}

$statement = mysqli_prepare($conn, 'DELETE FROM users WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);

$message = mysqli_stmt_affected_rows($statement) > 0
    ? 'Employee deleted permanently.'
    : 'Employee not found.';

header('Location: index.php?success=' . urlencode($message));
exit;
