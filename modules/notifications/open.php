<?php
require '../../config/db.php';
require_role(['Admin', 'Manager', 'Employee']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$userId = current_user_id();

if ($id) {
    $statement = mysqli_prepare($conn, 'SELECT link FROM notifications WHERE id = ? AND user_id = ?');
    mysqli_stmt_bind_param($statement, 'ii', $id, $userId);
    mysqli_stmt_execute($statement);
    $notif = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

    if ($notif) {
        $update = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        mysqli_stmt_bind_param($update, 'ii', $id, $userId);
        mysqli_stmt_execute($update);

        header('Location: ' . ($notif['link'] ?: 'index.php'));
        exit;
    }
}

header('Location: index.php');
exit;