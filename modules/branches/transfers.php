<?php
/**
 * NEW FILE: modules/branches/transfers.php — Employee branch transfer (section 5.3).
 * HR / managers ("branches.assign") request; Head Office ("branches.approve") approves or rejects. On the effective
 * date the primary branch changes. The account, attendance, payroll, performance and task history stay as they are.
 * Nobody approves their own request, except an Administrator who must then write a note.
 */
require __DIR__ . '/common.php';
br_require_setup($conn);
$canAsk = access_can('branches.assign', $conn);
$canApprove = access_can('branches.approve', $conn) && br_allowed_ids($conn) === null;
if (!$canAsk && !$canApprove) { br_deny(); }
br_apply_due_transfers($conn);
$me = (int) current_user_id();
$allowed = br_allowed_ids($conn);
$visible = array_filter(br_all($conn), function ($b) use ($allowed) { return $allowed === null || in_array((int) $b['id'], $allowed, true); });
$users = br_users($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!br_csrf_ok()) { br_go('transfers.php', 'error', 'The page had expired. Please try again.'); }
    $do = $_POST['do'] ?? '';
    if ($do === 'request') {
        if (!$canAsk) { br_deny(); }
        $uid = (int) ($_POST['user_id'] ?? 0); $to = (int) ($_POST['to_branch_id'] ?? 0);
        $eff = (string) ($_POST['effective_date'] ?? ''); $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!isset($users[$uid])) { br_go('transfers.php', 'error', 'Choose the employee.'); }
        $from = br_primary_id($conn, $uid);
        if ($allowed !== null && !in_array($from, $allowed, true)) { br_deny(); }
        if (!isset(br_all($conn)[$to]) || br_get($conn, $to)['status'] === 'Closed') { br_go('transfers.php', 'error', 'Choose an open destination branch.'); }
        if ($to === $from) { br_go('transfers.php', 'error', 'The employee already belongs to that branch.'); }
        if (!strtotime($eff)) { br_go('transfers.php', 'error', 'Choose the effective date.'); }
        if (mb_strlen($reason) < 5) { br_go('transfers.php', 'error', 'Explain the reason for the transfer.'); }
        if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM branch_transfers WHERE user_id = $uid AND status = 'Pending'"))[0]) { br_go('transfers.php', 'error', 'A transfer for this employee is already waiting for a decision.'); }
        $s = mysqli_prepare($conn, 'INSERT INTO branch_transfers (user_id, from_branch_id, to_branch_id, effective_date, reason, requested_by) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iiissi', $uid, $from, $to, $eff, $reason, $me);
        mysqli_stmt_execute($s);
        $tid = (int) mysqli_insert_id($conn);
        br_audit($conn, $from, 'transfer_requested', "Transfer #$tid requested: {$users[$uid]['names']} from " . br_get($conn, $from)['name'] . ' to ' . br_get($conn, $to)['name'] . " from $eff. Reason: $reason");
        br_go('transfers.php', 'success', "Transfer #$tid sent for approval.");
    }
    if ($do === 'decide') {
        if (!$canApprove) { br_deny(); }
        $tid = (int) ($_POST['id'] ?? 0); $dec = $_POST['decision'] ?? ''; $note = trim((string) ($_POST['note'] ?? ''));
        $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM branch_transfers WHERE id = $tid"));
        if (!$t || !in_array($dec, ['Approved', 'Rejected'], true)) { br_go('transfers.php', 'error', 'Transfer not found.'); }
        $own = (int) $t['requested_by'] === $me || (int) $t['user_id'] === $me;
        if ($own && current_user_role() !== 'Admin') { br_go('transfers.php', 'error', 'You cannot decide your own request.'); }
        if (($own || $dec === 'Rejected') && $note === '') { br_go('transfers.php', 'error', 'Please write a note.'); }
        $s = mysqli_prepare($conn, "UPDATE branch_transfers SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ? WHERE id = ? AND status = 'Pending'");
        mysqli_stmt_bind_param($s, 'sisi', $dec, $me, $note, $tid);
        mysqli_stmt_execute($s);
        if (mysqli_stmt_affected_rows($s) !== 1) { br_go('transfers.php', 'error', 'Already decided by someone else.'); }
        br_audit($conn, (int) $t['to_branch_id'], 'transfer_' . strtolower($dec), "Transfer #$tid $dec" . ($note ? ": $note" : '.'));
        $applied = $dec === 'Approved' ? br_apply_due_transfers($conn) : 0;
        try {
            $n = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
            $title = 'Branch transfer ' . strtolower($dec);
            $msg = 'Your transfer to ' . br_get($conn, (int) $t['to_branch_id'])['name'] . ' from ' . date('d M Y', strtotime($t['effective_date'])) . ' was ' . strtolower($dec) . '.' . ($note ? ' Note: ' . rtrim($note, '.') . '.' : '') . ' You keep the same MY MOTIVE account.';
            $uid = (int) $t['user_id'];
            mysqli_stmt_bind_param($n, 'iss', $uid, $title, $msg);
            mysqli_stmt_execute($n);
        } catch (Throwable $e) { }
        br_go('transfers.php', 'success', "Transfer #$tid " . strtolower($dec) . ($applied ? ' and applied (effective today or earlier).' : ($dec === 'Approved' ? '. It applies automatically on the effective date.' : '.')));
    }
    br_go('transfers.php', 'error', 'Unknown action.');
}

