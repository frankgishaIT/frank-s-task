<?php
// portal/_start.php — shared start of every signed-in portal page.
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/portal_layout.php';
$conn = portal_boot();
$me = portal_require_login($conn);
$cid = (int) $me['id'];
$GLOBALS['PORTAL_RECORDS_ON'] = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM portal_record_types WHERE is_enabled = 1'))[0] > 0;