<?php
// CHANGED (Task I): deleting a loan is done only through the executive deletion (Managing Director /
// explicitly authorized), with its warning page, reason and audit trail.
require '../../config/db.php';
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
header('Location: ' . ($id ? 'executive_delete.php?id=' . (int) $id : 'index.php'));
exit;