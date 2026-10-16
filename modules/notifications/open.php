<?php
/**
 * modules/notifications/open.php
 * Opens a notification from the bell menu or the list: marks it as read (only your own) and shows it.
 * Marking as read on opening is a normal, harmless effect of viewing, like opening an email.
 */
require '../../config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid notification.')); exit; }
$userId = (int) current_user_id();

$s = mysqli_prepare($conn, 'SELECT id, user_id FROM notifications WHERE id = ?');
mysqli_stmt_bind_param($s, 'i', $id);
mysqli_stmt_execute($s);
$n = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
if (!$n) { header('Location: index.php?error=' . urlencode('That notification no longer exists.')); exit; }

if ((int) $n['user_id'] === $userId) {
    $u = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
    mysqli_stmt_bind_param($u, 'ii', $id, $userId);
    mysqli_stmt_execute($u);
}

header('Location: view.php?id=' . $id);
exit;