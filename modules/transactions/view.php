<?php
require '../../config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    header('Location: index.php?success=Invalid transaction selected.');
    exit;
}

$statement = mysqli_prepare($conn, 'SELECT transactions.*, users.names AS recorder_name, funds.name AS fund_name FROM transactions LEFT JOIN users ON transactions.recorded_by = users.id LEFT JOIN funds ON transactions.fund_id = funds.id WHERE transactions.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$transaction = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$transaction) {
    header('Location: index.php?success=Transaction not found.');
    exit;
}

// Audit trail: every Fund movement caused by this transaction.
$trail = [];
if (!empty($transaction['fund_id'])) {
    $t = mysqli_prepare($conn, 'SELECT m.created_at, m.movement_type, m.direction, m.amount, m.description, u.names AS who FROM fund_movements m LEFT JOIN users u ON u.id = m.created_by WHERE m.transaction_id = ? ORDER BY m.id ASC');
    mysqli_stmt_bind_param($t, 'i', $id);
    mysqli_stmt_execute($t);
    $trail = mysqli_fetch_all(mysqli_stmt_get_result($t), MYSQLI_ASSOC);
}

$statusBadge = ['approved' => 'success', 'pending' => 'warning text-dark', 'rejected' => 'danger'];

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>
<div class="card shadow">
    <div class="card-header">
        <h3>Transaction Details</h3>
    </div>
    <div class="card-body">
        <table class="table table-bordered mb-4">
            <tr><th width="200">Category</th><td><?= htmlspecialchars($transaction['category'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Type</th><td><?= htmlspecialchars($transaction['transaction_type'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Amount</th><td>RWF <?= number_format((float) $transaction['amount'], 2); ?></td></tr>
            <?php if ($transaction['transaction_type'] === 'Expense') { ?>
            <tr><th>Fund</th><td><?= htmlspecialchars($transaction['fund_name'] ?? 'Not assigned (recorded before Funds)', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Expense Category</th><td><?= htmlspecialchars($transaction['expense_category'] ?: '—', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <?php } ?>
            <tr><th>Date</th><td><?= date('d M Y', strtotime($transaction['transaction_date'])); ?></td></tr>
            <tr><th>Status</th><td><span class="badge bg-<?= $statusBadge[$transaction['status']] ?? 'secondary'; ?>"><?= ucfirst(htmlspecialchars($transaction['status'], ENT_QUOTES, 'UTF-8')); ?></span>
                <?php if ($transaction['status'] === 'rejected' && !empty($transaction['rejection_reason'])) { ?>
                    <span class="text-muted small ms-2"><?= htmlspecialchars($transaction['rejection_reason'], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php } ?>
            </td></tr>
            <tr><th>Description</th><td><?= nl2br(htmlspecialchars($transaction['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td></tr>
            <tr><th>Recorded By</th><td><?= htmlspecialchars($transaction['recorder_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td></tr>
        </table>

        <?php if (!empty($transaction['fund_id'])) { ?>
        <h5 class="mb-2">Fund Movement History</h5>
        <?php if ($transaction['status'] === 'pending') { ?>
            <p class="text-muted small">This expense is waiting for approval. The amount is reserved in <?= htmlspecialchars($transaction['fund_name'] ?? 'the Fund', ENT_QUOTES, 'UTF-8'); ?> but has not left it yet.</p>
        <?php } elseif (!$trail) { ?>
            <p class="text-muted small">No money has moved in the Fund for this transaction.</p>
        <?php } else { ?>
        <div class="table-responsive mb-4">
            <table class="table table-sm table-bordered mb-0">
                <tr><th>Date</th><th>Movement</th><th>Description</th><th>Posted By</th><th class="text-end">Amount (RWF)</th></tr>
                <?php foreach ($trail as $m) { ?>
                <tr>
                    <td><?= date('d M Y H:i', strtotime($m['created_at'])); ?></td>
                    <td><?= $m['direction'] === 'OUT' ? 'Money out' : 'Money returned'; ?> (<?= ucfirst(strtolower($m['movement_type'])); ?>)</td>
                    <td><?= htmlspecialchars($m['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars($m['who'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end"><?= $m['direction'] === 'OUT' ? '-' : '+'; ?><?= number_format((float) $m['amount'], 2); ?></td>
                </tr>
                <?php } ?>
            </table>
        </div>
        <?php } ?>
        <?php } ?>

        <a href="index.php" class="btn btn-secondary">Back</a>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>