<?php
// NEW FILE: modules/kpi/kpis.php — all KPIs, and creating / editing one.
require __DIR__ . '/kpi_common.php';
kpi_guard($conn, 'kpi.view');
$canCreate = access_can('kpi.create', $conn);
$edit = null;
if (!empty($_GET['edit']) && $canCreate) { $edit = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM kpis WHERE id = ' . (int) $_GET['edit'])); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$canCreate) { throw new RuntimeException('You are not allowed to create or change KPIs.'); }
        $id = (int) ($_POST['id'] ?? 0);
        $v = [];
        $v['name'] = mb_substr(trim((string) $_POST['name']), 0, 160);
        $v['description'] = trim((string) ($_POST['description'] ?? '')) ?: null;
        $v['level'] = isset(KPI_LEVELS[$_POST['level'] ?? '']) ? $_POST['level'] : '';
        $v['department_id'] = $v['level'] === 'department' ? ((int) $_POST['department_id'] ?: null) : null;
        $v['team_id'] = $v['level'] === 'team' ? ((int) $_POST['team_id'] ?: null) : null;
        $v['user_id'] = $v['level'] === 'employee' ? ((int) $_POST['user_id'] ?: null) : null;
        $v['measure_type'] = isset(KPI_TYPES[$_POST['measure_type'] ?? '']) ? $_POST['measure_type'] : '';
        $v['direction'] = isset(KPI_DIRECTIONS[$_POST['direction'] ?? '']) ? $_POST['direction'] : 'higher';
        $v['target'] = (float) ($_POST['target'] ?? 0);
        $v['range_min'] = $v['direction'] === 'range' ? (float) $_POST['range_min'] : null;
        $v['range_max'] = $v['direction'] === 'range' ? (float) $_POST['range_max'] : null;
        $v['unit'] = mb_substr(trim((string) ($_POST['unit'] ?? '')), 0, 30) ?: null;
        $v['weight'] = max(0.1, min(100, (float) ($_POST['weight'] ?? 1)));
        $v['start_date'] = (string) ($_POST['start_date'] ?? '');
        $v['end_date'] = (string) ($_POST['end_date'] ?? '') ?: null;
        $v['frequency'] = isset(KPI_FREQUENCIES[$_POST['frequency'] ?? '']) ? $_POST['frequency'] : 'monthly';
        $v['manager_id'] = (int) ($_POST['manager_id'] ?? 0) ?: null;
        $v['data_source'] = isset(KPI_SOURCES[$_POST['data_source'] ?? '']) ? $_POST['data_source'] : 'manual';
        $v['status'] = in_array($_POST['status'] ?? '', KPI_STATUSES, true) ? $_POST['status'] : 'Active';
        if ($v['name'] === '' || $v['level'] === '' || $v['measure_type'] === '') { throw new RuntimeException('Give the KPI a name, a level and a measurement type.'); }
        if (($v['level'] === 'department' && !$v['department_id']) || ($v['level'] === 'team' && !$v['team_id']) || ($v['level'] === 'employee' && !$v['user_id'])) { throw new RuntimeException('Choose who the KPI is assigned to.'); }
        if (!DateTime::createFromFormat('Y-m-d', $v['start_date']) || ($v['end_date'] && $v['end_date'] < $v['start_date'])) { throw new RuntimeException('Check the start and end dates.'); }
        if ($v['direction'] === 'range' && $v['range_min'] > $v['range_max']) { throw new RuntimeException('The lowest value of the range must be below the highest.'); }
        if ($v['direction'] !== 'range' && $v['target'] < 0) { throw new RuntimeException('The target cannot be negative.'); }
        if ($v['direction'] === 'range') { $v['target'] = ($v['range_min'] + $v['range_max']) / 2; }
        $cols = array_keys($v); $types = 'sssiiissdddsdsssiss';
        $vals = array_values($v);
        if ($id) {
            $sql = 'UPDATE kpis SET ' . implode(', ', array_map(function ($c) { return "$c = ?"; }, $cols)) . ' WHERE id = ?';
            $s = mysqli_prepare($conn, $sql); $types .= 'i'; $vals[] = $id;
        } else {
            $sql = 'INSERT INTO kpis (' . implode(', ', $cols) . ', created_by) VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ', ?)';
            $s = mysqli_prepare($conn, $sql); $types .= 'i'; $vals[] = $me;
        }
        mysqli_stmt_bind_param($s, $types, ...$vals);
        mysqli_stmt_execute($s);
        $kid = $id ?: (int) mysqli_insert_id($conn);
        if ($v['level'] === 'employee' && $v['user_id'] && !$id) {
            require_once __DIR__ . '/../../includes/notification_helper.php';
            try { notifyUser($conn, (int) $v['user_id'], 'New KPI: ' . $v['name'], 'A new performance target was set for you. Open My Performance to see it.'); } catch (Throwable $x) {}
        }
        header('Location: kpi.php?id=' . $kid . '&success=' . urlencode($id ? 'KPI saved.' : 'KPI created.')); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); $edit = $_POST; }
}
$all = kpi_list($conn, [], false);
$deps = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC);
$teams = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM kpi_teams ORDER BY name'), MYSQLI_ASSOC);
$people = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC);
$val = function ($k, $d = '') use ($edit) { return $edit[$k] ?? $d; };
include '../../includes/header.php'; include '../../includes/sidebar.php';
kpi_tabs('kpis.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if ($canCreate) { ?>
<details class="card border-0 shadow-sm mb-3" <?= $edit ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold"><?= !empty($edit['id']) ? 'Edit KPI' : '+ New KPI'; ?></summary>
<form method="POST" class="card-body pt-0 row g-2" id="kpiForm"><input type="hidden" name="id" value="<?= (int) $val('id', 0); ?>">
    <div class="col-md-6"><label class="form-label small text-muted mb-1">KPI name *</label><input name="name" class="form-control form-control-sm" maxlength="160" required value="<?= $e($val('name')); ?>" placeholder="e.g. Monthly sales"></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">Data source</label><select name="data_source" id="srcSel" class="form-select form-select-sm">
        <?php foreach (KPI_SOURCES as $k => [$l, $u, $t, $d]) { ?><option value="<?= $k; ?>" data-unit="<?= $e($u); ?>" data-type="<?= $t; ?>" data-dir="<?= $d; ?>" <?= $val('data_source', 'manual') === $k ? 'selected' : ''; ?>><?= $e($l); ?></option><?php } ?></select></div>
    <div class="col-12"><textarea name="description" class="form-control form-control-sm" rows="2" placeholder="Description: what it measures and why it matters"><?= $e($val('description')); ?></textarea></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">Level *</label><select name="level" id="lvlSel" class="form-select form-select-sm" required><?php foreach (KPI_LEVELS as $k => $l) { ?><option value="<?= $k; ?>" <?= $val('level', 'employee') === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3 lvl lvl-department"><label class="form-label small text-muted mb-1">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">Choose</option><?php foreach ($deps as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= (string) $val('department_id') === (string) $d['id'] ? 'selected' : ''; ?>><?= $e($d['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3 lvl lvl-team"><label class="form-label small text-muted mb-1">Team</label><select name="team_id" class="form-select form-select-sm"><option value="">Choose</option><?php foreach ($teams as $t) { ?><option value="<?= (int) $t['id']; ?>" <?= (string) $val('team_id') === (string) $t['id'] ? 'selected' : ''; ?>><?= $e($t['name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3 lvl lvl-employee"><label class="form-label small text-muted mb-1">Employee</label><select name="user_id" class="form-select form-select-sm"><option value="">Choose</option><?php foreach ($people as $p) { ?><option value="<?= (int) $p['id']; ?>" <?= (string) $val('user_id') === (string) $p['id'] ? 'selected' : ''; ?>><?= $e($p['names']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">Responsible manager</label><select name="manager_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($people as $p) { ?><option value="<?= (int) $p['id']; ?>" <?= (string) $val('manager_id', $me) === (string) $p['id'] ? 'selected' : ''; ?>><?= $e($p['names']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">Measurement type *</label><select name="measure_type" id="typeSel" class="form-select form-select-sm" required><?php foreach (KPI_TYPES as $k => $l) { ?><option value="<?= $k; ?>" <?= $val('measure_type', 'financial') === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-3"><label class="form-label small text-muted mb-1">Direction</label><select name="direction" id="dirSel" class="form-select form-select-sm"><?php foreach (KPI_DIRECTIONS as $k => $l) { ?><option value="<?= $k; ?>" <?= $val('direction', 'higher') === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2 dir-single"><label class="form-label small text-muted mb-1">Target *</label><input type="number" step="0.01" min="0" name="target" class="form-control form-control-sm" value="<?= $e($val('target')); ?>"></div>
    <div class="col-6 col-md-2 dir-range"><label class="form-label small text-muted mb-1">From</label><input type="number" step="0.01" name="range_min" class="form-control form-control-sm" value="<?= $e($val('range_min')); ?>"></div>
    <div class="col-6 col-md-2 dir-range"><label class="form-label small text-muted mb-1">To</label><input type="number" step="0.01" name="range_max" class="form-control form-control-sm" value="<?= $e($val('range_max')); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Unit</label><input name="unit" id="unitIn" class="form-control form-control-sm" maxlength="30" value="<?= $e($val('unit')); ?>" placeholder="RWF, %, tasks…"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Weight</label><input type="number" step="0.1" min="0.1" max="100" name="weight" class="form-control form-control-sm" value="<?= $e($val('weight', 1)); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Frequency</label><select name="frequency" class="form-select form-select-sm"><?php foreach (KPI_FREQUENCIES as $k => $l) { ?><option value="<?= $k; ?>" <?= $val('frequency', 'monthly') === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Start *</label><input type="date" name="start_date" class="form-control form-control-sm" required value="<?= $e($val('start_date', date('Y-m-01'))); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">End</label><input type="date" name="end_date" class="form-control form-control-sm" value="<?= $e($val('end_date')); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label small text-muted mb-1">Status</label><select name="status" class="form-select form-select-sm"><?php foreach (KPI_STATUSES as $st) { ?><option <?= $val('status', 'Active') === $st ? 'selected' : ''; ?>><?= $st; ?></option><?php } ?></select></div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Save KPI</button> <span class="small text-muted ms-2">The actual value is filled in automatically when a data source is chosen; otherwise it is entered on the KPI page.</span></div>
</form></details>
<script>
(function () {
    var lvl = document.getElementById('lvlSel'), dir = document.getElementById('dirSel'), src = document.getElementById('srcSel');
    function sync() {
        document.querySelectorAll('.lvl').forEach(function (el) { el.style.display = el.classList.contains('lvl-' + lvl.value) ? '' : 'none'; });
        document.querySelectorAll('.dir-range').forEach(function (el) { el.style.display = dir.value === 'range' ? '' : 'none'; });
        document.querySelectorAll('.dir-single').forEach(function (el) { el.style.display = dir.value === 'range' ? 'none' : ''; });
    }
    src.addEventListener('change', function () { // suggest the unit, type and direction of the chosen source
        var o = src.options[src.selectedIndex];
        if (o.dataset.unit) { document.getElementById('unitIn').value = o.dataset.unit; }
        if (o.dataset.type) { document.getElementById('typeSel').value = o.dataset.type; }
        if (o.dataset.dir) { dir.value = o.dataset.dir; sync(); }
    });
    lvl.addEventListener('change', sync); dir.addEventListener('change', sync); sync();
})();
</script>
<?php } ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">KPI</th><th>For</th><th>Type / direction</th><th class="text-end">Target</th><th>Frequency</th><th>Source</th><th>Status</th><th></th></tr>
    <?php if (!$all) { ?><tr><td colspan="8" class="text-center text-muted py-4">No KPIs yet.</td></tr><?php } ?>
    <?php foreach ($all as $k) { ?>
    <tr><td class="ps-3"><a href="kpi.php?id=<?= (int) $k['id']; ?>"><?= $e($k['name']); ?></a><div class="small text-muted">weight <?= (float) $k['weight']; ?></div></td>
        <td class="small"><?= $e(KPI_LEVELS[$k['level']] . ': ' . kpi_scope_label($k)); ?></td>
        <td class="small"><?= $e(KPI_TYPES[$k['measure_type']] ?? ''); ?> · <?= $e(KPI_DIRECTIONS[$k['direction']] ?? ''); ?></td>
        <td class="text-end"><?= $k['direction'] === 'range' ? $e(kpi_fmt((float) $k['range_min'], $k['unit']) . ' – ' . kpi_fmt((float) $k['range_max'], $k['unit'])) : $e(kpi_fmt((float) $k['target'], $k['unit'])); ?></td>
        <td class="small"><?= $e(KPI_FREQUENCIES[$k['frequency']] ?? ''); ?></td><td class="small"><?= $k['data_source'] === 'manual' ? 'Manual' : 'Automatic'; ?></td>
        <td><span class="badge <?= ['Active' => 'bg-success', 'Draft' => 'bg-secondary', 'Completed' => 'bg-primary', 'Cancelled' => 'bg-dark'][$k['status']] ?? 'bg-secondary'; ?>"><?= $e($k['status']); ?></span></td>
        <td><?php if ($canCreate) { ?><a href="kpis.php?edit=<?= (int) $k['id']; ?>" class="btn btn-sm btn-outline-primary">Edit</a><?php } ?></td></tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>