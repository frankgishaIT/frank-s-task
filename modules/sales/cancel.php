<?php
require '../../config/db.php';
require '../../includes/sales_helpers.php';
require_role(['Admin', 'Manager']);

$saleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$saleId) {
    header('Location: index.php');
    exit;
}

$saleStatement = mysqli_prepare($conn, "SELECT sales.*, customers.name AS customer_name
    FROM sales LEFT JOIN customers ON sales.customer_id = customers.id WHERE sales.id = ?");
mysqli_stmt_bind_param($saleStatement, 'i', $saleId);
mysqli_stmt_execute($saleStatement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($saleStatement));

if (!$sale) {
    header('Location: index.php?error=Sale not found.');
    exit;
}

if (!in_array($sale['status'], ['Credit', 'Partially Paid', 'Paid'], true)) {
    header('Location: index.php?error=Only Credit, Partially Paid, or Paid sales can be cancelled.');
    exit;
}

if (isset($_POST['confirm_cancel'])) {
    $reason = trim($_POST['cancel_reason'] ?? '');
    $result = sales_cancel($conn, $saleId, current_user_id(), $reason ?: null);
    if ($result['ok']) {
        header('Location: index.php?success=Sale #' . $saleId . ' has been cancelled.');
    } else {
        header('Location: index.php?error=' . urlencode($result['error']));
    }
    exit;
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Cancel Sale #<?= (int) $sale['id']; ?></h2>
    <a href="index.php" class="btn btn-outline-secondary">Back to Sales</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <div class="alert alert-warning" style="border-radius:10px;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            This will mark the sale as <strong>Cancelled</strong> and remove its RWF <?= number_format($sale['total_amount'], 2); ?>
            income record. This cannot be undone.
        </div>

        <table class="table table-borderless mb-4" style="max-width:500px;">
            <tr><td class="text-muted">Customer</td><td><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="text-muted">Sale Date</td><td><?= date('d M Y', strtotime($sale['sale_date'])); ?></td></tr>
            <tr><td class="text-muted">Total</td><td>RWF <?= number_format($sale['total_amount'], 2); ?></td></tr>
            <tr><td class="text-muted">Paid so far</td><td>RWF <?= number_format($sale['amount_paid'], 2); ?></td></tr>
            <tr><td class="text-muted">Status</td><td><?= htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
        </table>

        <form method="POST">
            <input type="hidden" name="id" value="<?= (int) $sale['id']; ?>">
            <label class="form-label small fw-semibold text-muted">Reason (optional)</label>
            <textarea name="cancel_reason" class="form-control rm-input mb-4" rows="3" placeholder="e.g. Customer confirmed unable to pay"></textarea>

            <div class="d-flex gap-2 justify-content-end">
                <a href="index.php" class="btn btn-secondary">Never mind</a>
                <button type="submit" name="confirm_cancel" class="btn btn-danger">
                    <i class="bi bi-x-circle-fill me-1"></i>Confirm Cancellation
                </button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>