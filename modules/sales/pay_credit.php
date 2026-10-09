<?php
require '../../config/db.php';
require_once '../../includes/sales_helpers.php';   // NEW: also loads fund_helpers.php and profit_rules.php
// CHANGED: this page had no role check, unlike every other sales page, so anyone who could
// reach the URL could record payments (which also allocates profit to the RM Funds).
require_role(['Admin', 'Manager', 'Employee']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
// CHANGED: problems are sent as "error" (they were sent as "success"), and all messages are urlencoded.
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid sale requested.')); exit; }

$statement = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name FROM sales LEFT JOIN customers ON sales.customer_id = customers.id WHERE sales.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$sale) { header('Location: index.php?error=' . urlencode('Sale not found.')); exit; }

$balance = round((float) $sale['total_amount'] - (float) $sale['amount_paid'], 2);
// NEW: only Credit and Partially Paid sales can receive a payment
// (never Cancelled, Pending Discount Approval or already Paid).
$canPay = in_array($sale['status'], ['Credit', 'Partially Paid'], true);

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
            $freshBalance = round((float) $fresh['total_amount'] - (float) $fresh['amount_paid'], 2);
            if ($payAmount > $freshBalance) {
                throw new RuntimeException('Payment cannot exceed the remaining balance of RWF ' . number_format($freshBalance, 2) . '.');
            }

            $insert = mysqli_prepare($conn, 'INSERT INTO sale_payments (sale_id, amount, recorded_by) VALUES (?, ?, ?)');
            $recordedBy = current_user_id();
            mysqli_stmt_bind_param($insert, 'idi', $id, $payAmount, $recordedBy);
            mysqli_stmt_execute($insert);

            $newAmountPaid = round((float) $fresh['amount_paid'] + $payAmount, 2);
            // CHANGED: same status rule as the rest of the sales module.
            $newStatus = sales_compute_status($newAmountPaid, (float) $fresh['total_amount']);

            $update = mysqli_prepare($conn, 'UPDATE sales SET amount_paid = ?, status = ? WHERE id = ?');
            mysqli_stmt_bind_param($update, 'dsi', $newAmountPaid, $newStatus, $id);
            mysqli_stmt_execute($update);

            // NEW (spec section 1): the payment that completes the sale puts its profit
            // into the four Funds immediately. Safe if called twice.
            if ($newStatus === 'Paid') {
                allocate_sale_profit($conn, $id, $recordedBy ? (int) $recordedBy : null);
            }

            mysqli_commit($conn);
            header('Location: invoice.php?id=' . $id . '&success=' . urlencode('Payment recorded successfully.'));
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
            <p class="mb-3"><strong>Balance Remaining:</strong> RWF <?= number_format($balance, 2); ?></p>

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