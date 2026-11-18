<?php
// portal/_start.php — shared start of every signed-in portal page.
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/portal_layout.php';
$conn = portal_boot();
$me = portal_require_login($conn);
$cid = (int) $me['id'];
$GLOBALS['PORTAL_RECORDS_ON'] = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM portal_record_types WHERE is_enabled = 1'))[0] > 0;
// NEW: unread customer notifications (RM Loans / RM Invest).
// NEW: RM Digital Library in the portal (on when the Library allows customers).
try { $r = mysqli_fetch_row(mysqli_query($conn, "SELECT value FROM lib_settings WHERE name = 'aud_portal_enabled'")); $GLOBALS['PORTAL_LIBRARY_ON'] = $r ? $r[0] === '1' : true; } catch (Throwable $e) { $GLOBALS['PORTAL_LIBRARY_ON'] = false; }
try { $GLOBALS['PORTAL_UNREAD'] = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM portal_notifications WHERE customer_id = $cid AND read_at IS NULL"))[0]; } catch (Throwable $e) { $GLOBALS['PORTAL_UNREAD'] = 0; }