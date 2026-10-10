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

$saleStatement = mysqli_prepare($conn, "SELECT sales.*, customers.name AS customer_name
    FROM sales LEFT JOIN customers ON sales.customer_id = customers.id WHERE sales.id = ?");
mysqli_stmt_bind_param($saleStatement, 'i', $saleId);
mysqli_stmt_execute($saleStatement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($saleStatement));

// CHANGED: messages are urlencoded. Before, "Sale #12 has been cancelled." was cut to "Sale "
// because a raw # in a URL starts the page anchor, and spaces were not encoded either.
if (!$sale) {
    header('Location: index.php?error=' . urlencode('Sale not found.'));
    exit;
}

if (!in_array($sale['status'], ['Credit', 'Partially Paid', 'Paid'], true)) {
    header('Location: index.php?error=' . urlencode('Only Credit, Partially Paid, or Paid sales can be cancelled.'));
    exit;
}

// NEW: the products on this sale, so the user can see what would go back into stock.
$productLinesStatement = mysqli_prepare($conn, "SELECT sale_items.quantity, sale_items.pack_label, sale_items.pack_size, products.product_name, products.unit
    FROM sale_items JOIN products ON sale_items.product_id = products.id
    WHERE sale_items.sale_id = ? AND sale_items.item_type = 'Product'");
mysqli_stmt_bind_param($productLinesStatement, 'i', $saleId);
mysqli_stmt_execute($productLinesStatement);
$productLines = mysqli_fetch_all(mysqli_stmt_get_result($productLinesStatement), MYSQLI_ASSOC);
$hasProducts = !empty($productLines);

if (isset($_POST['confirm_cancel'])) {
    $reason = trim($_POST['cancel_reason'] ?? '');
    // NEW: only possible when the sale has products; services never touch stock.
    $returnToStock = $hasProducts && isset($_POST['return_to_stock']) && $_POST['return_to_stock'] === '1';

    $result = sales_cancel($conn, $saleId, current_user_id(), $reason ?: null, $returnToStock);
    if ($result['ok']) {
        // NEW: tell the customer the invoice was cancelled and nothing is owed on it.
        $cancelUser = current_user_id();
        sms_notify_sale_cancelled($conn, (int) $saleId, $cancelUser ? (int) $cancelUser : null);
        $message = 'Sale #' . $saleId . ' has been cancelled.' . ($returnToStock ? ' The items were returned to stock.' : '');
        header('Location: index.php?success=' . urlencode($message));
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
        <!-- CHANGED: the warning now lists everything that is reversed. -->
        <div class="alert alert-warning" style="border-radius:10px;">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            This will mark the sale as <strong>Cancelled</strong>, remove its RWF <?= number_format($sale['total_amount'], 2); ?>
            income record, remove its profit from the RM Funds, and take back any Loyalty Points it earned. This cannot be undone.
            <?php if ((float) $sale['amount_paid'] > 0) { ?>
                <br><strong>RWF <?= number_format($sale['amount_paid'], 2); ?> was already paid.</strong> If you give this money back to the customer, record the refund separately.
            <?php } ?>
        </div>

        <table class="table table-borderless mb-4" style="max-width:500px;">
            <tr><td class="text-muted">Customer</td><td><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="text-muted">Sale Date</td><td><?= date('d M Y', strtotime($sale['sale_date'])); ?></td></tr>
            <tr><td class="text-muted">Total</td><td>RWF <?= number_format($sale['total_amount'], 2); ?></td></tr>
            <tr><td class="text-muted">Paid so far</td><td>RWF <?= number_format($sale['amount_paid'], 2); ?></td></tr>
            <tr><td class="text-muted">Status</td><td><?= htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
        </table>

        <form method="POST" id="cancelForm">
            <input type="hidden" name="id" value="<?= (int) $sale['id']; ?>">

            <?php if ($hasProducts) { ?>
            <!-- NEW: return-to-stock option -->
            <div class="border rounded p-3 mb-4" style="border-radius:10px !important;">
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="return_to_stock" value="1" id="returnToStock">
                    <label class="form-check-label fw-semibold" for="returnToStock">
                        Return items to stock
                    </label>
                </div>
                <div class="small text-muted mb-2">
                    Tick this only if the goods are back in the shop (for example, the sale was entered by mistake or the customer returned everything).
                    The items will be added back to stock and their buying price returned to the RM Capital Fund.
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

<script>
// NEW: a final confirmation that says exactly what will happen to the stock.
document.getElementById('cancelForm').addEventListener('submit', function (e) {
    const box = document.getElementById('returnToStock');
    const msg = box && box.checked
        ? 'Cancel this sale and RETURN the items to stock?'
        : (box ? 'Cancel this sale WITHOUT returning the items to stock?' : 'Cancel this sale?');
    if (!confirm(msg)) { e.preventDefault(); }
});
</script>

<?php include '../../includes/footer.php'; ?>