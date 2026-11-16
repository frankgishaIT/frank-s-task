<?php
/**
 * NEW FILE: modules/branches/assignments.php — Employee Branch Assignment (section 5.2).
 * ONE account per employee. Branches are assignments: one PRIMARY branch, plus ADDITIONAL branches and TEMPORARY
 * assignments (with an end date). "All branches" (company-wide responsibilities) is given by Head Office only.
 * The primary branch changes only through a TRANSFER (with approval). Nothing here creates or deletes an account.
 */
require __DIR__ . '/common.php';
br_require_setup($conn);
if (!access_can('branches.assign', $conn)) { br_deny(); }
br_apply_due_transfers($conn);
$ho = br_head_office($conn);
$me = (int) current_user_id();
$allowed = br_allowed_ids($conn);
$visible = array_filter(br_all($conn), function ($b) use ($allowed) { return $allowed === null || in_array((int) $b['id'], $allowed, true); });
$users = br_users($conn);
$canManageUser = function (int $uid) use ($conn, $allowed) {
    if ($allowed === null) { return true; }
    return (bool) array_intersect(br_assigned_ids($conn, $uid) ?: [br_primary_id($conn, $uid)], $allowed);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $uid = (int) ($_POST['user_id'] ?? 0);
    $back = 'assignments.php?user=' . $uid;
    if (!br_csrf_ok()) { br_go($back, 'error', 'The page had expired. Please try again.'); }
    if (!isset($users[$uid]) || !$canManageUser($uid)) { br_deny(); }
    $do = $_POST['do'] ?? '';
    if ($do === 'add') {
        $bid = (int) ($_POST['branch_id'] ?? 0);
        $kind = ($_POST['kind'] ?? '') === 'temporary' ? 'temporary' : 'additional';
        $from = (string) ($_POST['start_date'] ?? ''); $to = trim((string) ($_POST['end_date'] ?? '')) ?: null;
        $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
        if (!isset($visible[$bid])) { br_go($back, 'error', 'Choose a branch you manage.'); }
        if ($visible[$bid]['status'] === 'Closed') { br_go($back, 'error', 'This branch is closed.'); }
        if (!strtotime($from) || ($to !== null && (!strtotime($to) || $to < $from))) { br_go($back, 'error', 'Check the dates.'); }
        if ($kind === 'temporary' && $to === null) { br_go($back, 'error', 'A temporary assignment needs an end date.'); }
        if (in_array($bid, br_assigned_ids($conn, $uid), true) && $kind === 'additional') { br_go($back, 'error', 'The employee is already assigned to this branch.'); }
        $s = mysqli_prepare($conn, 'INSERT INTO employee_branch_assignments (user_id, branch_id, kind, start_date, end_date, note, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iissssi', $uid, $bid, $kind, $from, $to, $note, $me);
        mysqli_stmt_execute($s);
        br_audit($conn, $bid, 'assignment_added', ucfirst($kind) . ' assignment: ' . $users[$uid]['names'] . " from $from" . ($to ? " to $to" : '') . ($note ? ". $note" : '') . '. Same account.');
        br_go($back, 'success', ucfirst($kind) . ' assignment added. The employee keeps the same account and can now switch to this branch.');
    }
    if ($do === 'end') {
        $aid = (int) ($_POST['assignment_id'] ?? 0);
        $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM employee_branch_assignments WHERE id = $aid AND user_id = $uid AND status = 'active'"));
        if (!$a || $a['kind'] === 'primary') { br_go($back, 'error', 'The primary branch changes through a transfer.'); }
        if (!isset($visible[(int) $a['branch_id']])) { br_deny(); }
        $y = date('Y-m-d', strtotime('-1 day'));
        mysqli_query($conn, "UPDATE employee_branch_assignments SET status = 'ended', end_date = IF(start_date > '$y', start_date, '$y') WHERE id = $aid");
        br_audit($conn, (int) $a['branch_id'], 'assignment_ended', ucfirst($a['kind']) . ' assignment of ' . $users[$uid]['names'] . ' ended. Records kept.');
        br_go($back, 'success', 'Assignment ended. Past records keep their branch.');
    }
    if ($do === 'all_access') {
        if (!$ho) { br_deny(); }
        $on = !empty($_POST['all_branches']) ? 1 : 0;
        $s = mysqli_prepare($conn, 'INSERT INTO user_branch_access (user_id, all_branches, updated_by, updated_at) VALUES (?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE all_branches = VALUES(all_branches), updated_by = VALUES(updated_by), updated_at = NOW()');
        mysqli_stmt_bind_param($s, 'iii', $uid, $on, $me);
        mysqli_stmt_execute($s);
        br_audit($conn, null, 'company_access', $users[$uid]['names'] . ($on ? ' now has company-wide access (all branches).' : ' no longer has company-wide access.'));
        br_go($back, 'success', $on ? 'Company-wide access given (Company View and all branches). Permissions of the role are unchanged.' : 'Company-wide access removed.');
    }
    br_go($back, 'error', 'Unknown action.');
}

