<?php
/**
 * modules/transactions/delete.php
 * CHANGED (Task 1): deletes a MANUAL transaction (Admins only, with a reason), puts back the money it moved,
 * and records it in the audit trail. The warning comes first (delete_confirm.php). Automatic transactions are
 * refused with the record they come from. Any failure shows its exact reason.
 */
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../../config/db.php';
require '../../includes/fund_helpers.php';
require_once '../../includes/income_allocation_helpers.php';
require_once '../../includes/transaction_audit_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}
$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('Only Administrators can delete transactions.'));
    exit;
}
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$reason = trim((string) ($_POST['reason'] ?? ''));
if (!$id) {
    header('Location: index.php?error=' . urlencode('Invalid transaction selected.'));
    exit;
}
if (mb_strlen($reason) < 5) {
    header('Location: delete_confirm.php?id=' . (int) $id);  // the reason is required: back to the warning page
    exit;
}
$adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$ref = tx_reference((int) $id);

try {
    // Before the transaction: makes sure the table accepts status 'deleted' (the cause of
    // "Unable to delete the transaction. Nothing was changed.") and the audit table exists.
    tx_audit_ensure($conn);
} catch (Throwable $e) {
    header('Location: index.php?error=' . urlencode('The database could not be prepared for deletions: ' . $e->getMessage()));
    exit;
}

mysqli_begin_transaction($conn);
try {
    $find = mysqli_prepare($conn, 'SELECT * FROM transactions WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($find, 'i', $id);
    mysqli_stmt_execute($find);
    $tx = mysqli_fetch_assoc(mysqli_stmt_get_result($find));
    if (!$tx) {
        throw new RuntimeException($ref . ' was not found.');
    }
    if ($tx['status'] === 'deleted') {
        throw new RuntimeException($ref . ' has already been deleted.');
    }
    $source = tx_source($conn, $tx);
    if ($source) {
        throw new RuntimeException($ref . ' was created automatically by ' . $source['label'] . '. ' . $source['how']);
    }

    $details = [];
    // Money this transaction took out of (or put into) a fund goes back, each reversal linked to its entry.
    if (!empty($tx['fund_id'])) {
        fund_lock($conn, (int) $tx['fund_id']);
        $n = fund_reverse_movements($conn, 'm.transaction_id = ?', 'i', [(int) $id], $adminId, $ref . ' deleted: ' . $reason);
        if ($n > 0) { $details[] = $n . ' fund entr' . ($n === 1 ? 'y' : 'ies') . ' reversed'; }
    }
    if ($tx['transaction_type'] === 'Income') {
        $n = (int) reverse_income_allocation($conn, (int) $id, $adminId, 'deleted');
        if ($n > 0) { $details[] = 'income share taken back from the funds'; }
    }
    if ($tx['status'] === 'pending') { $details[] = 'pending reservation released'; }

    // The row is kept, marked 'deleted' (who and when), so the history stays complete.
    $del = mysqli_prepare($conn, "UPDATE transactions SET status = 'deleted', deleted_by = ?, deleted_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($del, 'ii', $adminId, $id);
    mysqli_stmt_execute($del);

    tx_audit_log($conn, $tx, 'Deleted', $reason, ($details ? implode('; ', $details) . '. ' : '') . 'Status before: ' . $tx['status'] . '.', $adminId);

    mysqli_commit($conn);
    header('Location: index.php?success=' . urlencode($ref . ' deleted and recorded in the audit trail.'
        . ($tx['status'] === 'approved' && $tx['transaction_type'] === 'Expense' ? ' The money was returned to its fund.' : '')
        . ($tx['status'] === 'approved' && $tx['transaction_type'] === 'Income' ? ' Its share was removed from the funds.' : '')));
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    error_log('transaction delete failed for #' . $id . ': ' . $e->getMessage());
    // CHANGED (Task 1): always the exact reason (this page is for Admins only), never an unexplained error.
    $message = ($e instanceof RuntimeException && !($e instanceof mysqli_sql_exception))
        ? $e->getMessage()
        : 'Unable to delete ' . $ref . '. Nothing was changed. Reason: ' . $e->getMessage();
    header('Location: index.php?error=' . urlencode($message));
    exit;
}