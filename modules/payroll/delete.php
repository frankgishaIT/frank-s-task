<?php
require '../../config/db.php';
require_once '../../includes/payroll_fund_helpers.php'; // NEW: returns a paid payroll's money to the Operating Fund
require_role(['Admin']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// CHANGED: problems are sent as "error" (they were sent as "success").
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?error=' . urlencode('Invalid payroll record selected.'));
    exit;
}

$userId = current_user_id();
$userId = $userId ? (int) $userId : null;

// CHANGED (spec sections 3 and 14): deleting a PAID payroll returns its Net Salary to the
// RM Business Operating Fund (linked reversal; the expense transaction is marked Deleted, so the
// history stays visible). Before, the row was simply deleted and the fund stayed short forever.
// Everything runs in one database transaction: either all of it happens or nothing does.
mysqli_begin_transaction($conn);
try {
    $lock = mysqli_prepare($conn, 'SELECT id, status, net_salary FROM payroll WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($lock, 'i', $id);
    mysqli_stmt_execute($lock);
    $payroll = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
    if (!$payroll) {
        mysqli_rollback($conn);
        header('Location: index.php?error=' . urlencode('Payroll record not found.'));
        exit;
    }

    // Must run BEFORE the row is deleted (it reads the payroll). Nothing is returned for a Draft,
    // or for a payroll paid before the Funds existed (that money never came from the fund).
    $returned = 0.0;
    payroll_sync_fund($conn, (int) $id, $payroll['status'], $userId, true, $returned);

    $statement = mysqli_prepare($conn, 'DELETE FROM payroll WHERE id = ?');
    mysqli_stmt_bind_param($statement, 'i', $id);
    mysqli_stmt_execute($statement);

    mysqli_commit($conn);

    $message = 'Payroll record deleted successfully.';
    if ($returned > 0) {
        $message .= ' RWF ' . number_format($returned, 2) . ' was returned to the RM Business Operating Fund.';
    }
    header('Location: index.php?success=' . urlencode($message));
    exit;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    error_log('payroll delete failed for #' . $id . ': ' . $e->getMessage());
    header('Location: index.php?error=' . urlencode('Unable to delete the payroll record. Nothing was changed.'));
    exit;
}