$where = $allowed === null ? '1=1' : '(t.from_branch_id IN (' . implode(',', $allowed ?: [0]) . ') OR t.to_branch_id IN (' . implode(',', $allowed ?: [0]) . '))';
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT t.*, u.names, fb.name fname, tb.name tname, r.names rname, d.names dname FROM branch_transfers t JOIN users u ON u.id = t.user_id
    LEFT JOIN branches fb ON fb.id = t.from_branch_id JOIN branches tb ON tb.id = t.to_branch_id LEFT JOIN users r ON r.id = t.requested_by LEFT JOIN users d ON d.id = t.decided_by
    WHERE $where ORDER BY t.status = 'Pending' DESC, t.id DESC LIMIT 200"), MYSQLI_ASSOC);
$pre = (int) ($_GET['user'] ?? 0);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'transfers.php');
?>
<div class="row g-3">
    <?php if ($canAsk) { ?>
    <div class="col-xl-4"><form method="POST" class="card border-0 shadow-sm"><div class="card-body"><?= br_csrf_field(); ?><input type="hidden" name="do" value="request">
        <h6>Request a transfer</h6>
        <label class="form-label small">Employee</label><select name="user_id" class="form-select form-select-sm mb-2" required><option value="">Choose</option>
            <?php foreach ($users as $u) { $p = br_primary_id($conn, (int) $u['id']); if ($allowed !== null && !in_array($p, $allowed, true)) { continue; } ?><option value="<?= (int) $u['id']; ?>" <?= $pre === (int) $u['id'] ? 'selected' : ''; ?>><?= br_e($u['names'] . ' · now ' . (br_get($conn, $p)['name'] ?? '')); ?></option><?php } ?></select>
        <label class="form-label small">New branch</label><select name="to_branch_id" class="form-select form-select-sm mb-2" required><?php foreach (br_all($conn) as $b) { if ($b['status'] === 'Closed') { continue; } ?><option value="<?= (int) $b['id']; ?>"><?= br_e($b['name']); ?></option><?php } ?></select>
        <label class="form-label small">Effective date</label><input type="date" name="effective_date" class="form-control form-control-sm mb-2" value="<?= date('Y-m-d'); ?>" required>
        <label class="form-label small">Reason</label><textarea name="reason" class="form-control form-control-sm mb-2" rows="2" required></textarea>
        <button class="btn btn-sm btn-primary">Send for approval</button>
        <div class="small text-muted mt-2">The employee keeps the same account. Attendance, payroll, performance and task history stay with the branch where they happened.</div>
    </div></form></div>
    <?php } ?>
    <div class="<?= $canAsk ? 'col-xl-8' : 'col-12'; ?>"><div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:13px;">
        <tr><th class="ps-3">#</th><th>Employee</th><th>From → To</th><th>Effective</th><th>Reason</th><th>Status</th><th style="min-width:220px;"></th></tr>
        <?php foreach ($rows as $t) { ?>
        <tr><td class="ps-3"><?= (int) $t['id']; ?></td><td><?= br_e($t['names']); ?><div class="small text-muted">by <?= br_e($t['rname']); ?></div></td>
            <td><?= br_e($t['fname'] ?: '—'); ?> → <strong><?= br_e($t['tname']); ?></strong></td><td><?= br_e($t['effective_date']); ?></td><td class="small"><?= br_e($t['reason']); ?></td>
            <td><strong><?= br_e($t['status']); ?></strong><?= $t['applied_at'] ? '<div class="small text-success">applied</div>' : ''; ?><?= $t['dname'] ? '<div class="small text-muted">' . br_e($t['dname']) . ($t['decision_note'] ? ': ' . br_e($t['decision_note']) : '') . '</div>' : ''; ?></td>
            <td><?php if ($t['status'] === 'Pending' && $canApprove) { ?><form method="POST" class="d-flex gap-1"><?= br_csrf_field(); ?><input type="hidden" name="do" value="decide"><input type="hidden" name="id" value="<?= (int) $t['id']; ?>">
                <input name="note" class="form-control form-control-sm" placeholder="Note"><button name="decision" value="Approved" class="btn btn-sm btn-success">Approve</button><button name="decision" value="Rejected" class="btn btn-sm btn-outline-danger">Reject</button></form><?php } ?></td></tr>
        <?php } ?>
        <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-4">No transfers.</td></tr><?php } ?>
    </table></div></div></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>