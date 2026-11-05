<?php
/**
 * NEW FILE: modules/budget/funds.php — every fund (from the existing fund ledger): period statement, balance, reserved,
 * available and history; fund transfers (request → approval → two linked ledger entries); allocation % revisions
 * (request → approval, history kept).
 */
require __DIR__ . '/budget_common.php';
$funds = funds_all($conn);
$canCreate = access_can('budget.create', $conn); $canApprove = access_can('budget.approve', $conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        switch ($act) {
            case 'transfer_request':
                if (!$canCreate) { throw new RuntimeException('You cannot request transfers.'); }
                $from = (int) $_POST['from_fund_id']; $to = (int) $_POST['to_fund_id']; $amt = round((float) $_POST['amount'], 2);
                if (!$from || !$to || $from === $to || !fund_get($conn, $from) || !fund_get($conn, $to)) { throw new RuntimeException('Choose two different funds.'); }
                if ($amt <= 0 || mb_strlen($reason) < 10) { throw new RuntimeException('Enter the amount and a clear reason (at least 10 characters).'); }
                if ($amt > fund_available($conn, $from) + 0.001) { throw new InsufficientFundException('The source fund has only RWF ' . number_format(max(0, fund_available($conn, $from))) . ' available.'); }
                $s = mysqli_prepare($conn, 'INSERT INTO fund_transfers (from_fund_id, to_fund_id, amount, reason, requested_by) VALUES (?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'iidsi', $from, $to, $amt, $reason, $me); mysqli_stmt_execute($s); $new = (int) mysqli_insert_id($conn);
                bdg_audit($conn, 'Fund transfer requested: RWF ' . number_format($amt, 2), bdg_ref('transfer', $new), null, 'Pending', $reason, $me);
                require_once __DIR__ . '/../../includes/notification_helper.php';
                foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND LOWER(role) = 'admin'"), MYSQLI_ASSOC) as $u) { try { notifyUser($conn, (int) $u['id'], 'Fund transfer to approve (' . bdg_ref('transfer', $new) . ')', 'RWF ' . number_format($amt) . ': ' . $reason); } catch (Throwable $x) { } }
                $msg = 'Transfer request ' . bdg_ref('transfer', $new) . ' sent for approval. Nothing moves until it is approved.'; break;
            case 'transfer_approve':
                if (!$canApprove) { throw new RuntimeException('Only the Managing Director (or an authorised officer) approves transfers.'); }
                bdg_transfer_approve($conn, (int) $_POST['id'], $me, $reason); $msg = 'Transfer approved and executed.'; break;
            case 'transfer_reject':
                if (!$canApprove || mb_strlen($reason) < 5) { throw new RuntimeException('Write the reason for rejecting.'); }
                $id = (int) $_POST['id']; $s = mysqli_prepare($conn, "UPDATE fund_transfers SET status = 'Rejected', decided_by = ?, decision_note = ?, decided_at = NOW() WHERE id = ? AND status = 'Pending'");
                mysqli_stmt_bind_param($s, 'isi', $me, $reason, $id); mysqli_stmt_execute($s);
                bdg_audit($conn, 'Fund transfer rejected', bdg_ref('transfer', $id), 'Pending', 'Rejected', $reason, $me); $msg = 'Transfer rejected.'; break;
            case 'alloc_request':
                if (!$canCreate) { throw new RuntimeException('You cannot request allocation changes.'); }
                $old = []; $newV = []; $sum = 0.0;
                foreach ($funds as $f) { if ($f['allocation_percent'] === null) { continue; } $old[$f['id']] = (float) $f['allocation_percent']; $v = round((float) ($_POST['pct'][$f['id']] ?? $f['allocation_percent']), 2); $newV[$f['id']] = $v; $sum += $v; }
                if (abs($sum - 100) > 0.01) { throw new RuntimeException('The percentages must add up to 100% (now ' . $sum . '%).'); }
                if ($old == $newV) { throw new RuntimeException('Nothing changed.'); }
                if (mb_strlen($reason) < 10) { throw new RuntimeException('Write the reason for the change.'); }
                $s = mysqli_prepare($conn, 'INSERT INTO fund_alloc_revisions (old_values, new_values, reason, requested_by) VALUES (?, ?, ?, ?)');
                $o = json_encode($old); $n = json_encode($newV); mysqli_stmt_bind_param($s, 'sssi', $o, $n, $reason, $me); mysqli_stmt_execute($s); $new = (int) mysqli_insert_id($conn);
                bdg_audit($conn, 'Allocation change requested', bdg_ref('allocation', $new), $old, $newV, $reason, $me); $msg = 'Allocation change sent for approval.'; break;
            case 'alloc_approve': case 'alloc_reject':
                if (!$canApprove) { throw new RuntimeException('Only the Managing Director (or an authorised officer) approves allocation changes.'); }
                $id = (int) $_POST['id']; $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM fund_alloc_revisions WHERE id = $id AND status = 'Pending'"));
                if (!$r) { throw new RuntimeException('This change is no longer pending.'); }
                if ((int) $r['requested_by'] === $me) { throw new RuntimeException('It must be approved by someone other than the person who requested it.'); }
                mysqli_begin_transaction($conn);
                $to = $act === 'alloc_approve' ? 'Approved' : 'Rejected';
                if ($to === 'Approved') { $s = mysqli_prepare($conn, 'UPDATE funds SET allocation_percent = ? WHERE id = ?');
                    foreach (json_decode($r['new_values'], true) as $fid => $pct) { $p = (float) $pct; $fi = (int) $fid; mysqli_stmt_bind_param($s, 'di', $p, $fi); mysqli_stmt_execute($s); } }
                $s = mysqli_prepare($conn, 'UPDATE fund_alloc_revisions SET status = ?, decided_by = ?, decision_note = ?, decided_at = NOW() WHERE id = ?'); mysqli_stmt_bind_param($s, 'sisi', $to, $me, $reason, $id); mysqli_stmt_execute($s);
                bdg_audit($conn, 'Allocation change ' . strtolower($to) . ($to === 'Approved' ? ' (applies to new allocations; past allocations unchanged)' : ''), bdg_ref('allocation', $id), json_decode($r['old_values'], true), json_decode($r['new_values'], true), $reason ?: $r['reason'], $me);
                mysqli_commit($conn); $msg = 'Allocation change ' . strtolower($to) . '.'; break;
            default: throw new RuntimeException('Unknown action.');
        }
        header('Location: funds.php?success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$from = DateTime::createFromFormat('Y-m-d', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = DateTime::createFromFormat('Y-m-d', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$fundId = (int) ($_GET['fund'] ?? 0);
$transfers = mysqli_fetch_all(mysqli_query($conn, 'SELECT t.*, a.name AS src, b.name AS dst, u.names AS requester, d.names AS decider FROM fund_transfers t JOIN funds a ON a.id = t.from_fund_id JOIN funds b ON b.id = t.to_fund_id
    LEFT JOIN users u ON u.id = t.requested_by LEFT JOIN users d ON d.id = t.decided_by ORDER BY t.id DESC LIMIT 50'), MYSQLI_ASSOC);
$allocs = mysqli_fetch_all(mysqli_query($conn, 'SELECT r.*, u.names AS requester FROM fund_alloc_revisions r LEFT JOIN users u ON u.id = r.requested_by ORDER BY r.id DESC LIMIT 20'), MYSQLI_ASSOC);
$names = array_column($funds, 'name', 'id');
include '../../includes/header.php'; include '../../includes/sidebar.php';
bdg_tabs('funds.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<form class="d-flex flex-wrap gap-2 mb-2 align-items-end"><div><label class="small text-muted">From</label><input type="date" name="from" value="<?= $e($from); ?>" class="form-control form-control-sm"></div>
    <div><label class="small text-muted">To</label><input type="date" name="to" value="<?= $e($to); ?>" class="form-control form-control-sm"></div><button class="btn btn-sm btn-primary">Show</button></form>
<div class="small text-muted mb-2">Available = Opening + Inflows + Transfers in − Expenses − Transfers out − Reserved. Reversals are counted with the entry they reverse, so nothing is counted twice.</div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Fund</th><th class="text-end">Allocation</th><th class="text-end">Opening</th><th class="text-end">Inflows</th><th class="text-end">Transfers in</th><th class="text-end">Expenses</th><th class="text-end">Transfers out</th><th class="text-end">Closing</th><th class="text-end">Reserved</th><th class="text-end">Available</th><th></th></tr>
    <?php foreach ($funds as $f) { $s = bdg_fund_statement($conn, (int) $f['id'], $from, $to); ?>
    <tr><td class="ps-3"><strong><?= $e($f['name']); ?></strong></td><td class="text-end"><?= $f['allocation_percent'] !== null ? (float) $f['allocation_percent'] . '%' : '—'; ?></td>
        <?php foreach (['opening', 'inflows', 'transfers_in', 'expenses', 'transfers_out', 'closing', 'reserved'] as $k) { ?><td class="text-end"><?= number_format($s[$k]); ?></td><?php } ?>
        <td class="text-end fw-bold <?= $s['available'] < 0 ? 'text-danger' : ''; ?>"><?= number_format($s['available']); ?></td>
        <td><a href="funds.php?<?= $e(http_build_query(['from' => $from, 'to' => $to, 'fund' => $f['id']])); ?>#history" class="btn btn-sm btn-outline-primary">History</a></td></tr><?php } ?>
</table></div></div></div>
<?php if ($fundId && isset($names[$fundId])) { $cash = bdg_cash_condition();
    $mv = mysqli_fetch_all(mysqli_query($conn, "SELECT m.*, u.names FROM fund_movements m LEFT JOIN fund_movements o ON o.id = m.reverses_movement_id LEFT JOIN users u ON u.id = m.created_by
        WHERE m.fund_id = $fundId AND $cash AND DATE(m.created_at) BETWEEN '" . mysqli_real_escape_string($conn, $from) . "' AND '" . mysqli_real_escape_string($conn, $to) . "' ORDER BY m.id DESC LIMIT 500"), MYSQLI_ASSOC); ?>
<div class="card border-0 shadow-sm mb-3" id="history"><div class="card-body p-0"><div class="p-3 pb-1 fw-semibold"><?= $e($names[$fundId]); ?>: transaction history</div><div class="table-responsive"><table class="table table-sm mb-0" style="font-size:12.5px;">
    <tr><th class="ps-3">Date</th><th>Type</th><th class="text-end">In</th><th class="text-end">Out</th><th>Reference</th><th>Description</th><th>By</th></tr>
    <?php foreach ($mv as $m) { ?><tr><td class="ps-3 text-nowrap"><?= date('d M Y, H:i', strtotime($m['created_at'])); ?></td><td><?= $e($m['movement_type']); ?></td><td class="text-end"><?= $m['direction'] === 'IN' ? number_format((float) $m['amount']) : ''; ?></td><td class="text-end"><?= $m['direction'] === 'OUT' ? number_format((float) $m['amount']) : ''; ?></td>
        <td><?= $m['transaction_id'] ? 'TX-' . str_pad((string) $m['transaction_id'], 6, '0', STR_PAD_LEFT) : $e(trim(($m['ref_type'] ?? '') . ' ' . ($m['ref_id'] ?? ''))); ?></td><td><?= $e($m['description']); ?></td><td><?= $e($m['names']); ?></td></tr><?php } ?>
    <?php if (!$mv) { ?><tr><td colspan="7" class="text-center text-muted py-3">No entries in this period.</td></tr><?php } ?>
</table></div></div></div>
<?php } ?>
<div class="row g-3">
<div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body small"><h6>Fund transfers</h6>
    <?php if ($canCreate) { ?><form method="POST" class="row g-1 mb-3"><input type="hidden" name="action" value="transfer_request">
        <div class="col-6"><select name="from_fund_id" class="form-select form-select-sm" required><option value="">Source fund</option><?php foreach ($funds as $f) { ?><option value="<?= (int) $f['id']; ?>"><?= $e($f['name']); ?> (available <?= number_format(fund_available($conn, (int) $f['id'])); ?>)</option><?php } ?></select></div>
        <div class="col-6"><select name="to_fund_id" class="form-select form-select-sm" required><option value="">Destination fund</option><?php foreach ($funds as $f) { ?><option value="<?= (int) $f['id']; ?>"><?= $e($f['name']); ?></option><?php } ?></select></div>
        <div class="col-4"><input type="number" name="amount" min="1" class="form-control form-control-sm" placeholder="Amount" required></div><div class="col-8"><input name="reason" class="form-control form-control-sm" placeholder="Justification (required)" required></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Request transfer (needs approval)</button></div></form><?php } ?>
    <?php foreach ($transfers as $t) { ?><div class="border-top py-2"><div class="d-flex justify-content-between flex-wrap gap-1"><span><?= bdg_ref('transfer', (int) $t['id']); ?> · <strong><?= $rwf($t['amount']); ?></strong> <?= $e($t['src']); ?> → <?= $e($t['dst']); ?></span><?= bdg_badge($t['status']); ?></div>
        <div class="text-muted"><?= $e($t['reason']); ?> · requested by <?= $e($t['requester']); ?> <?= date('d M Y, H:i', strtotime($t['created_at'])); ?><?= $t['decider'] ? ' · ' . strtolower($t['status']) . ' by ' . $e($t['decider']) . ' ' . date('d M Y, H:i', strtotime($t['decided_at'])) : ''; ?></div>
        <?php if ($t['status'] === 'Pending' && $canApprove) { ?><div class="d-flex gap-1 mt-1"><form method="POST" class="d-flex gap-1"><input type="hidden" name="action" value="transfer_approve"><input type="hidden" name="id" value="<?= (int) $t['id']; ?>"><input name="reason" class="form-control form-control-sm" placeholder="Note"><button class="btn btn-sm btn-success text-nowrap" onclick="return confirm('Approve and execute this transfer?');">Approve</button></form>
            <form method="POST" class="d-flex gap-1"><input type="hidden" name="action" value="transfer_reject"><input type="hidden" name="id" value="<?= (int) $t['id']; ?>"><input name="reason" class="form-control form-control-sm" placeholder="Reason"><button class="btn btn-sm btn-outline-danger">Reject</button></form></div><?php } ?></div><?php } ?>
    <?php if (!$transfers) { ?><div class="text-muted">No transfers yet.</div><?php } ?>
</div></div></div>
<div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body small"><h6>Allocation percentages</h6>
    <?php if ($canCreate) { ?><form method="POST"><input type="hidden" name="action" value="alloc_request">
        <?php foreach ($funds as $f) { if ($f['allocation_percent'] === null) { continue; } ?><div class="d-flex justify-content-between align-items-center mb-1"><span><?= $e($f['name']); ?></span><input type="number" step="0.01" name="pct[<?= (int) $f['id']; ?>]" value="<?= (float) $f['allocation_percent']; ?>" class="form-control form-control-sm" style="width:90px;"></div><?php } ?>
        <input name="reason" class="form-control form-control-sm my-1" placeholder="Reason for the change" required><button class="btn btn-sm btn-primary">Request change (needs approval)</button>
        <div class="text-muted mt-1">Must add up to 100%. Applies to future allocations only; past allocations are kept as they were.</div></form><?php } ?>
    <?php foreach ($allocs as $r) { $o = json_decode($r['old_values'], true); $n = json_decode($r['new_values'], true); ?><div class="border-top py-2 mt-2"><div class="d-flex justify-content-between"><span><?= bdg_ref('allocation', (int) $r['id']); ?> · <?= $e($r['requester']); ?> · <?= date('d M Y', strtotime($r['created_at'])); ?></span><?= bdg_badge($r['status']); ?></div>
        <?php foreach ($n as $fid => $pct) { ?><div><?= $e($names[$fid] ?? $fid); ?>: <?= (float) ($o[$fid] ?? 0); ?>% → <strong><?= (float) $pct; ?>%</strong></div><?php } ?><div class="text-muted"><?= $e($r['reason']); ?></div>
        <?php if ($r['status'] === 'Pending' && $canApprove) { ?><div class="d-flex gap-1 mt-1"><form method="POST"><input type="hidden" name="action" value="alloc_approve"><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><button class="btn btn-sm btn-success">Approve</button></form>
            <form method="POST" class="d-flex gap-1"><input type="hidden" name="action" value="alloc_reject"><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><input name="reason" class="form-control form-control-sm" placeholder="Reason"><button class="btn btn-sm btn-outline-danger">Reject</button></form></div><?php } ?></div><?php } ?>
</div></div></div></div>
<?php include '../../includes/footer.php'; ?>