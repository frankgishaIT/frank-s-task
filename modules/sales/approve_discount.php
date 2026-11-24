<?php
require '../../config/db.php';
require '../../includes/sales_helpers.php';   // also loads fund_helpers.php and profit_rules.php (require_once)
require_once '../../includes/sms_messages.php'; // NEW: customer SMS when the sale is approved (MY MOTIVE SMS, messages 2 and 3)
require_role(['Manager', 'Admin']);
require_once '../../includes/price_approval.php'; // NEW (Customer First 3.5): set price, proposed price, difference, reason, margin, decision
pa_ensure($conn);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
require_once __DIR__ . '/../../includes/branch_helpers.php'; br_guard_record($conn, 'sales', $id); // NEW (Multi-branch): records of other branches are refused (and logged)
// CHANGED: problems are sent as "error" (they were sent as "success"), and messages are urlencoded.
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid sale selected.')); exit; }

$statement = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name, customers.loyalty_points AS customer_loyalty_points, users.names AS requested_by_name, d.names AS decided_by_name FROM sales LEFT JOIN customers ON sales.customer_id = customers.id LEFT JOIN users ON sales.discount_requested_by = users.id LEFT JOIN users d ON d.id = COALESCE(sales.price_decided_by, sales.discount_approved_by) WHERE sales.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$sale) { header('Location: index.php?error=' . urlencode('Sale not found.')); exit; }

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

