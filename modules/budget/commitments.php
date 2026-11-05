<?php
/**
 * NEW FILE: modules/budget/commitments.php — RESERVED FUNDS. A purchase or payment request names its fund;
 * approval RESERVES the amount (available drops, balance unchanged); "Complete payment" deducts it exactly once and
 * releases the reservation; cancelling releases it. Pending requests, approved commitments and completed payments are
 * shown apart.
 */
require __DIR__ . '/budget_common.php';
$L = bdg_lists($conn);
$canCreate = access_can('budget.create', $conn); $canApprove = access_can('budget.approve', $conn); $canPay = access_can('budget.manage', $conn) || $canApprove;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $id = (int) ($_POST['id'] ?? 0); $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        switch ($act) {
            case 'request':
                if (!$canCreate) { throw new RuntimeException('You cannot request commitments.'); }
                $fid = (int) ($_POST['fund_id'] ?? 0); $amt = round((float) ($_POST['amount'] ?? 0), 2); $desc = mb_substr(trim((string) $_POST['description']), 0, 255);
                if (!$fid || !fund_get($conn, $fid)) { throw new RuntimeException('Select the fund the money will come from (required).'); }
                if ($amt <= 0 || $desc === '') { throw new RuntimeException('Enter the amount and what it is for.'); }
                $st = bdg_fund_status($conn, $fid, $amt);
                if (!$st['ok']) { throw new InsufficientFundException('Not possible: ' . $st['fund'] . ' has RWF ' . number_format(max(0, $st['available'])) . ' available for RWF ' . number_format($amt) . '. Money is never taken from another fund; request a fund transfer if this is justified.'); }
                $cat = trim((string) ($_POST['expense_category'] ?? '')) ?: null; $dept = (int) ($_POST['department_id'] ?? 0) ?: null; $proj = (int) ($_POST['project_id'] ?? 0) ?: null;
                $s = mysqli_prepare($conn, 'INSERT INTO bdg_commitments (fund_id, amount, description, expense_category, department_id, project_id, requested_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'idssiii', $fid, $amt, $desc, $cat, $dept, $proj, $me); mysqli_stmt_execute($s); $new = (int) mysqli_insert_id($conn);
                bdg_audit($conn, 'Expense / purchase request submitted: RWF ' . number_format($amt, 2) . ' from ' . $st['fund'], bdg_ref('commitment', $new), null, 'Pending', $desc, $me);
                $msg = 'Request ' . bdg_ref('commitment', $new) . ' submitted. The money is reserved once it is approved.'; break;
            case 'approve': if (!$canApprove) { throw new RuntimeException('Only approvers can approve.'); } bdg_commitment_approve($conn, $id, $me, $reason); $msg = 'Approved: the amount is now reserved in its fund.'; break;
            case 'complete':
                if (!$canPay) { throw new RuntimeException('Only Finance / approvers record payments.'); }
                $date = DateTime::createFromFormat('Y-m-d', (string) ($_POST['paid_on'] ?? '')) ? $_POST['paid_on'] : date('Y-m-d');
                $tx = bdg_commitment_complete($conn, $id, $me, $date, ($_POST['paid_amount'] ?? '') !== '' ? (float) $_POST['paid_amount'] : null);
                try { bdg_check_alerts($conn); } catch (Throwable $x) { }
                $msg = 'Payment recorded (transaction TX-' . str_pad((string) $tx, 6, '0', STR_PAD_LEFT) . '): deducted once, reservation released.'; break;
            case 'cancel': case 'reject':
                $c = bdg_commitment($conn, $id);
                $mayCancel = $canApprove || ($c && (int) $c['requested_by'] === $me && $c['status'] === 'Pending');
                if (!$mayCancel) { throw new RuntimeException('You cannot ' . $act . ' this request.'); }
                if (mb_strlen($reason) < 3) { throw new RuntimeException('Write the reason.'); }
                bdg_commitment_cancel($conn, $id, $me, $reason, $act === 'reject'); $msg = 'Done. Any reserved amount was released.'; break;
            default: throw new RuntimeException('Unknown action.');
        }
        header('Location: commitments.php?success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$view = in_array($_GET['view'] ?? '', ['Pending', 'Approved', 'Completed', 'Cancelled', 'Rejected'], true) ? $_GET['view'] : 'open';
$where = $view === 'open' ? "c.status IN ('Pending', 'Approved')" : "c.status = '" . mysqli_real_escape_string($conn, $view) . "'";
if (!access_can('budget.approve', $conn) && !access_can('budget.manage', $conn)) { $where .= ' AND c.requested_by = ' . $me; } // others: their own requests
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT c.*, f.name AS fund, d.name AS department, u.names AS requester, a.names AS approver FROM bdg_commitments c JOIN funds f ON f.id = c.fund_id
    LEFT JOIN departments d ON d.id = c.department_id LEFT JOIN users u ON u.id = c.requested_by LEFT JOIN users a ON a.id = c.approved_by WHERE $where ORDER BY c.id DESC LIMIT 300"), MYSQLI_ASSOC);
$tot = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(CASE WHEN status = 'Pending' THEN amount END), 0) pending, COALESCE(SUM(CASE WHEN status = 'Approved' THEN amount END), 0) reserved FROM bdg_commitments"));
$pendTx = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) FROM transactions WHERE transaction_type = 'Expense' AND status = 'pending'"))[0];
include '../../includes/header.php'; include '../../includes/sidebar.php';
bdg_tabs('commitments.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<div class="row g-3 mb-3">
    <?php foreach ([['Pending requests (not reserved)', $tot['pending'], 'bg-primary'], ['Approved commitments (reserved)', $tot['reserved'], 'bg-dark'], ['Pending expense transactions (reserved)', $pendTx, 'bg-dark']] as [$l, $v, $c]) { ?>
    <div class="col-md-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted"><?= $l; ?></div><div class="fs-5 fw-bold"><?= $rwf($v); ?></div></div></div></div><?php } ?>
</div>
<?php if ($canCreate) { ?>
<details class="card border-0 shadow-sm mb-3" <?= isset($error) ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold">+ New purchase / payment request</summary>
<form method="POST" class="card-body pt-0 row g-2"><input type="hidden" name="action" value="request">
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Fund (required) *</label><select name="fund_id" id="cFund" class="form-select form-select-sm" required><option value="">Select the fund</option><?php foreach ($L['funds'] as $f) { ?><option value="<?= (int) $f['id']; ?>"><?= $e($f['name']); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Amount (RWF) *</label><input type="number" name="amount" id="cAmt" min="1" class="form-control form-control-sm" required></div>
    <div class="col-md-5"><label class="form-label small text-muted mb-1">What it is for *</label><input name="description" maxlength="255" class="form-control form-control-sm" required></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Expense category</label><select name="expense_category" class="form-select form-select-sm"><option value="">—</option><?php foreach ($L['categories'] as $c) { ?><option><?= $e($c); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($L['departments'] as $d) { ?><option value="<?= (int) $d['id']; ?>"><?= $e($d['name']); ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Project</label><select name="project_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($L['projects'] as $p) { ?><option value="<?= (int) $p['id']; ?>"><?= $e($p['name']); ?></option><?php } ?></select></div>
    <div class="col-12"><div id="cPanel" class="small border rounded-3 p-2" style="display:none; background:#F6F8FC;"></div></div>
    <div class="col-12"><button class="btn btn-primary btn-sm" id="cBtn">Submit request</button></div>
</form></details>
<script>(function () { var f = document.getElementById('cFund'), a = document.getElementById('cAmt'), p = document.getElementById('cPanel'), b = document.getElementById('cBtn'), t;
    function n(v) { return 'RWF ' + Number(v).toLocaleString(); }
    function load() { if (!f.value) { p.style.display = 'none'; return; }
        fetch('../transactions/fund_status.php?fund_id=' + f.value + '&amount=' + (a.value || 0), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.error) { return; } p.style.display = '';
            p.innerHTML = '<strong>' + d.fund + '</strong> · balance ' + n(d.balance) + ' · reserved ' + n(d.reserved) + ' · <strong>available ' + n(d.available) + '</strong> · this request ' + n(d.amount)
                + ' · expected remaining <strong style="color:' + (d.after < 0 ? '#E24B4A' : 'inherit') + '">' + n(d.after) + '</strong> · <span class="badge ' + (d.ok ? 'bg-success' : 'bg-danger') + '">' + d.status + '</span>';
            b.disabled = !d.ok && (parseFloat(a.value) || 0) > 0; }); }
    [f, a].forEach(function (el) { el.addEventListener('input', function () { clearTimeout(t); t = setTimeout(load, 250); }); }); })();</script>
<?php } ?>
<div class="btn-group btn-group-sm mb-2"><?php foreach (['open' => 'Open (pending + reserved)', 'Pending' => 'Pending', 'Approved' => 'Reserved', 'Completed' => 'Completed', 'Cancelled' => 'Cancelled', 'Rejected' => 'Rejected'] as $k => $l) { ?><a href="commitments.php?view=<?= $k; ?>" class="btn <?= $view === $k ? 'btn-primary' : 'btn-outline-primary'; ?>"><?= $l; ?></a><?php } ?></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Request</th><th>Fund</th><th class="text-end">Amount</th><th>For</th><th>Status</th><th style="min-width:250px;"></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing here.</td></tr><?php } ?>
    <?php foreach ($rows as $c) { $id = (int) $c['id']; ?>
    <tr><td class="ps-3"><?= bdg_ref('commitment', $id); ?><div class="small text-muted"><?= date('d M Y', strtotime($c['created_at'])); ?> · <?= $e($c['requester']); ?></div></td><td class="small"><?= $e($c['fund']); ?></td>
        <td class="text-end"><?= $rwf($c['amount']); ?></td><td class="small"><?= $e($c['description']); ?><div class="text-muted"><?= $e(trim(($c['expense_category'] ?? '') . ' · ' . ($c['department'] ?? ''), ' ·')); ?></div></td>
        <td><?= bdg_badge($c['status'] === 'Approved' ? 'Reserved' : $c['status']); ?><?= $c['transaction_id'] ? '<div class="small">TX-' . str_pad((string) $c['transaction_id'], 6, '0', STR_PAD_LEFT) . '</div>' : ''; ?></td>
        <td class="small"><?php if ($c['status'] === 'Pending' && $canApprove) { ?><form method="POST" class="d-inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $id; ?>"><button class="btn btn-sm btn-success">Approve &amp; reserve</button></form><?php } ?>
            <?php if ($c['status'] === 'Approved' && $canPay) { ?><form method="POST" class="d-flex gap-1 mb-1"><input type="hidden" name="action" value="complete"><input type="hidden" name="id" value="<?= $id; ?>"><input type="date" name="paid_on" value="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" style="width:140px;"><input type="number" name="paid_amount" placeholder="<?= (int) $c['amount']; ?>" class="form-control form-control-sm" style="width:110px;" title="Leave empty for the full amount"><button class="btn btn-sm btn-primary text-nowrap" onclick="return confirm('Record this payment? It is deducted from the fund once.');">Complete payment</button></form><?php } ?>
            <?php if (in_array($c['status'], ['Pending', 'Approved'], true) && ($canApprove || ((int) $c['requested_by'] === $me && $c['status'] === 'Pending'))) { ?><form method="POST" class="d-flex gap-1"><input type="hidden" name="action" value="<?= $c['status'] === 'Pending' && $canApprove ? 'reject' : 'cancel'; ?>"><input type="hidden" name="id" value="<?= $id; ?>"><input name="reason" class="form-control form-control-sm" placeholder="Reason"><button class="btn btn-sm btn-outline-danger"><?= $c['status'] === 'Pending' && $canApprove ? 'Reject' : 'Cancel'; ?></button></form><?php } ?></td></tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>