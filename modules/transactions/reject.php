<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/notification_helper.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php');
    exit;
}

// CHANGED: rejecting changes a transaction, so it only happens on a POST (the Reject form),
// never on a plain link (same rule as approve.php). The form on the list already uses POST.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 1000) ?: 'No reason provided.';

if (!$id) {
    header('Location: index.php');
    exit;
}

$statement = mysqli_prepare($conn, "SELECT recorded_by, amount, transaction_type FROM transactions WHERE id = ? AND status = 'pending'");
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$transaction = mysqli_stmt_get_result($statement)->fetch_assoc();

if ($transaction) {
    $adminId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

    // A pending expense only RESERVES money in its fund (nothing was written to the fund ledger),
    // so rejecting it simply releases the reservation. No fund movement is needed.
    $update = mysqli_prepare($conn, "UPDATE transactions SET status = 'rejected', approved_by = ?, approved_at = NOW(), rejection_reason = ? WHERE id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($update, 'isi', $adminId, $reason, $id);
    mysqli_stmt_execute($update);

    // CHANGED: only continue if THIS request really rejected it. If another admin approved it a
    // moment earlier, nothing changed, and the user must not be told it was rejected.
    if (mysqli_stmt_affected_rows($update) !== 1) {
        header('Location: index.php?error=' . urlencode('This transaction was already reviewed by someone else.'));
        exit;
    }

    if (!empty($transaction['recorded_by'])) {
        notifyUser(
            $conn,
            $transaction['recorded_by'],
            'Transaction Rejected',
            'Your ' . strtolower($transaction['transaction_type']) . ' of RWF ' . number_format((float) $transaction['amount'], 2) . ' (#' . $id . ') was rejected: ' . $reason
        );
    }

    // CHANGED: messages are urlencoded.
    header('Location: index.php?success=' . urlencode('Transaction rejected.'));
    exit;
}

header('Location: index.php?error=' . urlencode('Transaction not found or already reviewed.'));
exit;