<?php
/**
 * NEW FILE: modules/transactions/audit.php
 * Task 1: the audit trail of every transaction deletion (Admins only).
 */
require '../../config/db.php';
require_once '../../includes/transaction_audit_helpers.php';
require_role(['Admin']);
tx_audit_ensure($conn);
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT a.*, u.names FROM transaction_audit a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 500'), MYSQLI_ASSOC);
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Transaction Audit Trail</h2>
    <div class="d-flex gap-2"><a href="record_deletions.php" class="rm-btn rm-btn-secondary">Deleted Loans &amp; Assets</a>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Transactions</a></div>
</div>
<p class="text-muted small">Every deletion of a transaction: what it was, why, by whom and when. Deleted transactions stay in the list (marked Deleted) and are no longer counted in totals and reports.</p>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Date &amp; time</th><th>Reference</th><th>Action</th><th>Type / Category</th><th class="text-end">Amount (RWF)</th><th>Reason</th><th>What was done</th><th>Administrator</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">No deletions recorded yet.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?>
    <tr>
        <td class="text-nowrap"><?= date('d M Y, H:i', strtotime($r['created_at'])); ?></td>
        <td><?= $e($r['reference']); ?></td>
        <td><span class="badge bg-danger"><?= $e($r['action']); ?></span></td>
        <td><?= $e($r['transaction_type'] . ' · ' . $r['category']); ?></td>
        <td class="text-end"><?= number_format((float) $r['amount'], 2); ?></td>
        <td><?= $e($r['reason']); ?></td>
        <td class="small text-muted"><?= $e($r['details']); ?></td>
        <td><?= $e($r['names'] ?? '—'); ?></td>
    </tr>
    <?php } ?>
</table>
</div></div></div>
<?php include '../../includes/footer.php'; ?>