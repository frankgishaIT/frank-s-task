<?php
// NEW FILE: modules/loans/executive_delete.php — Task I: delete a loan (Managing Director / authorized only).
require '../../config/db.php';
require_once '../../includes/executive_delete_page.php';
exec_delete_page($conn, 'loan');