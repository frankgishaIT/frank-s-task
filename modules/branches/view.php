<?php
/**
 * NEW FILE: modules/branches/view.php — Branch Profile (section 4.3) and Status Management (4.4).
 * Everything about one branch: details, manager, employees, departments, operational locations, cash registers,
 * assets, products & stock, targets, performance and activity history. Head Office can change details and status,
 * assign departments, add locations and cash registers. Closing or suspending never deletes anything.
 */
require __DIR__ . '/common.php';
br_require_setup($conn);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$b = br_get($conn, $id);
if (!$b) { br_go('branches.php', 'error', 'Branch not found.'); }
if (!br_can_access($conn, $id)) { br_audit($conn, $id, 'access_denied', 'Tried to open the profile of a branch without access.'); br_deny(); }
$ho = br_head_office($conn);
$me = (int) current_user_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $back = 'view.php?id=' . $id;
    if (!br_csrf_ok()) { br_go($back, 'error', 'The page had expired. Please try again.'); }
    if (!$ho) { br_deny(); }
    $do = $_POST['do'] ?? '';
    if ($do === 'status') {
        $new = $_POST['status'] ?? ''; $reason = trim((string) ($_POST['reason'] ?? ''));
        if (!in_array($new, BR_STATUSES, true) || $new === $b['status']) { br_go($back, 'error', 'Choose a different status.'); }
        if ($reason === '') { br_go($back, 'error', 'Give the reason for the status change.'); }
        if ((int) $b['is_main'] === 1 && $new === 'Closed') { br_go($back, 'error', 'The Main Branch (Head Office) cannot be closed.'); }
        $s = mysqli_prepare($conn, 'UPDATE branches SET status = ?, status_reason = ?, status_changed_at = NOW(), updated_at = NOW() WHERE id = ?');
        mysqli_stmt_bind_param($s, 'ssi', $new, $reason, $id);
        mysqli_stmt_execute($s);
        br_forget();
        br_audit($conn, $id, 'status_changed', "Status changed from {$b['status']} to $new. Reason: $reason", ['status' => $b['status']], ['status' => $new]);
        try {
            require_once __DIR__ . '/../../includes/notification_helper.php';
            foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT user_id FROM employee_branch_assignments WHERE branch_id = $id AND status = 'active'"), MYSQLI_ASSOC) as $u) {
                $n = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
                $t = 'Branch status: ' . $b['name'] . ' is now ' . $new; $m = $b['name'] . ' (' . $b['code'] . ') is now ' . ($new === 'Suspended' ? 'temporarily suspended' : strtolower($new)) . '. Reason: ' . $reason;
                $uid = (int) $u['user_id'];
                mysqli_stmt_bind_param($n, 'iss', $uid, $t, $m);
                mysqli_stmt_execute($n);
            }
        } catch (Throwable $e) { error_log('branch status notify: ' . $e->getMessage()); }
        br_go($back, 'success', 'Status changed to ' . $new . '. Employees of the branch were notified. History stays available.');
    }
    if ($do === 'department') {
        $dep = (int) ($_POST['department_id'] ?? 0);
        $d = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, name, branch_id FROM departments WHERE id = ' . $dep));
        if (!$d) { br_go($back, 'error', 'Department not found.'); }
        mysqli_query($conn, "UPDATE departments SET branch_id = $id WHERE id = $dep");
        br_audit($conn, $id, 'department_assigned', 'Department "' . $d['name'] . '" assigned to this branch (was branch #' . ($d['branch_id'] ?: '—') . ').');
        br_go($back . '#departments', 'success', 'Department assigned.');
    }
    if ($do === 'location' || $do === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '') { br_go($back, 'error', 'Enter a name.'); }
        $t = $do === 'location' ? 'branch_locations' : 'branch_cash_registers';
        $prefix = $b['code'] . ($do === 'location' ? '-L' : '-C');
        $n = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM $t WHERE branch_id = $id"))[0] + 1;
        $code = $prefix . str_pad((string) $n, 2, '0', STR_PAD_LEFT);
        if ($do === 'location') {
            $kind = in_array($_POST['kind'] ?? '', ['Shop floor', 'Store', 'Office', 'Warehouse', 'Service point'], true) ? $_POST['kind'] : 'Shop floor';
            $addr = trim((string) ($_POST['address'] ?? '')) ?: null;
            $s = mysqli_prepare($conn, 'INSERT INTO branch_locations (branch_id, code, name, kind, address, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'issssi', $id, $code, $name, $kind, $addr, $me);
        } else {
            $loc = (int) ($_POST['location_id'] ?? 0) ?: null; $cashier = (int) ($_POST['cashier_user_id'] ?? 0) ?: null;
            $s = mysqli_prepare($conn, 'INSERT INTO branch_cash_registers (branch_id, location_id, code, name, cashier_user_id, created_by) VALUES (?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'iissii', $id, $loc, $code, $name, $cashier, $me);
        }
        mysqli_stmt_execute($s);
        br_audit($conn, $id, $do === 'location' ? 'location_added' : 'cash_register_added', ($do === 'location' ? 'Operational location ' : 'Cash register ') . "$code \"$name\" added.");
        br_go($back . '#' . $do . 's', 'success', ($do === 'location' ? 'Location' : 'Cash register') . " $code added.");
    }
    br_go($back, 'error', 'Unknown action.');
}

