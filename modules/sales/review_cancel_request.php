<?php
require '../../config/db.php';
require '../../includes/sales_helpers.php';
require_role(['Admin', 'Manager']);

$saleId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$saleId) {
    header('Location: index.php');
    exit;
}

$saleStatement = mysqli_prepare($conn, "SELECT sales.*, customers.name AS customer_name, users.names AS requested_by_name
    FROM sales LEFT JOIN customers ON sales.customer_id = customers.id
    LEFT JOIN users ON sales.cancel_requested_by = users.id
    WHERE sales.id = ?");
mysqli_stmt_bind_param($saleStatement, 'i', $saleId);
mysqli_stmt_execute($saleStatement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($saleStatement));

if (!$sale || empty($sale['cancel_requested_by'])) {
    header('Location: index.php?error=No pending cancellation request for that sale.');
    exit;
}

if (isset($_POST['approve'])) {
    $result = sales_cancel($conn, $saleId, current_user_id(), $sale['cancel_request_reason']);
    header('Location: index.php?' . ($result['ok'] ? 'success=Sale cancelled.' : 'error=' . urlencode($result['error'])));
    exit;
}
if (isset($_POST['reject'])) {
    sales_reject_cancel_request($conn, $saleId);
    header('Location: index.php?success=Cancellation request rejected.');
    exit;
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Review Cancellation Request — Sale #<?= (int) $sale['id']; ?></h2>
    <a href="index.php" class="btn btn-outline-secondary">Back to Sales</a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <table class="table table-borderless mb-4" style="max-width:500px;">
            <tr><td class="text-muted">Customer</td><td><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="text-muted">Total</td><td>RWF <?= number_format($sale['total_amount'], 2); ?></td></tr>
            <tr><td class="text-muted">Paid so far</td><td>RWF <?= number_format($sale['amount_paid'], 2); ?></td></tr>
            <tr><td class="text-muted">Requested by</td><td><?= htmlspecialchars($sale['requested_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="text-muted">Reason</td><td><?= htmlspecialchars($sale['cancel_request_reason'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
        </table>

        <form method="POST" class="d-flex gap-2 justify-content-end">
            <input type="hidden" name="id" value="<?= (int) $sale['id']; ?>">
            <button type="submit" name="reject" class="btn btn-secondary">Reject</button>
            <button type="submit" name="approve" class="btn btn-danger">Approve Cancellation</button>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>