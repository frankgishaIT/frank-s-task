<?php
require '../../config/db.php';

$id = $_GET['id'];

mysqli_query($conn, "
UPDATE notifications
SET is_read = 1
WHERE id = $id
");

header("Location:index.php?success=Notification marked as read.");
exit;