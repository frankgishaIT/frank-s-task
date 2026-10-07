<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../../config/db.php';
require '../../includes/fund_helpers.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?success=' . urlencode('You do not have permission to delete transactions.'));
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?success=' . urlencode('Invalid transaction selected.'));
    exit;
}

$adminId = $_SESSION['user_id'] ?? null;

try {
    mysqli_begin_transaction($conn);

    // Lock the transaction row so nobody approves/changes it while we delete it.
    $find = mysqli_prepare($conn, 'SELECT id, transaction_type, status, fund_id FROM transactions WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($find, 'i', $id);
    mysqli_stmt_execute($find);
    $transaction = mysqli_fetch_assoc(mysqli_stmt_get_result($find));

    if (!$transaction) {
        mysqli_rollback($conn);
        header('Location: index.php?success=' . urlencode('Transaction not found.'));
        exit;
    }

    // If this expense already took money out of a fund, put that money back
    // with a REVERSAL entry. The original EXPENSE row is kept, so the audit
    // trail still shows what happened.
    // Pending / rejected expenses never wrote a ledger row, so nothing to reverse
    // (deleting a pending one simply releases its reservation).
    if (!empty($transaction['fund_id'])) {
        $fundId = (int) $transaction['fund_id'];
        fund_lock($conn, $fundId);

        // Net amount this transaction still has out of the fund (OUT minus IN already reversed).
        $net = mysqli_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN direction = 'OUT' THEN amount ELSE -amount END), 0) AS taken FROM fund_movements WHERE transaction_id = ? AND fund_id = ?");
        mysqli_stmt_bind_param($net, 'ii', $id, $fundId);
        mysqli_stmt_execute($net);
        $taken = (float) mysqli_fetch_assoc(mysqli_stmt_get_result($net))['taken'];

        if ($taken > 0) {
            fund_record_movement(
                $conn, $fundId, 'REVERSAL', 'IN', $taken,
                date('Y-m'), $id, $adminId,
                'Reversal: transaction #' . $id . ' deleted'
            );
        }
    }

    $delete = mysqli_prepare($conn, 'DELETE FROM transactions WHERE id = ?');
    mysqli_stmt_bind_param($delete, 'i', $id);
    mysqli_stmt_execute($delete);

    mysqli_commit($conn);
    $message = 'Transaction deleted successfully.';
} catch (Throwable $e) {
    mysqli_rollback($conn);
    $message = 'Unable to delete the transaction.';
}

header('Location: index.php?success=' . urlencode($message));
exit;