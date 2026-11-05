<?php
/**
 * NEW FILE: modules/budget/budgets.php — budgets: create (annual / quarterly / monthly / custom; company, department,
 * project, expense category or revenue), submit, approve or reject, revise (original amount and history kept), close.
 */
require __DIR__ . '/budget_common.php';
$L = bdg_lists($conn);
$canCreate = access_can('budget.create', $conn); $canApprove = access_can('budget.approve', $conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $id = (int) ($_POST['id'] ?? 0); $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        $b = $id ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM budgets WHERE id = $id")) : null;
        switch ($act) {
            case 'create':
                if (!$canCreate) { throw new RuntimeException('You cannot create budgets.'); }
                $name = mb_substr(trim((string) $_POST['name']), 0, 160); $amount = round((float) $_POST['amount'], 2);
                $ptype = isset(BDG_PERIODS[$_POST['period_type'] ?? '']) ? $_POST['period_type'] : 'annual'; $fy = (int) $_POST['financial_year'];
                $scope = isset(BDG_SCOPES[$_POST['scope'] ?? '']) ? $_POST['scope'] : 'company'; $kind = $scope === 'revenue' ? 'revenue' : 'expense';
                if ($name === '' || $amount <= 0 || $fy < 2000 || $fy > 2100) { throw new RuntimeException('Give the budget a name, a financial year and an amount above zero.'); }
                if ($ptype === 'annual') { $start = "$fy-01-01"; $end = "$fy-12-31"; }
                elseif ($ptype === 'quarterly') { $q = max(1, min(4, (int) $_POST['quarter'])); $start = sprintf('%d-%02d-01', $fy, 3 * $q - 2); $end = date('Y-m-t', strtotime(sprintf('%d-%02d-01', $fy, 3 * $q))); }
                elseif ($ptype === 'monthly') { $m = max(1, min(12, (int) $_POST['month'])); $start = sprintf('%d-%02d-01', $fy, $m); $end = date('Y-m-t', strtotime($start)); }
                else { $start = (string) $_POST['start']; $end = (string) $_POST['end'];
                    if (!DateTime::createFromFormat('Y-m-d', $start) || !DateTime::createFromFormat('Y-m-d', $end) || $end < $start) { throw new RuntimeException('Check the custom period dates.'); } }
                $dept = (int) ($_POST['department_id'] ?? 0) ?: null; $proj = (int) ($_POST['project_id'] ?? 0) ?: null;
                $cat = trim((string) ($_POST['category'] ?? '')) ?: null; $fund = (int) ($_POST['fund_id'] ?? 0) ?: null;
                if ($scope === 'department' && !$dept) { throw new RuntimeException('Choose the department.'); }
                if ($scope === 'project' && !$proj) { throw new RuntimeException('Choose the project.'); }
                if ($scope === 'category' && !$cat) { throw new RuntimeException('Choose the expense category.'); }
                $status = ($_POST['submit_now'] ?? '') === '1' ? 'Pending Approval' : 'Draft'; $notes = trim((string) ($_POST['notes'] ?? '')) ?: null;
                $s = mysqli_prepare($conn, 'INSERT INTO budgets (name, financial_year, period_type, period_start, period_end, scope, kind, department_id, project_id, category, fund_id, original_amount, amount, status, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'sisssssiisiddssi', $name, $fy, $ptype, $start, $end, $scope, $kind, $dept, $proj, $cat, $fund, $amount, $amount, $status, $notes, $me);
                mysqli_stmt_execute($s); $new = (int) mysqli_insert_id($conn);
                bdg_audit($conn, 'Budget created (' . $status . ')', bdg_ref('budget', $new), null, ['amount' => $amount, 'period' => "$start to $end", 'scope' => $scope], $notes, $me);
                $msg = 'Budget ' . bdg_ref('budget', $new) . ' saved as ' . $status . '.'; break;
            case 'submit':
                if (!$canCreate || !$b || $b['status'] !== 'Draft') { throw new RuntimeException('Only a draft can be submitted.'); }
                mysqli_query($conn, "UPDATE budgets SET status = 'Pending Approval', updated_at = NOW() WHERE id = $id");
                bdg_audit($conn, 'Budget submitted for approval', bdg_ref('budget', $id), 'Draft', 'Pending Approval', null, $me); $msg = 'Submitted for approval.'; break;
            case 'approve': case 'reject':
                if (!$canApprove) { throw new RuntimeException('Only the Managing Director (or an authorised officer) approves budgets.'); }
                if (!$b || $b['status'] !== 'Pending Approval') { throw new RuntimeException('This budget is not waiting for approval.'); }
                if ($act === 'reject' && mb_strlen($reason) < 5) { throw new RuntimeException('Write the reason for rejecting.'); }
                $to = $act === 'approve' ? 'Approved' : 'Rejected';
                $s = mysqli_prepare($conn, 'UPDATE budgets SET status = ?, approved_by = ?, approved_at = NOW(), updated_at = NOW() WHERE id = ?'); mysqli_stmt_bind_param($s, 'sii', $to, $me, $id); mysqli_stmt_execute($s);
                bdg_audit($conn, 'Budget ' . strtolower($to), bdg_ref('budget', $id), 'Pending Approval', $to, $reason ?: null, $me); $msg = 'Budget ' . strtolower($to) . '.'; break;
            case 'revise':
                if (!$canCreate || !$b || $b['status'] !== 'Approved') { throw new RuntimeException('Only an approved budget can be revised.'); }
                $na = round((float) $_POST['new_amount'], 2);
                if ($na <= 0 || mb_strlen($reason) < 5) { throw new RuntimeException('Enter the new amount and the reason.'); }
                if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM budget_revisions WHERE budget_id = $id AND status = 'Pending'"))[0]) { throw new RuntimeException('A revision of this budget is already waiting for approval.'); }
                $s = mysqli_prepare($conn, 'INSERT INTO budget_revisions (budget_id, old_amount, new_amount, reason, requested_by) VALUES (?, ?, ?, ?, ?)');
                $old = (float) $b['amount']; mysqli_stmt_bind_param($s, 'iddsi', $id, $old, $na, $reason, $me); mysqli_stmt_execute($s); $rid = (int) mysqli_insert_id($conn);
                bdg_audit($conn, 'Budget revision requested', bdg_ref('revision', $rid) . ' / ' . bdg_ref('budget', $id), $old, $na, $reason, $me); $msg = 'Revision requested. The budget stays at ' . $rwf($old) . ' until it is approved.'; break;
            case 'revision_approve': case 'revision_reject':
                if (!$canApprove) { throw new RuntimeException('Only the Managing Director (or an authorised officer) approves revisions.'); }
                $rid = (int) $_POST['revision_id']; $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM budget_revisions WHERE id = $rid AND status = 'Pending'"));
                if (!$r) { throw new RuntimeException('This revision is no longer pending.'); }
                if ($act === 'revision_reject' && mb_strlen($reason) < 5) { throw new RuntimeException('Write the reason for rejecting.'); }
                mysqli_begin_transaction($conn);
                $to = $act === 'revision_approve' ? 'Approved' : 'Rejected';
                $s = mysqli_prepare($conn, 'UPDATE budget_revisions SET status = ?, decided_by = ?, decision_note = ?, decided_at = NOW() WHERE id = ?'); mysqli_stmt_bind_param($s, 'sisi', $to, $me, $reason, $rid); mysqli_stmt_execute($s);
                if ($to === 'Approved') { $s = mysqli_prepare($conn, 'UPDATE budgets SET amount = ?, updated_at = NOW() WHERE id = ?'); $na = (float) $r['new_amount']; $bid = (int) $r['budget_id']; mysqli_stmt_bind_param($s, 'di', $na, $bid); mysqli_stmt_execute($s);
                    mysqli_query($conn, "DELETE FROM bdg_alerts WHERE budget_id = $bid"); } // alerts are measured against the new amount
                bdg_audit($conn, 'Budget revision ' . strtolower($to), bdg_ref('revision', $rid) . ' / ' . bdg_ref('budget', (int) $r['budget_id']), (float) $r['old_amount'], $to === 'Approved' ? (float) $r['new_amount'] : (float) $r['old_amount'], $reason ?: $r['reason'], $me);
                mysqli_commit($conn); $msg = 'Revision ' . strtolower($to) . '.'; break;
            case 'close':
                if (!$canApprove || !$b || $b['status'] !== 'Approved') { throw new RuntimeException('Only an approved budget can be closed, by an approver.'); }
                mysqli_query($conn, "UPDATE budgets SET status = 'Closed', updated_at = NOW() WHERE id = $id");
                bdg_audit($conn, 'Budget closed', bdg_ref('budget', $id), 'Approved', 'Closed', $reason ?: null, $me); $msg = 'Budget closed (kept in the history).'; break;
            default: throw new RuntimeException('Unknown action.');
        }
        header('Location: budgets.php?' . http_build_query(['success' => $msg] + array_intersect_key($_GET, ['status' => 1, 'year' => 1]))); exit;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
$st = in_array($_GET['status'] ?? '', BDG_STATUSES, true) ? $_GET['status'] : '';
$year = (int) ($_GET['year'] ?? date('Y'));
$list = bdg_budgets($conn, ['year' => $year], $st !== '' ? [$st] : BDG_STATUSES);
$revs = mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, b.name, u.names AS requester FROM budget_revisions r JOIN budgets b ON b.id = r.budget_id LEFT JOIN users u ON u.id = r.requested_by WHERE r.status = 'Pending' ORDER BY r.id"), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
bdg_tabs('budgets.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if ($revs) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Budget revisions waiting for approval</h6>
    <?php foreach ($revs as $r) { ?><div class="d-flex flex-wrap justify-content-between align-items-center border-bottom py-2 gap-2">
        <span><?= bdg_ref('revision', (int) $r['id']); ?> · <strong><?= $e($r['name']); ?></strong>: <?= $rwf($r['old_amount']); ?> → <strong><?= $rwf($r['new_amount']); ?></strong> · <?= $e($r['reason']); ?> · <?= $e($r['requester']); ?></span>
        <?php if ($canApprove) { ?><span class="d-flex gap-1"><form method="POST"><input type="hidden" name="action" value="revision_approve"><input type="hidden" name="revision_id" value="<?= (int) $r['id']; ?>"><button class="btn btn-sm btn-success">Approve</button></form>
            <form method="POST" class="d-flex gap-1"><input type="hidden" name="action" value="revision_reject"><input type="hidden" name="revision_id" value="<?= (int) $r['id']; ?>"><input name="reason" class="form-control form-control-sm" placeholder="Reason"><button class="btn btn-sm btn-outline-danger">Reject</button></form></span><?php } ?>
    </div><?php } ?></div></div><?php } ?>
<?php if ($canCreate) { ?>
<details class="card border-0 shadow-sm mb-3" <?= isset($error) ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold">+ New budget</summary>
<form method="POST" class="card-body pt-0 row g-2" id="bForm"><input type="hidden" name="action" value="create">
    <div class="col-md-5"><label class="form-label small text-muted mb-1">Budget name *</label><input name="name" class="form-control form-control-sm" maxlength="160" required placeholder="e.g. Operations department 2027"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Financial year *</label><input type="number" name="financial_year" class="form-control form-control-sm" value="<?= date('Y'); ?>" required></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Period *</label><select name="period_type" id="bPeriod" class="form-select form-select-sm"><?php foreach (BDG_PERIODS as $k => $l) { ?><option value="<?= $k; ?>"><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3 p-q"><label class="form-label small text-muted mb-1">Quarter</label><select name="quarter" class="form-select form-select-sm"><?php for ($i = 1; $i <= 4; $i++) { ?><option value="<?= $i; ?>">Q<?= $i; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3 p-m"><label class="form-label small text-muted mb-1">Month</label><select name="month" class="form-select form-select-sm"><?php for ($i = 1; $i <= 12; $i++) { ?><option value="<?= $i; ?>" <?= $i === (int) date('n') ? 'selected' : ''; ?>><?= date('F', mktime(0, 0, 0, $i, 1)); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3 p-c"><label class="form-label small text-muted mb-1">From</label><input type="date" name="start" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-3 p-c"><label class="form-label small text-muted mb-1">To</label><input type="date" name="end" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Budget for *</label><select name="scope" id="bScope" class="form-select form-select-sm"><?php foreach (BDG_SCOPES as $k => $l) { ?><option value="<?= $k; ?>"><?= $l; ?></option><?php } ?></select></div>
    <div class="col-md-3 s-department"><label class="form-label small text-muted mb-1">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($L['departments'] as $d) { ?><option value="<?= (int) $d['id']; ?>"><?= $e($d['name']); ?></option><?php } ?></select></div>
    <div class="col-md-3 s-project"><label class="form-label small text-muted mb-1">Project</label><select name="project_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($L['projects'] as $p) { ?><option value="<?= (int) $p['id']; ?>"><?= $e($p['name']); ?></option><?php } ?></select></div>
    <div class="col-md-3 s-category"><label class="form-label small text-muted mb-1">Expense category</label><select name="category" class="form-select form-select-sm"><option value="">—</option><?php foreach ($L['categories'] as $c) { ?><option><?= $e($c); ?></option><?php } ?></select></div>
    <div class="col-md-3 s-fund"><label class="form-label small text-muted mb-1">Fund (optional)</label><select name="fund_id" class="form-select form-select-sm"><option value="">Any fund</option><?php foreach ($L['funds'] as $f) { ?><option value="<?= (int) $f['id']; ?>"><?= $e($f['name']); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Amount (RWF) *</label><input type="number" name="amount" min="1" step="1" class="form-control form-control-sm" required></div>
    <div class="col-md-9"><label class="form-label small text-muted mb-1">Notes</label><input name="notes" class="form-control form-control-sm" maxlength="500"></div>
    <div class="col-12 d-flex gap-2"><button name="submit_now" value="1" class="btn btn-primary btn-sm">Save &amp; submit for approval</button><button name="submit_now" value="0" class="btn btn-outline-primary btn-sm">Save as draft</button></div>
</form></details>
<script>(function () { var p = document.getElementById('bPeriod'), s = document.getElementById('bScope');
    function sync() { [['p-q', 'quarterly'], ['p-m', 'monthly'], ['p-c', 'custom']].forEach(function (x) { document.querySelectorAll('.' + x[0]).forEach(function (el) { el.style.display = p.value === x[1] ? '' : 'none'; }); });
        ['department', 'project', 'category'].forEach(function (k) { document.querySelectorAll('.s-' + k).forEach(function (el) { el.style.display = s.value === k ? '' : 'none'; }); });
        document.querySelectorAll('.s-fund').forEach(function (el) { el.style.display = s.value === 'revenue' ? 'none' : ''; }); }
    p.addEventListener('change', sync); s.addEventListener('change', sync); sync(); })();</script>
<?php } ?>
<form class="d-flex flex-wrap gap-2 mb-2"><input type="number" name="year" value="<?= $year; ?>" class="form-control form-control-sm" style="width:110px;">
    <select name="status" class="form-select form-select-sm" style="width:180px;"><option value="">All statuses</option><?php foreach (BDG_STATUSES as $x) { ?><option <?= $st === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select><button class="btn btn-sm btn-primary">Show</button></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Budget</th><th>Period</th><th>For</th><th class="text-end">Approved budget</th><th class="text-end">Actual</th><th class="text-end">Available</th><th>Utilization</th><th>Status</th><th style="min-width:210px;"></th></tr>
    <?php if (!$list) { ?><tr><td colspan="9" class="text-center text-muted py-4">No budgets for <?= $year; ?>.</td></tr><?php } ?>
    <?php foreach ($list as $b) { $f = $b['status'] === 'Approved' || $b['status'] === 'Closed' ? bdg_figures($conn, $b) : null; $id = (int) $b['id']; ?>
    <tr><td class="ps-3"><strong><?= $e($b['name']); ?></strong><div class="small text-muted"><?= bdg_ref('budget', $id); ?> · <?= $b['kind'] === 'revenue' ? 'Revenue target' : 'Expense'; ?><?= (float) $b['original_amount'] !== (float) $b['amount'] ? ' · originally ' . $rwf($b['original_amount']) : ''; ?></div></td>
        <td class="small"><?= $e(bdg_period_label($b)); ?></td><td class="small"><?= $e(BDG_SCOPES[$b['scope']] ?? ''); ?><div class="text-muted"><?= $e(bdg_scope_label($b)); ?></div></td>
        <td class="text-end"><?= $rwf($b['amount']); ?></td><td class="text-end"><?= $f ? $rwf($f['actual']) : '—'; ?></td><td class="text-end <?= $f && $f['available'] < 0 ? 'text-danger' : ''; ?>"><?= $f ? $rwf($f['available']) : '—'; ?></td>
        <td><?= $f ? ($f['utilization'] !== null ? $f['utilization'] . '% ' : '') . bdg_badge($f['level']) : '—'; ?></td><td><?= bdg_badge($b['status']); ?></td>
        <td class="small"><?php if ($b['status'] === 'Draft' && $canCreate) { ?><form method="POST" class="d-inline"><input type="hidden" name="action" value="submit"><input type="hidden" name="id" value="<?= $id; ?>"><button class="btn btn-sm btn-outline-primary">Submit</button></form><?php } ?>
            <?php if ($b['status'] === 'Pending Approval' && $canApprove) { ?><form method="POST" class="d-inline"><input type="hidden" name="action" value="approve"><input type="hidden" name="id" value="<?= $id; ?>"><button class="btn btn-sm btn-success">Approve</button></form>
                <form method="POST" class="d-flex gap-1 mt-1"><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= $id; ?>"><input name="reason" class="form-control form-control-sm" placeholder="Reason"><button class="btn btn-sm btn-outline-danger">Reject</button></form><?php } ?>
            <?php if ($b['status'] === 'Approved' && $canCreate) { ?><details><summary class="text-primary">Revise</summary><form method="POST" class="mt-1"><input type="hidden" name="action" value="revise"><input type="hidden" name="id" value="<?= $id; ?>">
                <input type="number" name="new_amount" min="1" class="form-control form-control-sm mb-1" placeholder="New amount"><input name="reason" class="form-control form-control-sm mb-1" placeholder="Reason"><button class="btn btn-sm btn-primary">Request revision</button></form></details><?php } ?>
            <?php if ($b['status'] === 'Approved' && $canApprove) { ?><form method="POST" class="d-inline" onsubmit="return confirm('Close this budget? It stays in the history.');"><input type="hidden" name="action" value="close"><input type="hidden" name="id" value="<?= $id; ?>"><button class="btn btn-sm btn-link text-muted p-0">Close</button></form><?php } ?></td></tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>