$fb = (int) ($_GET['branch'] ?? 0);
$sel = (int) ($_GET['user'] ?? 0);
$today = date('Y-m-d');
$assign = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, b.name bname, b.code FROM employee_branch_assignments a JOIN branches b ON b.id = a.branch_id WHERE a.status = 'active' AND (a.end_date IS NULL OR a.end_date >= '$today') ORDER BY a.kind = 'primary' DESC, a.start_date"), MYSQLI_ASSOC) as $a) { $assign[(int) $a['user_id']][] = $a; }
$all = [];
foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT user_id, all_branches FROM user_branch_access'), MYSQLI_ASSOC) as $r) { $all[(int) $r['user_id']] = (int) $r['all_branches']; }
$list = array_filter($users, function ($u) use ($canManageUser, $fb, $assign) { return $canManageUser((int) $u['id']) && (!$fb || in_array($fb, array_map('intval', array_column($assign[(int) $u['id']] ?? [], 'branch_id')), true)); });
$history = $sel ? mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, b.name bname FROM employee_branch_assignments a JOIN branches b ON b.id = a.branch_id WHERE a.user_id = $sel ORDER BY a.start_date DESC, a.id DESC"), MYSQLI_ASSOC) : [];

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'assignments.php');
?>
<div class="row g-3">
<div class="<?= $sel ? 'col-xl-7' : 'col-12'; ?>">
    <form class="d-flex gap-2 mb-2" method="GET"><select name="branch" class="form-select form-select-sm" style="max-width:320px;" onchange="this.form.submit()"><option value="0">All branches I manage</option>
        <?php foreach ($visible as $b) { ?><option value="<?= (int) $b['id']; ?>" <?= $fb === (int) $b['id'] ? 'selected' : ''; ?>><?= br_e($b['name']); ?></option><?php } ?></select></form>
    <div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:13px;">
        <tr><th class="ps-3">Employee</th><th>Primary branch</th><th>Additional / temporary</th><th>Company-wide</th><th></th></tr>
        <?php foreach ($list as $u) { $as = $assign[(int) $u['id']] ?? []; $p = array_values(array_filter($as, function ($a) { return $a['kind'] === 'primary'; }))[0] ?? null; ?>
        <tr class="<?= $sel === (int) $u['id'] ? 'table-primary' : ''; ?>">
            <td class="ps-3"><strong><?= br_e($u['names']); ?></strong><div class="small text-muted"><?= br_e($u['role'] . ($u['department'] ? ' · ' . $u['department'] : '')); ?></div></td>
            <td><?= $p ? br_e($p['bname']) : '<span class="text-danger">none</span>'; ?></td>
            <td class="small"><?php foreach ($as as $a) { if ($a['kind'] === 'primary') { continue; } ?><span class="badge bg-<?= $a['kind'] === 'temporary' ? 'warning text-dark' : 'info'; ?> me-1"><?= br_e($a['bname']); ?><?= $a['end_date'] ? ' until ' . date('d M', strtotime($a['end_date'])) : ''; ?></span><?php } ?></td>
            <td><?= !empty($all[(int) $u['id']]) || (!isset($all[(int) $u['id']]) && $u['role'] === 'Admin') ? '<span class="badge bg-dark">All branches</span>' : '—'; ?></td>
            <td class="text-nowrap"><a href="?user=<?= (int) $u['id']; ?>&branch=<?= $fb; ?>" class="btn btn-sm btn-outline-primary">Manage</a></td>
        </tr>
        <?php } ?>
        <?php if (!$list) { ?><tr><td colspan="5" class="text-center text-muted py-4">No employees.</td></tr><?php } ?>
    </table></div></div></div>
