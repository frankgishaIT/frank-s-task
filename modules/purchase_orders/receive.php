<?php
require '../../config/db.php';
require '../../includes/purchase_order_helpers.php';
require_role(['Admin', 'Manager']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=Invalid purchase order.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT purchase_orders.*, users.names AS created_by_name FROM purchase_orders LEFT JOIN users ON purchase_orders.created_by = users.id WHERE purchase_orders.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$po = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$po) { header('Location: index.php?error=Purchase order not found.'); exit; }

$itemsStatement = mysqli_prepare($conn, 'SELECT purchase_order_items.*, products.product_name, products.product_code
    FROM purchase_order_items LEFT JOIN products ON purchase_order_items.product_id = products.id
    WHERE purchase_order_id = ? ORDER BY purchase_order_items.id');
mysqli_stmt_bind_param($itemsStatement, 'i', $id);
mysqli_stmt_execute($itemsStatement);
$items = mysqli_stmt_get_result($itemsStatement);

if (isset($_POST['confirm'])) {
    $result = po_receive($conn, $id, current_user_id());
    if ($result['ok']) {
        header('Location: view.php?id=' . $id . '&success=' . urlencode('Purchase Order received. Stock updated and Expense transaction posted.'));
    } else {
        header('Location: view.php?id=' . $id . '&error=' . urlencode($result['error']));
    }
    exit;
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
$modal_icon = 'bi-box-arrow-in-down'; $modal_title = 'Receive Purchase Order'; $modal_subtitle = 'This will update stock and post an Expense transaction automatically.';
?>
<div class="rm-modal-backdrop"><div class="rm-modal">
    <?php include '../../includes/model_header.php'; ?>
    <div class="rm-modal-body">
        <?php if ($po['status'] !== 'Ordered') { ?>
            <div class="alert alert-secondary" style="border-radius:10px;">Only Purchase Orders with status "Ordered" can be received. This one is currently "<?= htmlspecialchars($po['status'], ENT_QUOTES, 'UTF-8'); ?>".</div>
            <a href="view.php?id=<?= (int) $id; ?>" class="btn btn-light rm-btn-light">Back</a>
        <?php } else { ?>
        <table class="table table-bordered mb-3">
            <tr><th>Product</th><th>Qty</th><th>Unit Cost</th><th>Line Total</th></tr>
            <?php while ($item = mysqli_fetch_assoc($items)) { ?>
            <tr>
                <td><?= htmlspecialchars($item['product_name'] ?? 'Deleted product', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= (int) $item['quantity']; ?></td>
                <td>RWF <?= number_format($item['unit_cost'], 2); ?></td>
                <td>RWF <?= number_format($item['line_total'], 2); ?></td>
            </tr>
            <?php } ?>
        </table>
        <div class="mb-3 p-3" style="background:#F8FAFC; border-radius:12px;">
            <div class="row g-2 small">
                <div class="col-6"><span class="text-muted">Supplier:</span> <strong><?= htmlspecialchars($po['supplier'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="col-6"><span class="text-muted">Total:</span> <strong>RWF <?= number_format($po['total_amount'], 2); ?></strong></div>
            </div>
        </div>
        <p class="text-muted small">Confirming will add all quantities above to stock immediately, record them in Purchase History, and post one Expense transaction for RWF <?= number_format($po['total_amount'], 2); ?> to Transactions — no approval required.</p>
        <form method="POST">
            <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                <button class="btn btn-success rm-btn-primary" type="submit" name="confirm" value="1"><i class="bi bi-check-circle-fill me-2"></i>Confirm Receive</button>
                <a href="view.php?id=<?= (int) $id; ?>" class="btn btn-light rm-btn-light">Cancel</a>
            </div>
        </form>
        <?php } ?>
    </div>
</div></div>
<?php include '../../includes/footer.php'; ?>