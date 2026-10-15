<?php
/**
 * NEW FILE: modules/asset_management/refund_receive.php
 * Section 4: the refund for an asset returned to the seller has now been received:
 * the money enters the RM Capital Fund and the receivable is cleared.
 */
require '../../config/db.php';
require_once '../../includes/admin_error_guard.php'; // NEW: shows the Admin the exact reason if this page fails
require_once '../../includes/asset_capital_helpers.php';
asset_capital_ensure_schema($conn); // NEW: adds any missing table / column of this feature automatically
require_role(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$id = filter_input(INPUT_POST, 'disposal_id', FILTER_VALIDATE_INT);
mysqli_begin_transaction($conn);
try {
    if (!$id) { throw new RuntimeException('Invalid refund.'); }
    $amount = asset_refund_receive($conn, (int) $id, date('Y-m-d'), (int) current_user_id());
    mysqli_commit($conn);
    header('Location: index.php?success=' . urlencode('Refund of RWF ' . number_format($amount, 2) . ' received and added to the RM Capital Fund.'));
} catch (Throwable $e) {
    mysqli_rollback($conn);
    $msg = ($e instanceof RuntimeException && !($e instanceof mysqli_sql_exception)) ? $e->getMessage() : 'Unable to record the refund. Nothing was changed.';
    header('Location: index.php?error=' . urlencode($msg));
}
exit;