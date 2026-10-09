<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../../config/db.php';
require '../../includes/fund_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
// CHANGED: problems are sent as "error" (they were sent as "success").
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('You do not have permission to delete transactions.'));
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?error=' . urlencode('Invalid transaction selected.'));
    exit;
}

$adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

mysqli_begin_transaction($conn);
try {
    // Lock the transaction row so nobody approves/changes it while we delete it.
    // CHANGED: also reads is_automatic and the sale it belongs to.
    $find = mysqli_prepare($conn, 'SELECT t.id, t.transaction_type, t.status, t.fund_id, t.is_automatic, s.id AS linked_sale_id
        FROM transactions t LEFT JOIN sales s ON s.transaction_id = t.id WHERE t.id = ? FOR UPDATE');
    mysqli_stmt_bind_param($find, 'i', $id);
    mysqli_stmt_execute($find);
    $transaction = mysqli_fetch_assoc(mysqli_stmt_get_result($find));

    if (!$transaction) {
        throw new RuntimeException('Transaction not found.');
    }
    // NEW (spec section 3): only MANUAL transactions can be deleted here. Deleting a sale's income
    // or a purchase order's expense would leave the sale, stock or fund out of step.
    if ((int) $transaction['is_automatic'] === 1 || !empty($transaction['linked_sale_id'])) {
        throw new RuntimeException('This transaction was created automatically. Change it from its own module (for example, cancel the sale or purchase order).');
    }
    if ($transaction['status'] === 'deleted') {
        throw new RuntimeException('This transaction has already been deleted.');
    }

    // If this expense already took money out of a fund, put that money back.
    // CHANGED: uses fund_reverse_movements(), so each reversal is LINKED to the entry it
    // reverses (reverses_movement_id). The old code wrote an unlinked REVERSAL row, which the
    // fund reports could not tell apart from new money coming in.
    // Pending / rejected expenses never wrote a ledger row, so nothing to reverse
    // (deleting a pending one simply releases its reservation).
    if (!empty($transaction['fund_id'])) {
        fund_lock($conn, (int) $transaction['fund_id']);
        fund_reverse_movements($conn, 'm.transaction_id = ?', 'i', [(int) $id], $adminId,
            'Transaction #' . $id . ' deleted');
    }

    // CHANGED (spec sections 2 and 3, audit trail): the row is no longer erased. It is marked
    // 'deleted', so the Transactions list, the fund ledger and the reports can still show what
    // it was, who deleted it and when. Totals only count 'approved' rows, so it no longer counts.
    $delete = mysqli_prepare($conn, "UPDATE transactions SET status = 'deleted', deleted_by = ?, deleted_at = NOW() WHERE id = ?");
    mysqli_stmt_bind_param($delete, 'ii', $adminId, $id);
    mysqli_stmt_execute($delete);

    mysqli_commit($conn);
    header('Location: index.php?success=' . urlencode('Transaction deleted successfully.' . (!empty($transaction['fund_id']) && $transaction['status'] === 'approved' ? ' The money was returned to its fund.' : '')));
    exit;
} catch (RuntimeException $e) {
    mysqli_rollback($conn);
    $message = $e instanceof mysqli_sql_exception ? 'Unable to delete the transaction. Nothing was changed.' : $e->getMessage();
    if ($e instanceof mysqli_sql_exception) { error_log('transaction delete failed for #' . $id . ': ' . $e->getMessage()); }
    header('Location: index.php?error=' . urlencode($message));
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    error_log('transaction delete failed for #' . $id . ': ' . $e->getMessage());
    header('Location: index.php?error=' . urlencode('Unable to delete the transaction. Nothing was changed.'));
    exit;
}