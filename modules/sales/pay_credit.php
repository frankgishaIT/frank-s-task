<?php
require '../../config/db.php';
require_once '../../includes/sales_helpers.php';   // NEW: also loads fund_helpers.php and profit_rules.php
require_once '../../includes/sms_messages.php';    // NEW: payment SMS (MY MOTIVE SMS, message 4)
// CHANGED: this page had no role check, unlike every other sales page, so anyone who could
// reach the URL could record payments (which also allocates profit to the RM Funds).
require_role(['Admin', 'Manager', 'Employee']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
// CHANGED: problems are sent as "error" (they were sent as "success"), and all messages are urlencoded.
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid sale requested.')); exit; }

// NEW (overdue charges): bring the invoice's daily charges up to date before showing it.
credit_accrue_now($conn, (int) $id);

$statement = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name FROM sales LEFT JOIN customers ON sales.customer_id = customers.id WHERE sales.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$sale) { header('Location: index.php?error=' . urlencode('Sale not found.')); exit; }

// CHANGED: what is owed = the remaining invoice amount + unpaid overdue charges.
$principalOut = round((float) $sale['total_amount'] - (float) $sale['amount_paid'], 2);
$chargesOut = round((float) $sale['charges_accrued'] - (float) $sale['charges_paid'], 2);
$balance = round($principalOut + $chargesOut, 2);
// NEW: only Credit and Partially Paid sales can receive a payment
// (never Cancelled, Pending Discount Approval or already Paid).
$canPay = in_array($sale['status'], ['Credit', 'Partially Paid'], true);

