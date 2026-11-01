<?php
// NEW FILE: portal/logout.php
require_once __DIR__ . '/../includes/portal_helpers.php';
$conn = portal_boot();
if (!empty($_SESSION['portal_customer_id'])) { portal_log($conn, (int) $_SESSION['portal_customer_id'], 'logout', ''); }
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;