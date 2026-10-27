<?php
/**
 * NEW FILE: modules/transactions/delete_confirm.php
 * Task 1: the warning shown BEFORE deleting a transaction (Admins only): what it is, what deleting it does
 * to the money, and a required reason. An automatic transaction is not deleted here: the page explains
 * where it came from and links to that record, where it is cancelled or reversed properly.
 */
require '../../config/db.php';
require_once '../../includes/transaction_audit_helpers.php';
require_role(['Admin']);
tx_audit_ensure($conn);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$s = mysqli_prepare($conn, 'SELECT t.*, f.name AS fund_name, u.names AS recorder FROM transactions t
    LEFT JOIN funds f ON f.id = t.fund_id LEFT JOIN users u ON u.id = t.recorded_by WHERE t.id = ?');
mysqli_stmt_bind_param($s, 'i', $id);
mysqli_stmt_execute($s);
$tx = $id ? mysqli_fetch_assoc(mysqli_stmt_get_result($s)) : null;
if (!$tx) { header('Location: index.php?error=' . urlencode('Transaction not found.')); exit; }
if ($tx['status'] === 'deleted') { header('Location: index.php?error=' . urlencode(tx_reference((int) $id) . ' has already been deleted.')); exit; }

$source = tx_source($conn, $tx);
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$modal_icon = $source ? 'bi-signpost-split-fill' : 'bi-exclamation-triangle-fill';
$modal_title = $source ? 'Correct at its source' : 'Delete transaction';
$modal_subtitle = tx_reference((int) $id) . ' · ' . $tx['transaction_type'] . ' · RWF ' . number_format((float) $tx['amount'], 2);
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>
        <div class="rm-modal-body">
            <table class="table table-sm mb-3" style="font-size:13px;">
                <tr><th style="width:35%;">Reference</th><td><?= $e(tx_reference((int) $id)); ?></td></tr>
                <tr><th>Date</th><td><?= date('d M Y', strtotime($tx['transaction_date'])); ?></td></tr>
                <tr><th>Type / Category</th><td><?= $e($tx['transaction_type'] . ' · ' . ($tx['expense_category'] ?: $tx['category'])); ?></td></tr>
                <tr><th>Amount</th><td class="fw-semibold">RWF <?= number_format((float) $tx['amount'], 2); ?></td></tr>
                <tr><th>Fund</th><td><?= $e($tx['fund_name'] ?? '—'); ?></td></tr>
                <tr><th>Status</th><td><?= $e(ucfirst($tx['status'])); ?></td></tr>
                <tr><th>Recorded by</th><td><?= $e($tx['recorder'] ?? '—'); ?></td></tr>
                <tr><th>Description</th><td><?= $e($tx['description']); ?></td></tr>
            </table>

            <?php if ($source) { ?>
                <div class="alert alert-info small">
                    <strong>This transaction was created automatically by: <?= $e($source['label']); ?>.</strong><br>
                    It cannot be deleted here, because its source record (and its stock, balances or fund entries) would be left out of step.
                    <div class="mt-2"><strong>How to correct it:</strong> <?= $e($source['how']); ?></div>
                </div>
                <div class="d-flex gap-2 justify-content-end">
                    <a href="<?= $e($source['url']); ?>" class="rm-btn rm-btn-primary"><i class="bi bi-box-arrow-up-right me-1"></i>Open the source record</a>
                    <a href="index.php" class="rm-btn rm-btn-secondary">Back</a>
                </div>
            <?php } else { ?>
                <div class="alert alert-danger small">
                    <strong>What deleting this transaction does:</strong>
                    <ul class="mb-0 mt-1"><?php foreach (tx_delete_consequences($conn, $tx) as $line) { ?><li><?= $e($line); ?></li><?php } ?></ul>
                </div>
                <form method="POST" action="delete.php" onsubmit="return confirm('Delete <?= $e(tx_reference((int) $id)); ?> now?');">
                    <input type="hidden" name="id" value="<?= (int) $id; ?>">
                    <label class="form-label small fw-semibold text-muted">Reason for deleting <span class="text-danger">*</span></label>
                    <textarea name="reason" class="form-control rm-input mb-3" rows="3" minlength="5" maxlength="1000" required placeholder="e.g. Recorded twice by mistake (the correct one is TX-000123)"></textarea>
                    <div class="d-flex gap-2 justify-content-end">
                        <button type="submit" class="rm-btn rm-btn-danger"><i class="bi bi-trash me-1"></i>Delete transaction</button>
                        <a href="index.php" class="rm-btn rm-btn-secondary">Cancel</a>
                    </div>
                </form>
            <?php } ?>
        </div>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>