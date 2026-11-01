<?php
// portal/_start.php — shared start of every signed-in portal page.
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/portal_layout.php';
$conn = portal_boot();
$me = portal_require_login($conn);
$cid = (int) $me['id'];
$GLOBALS['PORTAL_RECORDS_ON'] = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM portal_record_types WHERE is_enabled = 1'))[0] > 0;
// NEW: unread customer notifications (RM Loans / RM Invest).
try { $GLOBALS['PORTAL_UNREAD'] = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM portal_notifications WHERE customer_id = $cid AND read_at IS NULL"))[0]; } catch (Throwable $e) { $GLOBALS['PORTAL_UNREAD'] = 0; }