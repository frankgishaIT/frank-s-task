<?php
require '../../config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid sale requested.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name FROM sales LEFT JOIN customers ON sales.customer_id = customers.id WHERE sales.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$sale) { header('Location: index.php?success=Sale not found.'); exit; }

$balance = (float) $sale['total_amount'] - (float) $sale['amount_paid'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payAmount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);

    if (!$payAmount || $payAmount <= 0) {
        $error = 'Enter a valid payment amount.';
    } elseif ($payAmount > $balance) {
        $error = 'Payment cannot exceed the remaining balance of RWF ' . number_format($balance, 2) . '.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            $insert = mysqli_prepare($conn, 'INSERT INTO sale_payments (sale_id, amount, recorded_by) VALUES (?, ?, ?)');
            $recordedBy = current_user_id();
            mysqli_stmt_bind_param($insert, 'idi', $id, $payAmount, $recordedBy);
            mysqli_stmt_execute($insert);

            $newAmountPaid = (float) $sale['amount_paid'] + $payAmount;
            if ($newAmountPaid >= (float) $sale['total_amount']) {
                $newStatus = 'Paid';
            } elseif ($newAmountPaid > 0) {
                $newStatus = 'Partially Paid';
            } else {
                $newStatus = 'Credit';
            }

            $update = mysqli_prepare($conn, 'UPDATE sales SET amount_paid = ?, status = ? WHERE id = ?');
            mysqli_stmt_bind_param($update, 'dsi', $newAmountPaid, $newStatus, $id);
            mysqli_stmt_execute($update);

            mysqli_commit($conn);
            header('Location: invoice.php?id=' . $id . '&success=Payment recorded successfully.');
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = 'Something went wrong. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Record Payment - Sale #<?= $id; ?></title>
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

            <?php if ($balance <= 0) { ?>
                <div class="alert alert-success">This sale is already fully paid.</div>
            <?php } else { ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label">Amount Being Paid Now (RWF)</label>
                    <input type="number" step="0.01" name="amount" class="form-control" max="<?= $balance; ?>" required>
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