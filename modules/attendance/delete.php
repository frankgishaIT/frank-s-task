<?php
require '../../config/db.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid attendance record selected.'); exit; }
$statement = mysqli_prepare($conn, 'DELETE FROM attendance WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id); mysqli_stmt_execute($statement);
$message = mysqli_stmt_affected_rows($statement) > 0 ? 'Attendance record deleted successfully.' : 'Attendance record not found.';
header('Location: index.php?success=' . urlencode($message)); exit;
