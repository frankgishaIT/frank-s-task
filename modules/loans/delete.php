<?php
/**
 * NEW FILE: <your loans folder>/delete.php
 * Deletes a loan that was recorded by mistake.
 *   - Only loans with NO repayments can be deleted (repayments really happened and were paid
 *     from the funds, so such a loan must stay).
 *   - The loan money that came into the RM Capital Fund is taken back out with a linked REVERSAL
 *     (the fund history keeps both), and only if the fund still has that money.
 *   - The transaction created when the loan was received is marked Deleted (kept for the history).
 *   - The loan and its repayment schedule are removed.
 * Everything happens in one database transaction: all of it, or nothing.
 */
require '../../config/db.php';
require_once '../../includes/fund_helpers.php';
require_role(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?error=' . urlencode('Invalid loan selected.'));
    exit;
}
$userId = current_user_id();
$userId = $userId ? (int) $userId : null;

mysqli_begin_transaction($conn);
try {
    $lock = mysqli_prepare($conn, 'SELECT * FROM loans WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($lock, 'i', $id);
    mysqli_stmt_execute($lock);
    $loan = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
    if (!$loan) {
        throw new RuntimeException('Loan not found.');
    }

    // 1. Loans with repayments cannot be deleted.
    $p = mysqli_prepare($conn, 'SELECT COUNT(*) AS n FROM loan_payments WHERE loan_id = ?');
    mysqli_stmt_bind_param($p, 'i', $id);
    mysqli_stmt_execute($p);
    if ((int) mysqli_fetch_assoc(mysqli_stmt_get_result($p))['n'] > 0) {
        throw new RuntimeException('This loan already has repayments, so it cannot be deleted. Those payments really happened and were paid from the RM Funds.');
    }

    // 2. Take the loan money back out of the RM Capital Fund (if it was put in through the Loans module).
    $capital = fund_by_code($conn, 'CAPITAL');
    if ($capital) {
        fund_lock($conn, (int) $capital['id']);
        $q = mysqli_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END), 0) AS v
            FROM fund_movements WHERE ref_type = 'loan' AND ref_id = ? AND fund_id = ?");
        $capitalId = (int) $capital['id'];
        mysqli_stmt_bind_param($q, 'ii', $id, $capitalId);
        mysqli_stmt_execute($q);
        $inFund = round((float) mysqli_fetch_assoc(mysqli_stmt_get_result($q))['v'], 2);

        if ($inFund > 0.009) {
            $available = fund_available($conn, $capitalId);
            if ($inFund > $available + 0.001) {
                throw new RuntimeException('The RM Capital Fund only has RWF ' . number_format($available, 2) . ' available, but this loan brought in RWF '
                    . number_format($inFund, 2) . '. Part of the loan money has already been used, so the loan cannot be deleted.');
            }
            fund_reverse_movements($conn, "m.ref_type = 'loan' AND m.ref_id = ?", 'i', [(int) $id], $userId,
                'Loan #' . $id . ' (' . $loan['lender'] . ') deleted');
        }
    }

    // 3. The transaction created when the loan was received: kept, but marked Deleted.
    if (!empty($loan['received_transaction_id'])) {
        $tx = mysqli_prepare($conn, "UPDATE transactions SET status = 'deleted', deleted_by = ?, deleted_at = NOW() WHERE id = ? AND status <> 'deleted'");
        $txId = (int) $loan['received_transaction_id'];
        mysqli_stmt_bind_param($tx, 'ii', $userId, $txId);
        mysqli_stmt_execute($tx);
    }

    // 4. The schedule and the loan itself.
    $ds = mysqli_prepare($conn, 'DELETE FROM loan_schedule WHERE loan_id = ?');
    mysqli_stmt_bind_param($ds, 'i', $id);
    mysqli_stmt_execute($ds);
    $dl = mysqli_prepare($conn, 'DELETE FROM loans WHERE id = ?');
    mysqli_stmt_bind_param($dl, 'i', $id);
    mysqli_stmt_execute($dl);

    mysqli_commit($conn);
    $message = 'Loan from ' . $loan['lender'] . ' (RWF ' . number_format((float) $loan['loan_amount'], 2) . ') deleted.'
        . (isset($inFund) && $inFund > 0.009 ? ' RWF ' . number_format($inFund, 2) . ' was taken back out of the RM Capital Fund.' : '');
    header('Location: index.php?success=' . urlencode($message));
    exit;
} catch (RuntimeException $e) {
    mysqli_rollback($conn);
    $msg = $e instanceof mysqli_sql_exception ? 'Unable to delete the loan. Nothing was changed.' : $e->getMessage();
    if ($e instanceof mysqli_sql_exception) { error_log('loan delete failed for #' . $id . ': ' . $e->getMessage()); }
    header('Location: index.php?error=' . urlencode($msg));
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    error_log('loan delete failed for #' . $id . ': ' . $e->getMessage());
    header('Location: index.php?error=' . urlencode('Unable to delete the loan. Nothing was changed.'));
    exit;
}