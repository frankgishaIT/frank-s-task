<?php
/**
 * NEW FILE: modules/marketing/pools.php — SHARED REWARD POOLS: a team reward split equally, by approved contribution
 * points (in a campaign) or by set percentages. Proposed by a Manager; approved by an Administrator; each share then
 * becomes an approved line paid with the payroll.
 */
require __DIR__ . '/mkt_common.php';
mkt_ensure_team($conn);
$L = mkt_lists($conn);
$camps = mysqli_fetch_all(mysqli_query($conn, "SELECT id, name, created_at FROM mkt_campaigns WHERE status <> 'Draft' ORDER BY name"), MYSQLI_ASSOC);
$canApprove = access_can('marketing.approve', $conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if ($act === 'propose') {
            if (!mkt_is_manager()) { throw new RuntimeException('Only Managers / Administrators propose reward pools.'); }
            $pool = ['name' => mb_substr(trim((string) $_POST['name']), 0, 160), 'campaign_id' => (int) ($_POST['campaign_id'] ?? 0) ?: null, 'amount' => round((float) $_POST['amount'], 2),
                     'method' => in_array($_POST['method'] ?? '', ['equal', 'points', 'percent'], true) ? $_POST['method'] : 'equal', 'period' => preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['period'] ?? '')) ? $_POST['period'] : date('Y-m'),
                     'reason' => trim((string) ($_POST['reason'] ?? ''))];
            if ($pool['name'] === '' || $pool['amount'] <= 0 || mb_strlen($pool['reason']) < 10) { throw new RuntimeException('Give the pool a name, an amount above zero and the reason (what result it rewards).'); }
            $members = array_values(array_intersect(array_map('intval', (array) ($_POST['members'] ?? [])), array_map('intval', array_column($L['users'], 'id'))));
            $pcts = array_map('floatval', (array) ($_POST['pct'] ?? []));
            $split = mkt_pool_split($conn, $pool, $members, $pcts); // validates (percentages = 100%, points exist ...)
            mysqli_begin_transaction($conn);
            $s = mysqli_prepare($conn, 'INSERT INTO mkt_pools (name, campaign_id, amount, method, period, reason, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'sidsssi', $pool['name'], $pool['campaign_id'], $pool['amount'], $pool['method'], $pool['period'], $pool['reason'], $me); mysqli_stmt_execute($s);
            $pid = (int) mysqli_insert_id($conn);
            $ins = mysqli_prepare($conn, 'INSERT INTO mkt_pool_shares (pool_id, user_id, share_pct, amount) VALUES (?, ?, ?, ?)');
            foreach ($split as $u => $amt) { $pc = round(100 * $amt / $pool['amount'], 3); mysqli_stmt_bind_param($ins, 'iidd', $pid, $u, $pc, $amt); mysqli_stmt_execute($ins); }
            mkt_audit($conn, 'Reward pool proposed: RWF ' . number_format($pool['amount'], 2) . ' (' . $pool['method'] . ')', 'POOL-' . $pid, null, $split, $pool['reason'], $me);
            mysqli_commit($conn);
            mkt_notify($conn, mkt_admin_ids($conn), 'Reward pool to approve: ' . $pool['name'], 'RWF ' . number_format($pool['amount']) . ' for ' . count($split) . ' people.');
            $msg = 'Pool proposed. An Administrator approves it.';
        } elseif (in_array($act, ['approve', 'reject'], true)) {
            if (!$canApprove) { throw new RuntimeException('Only Administrators approve reward pools.'); }
            $pid = (int) $_POST['id']; $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_pools WHERE id = $pid AND status = 'Proposed'"));
            if (!$p) { throw new RuntimeException('This pool is no longer waiting for approval.'); }
            $shares = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mkt_pool_shares WHERE pool_id = $pid"), MYSQLI_ASSOC);
            $own = in_array($me, array_map('intval', array_column($shares, 'user_id')), true) || (int) $p['created_by'] === $me;
            if ($own && mb_strlen($note) < 5) { throw new RuntimeException('You proposed this pool or share in it: write a note (kept in the audit trail).'); }
            if ($act === 'reject' && mb_strlen($note) < 5) { throw new RuntimeException('Write the reason for rejecting.'); }
            mysqli_begin_transaction($conn);
            $to = $act === 'approve' ? 'Approved' : 'Rejected';
            $s = mysqli_prepare($conn, 'UPDATE mkt_pools SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ? AND status = \'Proposed\''); mysqli_stmt_bind_param($s, 'sisi', $to, $me, $note, $pid); mysqli_stmt_execute($s);
            if ($to === 'Approved') {
                $ins = mysqli_prepare($conn, "INSERT INTO mkt_commissions (kind, pool_id, campaign_id, user_id, period, share_pct, amount, status, approved_by, approved_at, note) VALUES ('Pool', ?, ?, ?, ?, ?, ?, 'Approved', ?, NOW(), ?)");
                foreach ($shares as $sh) { $u = (int) $sh['user_id']; $pc = (float) $sh['share_pct']; $amt = (float) $sh['amount']; $nt = 'Reward pool: ' . $p['name'];
                    mysqli_stmt_bind_param($ins, 'iiisddis', $pid, $p['campaign_id'], $u, $p['period'], $pc, $amt, $me, $nt); mysqli_stmt_execute($ins); }
            }
            mkt_audit($conn, 'Reward pool ' . strtolower($to) . ($own ? ' (Administrator involved, with note)' : ''), 'POOL-' . $pid, 'Proposed', $to, $note ?: null, $me);
            mysqli_commit($conn);
            mkt_notify($conn, array_merge(array_map('intval', array_column($shares, 'user_id')), [(int) $p['created_by']]), 'Reward pool ' . strtolower($to) . ': ' . $p['name'], $to === 'Approved' ? 'Your share will be paid with the payroll.' : $note);
            $msg = 'Pool ' . strtolower($to) . '.';
        } else { throw new RuntimeException('Unknown action.'); }
        header('Location: pools.php?success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$pools = mysqli_fetch_all(mysqli_query($conn, 'SELECT p.*, u.names AS creator, d.names AS decider, c.name AS campaign FROM mkt_pools p LEFT JOIN users u ON u.id = p.created_by LEFT JOIN users d ON d.id = p.decided_by LEFT JOIN mkt_campaigns c ON c.id = p.campaign_id ORDER BY p.id DESC LIMIT 100'), MYSQLI_ASSOC);
$names = array_column($L['users'], 'names', 'id');
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('pools.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if (mkt_is_manager()) { ?>
<details class="card border-0 shadow-sm mb-3" <?= isset($error) ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold">+ Propose a shared reward</summary>
<form method="POST" class="card-body pt-0 row g-2"><input type="hidden" name="action" value="propose">
    <div class="col-md-5"><label class="form-label small text-muted mb-1">Name *</label><input name="name" maxlength="160" required class="form-control form-control-sm" placeholder="e.g. Schools campaign target reached"></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Campaign</label><select name="campaign_id" class="form-select form-select-sm"><option value="">— none —</option><?php foreach ($camps as $c) { ?><option value="<?= (int) $c['id']; ?>"><?= $e($c['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Amount (RWF) *</label><input type="number" min="1" name="amount" required class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Payroll month</label><input type="month" name="period" value="<?= date('Y-m'); ?>" class="form-control form-control-sm"></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Split</label><select name="method" id="pMethod" class="form-select form-select-sm"><option value="equal">Equally</option><option value="points">By approved contribution points (campaign)</option><option value="percent">By set percentages</option></select></div>
    <div class="col-md-8"><label class="form-label small text-muted mb-1">Reason (the result it rewards) *</label><input name="reason" required class="form-control form-control-sm"></div>
    <div class="col-12"><label class="form-label small text-muted mb-1">Team members</label><div class="row g-1">
        <?php foreach ($L['users'] as $u) { ?><div class="col-md-3 d-flex align-items-center gap-1"><label class="flex-grow-1 small"><input type="checkbox" name="members[]" value="<?= (int) $u['id']; ?>" class="form-check-input me-1"><?= $e($u['names']); ?></label><input type="number" step="0.01" min="0" max="100" name="pct[<?= (int) $u['id']; ?>]" class="form-control form-control-sm p-pct" style="width:70px; display:none;" placeholder="%"></div><?php } ?></div></div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Propose (Administrator approves)</button></div>
</form></details>
<script>(function () { var m = document.getElementById('pMethod'); function f() { document.querySelectorAll('.p-pct').forEach(function (el) { el.style.display = m.value === 'percent' ? '' : 'none'; }); } m.addEventListener('change', f); f(); })();</script>
<?php } ?>
<?php if (!$pools) { ?><div class="card border-0 shadow-sm"><div class="card-body text-muted">No reward pools yet.</div></div><?php } ?>
<?php foreach ($pools as $p) { $shares = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM mkt_pool_shares WHERE pool_id = ' . (int) $p['id'] . ' ORDER BY amount DESC'), MYSQLI_ASSOC); ?>
<div class="card border-0 shadow-sm mb-2"><div class="card-body small">
    <div class="d-flex justify-content-between flex-wrap gap-1"><span><strong><?= $e($p['name']); ?></strong> · POOL-<?= (int) $p['id']; ?> · <?= $rwf($p['amount']); ?> · <?= ['equal' => 'equal split', 'points' => 'by contribution points', 'percent' => 'set percentages'][$p['method']]; ?> · payroll <?= $e($p['period']); ?></span><?= mkt_badge($p['status']); ?></div>
    <div class="text-muted"><?= $e($p['reason']); ?> · proposed by <?= $e($p['creator']); ?><?= $p['campaign'] ? ' · ' . $e($p['campaign']) : ''; ?><?= $p['decider'] ? ' · ' . strtolower($p['status']) . ' by ' . $e($p['decider']) . ($p['decision_note'] ? ': ' . $e($p['decision_note']) : '') : ''; ?></div>
    <div class="mt-1"><?php foreach ($shares as $s) { ?><span class="me-3"><?= $e($names[$s['user_id']] ?? '#' . $s['user_id']); ?>: <strong><?= $rwf($s['amount']); ?></strong> (<?= round((float) $s['share_pct'], 2); ?>%)</span><?php } ?></div>
    <?php if ($p['status'] === 'Proposed' && $canApprove) { ?><form method="POST" class="d-flex gap-1 mt-2"><input type="hidden" name="id" value="<?= (int) $p['id']; ?>"><input name="note" class="form-control form-control-sm" style="max-width:300px;" placeholder="Note"><button name="action" value="approve" class="btn btn-sm btn-success">Approve</button><button name="action" value="reject" class="btn btn-sm btn-outline-danger">Reject</button></form><?php } ?>
</div></div><?php } ?>
<?php include '../../includes/footer.php'; ?>