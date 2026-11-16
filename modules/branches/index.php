<?php
/**
 * NEW FILE: modules/branches/index.php — Branch Dashboard (section 4.1).
 * Company-wide figures for Head Office; a branch manager only ever sees their own branch(es) (server-side).
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/report_helpers.php';
if (!br_enabled($conn)) { if (current_user_role() === 'Admin') { header('Location: setup.php'); exit; } br_deny(); }
br_apply_due_transfers($conn);

if (empty($_GET['period'])) { $_GET['period'] = 'this_month'; }
$period = report_period_from_request($_GET);
if (!empty($period['error'])) { $err = $period['error']; $period = report_period_from_request(['period' => 'this_month']); }
$from = $period['start'] ?? '2000-01-01'; $to = min($period['end'] ?? date('Y-m-d'), date('Y-m-d'));

$allowed = br_allowed_ids($conn);
$visible = array_filter(br_all($conn), function ($b) use ($allowed) { return $allowed === null || in_array((int) $b['id'], $allowed, true); });
$filter = $_GET['branch'] ?? ($allowed === null ? 'all' : (string) br_current($conn));
if ($filter !== 'all' && !isset($visible[(int) $filter])) { $filter = $allowed === null ? 'all' : (string) array_key_first($visible); }
$shown = $filter === 'all' ? $visible : [(int) $filter => $visible[(int) $filter]];

$fig = br_figures($conn, $from, $to);
$tot = ['revenue' => 0, 'expenses' => 0, 'employees' => 0, 'departments' => 0, 'stock_value' => 0, 'pending' => 0, 'sales' => 0];
foreach ($shown as $id => $b) { foreach ($tot as $k => $v) { $tot[$k] += $fig[$id][$k] ?? 0; } }
$status = ['Active' => 0, 'Suspended' => 0, 'Closed' => 0];
foreach ($shown as $b) { $status[$b['status']] = ($status[$b['status']] ?? 0) + 1; }
$pendingTransfers = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM branch_transfers WHERE status = 'Pending'" . ($filter === 'all' ? ($allowed === null ? '' : ' AND (from_branch_id IN (' . implode(',', $allowed ?: [0]) . ') OR to_branch_id IN (' . implode(',', $allowed ?: [0]) . '))') : ' AND (from_branch_id = ' . (int) $filter . ' OR to_branch_id = ' . (int) $filter . ')')))[0];
$main = br_get($conn, br_main_id($conn));
$money = function ($v) { return 'RWF ' . number_format((float) $v, 0); };

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'index.php');
?>
<?php if (!empty($err)) { ?><div class="alert alert-danger"><?= br_e($err); ?></div><?php } ?>
<?php if ($main && !$main['manager_user_id'] && br_head_office($conn)) { ?><div class="alert alert-warning small">The Main Branch has no Branch Manager yet. <a href="edit.php?id=<?= (int) $main['id']; ?>">Assign authorized management</a>.</div><?php } ?>

<form class="row g-2 mb-3 align-items-end" method="GET">
    <div class="col-6 col-md-3"><label class="form-label small mb-0">Branch</label><select name="branch" class="form-select form-select-sm">
        <?php if ($allowed === null) { ?><option value="all">All Branches (company)</option><?php } ?>
        <?php foreach ($visible as $b) { ?><option value="<?= (int) $b['id']; ?>" <?= (string) $filter === (string) $b['id'] ? 'selected' : ''; ?>><?= br_e($b['name']); ?><?= $b['is_main'] ? ' · Main' : ''; ?></option><?php } ?>
    </select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Period</label><select name="period" class="form-select form-select-sm" id="bdP">
        <?php foreach (report_period_options() as $k => $l) { ?><option value="<?= $k; ?>" <?= $period['preset'] === $k ? 'selected' : ''; ?>><?= br_e($l); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2 bd-c"><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= br_e($_GET['from'] ?? ''); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2 bd-c"><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= br_e($_GET['to'] ?? ''); ?>" class="form-control form-control-sm"></div>
    <div class="col-12 col-md-2"><button class="btn btn-sm btn-primary w-100">Show</button></div>
</form>
<script>(function(){var p=document.getElementById('bdP');function f(){document.querySelectorAll('.bd-c').forEach(function(e){e.style.display=p.value==='custom'?'':'none';});}p.addEventListener('change',f);f();})();</script>

<div class="row g-2 mb-3">
    <?php foreach ([['Total branches', count($shown), 'bi-diagram-3', 'primary'], ['Active', $status['Active'], 'bi-check-circle', 'success'], ['Suspended', $status['Suspended'], 'bi-pause-circle', 'warning'],
        ['Closed', $status['Closed'], 'bi-x-circle', 'secondary'], ['Employees', number_format($tot['employees']), 'bi-people', 'primary'], ['Departments', number_format($tot['departments']), 'bi-building', 'primary'],
        ['Revenue', $money($tot['revenue']), 'bi-graph-up-arrow', 'success'], ['Expenses', $money($tot['expenses']), 'bi-receipt', 'danger'],
        ['Stock value', $money($tot['stock_value']), 'bi-box-seam', 'primary'], ['Pending requests', $pendingTransfers, 'bi-hourglass-split', $pendingTransfers ? 'warning' : 'secondary']] as [$l, $v, $i, $c]) { ?>
        <div class="col-6 col-md-4 col-xl"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2">
            <div class="small text-muted"><i class="bi <?= $i; ?> text-<?= $c; ?>"></i> <?= br_e($l); ?></div><div class="fw-bold"><?= br_e((string) $v); ?></div>
        </div></div></div>
    <?php } ?>
</div>

<div class="row g-3 mb-3">
    <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Branch performance comparison · <?= br_e($period['label']); ?></h6>
        <div style="position:relative;height:280px;"><canvas id="bdChart"></canvas></div>
    </div></div></div>
    <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Branches</h6>
        <div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
            <tr><th>Branch</th><th>Status</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Result</th></tr>
            <?php foreach ($shown as $id => $b) { $f = $fig[$id]; ?>
                <tr><td><a href="view.php?id=<?= $id; ?>"><?= br_e($b['name']); ?></a><div class="small text-muted"><?= br_e($b['code']); ?> · <?= (int) $f['employees']; ?> staff</div></td>
                    <td><?= br_badge($b['status']); ?></td><td class="text-end"><?= number_format($f['revenue']); ?></td><td class="text-end"><?= number_format($f['expenses']); ?></td>
                    <td class="text-end fw-semibold <?= $f['revenue'] - $f['expenses'] < 0 ? 'text-danger' : 'text-success'; ?>"><?= number_format($f['revenue'] - $f['expenses']); ?></td></tr>
            <?php } ?>
        </table></div>
        <div class="small text-muted mt-2">Revenue = confirmed sales (Paid, Partially Paid, Credit). Expenses = approved expense transactions. Amounts in RWF.</div>
        <?php if (br_head_office($conn)) { ?><a href="create.php" class="btn btn-sm btn-primary mt-2"><i class="bi bi-plus-lg"></i> Register a new branch</a><?php } ?>
    </div></div></div>
</div>
<script>
(function () {
    if (typeof Chart === 'undefined') { return; }
    var d = <?= json_encode(array_values(array_map(function ($id) use ($shown, $fig) { return ['n' => trim(preg_replace('/^RISE MOTIVE\s*[–-]\s*/u', '', $shown[$id]['name'])), 'r' => $fig[$id]['revenue'], 'e' => $fig[$id]['expenses']]; }, array_keys($shown)))); ?>;
    new Chart(document.getElementById('bdChart'), { type: 'bar', data: { labels: d.map(function (x) { return x.n; }), datasets: [
        { label: 'Revenue', data: d.map(function (x) { return x.r; }), backgroundColor: '#0FA968' }, { label: 'Expenses', data: d.map(function (x) { return x.e; }), backgroundColor: '#E24B4A' },
        { label: 'Result', data: d.map(function (x) { return x.r - x.e; }), backgroundColor: '#1E2FE0' }] },
        options: { maintainAspectRatio: false, scales: { y: { ticks: { callback: function (v) { return Math.abs(v) >= 1e6 ? (v / 1e6) + 'M' : Math.abs(v) >= 1e3 ? (v / 1e3) + 'k' : v; } } } } } });
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>