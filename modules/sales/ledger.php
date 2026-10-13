<?php
/**
 * NEW FILE: modules/sales/ledger.php
 * The credit ledger of one invoice: principal, payments, daily overdue charges and waivers,
 * with the balances after each entry (complete audit trail). Admin can waive unpaid charges.
 */
require '../../config/db.php';
require_once '../../includes/credit_charge_helpers.php';
require_role(['Admin', 'Manager']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid sale.')); exit; }
$isAdmin = current_user_role() === 'Admin';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['waive'])) {
    $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 200);
    $amount = $_POST['amount'] === '' ? null : filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    if (!$isAdmin) {
        $error = 'Only an Admin can waive charges.';
    } elseif ($reason === '') {
        $error = 'Please give a reason for the waiver.';
    } elseif ($amount !== null && ($amount === false || $amount <= 0)) {
        $error = 'Enter a valid amount, or leave it empty to waive all unpaid charges.';
    } else {
        mysqli_begin_transaction($conn);
        try {
            credit_accrue($conn, (int) $id, (int) current_user_id());
            $w = credit_waive($conn, (int) $id, $amount === null ? null : round($amount, 2), 'Waived by Admin: ' . $reason, (int) current_user_id());
            mysqli_commit($conn);
            header('Location: ledger.php?id=' . (int) $id . '&success=' . urlencode($w > 0 ? 'RWF ' . number_format($w, 2) . ' of charges waived.' : 'There were no unpaid charges to waive.'));
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('charge waiver failed for sale #' . $id . ': ' . $e->getMessage());
            $error = 'Unable to waive the charges. Nothing was changed.';
        }
    }
}

credit_accrue_now($conn, (int) $id);
$s = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name FROM sales LEFT JOIN customers ON customers.id = sales.customer_id WHERE sales.id = ?');
mysqli_stmt_bind_param($s, 'i', $id);
mysqli_stmt_execute($s);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
if (!$sale) { header('Location: index.php?error=' . urlencode('Sale not found.')); exit; }

$l = mysqli_prepare($conn, 'SELECT l.*, u.names AS by_name FROM credit_ledger l LEFT JOIN users u ON u.id = l.created_by WHERE l.sale_id = ? ORDER BY l.id');
mysqli_stmt_bind_param($l, 'i', $id);
mysqli_stmt_execute($l);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($l), MYSQLI_ASSOC);

$principalOut = round((float) $sale['total_amount'] - (float) $sale['amount_paid'], 2);
$chargesOut = round((float) $sale['charges_accrued'] - (float) $sale['charges_paid'], 2);
$labels = ['PRINCIPAL' => 'Invoice amount', 'PAYMENT_PRINCIPAL' => 'Payment (invoice)', 'CHARGE' => 'Overdue charge',
           'PAYMENT_CHARGE' => 'Payment (charges)', 'WAIVER' => 'Charges waived'];
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success"><?= $e($_GET['success']); ?></div><?php } ?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <a href="index.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to Sales</a>
        <h2 class="mt-1">Invoice RM<?= str_pad((string) $sale['id'], 6, '0', STR_PAD_LEFT); ?> — Ledger</h2>
        <div class="text-muted"><?= $e($sale['customer_name'] ?? 'Walk-in'); ?> · <?= $e($sale['status']); ?></div>
    </div>
    <a href="invoice.php?id=<?= (int) $id; ?>" target="_blank" class="btn btn-outline-primary">Invoice PDF</a>
</div>

<div class="row g-3 mb-4">
    <?php foreach ([['Invoice Balance', $principalOut], ['Unpaid Charges', $chargesOut], ['Total Due', $principalOut + $chargesOut]] as $c) { ?>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small"><?= $c[0]; ?></div><div class="fs-5 fw-bold">RWF <?= number_format($c[1], 2); ?></div></div></div></div>
    <?php } ?>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Payment Deadline</div>
        <div class="fw-bold"><?= $sale['payment_deadline'] ? date('d M Y, H:i', strtotime($sale['payment_deadline'])) : '—'; ?></div>
        <div class="small text-muted"><?= (int) $sale['charge_days']; ?> charge day(s) so far</div></div></div></div>
</div>

<div class="card border-0 shadow-sm mb-4"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-bordered bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>When</th><th>Entry</th><th>Day</th><th class="text-end">Amount (RWF)</th><th class="text-end">Invoice Balance</th><th class="text-end">Charges Balance</th><th>Notes</th><th>By</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">No ledger entries (this sale was paid at checkout).</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?>
    <tr>
        <td class="text-nowrap"><?= date('d M Y H:i', strtotime($r['created_at'])); ?><?= $r['charge_date'] ? '<div class="small text-muted">for ' . date('d M Y', strtotime($r['charge_date'])) . '</div>' : ''; ?></td>
        <td><?= $e($labels[$r['entry_type']] ?? $r['entry_type']); ?></td>
        <td><?= $r['charge_day_no'] ? (int) $r['charge_day_no'] : ''; ?></td>
        <td class="text-end <?= in_array($r['entry_type'], ['PAYMENT_PRINCIPAL', 'PAYMENT_CHARGE', 'WAIVER'], true) ? 'text-success' : ''; ?>">
            <?= in_array($r['entry_type'], ['PAYMENT_PRINCIPAL', 'PAYMENT_CHARGE', 'WAIVER'], true) ? '&minus; ' : ''; ?><?= number_format((float) $r['amount'], 2); ?></td>
        <td class="text-end"><?= number_format((float) $r['principal_balance'], 2); ?></td>
        <td class="text-end"><?= number_format((float) $r['charges_balance'], 2); ?></td>
        <td><?= $e($r['notes']); ?></td>
        <td><?= $e($r['by_name'] ?? 'System'); ?></td>
    </tr>
    <?php } ?>
</table>
</div></div></div>

<?php if ($isAdmin && $chargesOut > 0.009) { ?>
<div class="card border-0 shadow-sm"><div class="card-body">
    <h6>Waive charges</h6>
    <form method="POST" class="row g-2 align-items-end" onsubmit="return confirm('Waive these charges? This is recorded in the ledger.');">
        <input type="hidden" name="id" value="<?= (int) $id; ?>">
        <div class="col-md-3"><label class="form-label small text-muted">Amount (empty = all RWF <?= number_format($chargesOut, 2); ?>)</label>
            <input type="number" name="amount" class="form-control" min="0.01" step="0.01" max="<?= $chargesOut; ?>"></div>
        <div class="col-md-6"><label class="form-label small text-muted">Reason</label><input type="text" name="reason" class="form-control" maxlength="200" required></div>
        <div class="col-md-3"><button name="waive" value="1" class="btn btn-warning">Waive</button></div>
    </form>
</div></div>
<?php } ?>
<?php include '../../includes/footer.php'; ?>