</div>
<?php if ($sel && isset($users[$sel]) && $canManageUser($sel)) { $u = $users[$sel]; ?>
<div class="col-xl-5">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <h6><?= br_e($u['names']); ?> <span class="text-muted small">· one account (<?= br_e($u['email']); ?>)</span></h6>
        <table class="table table-sm small">
            <?php foreach ($assign[$sel] ?? [] as $a) { ?><tr><td><span class="badge bg-<?= $a['kind'] === 'primary' ? 'primary' : ($a['kind'] === 'temporary' ? 'warning text-dark' : 'info'); ?>"><?= ucfirst($a['kind']); ?></span></td><td><?= br_e($a['bname']); ?></td><td class="text-muted"><?= br_e($a['start_date']); ?><?= $a['end_date'] ? ' → ' . br_e($a['end_date']) : ''; ?></td>
                <td class="text-end"><?php if ($a['kind'] !== 'primary' && isset($visible[(int) $a['branch_id']])) { ?><form method="POST" class="m-0"><?= br_csrf_field(); ?><input type="hidden" name="do" value="end"><input type="hidden" name="user_id" value="<?= $sel; ?>"><input type="hidden" name="assignment_id" value="<?= (int) $a['id']; ?>"><button class="btn btn-sm btn-link text-danger p-0" onclick="return confirm('End this assignment?');">End</button></form><?php } elseif ($a['kind'] === 'primary') { ?><a href="transfers.php?user=<?= $sel; ?>" class="small">Transfer</a><?php } ?></td></tr><?php } ?>
        </table>
        <h6 class="mt-3">Add a branch</h6>
        <form method="POST" class="row g-2"><?= br_csrf_field(); ?><input type="hidden" name="do" value="add"><input type="hidden" name="user_id" value="<?= $sel; ?>">
            <div class="col-6"><select name="branch_id" class="form-select form-select-sm" required><?php foreach ($visible as $b) { if ($b['status'] === 'Closed') { continue; } ?><option value="<?= (int) $b['id']; ?>"><?= br_e($b['name']); ?></option><?php } ?></select></div>
            <div class="col-6"><select name="kind" class="form-select form-select-sm"><option value="additional">Additional (ongoing)</option><option value="temporary">Temporary (with end date)</option></select></div>
            <div class="col-6"><label class="small">From</label><input type="date" name="start_date" class="form-control form-control-sm" value="<?= $today; ?>" required></div>
            <div class="col-6"><label class="small">Until</label><input type="date" name="end_date" class="form-control form-control-sm"></div>
            <div class="col-12"><input name="note" class="form-control form-control-sm" placeholder="Note (e.g. covering the Huye shop)"></div>
            <div class="col-12"><button class="btn btn-sm btn-primary">Add assignment</button></div>
        </form>
        <?php if ($ho) { $on = !empty($all[$sel]) || (!isset($all[$sel]) && $u['role'] === 'Admin'); ?>
        <form method="POST" class="mt-3 border-top pt-3"><?= br_csrf_field(); ?><input type="hidden" name="do" value="all_access"><input type="hidden" name="user_id" value="<?= $sel; ?>">
            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="all_branches" value="1" id="allb" <?= $on ? 'checked' : ''; ?> onchange="this.form.submit()"><label class="form-check-label" for="allb">Company-wide responsibilities (Company View, all branches)</label></div>
            <div class="small text-muted">For the Managing Director, Operations Manager, HR, Accountant... It does not change what the role is allowed to do.</div>
        </form>
        <?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body">
        <h6>Assignment history</h6>
        <?php foreach ($history as $h) { ?><div class="small border-top py-1"><?= br_e(ucfirst($h['kind'])); ?> · <strong><?= br_e($h['bname']); ?></strong> · <?= br_e($h['start_date']); ?> → <?= br_e($h['end_date'] ?: 'now'); ?> · <?= br_e($h['status']); ?><?= $h['note'] ? '<div class="text-muted">' . br_e($h['note']) . '</div>' : ''; ?></div><?php } ?>
    </div></div>
</div>
<?php } ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>