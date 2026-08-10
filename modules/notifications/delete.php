<?php
require '../../config/db.php';

$id = $_GET['id'];

mysqli_query($conn, "
DELETE FROM notifications
WHERE id = $id
");

header("Location:index.php?success=Notification deleted successfully.");
exit;