// NEW (72-hour rule): shown on the page so the cashier knows what full payment now means for points.
$deadlineTs = !empty($sale['payment_deadline']) ? strtotime($sale['payment_deadline']) : null;
$pointsPending = ($sale['points_status'] ?? '') === 'Pending' ? (int) $sale['points_pending'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $payAmount = $payAmount ? round($payAmount, 2) : $payAmount;

    if (!$canPay) {
        $error = 'Payments can only be recorded on Credit or Partially Paid sales.';
    } elseif (!$payAmount || $payAmount <= 0) {
        $error = 'Enter a valid payment amount.';
    } elseif ($payAmount > $balance) {
        $error = 'Payment cannot exceed the remaining balance of RWF ' . number_format($balance, 2) . '.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            // CHANGED: re-read the sale under a lock, so two payments at the same moment
            // cannot both use the same old amount_paid or overpay the sale.
            $lock = mysqli_prepare($conn, 'SELECT * FROM sales WHERE id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $id);
            mysqli_stmt_execute($lock);
            $fresh = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));

            if (!$fresh || !in_array($fresh['status'], ['Credit', 'Partially Paid'], true)) {
                throw new RuntimeException('This sale can no longer receive payments. Its status is now ' . ($fresh['status'] ?? 'unknown') . '.');
            }
            // NEW: charges due up to this moment are added first (same transaction, same lock).
            credit_accrue($conn, (int) $id, $recordedBy = current_user_id());
            $lock2 = mysqli_prepare($conn, 'SELECT * FROM sales WHERE id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock2, 'i', $id);
            mysqli_stmt_execute($lock2);
            $fresh = mysqli_fetch_assoc(mysqli_stmt_get_result($lock2));
            $freshBalance = round((float) $fresh['total_amount'] - (float) $fresh['amount_paid'] + (float) $fresh['charges_accrued'] - (float) $fresh['charges_paid'], 2);
            if ($payAmount > $freshBalance) {
                throw new RuntimeException('Payment cannot exceed the remaining balance of RWF ' . number_format($freshBalance, 2) . '.');
            }

            $insert = mysqli_prepare($conn, 'INSERT INTO sale_payments (sale_id, amount, recorded_by) VALUES (?, ?, ?)');
            $recordedBy = current_user_id();
            mysqli_stmt_bind_param($insert, 'idi', $id, $payAmount, $recordedBy);
            mysqli_stmt_execute($insert);
            $paymentId = (int) mysqli_insert_id($conn);

            // CHANGED (overdue charges): the payment pays the charges first, then the invoice
            // amount, each with its own ledger entry. The status is Paid only when both are cleared.
            $applied = credit_apply_payment($conn, $fresh, (float) $payAmount, $paymentId, $recordedBy ? (int) $recordedBy : null);
            $newStatus = $applied['status'];

            $pointsMessage = '';
            if ($newStatus === 'Paid') {
                // NEW (72-hour rule): record when it was fully paid, and decide the pending points
                // (awarded if paid in full by the deadline, otherwise not awarded).
                $paidAt = mysqli_prepare($conn, 'UPDATE sales SET fully_paid_at = NOW() WHERE id = ? AND fully_paid_at IS NULL');
                mysqli_stmt_bind_param($paidAt, 'i', $id);
                mysqli_stmt_execute($paidAt);
                if (($fresh['points_status'] ?? '') === 'Pending') {
                    $awarded = sales_settle_pending_points($conn, (int) $id);
                    $pointsMessage = $awarded > 0
                        ? ' Paid within 72 hours: ' . $awarded . ' Loyalty Points awarded.'
                        : ' Paid after the 72-hour deadline: no Loyalty Points for this invoice.';
                }

                // NEW (spec section 1): the payment that completes the sale puts its profit
                // into the four Funds immediately. Safe if called twice.
                allocate_sale_profit($conn, $id, $recordedBy ? (int) $recordedBy : null);
            }

            mysqli_commit($conn);
            // NEW: "we received your payment" SMS, sent only after the payment is saved.
            sms_notify_payment($conn, (int) $id, $paymentId, (float) $payAmount, $recordedBy ? (int) $recordedBy : null);
            $split = $applied['to_charges'] > 0
                ? ' RWF ' . number_format($applied['to_charges'], 2) . ' paid the overdue charges and RWF ' . number_format($applied['to_principal'], 2) . ' the invoice.'
                : '';
            header('Location: invoice.php?id=' . $id . '&success=' . urlencode('Payment recorded successfully.' . $split . $pointsMessage));
            exit;
        } catch (mysqli_sql_exception $e) {
            mysqli_rollback($conn);
            error_log('record_payment failed for sale #' . $id . ': ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        } catch (RuntimeException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage();
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('record_payment failed for sale #' . $id . ': ' . $e->getMessage());
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Record Payment - Sale #<?= (int) $id; ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
</head>
<body class="bg-light">
<div class="container" style="max-width:480px; margin-top:60px;">
    <div class="card shadow-sm">
        <div class="card-body">
            <h5 class="card-title mb-3">Record Payment</h5>
            <p class="mb-1"><strong>Customer:</strong> <?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer', ENT_QUOTES, 'UTF-8'); ?></p>
            <p class="mb-1"><strong>Total Amount:</strong> RWF <?= number_format($sale['total_amount'], 2); ?></p>
            <p class="mb-1"><strong>Already Paid:</strong> RWF <?= number_format($sale['amount_paid'], 2); ?></p>
            <?php if ($chargesOut > 0.009) { ?>
            <!-- NEW: overdue charges -->
            <p class="mb-1"><strong>Invoice Balance:</strong> RWF <?= number_format($principalOut, 2); ?></p>
            <p class="mb-1 text-danger"><strong>Overdue Charges (1% per day):</strong> RWF <?= number_format($chargesOut, 2); ?>
                <a href="ledger.php?id=<?= (int) $id; ?>" class="small">details</a></p>
            <?php } ?>
            <p class="mb-3"><strong>Total Balance Due:</strong> RWF <?= number_format($balance, 2); ?></p>
            <?php if ($chargesOut > 0.009) { ?><p class="small text-muted">A payment pays the overdue charges first, then the invoice amount.</p><?php } ?>

            <?php if ($canPay && $deadlineTs) { ?>
                <!-- NEW (72-hour rule) -->
                <div class="alert <?= time() <= $deadlineTs ? 'alert-info' : 'alert-warning'; ?> small">
                    Payment deadline: <strong><?= date('d M Y, H:i', $deadlineTs); ?></strong>.
                    <?php if ($pointsPending > 0) { ?>
                        <?= time() <= $deadlineTs
                            ? 'If the full balance is paid by then, the customer earns <strong>' . $pointsPending . ' Loyalty Points</strong>.'
                            : 'The deadline has passed: the <strong>' . $pointsPending . ' pending Loyalty Points</strong> will not be awarded.'; ?>
                    <?php } ?>
                </div>
            <?php } ?>

            <?php if (!empty($error)) { ?>
                <div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php } ?>

            <?php if (!$canPay && $sale['status'] !== 'Paid') { ?>
                <div class="alert alert-warning">Payments can only be recorded on Credit or Partially Paid sales. This sale is <strong><?= htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8'); ?></strong>.</div>
            <?php } elseif ($balance <= 0 || !$canPay) { ?>
                <div class="alert alert-success">This sale is already fully paid.</div>
            <?php } else { ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Amount Being Paid Now (RWF)</label>
                    <input type="number" step="0.01" min="0.01" name="amount" class="form-control" max="<?= $balance; ?>" required>
                </div>
                <button type="submit" class="btn btn-primary w-100">Record Payment</button>
            </form>
            <?php } ?>
            <a href="index.php" class="btn btn-link mt-3 d-block text-center">Back to Sales</a>
        </div>
    </div>
</div>
</body>
</html>