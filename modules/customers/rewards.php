<?php
/**
 * NEW FILE: modules/customers/rewards.php
 * Rewards Management: all customer rewards, or one customer's Rewards section (?customer_id=).
 */
require '../../config/db.php';
require_once '../../includes/reward_helpers.php';
require_role(['Admin', 'Manager']);

$isAdmin = current_user_role() === 'Admin';
$customerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT) ?: 0;
$status = $_GET['status'] ?? '';
$statuses = ['Pending', 'Fulfilled', 'Cancelled', 'Reversed'];

$customer = null;
if ($customerId) {
    $s = mysqli_prepare($conn, 'SELECT * FROM customers WHERE id = ?');
    mysqli_stmt_bind_param($s, 'i', $customerId);
    mysqli_stmt_execute($s);
    $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$customer) { header('Location: rewards.php?error=' . urlencode('Customer not found.')); exit; }
}

$where = []; $types = ''; $params = [];
if ($customerId) { $where[] = 'r.customer_id = ?'; $types .= 'i'; $params[] = $customerId; }
if (in_array($status, $statuses, true)) { $where[] = 'r.status = ?'; $types .= 's'; $params[] = $status; }
$sql = 'SELECT r.*, t.name AS type_name, t.pays_money, c.name AS customer_name, pu.names AS processed_name, au.names AS approved_name
    FROM customer_rewards r JOIN reward_types t ON t.id = r.reward_type_id JOIN customers c ON c.id = r.customer_id
    LEFT JOIN users pu ON pu.id = r.processed_by LEFT JOIN users au ON au.id = r.approved_by'
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY r.id DESC LIMIT 300';
$st = mysqli_prepare($conn, $sql);
if ($types !== '') { mysqli_stmt_bind_param($st, $types, ...$params); }
mysqli_stmt_execute($st);
$rewards = mysqli_fetch_all(mysqli_stmt_get_result($st), MYSQLI_ASSOC);

