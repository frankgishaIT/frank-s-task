<?php
/**
 * NEW FILE: modules/notifications/action.php
 * All changes to notifications, as POST buttons (never plain links):
 *   mark_one     mark one of MY notifications as read
 *   mark_all     mark ALL my notifications as read
 *   delete_one   delete one notification (my own; an Admin may delete anyone's)
 *   delete_read  delete all my notifications that are already read
 */
require '../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }

$userId = (int) current_user_id();
$isAdmin = current_user_role() === 'Admin';
$action = $_POST['action'] ?? '';
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
// Back to the same list view (only a local index.php address is accepted).
$back = (string) ($_POST['back'] ?? 'index.php');
if (!preg_match('/^index\.php(\?[A-Za-z0-9_=&%.-]*)?$/', $back)) { $back = 'index.php'; }
$go = function (string $type, string $msg) use ($back) {
    header('Location: ' . $back . (strpos($back, '?') === false ? '?' : '&') . $type . '=' . urlencode($msg));
    exit;
};

// CHANGED (Task 2B): only Administrators may delete notifications. Employees (and Managers) can read and
// mark them as read, but a notification sent to them cannot be deleted, even by a manually sent request.
if (in_array($action, ['delete_one', 'delete_read'], true) && !$isAdmin) {
    $go('error', 'Only an Administrator can delete notifications.');
}

switch ($action) {
    case 'mark_one':
        if (!$id) { $go('error', 'Invalid notification.'); }
        $s = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?');
        mysqli_stmt_bind_param($s, 'ii', $id, $userId);
        mysqli_stmt_execute($s);
        $go('success', 'Notification marked as read.');
        break;

    case 'mark_all':
        $s = mysqli_prepare($conn, 'UPDATE notifications SET is_read = 1 WHERE user_id = ? AND is_read = 0');
        mysqli_stmt_bind_param($s, 'i', $userId);
        mysqli_stmt_execute($s);
        $n = mysqli_stmt_affected_rows($s);
        $go('success', $n > 0 ? $n . ' notification(s) marked as read.' : 'All your notifications were already read.');
        break;

    case 'delete_one':
        if (!$id) { $go('error', 'Invalid notification.'); }
        $s = mysqli_prepare($conn, 'DELETE FROM notifications WHERE id = ?'); // Admins only (checked above)
        mysqli_stmt_bind_param($s, 'i', $id);
        mysqli_stmt_execute($s);
        mysqli_stmt_affected_rows($s) === 1 ? $go('success', 'Notification deleted.') : $go('error', 'That notification could not be deleted.');
        break;

    case 'delete_read':
        $s = mysqli_prepare($conn, 'DELETE FROM notifications WHERE user_id = ? AND is_read = 1');
        mysqli_stmt_bind_param($s, 'i', $userId);
        mysqli_stmt_execute($s);
        $go('success', mysqli_stmt_affected_rows($s) . ' read notification(s) deleted.');
        break;
}
$go('error', 'Unknown action.');