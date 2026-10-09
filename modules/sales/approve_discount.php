<?php
require '../../config/db.php';
require '../../includes/sales_helpers.php';   // also loads fund_helpers.php and profit_rules.php (require_once)
require_role(['Manager', 'Admin']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid sale selected.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name, customers.loyalty_points AS customer_loyalty_points, users.names AS requested_by_name FROM sales LEFT JOIN customers ON sales.customer_id = customers.id LEFT JOIN users ON sales.discount_requested_by = users.id WHERE sales.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$sale) { header('Location: index.php?success=Sale not found.'); exit; }

$itemsStatement = mysqli_prepare($conn, 'SELECT sale_items.*, products.product_name FROM sale_items LEFT JOIN products ON sale_items.product_id = products.id WHERE sale_id = ? ORDER BY sale_items.id');
mysqli_stmt_bind_param($itemsStatement, 'i', $id);
mysqli_stmt_execute($itemsStatement);
$items = mysqli_stmt_get_result($itemsStatement);

$role = current_user_role();
$isPending = $sale['status'] === 'Pending Discount Approval';
$needsDiscount = (float) $sale['discount_amount'] > 0;
$needsCredit = !empty($sale['needs_credit_approval']);
$customerLoyaltyPoints = $sale['customer_loyalty_points'] !== null ? (int) $sale['customer_loyalty_points'] : 0;

// Credit sales below the Loyalty Points threshold require the Managing
// Director (Admin role) specifically — a Manager may view the request but
// cannot approve/reject it themselves. Discount-only requests (no credit
// issue) keep the existing Manager-or-Admin approval.
$canAct = $isPending && (!$needsCredit || $role === 'Admin');

