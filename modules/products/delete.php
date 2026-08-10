<?php
require '../../config/db.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid item selected.'); exit; }
$statement = mysqli_prepare($conn, 'UPDATE products SET is_active = 0 WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
header('Location: index.php?success=Item deactivated successfully.');
exit;
