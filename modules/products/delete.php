<?php
session_start();
require '../../config/db.php';

// Admin-only action
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?success=' . urlencode('You do not have permission to perform this action.'));
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?success=Invalid item selected.');
    exit;
}

// Look up the current status first, so we know which way to flip it.
$statusStatement = mysqli_prepare($conn, 'SELECT is_active FROM products WHERE id = ?');
mysqli_stmt_bind_param($statusStatement, 'i', $id);
mysqli_stmt_execute($statusStatement);
$product = mysqli_fetch_assoc(mysqli_stmt_get_result($statusStatement));

if (!$product) {
    header('Location: index.php?success=Item not found.');
    exit;
}

$newStatus = $product['is_active'] ? 0 : 1;

$statement = mysqli_prepare($conn, 'UPDATE products SET is_active = ? WHERE id = ?');
mysqli_stmt_bind_param($statement, 'ii', $newStatus, $id);
mysqli_stmt_execute($statement);

$message = $newStatus
    ? 'Item reactivated successfully.'
    : 'Item deactivated successfully.';

header('Location: index.php?success=' . urlencode($message));
exit;