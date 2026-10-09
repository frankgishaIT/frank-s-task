<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/notification_helper.php';
require '../../includes/fund_helpers.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php');
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php');
    exit;
}

$statement = mysqli_prepare($conn, "SELECT recorded_by, amount, transaction_type, transaction_date, description, fund_id, expense_category FROM transactions WHERE id = ? AND status = 'pending'");
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$transaction = mysqli_stmt_get_result($statement)->fetch_assoc();

if ($transaction) {
    $adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $amount = (float) $transaction['amount'];
    $isExpense = $transaction['transaction_type'] === 'Expense';
    $fundId = $transaction['fund_id'] ? (int) $transaction['fund_id'] : null;

    // Expenses must be linked to a fund. Old pending expenses created before
    // the Fund system have no fund, so they cannot be approved.
    if ($isExpense && !$fundId) {
        header('Location: index.php?error=' . urlencode('This expense has no Fund assigned. Please reject it and ask the user to record it again with a Fund.'));
        exit;
    }

    try {
        mysqli_begin_transaction($conn);

        if ($isExpense) {
            fund_lock($conn, $fundId);
            $fund = fund_get($conn, $fundId);
            if (!$fund) {
                throw new RuntimeException('Fund not found.');
            }

            // This expense is itself still "pending", so its own amount is already
            // reserved inside fund_pending_total(). Exclude it before comparing,
            // so it is not counted against itself.
            $availableForThis = fund_balance($conn, $fundId) - (fund_pending_total($conn, $fundId) - $amount);
            if ($amount > $availableForThis + 0.001) {
                throw new InsufficientFundException(fund_insufficient_message($fund['name']));
            }
        }

        // Only change status if it is still pending (protects against two admins clicking at once).
        $update = mysqli_prepare($conn, "UPDATE transactions SET status = 'approved', approved_by = ?, approved_at = NOW() WHERE id = ? AND status = 'pending'");
        mysqli_stmt_bind_param($update, 'ii', $adminId, $id);
        mysqli_stmt_execute($update);

        if (mysqli_stmt_affected_rows($update) !== 1) {
            throw new RuntimeException('Transaction already reviewed.');
        }

        // Money now leaves the fund. The ledger entry carries the transaction id, so a later
        // edit or delete can reverse exactly this entry.
        if ($isExpense) {
            fund_record_movement(
                $conn, $fundId, 'EXPENSE', 'OUT', $amount,
                substr($transaction['transaction_date'], 0, 7), $id, $adminId,
                mb_substr($transaction['expense_category'] ?: ($transaction['description'] ?: 'Expense'), 0, 255)
            );
        }

        mysqli_commit($conn);
    } catch (InsufficientFundException $e) {
        mysqli_rollback($conn);
        header('Location: index.php?error=' . urlencode($e->getMessage()));
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        // CHANGED: the cause is logged.
        error_log('transaction approve failed for #' . $id . ': ' . $e->getMessage());
        header('Location: index.php?error=' . urlencode('Unable to approve the transaction. Nothing was changed.'));
        exit;
    }

    if (!empty($transaction['recorded_by'])) {
        notifyUser(
            $conn,
            $transaction['recorded_by'],
            'Transaction Approved',
            'Your ' . strtolower($transaction['transaction_type']) . ' of RWF ' . number_format($amount, 2) . ' (#' . $id . ') was approved.'
        );
    }

    // CHANGED: messages are urlencoded.
    header('Location: index.php?success=' . urlencode('Transaction approved.'));
    exit;
}

header('Location: index.php?error=' . urlencode('Transaction not found or already reviewed.'));
exit;