if ($canAct && isset($_POST['decision']) && in_array($_POST['decision'], ['approve', 'reject'], true)) {
    $decision = $_POST['decision'];
    $managerId = current_user_id();

    // CHANGED: the whole decision runs in ONE database transaction. Approving deducts stock,
    // posts the income, and (if the sale is fully paid) allocates profit to the Funds, so all
    // of it must succeed together or not at all.
    mysqli_begin_transaction($conn);
    try {
        // NEW: re-check the status under a lock. Two people acting on the same sale at the
        // same moment can no longer finalize it twice, or reject a sale that was just approved.
        $lock = mysqli_prepare($conn, 'SELECT status FROM sales WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($lock, 'i', $id);
        mysqli_stmt_execute($lock);
        $current = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));

        if (!$current || $current['status'] !== 'Pending Discount Approval') {
            mysqli_rollback($conn);
            header('Location: index.php?success=' . urlencode('This sale has already been actioned by someone else.'));
            exit;
        }

        if ($decision === 'approve') {
            $update = mysqli_prepare($conn, 'UPDATE sales SET discount_approved_by = ?, discount_approved_at = NOW() WHERE id = ?');
            mysqli_stmt_bind_param($update, 'ii', $managerId, $id);
            mysqli_stmt_execute($update);

            // CHANGED: passes the approver so the fund allocation records who triggered it.
            if (!sales_finalize($conn, $id, $managerId ? (int) $managerId : null)) {
                throw new RuntimeException('Sale not found.');
            }

            mysqli_commit($conn);
            header('Location: invoice.php?id=' . $id . '&success=Sale approved. Invoice released.');
            exit;
        }

        // reject: the sale was never finalized, so no stock, income or fund movement exists to undo
        $update = mysqli_prepare($conn, "UPDATE sales SET status = 'Cancelled' WHERE id = ? AND status = 'Pending Discount Approval'");
        mysqli_stmt_bind_param($update, 'i', $id);
        mysqli_stmt_execute($update);

        mysqli_commit($conn);
        header('Location: index.php?success=Sale cancelled — was not approved.');
        exit;
    } catch (mysqli_sql_exception $e) {
        mysqli_rollback($conn);
        error_log('approve_discount failed for sale #' . $id . ': ' . $e->getMessage());
        $error = 'Unable to process this sale. Nothing was changed. Please try again.';
    } catch (RuntimeException $e) {
        mysqli_rollback($conn);
        $error = $e->getMessage() . ' Nothing was changed.';
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('approve_discount failed for sale #' . $id . ': ' . $e->getMessage());
        $error = 'Unable to process this sale. Nothing was changed. Please try again.';
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
$modal_icon = 'bi-shield-check'; $modal_title = 'Sale Approval'; $modal_subtitle = 'Requested by ' . htmlspecialchars($sale['requested_by_name'] ?? '—', ENT_QUOTES, 'UTF-8');
?>
<div class="rm-modal-backdrop"><div class="rm-modal">
    <?php include '../../includes/model_header.php'; ?>
    <div class="rm-modal-body">
        <?php if (!empty($error)) { ?>
            <div class="alert alert-danger" style="border-radius:10px;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php } ?>
        <div class="mb-3">
            <?php if ($needsDiscount) { ?>
                <span class="badge bg-info text-dark me-1">Discount Requested</span>
            <?php } ?>
            <?php if ($needsCredit) { ?>
                <span class="badge bg-danger me-1">Credit — Below 500 Points</span>
                <span class="badge bg-dark me-1">Requires Managing Director Approval</span>
            <?php } ?>
        </div>

        <table class="table table-bordered mb-3">
            <tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Line Total</th></tr>
            <?php while ($item = mysqli_fetch_assoc($items)) { ?>
            <tr><td><?= htmlspecialchars($item['product_name'] ?? $item['service_name'] ?? 'Item', ENT_QUOTES, 'UTF-8'); ?></td><td><?= (int) $item['quantity']; ?></td><td>RWF <?= number_format($item['unit_price'], 2); ?></td><td>RWF <?= number_format($item['line_total'], 2); ?></td></tr>
            <?php } ?>
        </table>
        <div class="mb-3 p-3" style="background:#F8FAFC; border-radius:12px;">
            <div class="row g-2 small">
                <div class="col-6"><span class="text-muted">Customer:</span> <strong><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <div class="col-6"><span class="text-muted">Payment Method:</span> <strong><?= htmlspecialchars($sale['payment_method'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php if ($sale['customer_id']) { ?>
                <div class="col-6"><span class="text-muted">Customer Loyalty Points:</span> <strong><?= $customerLoyaltyPoints; ?></strong></div>
                <div class="col-6"><span class="text-muted">Credit Status:</span> <strong><?= customer_credit_status($customerLoyaltyPoints); ?></strong></div>
                <?php } ?>
                <div class="col-6"><span class="text-muted">Subtotal:</span> <strong>RWF <?= number_format($sale['subtotal'], 2); ?></strong></div>
                <div class="col-6"><span class="text-muted">Discount Requested:</span> <strong class="text-danger">- RWF <?= number_format($sale['discount_amount'], 2); ?></strong></div>
                <div class="col-6"><span class="text-muted">Total After Discount:</span> <strong>RWF <?= number_format($sale['total_amount'], 2); ?></strong></div>
            </div>
        </div>

        <?php if (!$isPending) { ?>
            <div class="alert alert-secondary" style="border-radius:10px;">This sale has already been actioned (<?= htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8'); ?>).</div>
            <a href="index.php" class="btn btn-light rm-btn-light">Back</a>
        <?php } elseif (!$canAct) { ?>
            <div class="alert alert-secondary" style="border-radius:10px;">This is a Credit sale below the Loyalty Points threshold, so it requires Managing Director (Admin) approval. You can view it, but only an Admin can approve or reject it.</div>
            <a href="index.php" class="btn btn-light rm-btn-light">Back</a>
        <?php } else { ?>
        <form method="POST">
            <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                <button class="btn btn-success rm-btn-primary" type="submit" name="decision" value="approve"><i class="bi bi-check-circle-fill me-2"></i>Approve Sale</button>
                <button class="btn btn-danger rm-btn-primary" type="submit" name="decision" value="reject"><i class="bi bi-x-circle-fill me-2"></i>Reject & Cancel Sale</button>
                <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
            </div>
        </form>
        <?php } ?>
    </div>
</div></div>
<?php include '../../includes/footer.php'; ?>