$tPoints = 0; $tPaid = 0.0; $pending = 0;
foreach ($rewards as $r) {
    if (in_array($r['status'], ['Pending', 'Fulfilled'], true)) { $tPoints += (int) $r['points_redeemed']; }
    if ($r['payment_status'] === 'Paid') { $tPaid += (float) $r['reward_amount']; }
    if ($r['status'] === 'Pending') { $pending++; }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
$badge = ['Pending' => 'warning text-dark', 'Fulfilled' => 'success', 'Cancelled' => 'secondary', 'Reversed' => 'dark'];
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show"><?= $e($_GET['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($_GET['error'])) { ?><div class="alert alert-danger alert-dismissible fade show"><?= $e($_GET['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="index.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to Customers</a>
        <h2 class="mt-1"><?= $customer ? 'Rewards — ' . $e($customer['name']) : 'Customer Rewards'; ?></h2>
    </div>
    <div class="d-flex gap-2">
        <?php if ($isAdmin) { ?><a href="reward_types.php" class="btn btn-outline-secondary">Reward Types</a><?php } ?>
        <a href="reward_create.php<?= $customerId ? '?customer_id=' . (int) $customerId : ''; ?>" class="rm-btn rm-btn-primary">+ Record Reward</a>
    </div>
</div>

<?php if ($customer) { ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <div class="text-muted small">Customer ID</div><div class="fs-5 fw-bold">RMC<?= str_pad((string) $customer['id'], 6, '0', STR_PAD_LEFT); ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <div class="text-muted small">Unredeemed Points Balance</div><div class="fs-5 fw-bold" style="color:var(--accent-blue);"><?= number_format((int) $customer['loyalty_points']); ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <div class="text-muted small">Points Redeemed</div><div class="fs-5 fw-bold"><?= number_format($tPoints); ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm"><div class="card-body">
        <div class="text-muted small">Rewards Paid (RWF)</div><div class="fs-5 fw-bold"><?= number_format($tPaid, 2); ?></div></div></div></div>
</div>
<?php } ?>

<form method="GET" class="row g-2 mb-3">
    <?php if ($customerId) { ?><input type="hidden" name="customer_id" value="<?= (int) $customerId; ?>"><?php } ?>
    <div class="col-auto">
        <select name="status" class="form-select" onchange="this.form.submit()">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s) { ?><option value="<?= $s; ?>" <?= $status === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?>
        </select>
    </div>
    <?php if ($pending > 0 && $isAdmin) { ?><div class="col-auto align-self-center small text-warning fw-semibold"><?= $pending; ?> reward(s) waiting for approval</div><?php } ?>
</form>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0 align-middle" style="font-size:13px;">
    <tr>
        <th>Reward ID</th><th>Customer</th><th>Type</th><th>Reward Given</th><th class="text-end">Amount (RWF)</th>
        <th class="text-end">Points Before</th><th class="text-end">Redeemed</th><th class="text-end">Remaining</th>
        <th>Status</th><th>Payment</th><th>Earned</th><th>Fulfilled</th><th>Processed / Approved</th><th>Evidence</th><th>Action</th>
    </tr>
    <?php if (!$rewards) { ?><tr><td colspan="15" class="text-center text-muted py-4">No rewards recorded yet.</td></tr><?php } ?>
    <?php foreach ($rewards as $r) { $rid = (int) $r['id']; ?>
    <tr>
        <td class="text-nowrap fw-semibold"><?= reward_code($rid); ?><?php if ($r['voucher_code']) { ?><div class="small text-muted">Voucher <?= $e($r['voucher_code']); ?></div><?php } ?></td>
        <td><a href="rewards.php?customer_id=<?= (int) $r['customer_id']; ?>">RMC<?= str_pad((string) $r['customer_id'], 6, '0', STR_PAD_LEFT); ?></a><div class="small"><?= $e($r['customer_name']); ?></div></td>
        <td><?= $e($r['type_name']); ?></td>
        <td><?= $e($r['reward_details']); ?><?php if ($r['notes']) { ?><div class="small text-muted"><?= $e($r['notes']); ?></div><?php } ?></td>
        <td class="text-end"><?= number_format((float) $r['reward_amount'], 2); ?></td>
        <td class="text-end"><?= number_format((int) $r['points_before']); ?></td>
        <td class="text-end"><?= number_format((int) $r['points_redeemed']); ?></td>
        <td class="text-end"><?= number_format((int) $r['points_after']); ?></td>
        <td><span class="badge bg-<?= $badge[$r['status']] ?? 'secondary'; ?>"><?= $e($r['status']); ?></span>
            <?php if ($r['cancel_reason']) { ?><i class="bi bi-info-circle" title="<?= $e($r['cancel_reason']); ?>"></i><?php } ?></td>
        <td><?= $e($r['payment_status']); ?></td>
        <td class="text-nowrap"><?= date('d M Y', strtotime($r['date_earned'])); ?></td>
        <td class="text-nowrap"><?= $r['fulfilled_at'] ? date('d M Y', strtotime($r['fulfilled_at'])) : '—'; ?></td>
        <td><?= $e($r['processed_name'] ?? '—'); ?><div class="small text-muted"><?= $e($r['approved_name'] ?? 'not approved yet'); ?></div></td>
        <td><?= $r['evidence_file'] ? '<a href="reward_evidence.php?id=' . $rid . '" target="_blank">View</a>' : '—'; ?></td>
        <td class="text-nowrap">
            <?php if ($r['status'] === 'Pending' && $isAdmin) { ?>
                <form method="POST" action="reward_action.php" class="d-inline" onsubmit="return confirm('Approve and fulfil this reward?<?= (int) $r['pays_money'] === 1 && (float) $r['reward_amount'] > 0 ? ' RWF ' . number_format((float) $r['reward_amount'], 2) . ' will be paid from the RM Business Operating Fund.' : ''; ?>');">
                    <input type="hidden" name="id" value="<?= $rid; ?>"><input type="hidden" name="action" value="fulfil">
                    <button class="btn btn-success btn-sm">Fulfil</button>
                </form>
            <?php } ?>
            <?php if ($r['status'] === 'Pending' || ($r['status'] === 'Fulfilled' && $isAdmin)) { $act = $r['status'] === 'Pending' ? 'cancel' : 'reverse'; ?>
                <form method="POST" action="reward_action.php" class="d-inline reason-form">
                    <input type="hidden" name="id" value="<?= $rid; ?>"><input type="hidden" name="action" value="<?= $act; ?>">
                    <input type="hidden" name="reason" value="">
                    <button class="btn btn-outline-danger btn-sm"><?= $act === 'cancel' ? 'Cancel' : 'Reverse'; ?></button>
                </form>
            <?php } ?>
        </td>
    </tr>
    <?php } ?>
</table>
</div></div></div>

<script>
// Cancel / Reverse need a reason (kept in the history).
document.querySelectorAll('.reason-form').forEach(function (f) {
    f.addEventListener('submit', function (e) {
        var reverse = f.querySelector('[name=action]').value === 'reverse';
        var reason = prompt(reverse
            ? 'Reverse this reward? The points go back to the customer and any money paid returns to the RM Business Operating Fund.\n\nReason:'
            : 'Cancel this reward? The points go back to the customer.\n\nReason:');
        if (!reason || !reason.trim()) { e.preventDefault(); return; }
        f.querySelector('[name=reason]').value = reason.trim();
    });
});
</script>
<?php include '../../includes/footer.php'; ?>