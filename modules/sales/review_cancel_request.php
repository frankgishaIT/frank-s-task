<?php
require '../../config/db.php';
require '../../includes/sales_helpers.php';
require_once '../../includes/sms_messages.php'; // NEW: cancellation SMS (MY MOTIVE SMS, message 5)
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

// CHANGED: all messages are urlencoded (spaces and special characters in a raw address can break them).
if (!$sale || empty($sale['cancel_requested_by'])) {
    header('Location: index.php?error=' . urlencode('No pending cancellation request for that sale.'));
    exit;
}

// NEW: the products on this sale, so the manager can see what would go back into stock.
$productLinesStatement = mysqli_prepare($conn, "SELECT sale_items.quantity, sale_items.pack_label, sale_items.pack_size, products.product_name, products.unit
    FROM sale_items JOIN products ON sale_items.product_id = products.id
    WHERE sale_items.sale_id = ? AND sale_items.item_type = 'Product'");
mysqli_stmt_bind_param($productLinesStatement, 'i', $saleId);
mysqli_stmt_execute($productLinesStatement);
$productLines = mysqli_fetch_all(mysqli_stmt_get_result($productLinesStatement), MYSQLI_ASSOC);
$hasProducts = !empty($productLines);

if (isset($_POST['approve'])) {
    // NEW: same choice as the Cancel page. Ticked = the goods came back: they return to stock and
    // their buying price returns to the RM Capital Fund. Unticked = the goods are gone.
    $returnToStock = $hasProducts && isset($_POST['return_to_stock']) && $_POST['return_to_stock'] === '1';
    $managerId = current_user_id();

    $result = sales_cancel($conn, $saleId, $managerId, $sale['cancel_request_reason'], $returnToStock);
    if ($result['ok']) {
        // NEW: tell the customer the invoice was cancelled and nothing is owed on it.
        sms_notify_sale_cancelled($conn, (int) $saleId, $managerId ? (int) $managerId : null);
        $message = 'Sale #' . $saleId . ' cancelled.' . ($returnToStock ? ' The items were returned to stock.' : '');
        header('Location: index.php?success=' . urlencode($message));
    } else {
        header('Location: index.php?error=' . urlencode($result['error']));
    }
    exit;
}
if (isset($_POST['reject'])) {
    sales_reject_cancel_request($conn, $saleId);
    header('Location: index.php?success=' . urlencode('Cancellation request rejected.'));
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
            <tr><td class="text-muted">Status</td><td><?= htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="text-muted">Total</td><td>RWF <?= number_format($sale['total_amount'], 2); ?></td></tr>
            <tr><td class="text-muted">Paid so far</td><td>RWF <?= number_format($sale['amount_paid'], 2); ?></td></tr>
            <tr><td class="text-muted">Requested by</td><td><?= htmlspecialchars($sale['requested_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="text-muted">Reason</td><td><?= htmlspecialchars($sale['cancel_request_reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td></tr>
        </table>

        <!-- NEW: what approving does -->
        <div class="alert alert-warning small" style="border-radius:10px;">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            Approving marks the sale as <strong>Cancelled</strong>, removes its income record and its profit from the RM Funds,
            takes back any Loyalty Points it earned, and sends the customer a cancellation SMS.
            <?php if ((float) $sale['amount_paid'] > 0) { ?>
                <br><strong>RWF <?= number_format($sale['amount_paid'], 2); ?> was already paid.</strong> If you give it back to the customer, record the refund separately.
            <?php } ?>
        </div>

        <form method="POST" id="reviewForm">
            <input type="hidden" name="id" value="<?= (int) $sale['id']; ?>">

            <?php if ($hasProducts) { ?>
            <!-- NEW: return-to-stock option (same as the Cancel page) -->
            <div class="border rounded p-3 mb-4">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="return_to_stock" value="1" id="returnToStock">
                    <label class="form-check-label fw-semibold" for="returnToStock">Return items to stock</label>
                </div>
                <div class="small text-muted mb-2">
                    Tick this only if the goods are back in the shop. They will be added back to stock and their buying price returned to the RM Capital Fund.
                    Leave it unticked if the goods are gone.
                </div>
                <ul class="small mb-0">
                    <?php foreach ($productLines as $line) {
                        $baseUnits = (int) $line['quantity'] * max(1, (int) $line['pack_size']); ?>
                        <li>
                            <?= htmlspecialchars($line['product_name'], ENT_QUOTES, 'UTF-8'); ?>:
                            <?= (int) $line['quantity']; ?> <?= htmlspecialchars($line['pack_label'] ?: 'Piece', ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ((int) $line['pack_size'] > 1) { ?>
                                (<?= $baseUnits; ?> <?= htmlspecialchars($line['unit'] ?: 'units', ENT_QUOTES, 'UTF-8'); ?>)
                            <?php } ?>
                        </li>
                    <?php } ?>
                </ul>
            </div>
            <?php } ?>

            <div class="d-flex gap-2 justify-content-end">
                <button type="submit" name="reject" value="1" class="btn btn-secondary" data-action="reject">Reject</button>
                <button type="submit" name="approve" value="1" class="btn btn-danger" data-action="approve">Approve Cancellation</button>
            </div>
        </form>
    </div>
</div>

<script>
// NEW: a final confirmation that says exactly what will happen.
(function () {
    let action = '';
    document.querySelectorAll('#reviewForm button[data-action]').forEach(function (b) {
        b.addEventListener('click', function () { action = b.dataset.action; });
    });
    document.getElementById('reviewForm').addEventListener('submit', function (e) {
        let msg = 'Reject this cancellation request? The sale stays as it is.';
        if (action === 'approve') {
            const box = document.getElementById('returnToStock');
            msg = box && box.checked
                ? 'Cancel this sale and RETURN the items to stock?'
                : (box ? 'Cancel this sale WITHOUT returning the items to stock?' : 'Cancel this sale?');
        }
        if (!confirm(msg)) { e.preventDefault(); }
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>