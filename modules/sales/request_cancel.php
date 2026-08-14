<?php
require '../../config/db.php';
require '../../includes/sales_helpers.php';
require_role(['Admin', 'Manager', 'Employee']);

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

// Employees can only act on their own recorded sales
if (current_user_role() === 'Employee' && (int) $sale['recorded_by'] !== (int) current_user_id()) {
    header('Location: index.php?error=You can only request cancellation on your own sales.');
    exit;
}

if (isset($_POST['submit_request'])) {
    $reason = trim($_POST['cancel_request_reason'] ?? '');
    if ($reason === '') {
        $formError = 'Please explain why this sale needs to be cancelled.';
    } else {
        $result = sales_request_cancel($conn, $saleId, current_user_id(), $reason);
        if ($result['ok']) {
            header('Location: index.php?success=Cancellation request sent for approval.');
            exit;
        }
        $formError = $result['error'];
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Request Cancellation — Sale #<?= (int) $sale['id']; ?></h2>
    <a href="index.php" class="btn btn-outline-secondary">Back to Sales</a>
</div>

<?php if (isset($formError)) { ?>
<div class="alert alert-danger"><?= htmlspecialchars($formError, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <table class="table table-borderless mb-4" style="max-width:500px;">
            <tr><td class="text-muted">Customer</td><td><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="text-muted">Total</td><td>RWF <?= number_format($sale['total_amount'], 2); ?></td></tr>
            <tr><td class="text-muted">Paid so far</td><td>RWF <?= number_format($sale['amount_paid'], 2); ?></td></tr>
            <tr><td class="text-muted">Status</td><td><?= htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
        </table>

        <form method="POST">
            <input type="hidden" name="id" value="<?= (int) $sale['id']; ?>">
            <label class="form-label small fw-semibold text-muted">Why should this be cancelled?</label>
            <textarea name="cancel_request_reason" class="form-control rm-input mb-4" rows="3" placeholder="e.g. Customer says they can't pay, unreachable, etc." required></textarea>
            <div class="d-flex gap-2 justify-content-end">
                <a href="index.php" class="btn btn-secondary">Never mind</a>
                <button type="submit" name="submit_request" class="btn btn-warning">Send Request to Admin</button>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>