$paLines = pa_lines($conn, (int) $id); $paSum = pa_summary($sale, $paLines); // NEW (3.5)
if ($canAct && isset($_POST['decision']) && in_array($_POST['decision'], ['approve', 'reject'], true)) {
    $decision = $_POST['decision'];
    $managerId = current_user_id();
    $decisionReason = mb_substr(trim((string) ($_POST['decision_reason'] ?? '')), 0, 255); // NEW (3.5)
    if ($decision === 'reject' && mb_strlen($decisionReason) < 3) { $error = 'Give the reason for the rejection (the employee will see it).'; $decision = null; }
}
if ($canAct && !empty($decision)) {

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
            header('Location: index.php?error=' . urlencode('This sale has already been actioned by someone else.'));
            exit;
        }

        if ($decision === 'approve') {
            $update = mysqli_prepare($conn, "UPDATE sales SET discount_approved_by = ?, discount_approved_at = NOW(), price_decision = 'Approved', price_decided_by = ?, price_decided_at = NOW(), price_decision_reason = ? WHERE id = ?");
            $dr = $decisionReason !== '' ? $decisionReason : null; mysqli_stmt_bind_param($update, 'iisi', $managerId, $managerId, $dr, $id);
            mysqli_stmt_execute($update);

            // CHANGED: passes the approver so the fund allocation records who triggered it.
            if (!sales_finalize($conn, $id, $managerId ? (int) $managerId : null)) {
                throw new RuntimeException('Sale not found.');
            }

            mysqli_commit($conn);
            // NEW: the receipt (paid) or credit SMS goes out now that the sale is approved and saved.
            sms_notify_sale_created($conn, (int) $id, $managerId ? (int) $managerId : null);
            header('Location: invoice.php?id=' . $id . '&success=' . urlencode('Sale approved. Invoice released.'));
            exit;
        }

        // reject: the sale was never finalized, so no stock, income or fund movement exists to undo
        $update = mysqli_prepare($conn, "UPDATE sales SET status = 'Cancelled', price_decision = 'Rejected', price_decided_by = ?, price_decided_at = NOW(), price_decision_reason = ? WHERE id = ? AND status = 'Pending Discount Approval'");
        mysqli_stmt_bind_param($update, 'isi', $managerId, $decisionReason, $id);
        mysqli_stmt_execute($update);

        mysqli_commit($conn);
        if (!empty($sale['discount_requested_by']) && (int) $sale['discount_requested_by'] !== (int) $managerId) { // NEW (3.5): the employee learns the decision and why
            require_once '../../includes/notification_helper.php';
            notifyUser($conn, (int) $sale['discount_requested_by'], 'Price approval rejected', 'Sale #' . $id . ' was not approved: ' . $decisionReason);
        }
        // No SMS on rejection: the customer was never sent an invoice for this sale.
        header('Location: index.php?success=' . urlencode('Sale cancelled — was not approved.'));
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

        <!-- CHANGED (3.5): set price, proposed price and the difference of every line -->
        <div class="table-responsive"><table class="table table-bordered table-sm mb-3 small">
            <tr><th>Product</th><th class="text-end">Qty</th><th class="text-end">Set price</th><th class="text-end">Proposed price</th><th class="text-end">Difference</th><th class="text-end">Line total</th></tr>
            <?php foreach ($paLines as $item) { ?>
            <tr><td><?= htmlspecialchars($item['product_name'] ?? $item['service_name'] ?? 'Item', ENT_QUOTES, 'UTF-8'); ?><?= ($item['pack_size'] ?? 1) > 1 ? ' <span class="text-muted">(' . htmlspecialchars((string) $item['pack_label'], ENT_QUOTES, 'UTF-8') . ')</span>' : ''; ?></td><td class="text-end"><?= (int) $item['quantity']; ?></td>
                <td class="text-end"><?= $item['set'] !== null ? number_format($item['set'], 2) : '—'; ?></td><td class="text-end"><?= number_format($item['unit_price'], 2); ?></td>
                <td class="text-end <?= $item['diff'] !== null && $item['diff'] < -0.004 ? 'text-danger fw-semibold' : 'text-muted'; ?>"><?= $item['diff'] !== null ? ($item['diff'] < -0.004 ? '− ' : '') . number_format(abs($item['diff']), 2) : '—'; ?></td><td class="text-end"><?= number_format($item['line_total'], 2); ?></td></tr>
            <?php } ?>
        </table></div>
        <div class="mb-3 p-3 small" style="background:#FFF8E6; border-radius:12px;">
            <div class="row g-2">
                <div class="col-6 col-md-3"><span class="text-muted d-block">Set price (total)</span><strong><?= $paSum['set_total'] !== null ? 'RWF ' . number_format($paSum['set_total'], 2) : '—'; ?></strong></div>
                <div class="col-6 col-md-3"><span class="text-muted d-block">Proposed total</span><strong>RWF <?= number_format($paSum['proposed'], 2); ?></strong></div>
                <div class="col-6 col-md-3"><span class="text-muted d-block">Difference</span><strong class="text-danger"><?= $paSum['difference'] !== null ? 'RWF ' . number_format($paSum['difference'], 2) : '—'; ?></strong></div>
                <div class="col-6 col-md-3"><span class="text-muted d-block">Margin after this price</span><strong class="<?= $paSum['loss'] ? 'text-danger' : ''; ?>"><?= $paSum['margin'] !== null ? 'RWF ' . number_format($paSum['margin'], 2) . ($paSum['loss'] ? ' (LOSS)' : '') : 'cost not known'; ?></strong></div>
                <div class="col-12"><span class="text-muted">Reason given by <?= htmlspecialchars($sale['requested_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>:</span> <strong><?= htmlspecialchars($sale['discount_reason'] ?? '— (no reason recorded)', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                <?php [$paLab, $paCol] = pa_status($sale); if ($paLab) { ?><div class="col-12"><span class="text-muted">Status:</span> <span class="badge bg-<?= $paCol; ?><?= $paCol === 'warning' ? ' text-dark' : ''; ?>"><?= $paLab; ?></span><?php if (!empty($sale['decided_by_name']) && $sale['status'] !== 'Pending Discount Approval') { ?> by <strong><?= htmlspecialchars($sale['decided_by_name'], ENT_QUOTES, 'UTF-8'); ?></strong> on <?= htmlspecialchars(date('d M Y H:i', strtotime((string) ($sale['price_decided_at'] ?: $sale['discount_approved_at']))), ENT_QUOTES, 'UTF-8'); ?><?= !empty($sale['price_decision_reason']) ? ' — ' . htmlspecialchars($sale['price_decision_reason'], ENT_QUOTES, 'UTF-8') : ''; ?><?php } ?></div><?php } ?>
            </div>
        </div>
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
            <label class="form-label small fw-semibold text-muted">Decision reason <span class="fw-normal">(required to reject; the employee sees it)</span></label>
            <input name="decision_reason" maxlength="255" class="form-control form-control-sm" value="<?= htmlspecialchars((string) ($_POST['decision_reason'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
            <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                <button class="btn btn-success rm-btn-primary" type="submit" name="decision" value="approve"><i class="bi bi-check-circle-fill me-2"></i>Approve Sale</button>
                <button class="btn btn-danger rm-btn-primary" type="submit" name="decision" value="reject" onclick="return confirm('Reject and cancel this sale?');"><i class="bi bi-x-circle-fill me-2"></i>Reject & Cancel Sale</button>
                <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
            </div>
        </form>
        <?php } ?>
    </div>
</div></div>
<?php include '../../includes/footer.php'; ?>