$users = br_users($conn, false);
$today = date('Y-m-d');
$staff = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names, u.role, u.is_active, d.name department FROM employee_branch_assignments a JOIN users u ON u.id = a.user_id LEFT JOIN departments d ON d.id = u.department_id
    WHERE a.branch_id = $id AND a.status = 'active' AND (a.end_date IS NULL OR a.end_date >= '$today') ORDER BY a.kind = 'primary' DESC, u.names"), MYSQLI_ASSOC);
$deps = mysqli_fetch_all(mysqli_query($conn, "SELECT d.*, (SELECT COUNT(*) FROM users u WHERE u.department_id = d.id AND u.is_active = 1) people FROM departments d WHERE d.branch_id = $id ORDER BY d.name"), MYSQLI_ASSOC);
$otherDeps = mysqli_fetch_all(mysqli_query($conn, "SELECT d.id, d.name, b.name bname FROM departments d LEFT JOIN branches b ON b.id = d.branch_id WHERE COALESCE(d.branch_id, 0) <> $id ORDER BY d.name"), MYSQLI_ASSOC);
$locs = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM branch_locations WHERE branch_id = $id ORDER BY code"), MYSQLI_ASSOC);
$regs = mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, l.name lname FROM branch_cash_registers r LEFT JOIN branch_locations l ON l.id = r.location_id WHERE r.branch_id = $id ORDER BY r.code"), MYSQLI_ASSOC);
$assets = [];
try { $assets = mysqli_fetch_all(mysqli_query($conn, "SELECT id, asset_code, asset_name, asset_type, current_value, status FROM assets WHERE branch_id = $id ORDER BY asset_name LIMIT 100"), MYSQLI_ASSOC); } catch (Throwable $e) { }
$products = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM products WHERE COALESCE(is_active, 1) = 1"))[0];
$month = br_figures($conn, date('Y-m-01'), $today)[$id];
$year = br_figures($conn, date('Y-01-01'), $today)[$id];
$activity = mysqli_fetch_all(mysqli_query($conn, "SELECT l.*, u.names FROM branch_audit_logs l LEFT JOIN users u ON u.id = l.user_id WHERE l.branch_id = $id ORDER BY l.id DESC LIMIT 40"), MYSQLI_ASSOC);
$money = function ($v) { return 'RWF ' . number_format((float) $v, 0); };

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'branches.php');
?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body d-flex flex-wrap justify-content-between gap-3">
    <div>
        <div class="small text-muted"><?= br_e($b['code']); ?> · <?= br_e($b['branch_type']); ?><?= $b['is_main'] ? ' · Main · Default branch' : ''; ?> · RISE MOTIVE Ltd</div>
        <h4 class="mb-1"><?= br_e($b['name']); ?> <?= br_badge($b['status']); ?></h4>
        <div class="small"><i class="bi bi-geo-alt"></i> <?= br_e(implode(', ', array_filter([$b['address'], $b['village'], $b['cell'], $b['sector'], $b['district'], $b['province']]))); ?></div>
        <div class="small"><i class="bi bi-telephone"></i> <?= br_e($b['phone']); ?><?= $b['email'] ? ' · <i class="bi bi-envelope"></i> ' . br_e($b['email']) : ''; ?> · Opened <?= $b['opening_date'] ? date('d M Y', strtotime($b['opening_date'])) : '—'; ?></div>
        <div class="small"><i class="bi bi-person-badge"></i> Branch Manager: <strong><?= br_e($users[(int) $b['manager_user_id']]['names'] ?? 'not assigned'); ?></strong></div>
        <?php if ($b['status'] !== 'Active') { ?><div class="small text-danger mt-1"><?= br_e($b['status']); ?> since <?= br_e($b['status_changed_at']); ?>: <?= br_e($b['status_reason']); ?></div><?php } ?>
    </div>
    <div class="d-flex gap-2 align-items-start"><?php if ($ho) { ?><a href="edit.php?id=<?= $id; ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i> Edit details</a><?php } ?><a href="index.php?branch=<?= $id; ?>" class="btn btn-sm btn-outline-secondary">Dashboard</a></div>
</div></div>

<div class="row g-2 mb-3">
    <?php foreach ([['Revenue this month', $money($month['revenue'])], ['Expenses this month', $money($month['expenses'])], ['Result this month', $money($month['revenue'] - $month['expenses'])],
        ['Revenue this year', $money($year['revenue'])], ['Sales this year', number_format($year['sales'])], ['Stock value', $money($month['stock_value'])]] as [$k, $v]) { ?>
        <div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="small text-muted"><?= br_e($k); ?></div><div class="fw-bold"><?= br_e($v); ?></div></div></div></div>
    <?php } ?>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3" id="employees"><div class="card-body">
            <div class="d-flex justify-content-between"><h6>Employees (<?= count($staff); ?>)</h6><?php if (access_can('branches.assign', $conn)) { ?><a href="assignments.php?branch=<?= $id; ?>" class="small">Manage assignments</a><?php } ?></div>
            <div class="table-responsive" style="max-height:300px;"><table class="table table-sm small mb-0">
                <?php foreach ($staff as $s) { ?><tr><td><?= br_e($s['names']); ?><div class="text-muted"><?= br_e($s['role'] . ($s['department'] ? ' · ' . $s['department'] : '')); ?></div></td>
                    <td><span class="badge bg-<?= $s['kind'] === 'primary' ? 'primary' : ($s['kind'] === 'temporary' ? 'warning text-dark' : 'info'); ?>"><?= br_e(ucfirst($s['kind'])); ?></span></td>
                    <td class="text-muted">since <?= br_e($s['start_date']); ?><?= $s['end_date'] ? ' until ' . br_e($s['end_date']) : ''; ?></td></tr><?php } ?>
                <?php if (!$staff) { ?><tr><td class="text-muted">No employees assigned.</td></tr><?php } ?>
            </table></div>
        </div></div>
        <div class="card border-0 shadow-sm mb-3" id="departments"><div class="card-body">
            <h6>Departments (<?= count($deps); ?>)</h6>
            <table class="table table-sm small mb-2"><?php foreach ($deps as $d) { ?><tr><td><?= br_e($d['name']); ?></td><td class="text-end"><?= (int) $d['people']; ?> people</td></tr><?php } ?>
                <?php if (!$deps) { ?><tr><td class="text-muted">No departments assigned.</td></tr><?php } ?></table>
            <?php if ($ho && $otherDeps) { ?><form method="POST" class="d-flex gap-2"><?= br_csrf_field(); ?><input type="hidden" name="do" value="department"><input type="hidden" name="id" value="<?= $id; ?>">
                <select name="department_id" class="form-select form-select-sm"><?php foreach ($otherDeps as $d) { ?><option value="<?= (int) $d['id']; ?>"><?= br_e($d['name'] . ($d['bname'] ? ' (now: ' . $d['bname'] . ')' : '')); ?></option><?php } ?></select>
                <button class="btn btn-sm btn-outline-primary text-nowrap">Assign to this branch</button></form><?php } ?>
        </div></div>
        <div class="card border-0 shadow-sm mb-3" id="assets"><div class="card-body">
            <h6>Assigned assets (<?= count($assets); ?>)</h6>
            <div class="table-responsive" style="max-height:220px;"><table class="table table-sm small mb-0">
                <?php foreach ($assets as $a) { ?><tr><td><?= br_e($a['asset_code']); ?></td><td><?= br_e($a['asset_name']); ?></td><td><?= br_e($a['asset_type']); ?></td><td class="text-end"><?= number_format((float) $a['current_value']); ?></td><td><?= br_e($a['status']); ?></td></tr><?php } ?>
                <?php if (!$assets) { ?><tr><td class="text-muted">No assets recorded at this branch.</td></tr><?php } ?>
            </table></div>
        </div></div>
    </div>
    <div class="col-lg-6">
        <?php if ($ho) { ?>
        <form method="POST" class="card border-0 shadow-sm mb-3" id="status"><div class="card-body"><?= br_csrf_field(); ?><input type="hidden" name="do" value="status"><input type="hidden" name="id" value="<?= $id; ?>">
            <h6>Status management</h6>
            <div class="row g-2">
                <div class="col-md-4"><select name="status" class="form-select form-select-sm"><?php foreach (BR_STATUSES as $s) { if ($s === 'Closed' && $b['is_main']) { continue; } ?><option value="<?= $s; ?>" <?= $b['status'] === $s ? 'selected' : ''; ?>><?= $s === 'Suspended' ? 'Temporarily Suspended' : $s; ?></option><?php } ?></select></div>
                <div class="col-md-8"><input name="reason" class="form-control form-control-sm" placeholder="Reason (required)" required maxlength="500"></div>
            </div>
            <button class="btn btn-sm btn-outline-danger mt-2" onclick="return confirm('Change the status of this branch?');">Change status</button>
            <div class="small text-muted mt-2"><strong>Suspended</strong>: new operations are blocked as set in Settings; history stays. <strong>Closed</strong>: no new operations at all; employees, finances, stock history and reports are kept.</div>
        </div></form>
        <?php } ?>
        <div class="card border-0 shadow-sm mb-3" id="locations"><div class="card-body">
            <h6>Operational locations (<?= count($locs); ?>)</h6>
            <table class="table table-sm small mb-2"><?php foreach ($locs as $l) { ?><tr><td><?= br_e($l['code']); ?></td><td><?= br_e($l['name']); ?></td><td><?= br_e($l['kind']); ?></td><td class="text-muted"><?= br_e($l['address']); ?></td></tr><?php } ?>
                <?php if (!$locs) { ?><tr><td class="text-muted">None yet (shop floor, store, office ...). Used later for EBM device mapping.</td></tr><?php } ?></table>
            <?php if ($ho) { ?><form method="POST" class="row g-1"><?= br_csrf_field(); ?><input type="hidden" name="do" value="location"><input type="hidden" name="id" value="<?= $id; ?>">
                <div class="col-4"><input name="name" class="form-control form-control-sm" placeholder="Name" required></div>
                <div class="col-3"><select name="kind" class="form-select form-select-sm"><?php foreach (['Shop floor', 'Store', 'Office', 'Warehouse', 'Service point'] as $k) { ?><option><?= $k; ?></option><?php } ?></select></div>
                <div class="col-3"><input name="address" class="form-control form-control-sm" placeholder="Address"></div>
                <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div></form><?php } ?>
        </div></div>
        <div class="card border-0 shadow-sm mb-3" id="registers"><div class="card-body">
            <h6>Cash registers (<?= count($regs); ?>)</h6>
            <table class="table table-sm small mb-2"><?php foreach ($regs as $r) { ?><tr><td><?= br_e($r['code']); ?></td><td><?= br_e($r['name']); ?></td><td><?= br_e($r['lname'] ?: '—'); ?></td><td><?= br_e($users[(int) $r['cashier_user_id']]['names'] ?? '—'); ?></td></tr><?php } ?>
                <?php if (!$regs) { ?><tr><td class="text-muted">No cash registers yet.</td></tr><?php } ?></table>
            <?php if ($ho) { ?><form method="POST" class="row g-1"><?= br_csrf_field(); ?><input type="hidden" name="do" value="register"><input type="hidden" name="id" value="<?= $id; ?>">
                <div class="col-4"><input name="name" class="form-control form-control-sm" placeholder="Name, e.g. Till 1" required></div>
                <div class="col-3"><select name="location_id" class="form-select form-select-sm"><option value="">Location</option><?php foreach ($locs as $l) { ?><option value="<?= (int) $l['id']; ?>"><?= br_e($l['name']); ?></option><?php } ?></select></div>
                <div class="col-3"><select name="cashier_user_id" class="form-select form-select-sm"><option value="">Cashier</option><?php foreach ($staff as $s) { ?><option value="<?= (int) $s['user_id']; ?>"><?= br_e($s['names']); ?></option><?php } ?></select></div>
                <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Add</button></div></form><?php } ?>
        </div></div>
        <div class="card border-0 shadow-sm mb-3"><div class="card-body">
            <h6>Products, services and stock</h6>
            <div class="small">The product catalogue is shared by all branches (<?= number_format($products); ?> active products and services, managed centrally in <a href="../products/index.php">Products</a>).
                Stock value held here: <strong><?= br_e($money($month['stock_value'])); ?></strong>.</div>
        </div></div>
        <div class="card border-0 shadow-sm"><div class="card-body">
            <h6>Activity history</h6>
            <div style="max-height:320px; overflow:auto;">
            <?php foreach ($activity as $a) { ?><div class="small border-top py-1"><span class="text-muted"><?= date('d M Y H:i', strtotime($a['created_at'])); ?> · <?= br_e($a['names'] ?: 'System'); ?> · <?= br_e($a['action']); ?></span><br><?= br_e($a['details']); ?></div><?php } ?>
            <?php if (!$activity) { ?><div class="small text-muted">No activity yet.</div><?php } ?>
            </div>
        </div></div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>