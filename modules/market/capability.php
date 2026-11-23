<?php
/**
 * NEW FILE: modules/market/capability.php — EMPLOYEE CAPABILITY ASSESSMENT.
 *   ?id=…   for a requested service (what stopped us from delivering it)
 *   ?new=1  an internal skill need not linked to a request (e.g. three employees need Advanced Excel)
 * Before declaring an organisational gap, MY MOTIVE checks the Employee skills register (other qualified colleagues and
 * their availability: approved leave, open tasks), the department, equipment, software and RM Offerings. The person
 * who saves the assessment is recorded as the one who confirmed it.
 */
require __DIR__ . '/common.php';
mi_require($conn, 'research');
$standalone = !empty($_GET['new']) || !empty($_POST['standalone']);
if ($standalone) { $o = ['id' => 0, 'kind' => 'service', 'name' => 'Internal skill need', 'product_id' => null, 'status' => '']; $id = 0; $self = 'capability.php?new=1'; }
else { $o = mi_load_opp($conn, 'capability.php'); $id = (int) $o['id']; $self = 'capability.php?id=' . $id; if ($o['kind'] !== 'service') { header('Location: quotes.php?id=' . $id); exit; } }
$users = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names, department_id FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC);
$depts = []; try { $depts = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC), 'name', 'id'); } catch (Throwable $e) { }
$in = null; $res = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mi_csrf_ok()) { mi_go($self, 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'close') {
        $gid = (int) $_POST['gap_id']; $st = ($_POST['status'] ?? '') === 'Addressed' ? 'Addressed' : 'Closed'; $now = date('Y-m-d H:i:s');
        mysqli_query($conn, "UPDATE mi_gaps SET status = '$st', updated_at = '$now' WHERE id = $gid");
        mi_audit($conn, $id ?: null, null, 'gap_' . strtolower($st), 'Capability gap #' . $gid . ' marked ' . $st);
        mi_go($self, 'success', 'Capability gap #' . $gid . ': ' . $st . '.');
    }
    $in = ['skill' => mb_substr(trim((string) ($_POST['skill'] ?? '')), 0, 120), 'level' => (int) ($_POST['level'] ?? 2), 'department_id' => (int) ($_POST['department_id'] ?? 0) ?: null,
           'employees' => array_values(array_filter(array_map('intval', (array) ($_POST['employees'] ?? [])))), 'equipment_missing' => !empty($_POST['equipment_missing']), 'equipment' => mb_substr(trim((string) ($_POST['equipment'] ?? '')), 0, 160),
           'software_missing' => !empty($_POST['software_missing']), 'software' => mb_substr(trim((string) ($_POST['software'] ?? '')), 0, 160), 'dept_insufficient' => !empty($_POST['dept_insufficient']),
           'can_learn' => in_array($_POST['can_learn'] ?? '', ['Yes', 'No', 'Not sure'], true) ? $_POST['can_learn'] : 'Not sure', 'partner' => in_array($_POST['partner'] ?? '', ['Yes', 'No', 'Not sure'], true) ? $_POST['partner'] : 'Not sure',
           'within_days' => max(0, min(365, (int) ($_POST['within_days'] ?? 7))), 'notes' => mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000)];
    if ($in['skill'] === '') { $err = 'Name the skill or competency needed.'; }
    elseif ($standalone && !$in['employees']) { $err = 'Choose the employees who need this skill.'; }
    else {
        $res = mi_assess_capability($conn, $o, $in);
        if ($do === 'save') {
            $checks = json_encode(['input' => $in, 'qualified' => array_values(array_map(function ($s) { return ['user_id' => (int) $s['user_id'], 'names' => $s['names'], 'level' => (int) $s['proficiency'], 'meets' => $s['meets'], 'available' => $s['available'], 'on_leave' => $s['on_leave'], 'open_tasks' => $s['open_tasks']]; }, $res['qualified'])),
                'courses' => array_column($res['courses'], 'title'), 'alternatives' => $res['alternatives']]);
            $u = (int) current_user_id(); $now = date('Y-m-d H:i:s'); $why = implode("\n", $res['why']); $key = mi_key($in['skill']); $oid = $id ?: null;
            $s = mysqli_prepare($conn, 'INSERT INTO mi_gaps (opportunity_id, skill, skill_key, gap_type, required_level, department_id, equipment, software, checks, recommendation, why, status, confirmed_by, confirmed_at, created_by, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $status = $res['gap_type'] === 'none' ? 'Closed' : 'Open';
            mysqli_stmt_bind_param($s, 'isssiissssssisis', $oid, $in['skill'], $key, $res['gap_type'], $in['level'], $in['department_id'], $in['equipment'], $in['software'], $checks, $res['recommendation'], $why, $status, $u, $now, $u, $now);
            mysqli_stmt_execute($s); $gid = (int) mysqli_insert_id($conn);
            $cur = []; foreach ($res['qualified'] as $q) { $cur[(int) $q['user_id']] = (int) $q['proficiency']; }
            foreach ($in['employees'] as $eid) { $lv = $cur[$eid] ?? null; $s2 = mysqli_prepare($conn, 'INSERT INTO mi_gap_employees (gap_id, user_id, current_level) VALUES (?, ?, ?)'); mysqli_stmt_bind_param($s2, 'iii', $gid, $eid, $lv); mysqli_stmt_execute($s2); }
            if ($id) { mysqli_query($conn, "UPDATE mi_opportunities SET status = IF(status = 'New', 'Under Review', status) WHERE id = $id"); }
            mi_audit($conn, $id ?: null, null, 'capability', 'Capability assessment #' . $gid . ' (' . $in['skill'] . '): ' . MI_GAP_TYPES[$res['gap_type']] . ' → ' . $res['recommendation']);
            mi_go($standalone ? 'gaps.php' : $self, 'success', 'Assessment saved: ' . MI_GAP_TYPES[$res['gap_type']] . ' — ' . $res['recommendation'] . '.');
        }
    }
}
$gaps = $id ? mysqli_fetch_all(mysqli_query($conn, "SELECT g.*, u.names cname FROM mi_gaps g LEFT JOIN users u ON u.id = g.confirmed_by WHERE g.opportunity_id = $id ORDER BY g.id DESC"), MYSQLI_ASSOC) : [];
$reasons = [];
if ($id) { foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT reason, COUNT(*) n, GROUP_CONCAT(DISTINCT skills_required) sk, GROUP_CONCAT(DISTINCT equipment_required) eq, GROUP_CONCAT(DISTINCT recorded_by) who FROM mi_requests WHERE opportunity_id = $id GROUP BY reason"), MYSQLI_ASSOC) as $r) { $reasons[] = $r; } }
$pre = $in ?? ['skill' => '', 'level' => 2, 'department_id' => null, 'employees' => [], 'equipment_missing' => false, 'equipment' => '', 'software_missing' => false, 'software' => '', 'dept_insufficient' => false, 'can_learn' => 'Not sure', 'partner' => 'Not sure', 'within_days' => 7, 'notes' => ''];
if (!$in && $reasons) { // pre-fill from what employees recorded on the requests
    foreach ($reasons as $r) {
        if (!$pre['skill'] && $r['sk']) { $pre['skill'] = trim(explode(',', $r['sk'])[0]); }
        if ($r['reason'] === 'Required equipment unavailable') { $pre['equipment_missing'] = true; $pre['equipment'] = $pre['equipment'] ?: trim(explode(',', (string) $r['eq'])[0]); }
        if ($r['reason'] === 'Required software or technology unavailable') { $pre['software_missing'] = true; }
        if (in_array($r['reason'], ['Lack of technical skills', 'Insufficient employee proficiency'], true)) { $pre['employees'] = array_values(array_unique(array_merge($pre['employees'], array_map('intval', explode(',', (string) $r['who']))))); }
        if ($r['reason'] === 'Insufficient human resources') { $pre['dept_insufficient'] = true; }
    }
    if (!$pre['skill']) { $pre['skill'] = $o['name']; }
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, $standalone ? 'gaps.php' : 'opportunities.php', $standalone ? 'Record an internal skill need' : $o['name']);
if (!$standalone) { mi_opp_nav($conn, $o, 'capability.php'); }
?>
<?php if (!empty($err)) { ?><div class="alert alert-danger py-2 small"><?= mi_e($err); ?></div><?php } ?>
<div class="row g-3">
<div class="col-xl-6">
    <?php if ($reasons) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Why employees could not deliver it</div>
        <?php foreach ($reasons as $r) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= mi_e($r['reason'] ?: 'No reason given'); ?><?= $r['sk'] ? '<div class="text-muted">Skills: ' . mi_e($r['sk']) . '</div>' : ''; ?><?= $r['eq'] ? '<div class="text-muted">Equipment: ' . mi_e($r['eq']) . '</div>' : ''; ?></span><strong><?= (int) $r['n']; ?></strong></div><?php } ?></div></div><?php } ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body small row g-2"><?= mi_csrf_field(); ?><?php if ($standalone) { ?><input type="hidden" name="standalone" value="1"><?php } else { ?><input type="hidden" name="id" value="<?= $id; ?>"><?php } ?>
        <div class="col-12 fw-semibold">Capability assessment</div>
        <div class="col-md-8"><label class="form-label">Skill or competency needed</label><input name="skill" class="form-control form-control-sm" maxlength="120" value="<?= mi_e($pre['skill']); ?>" required placeholder="e.g. Graphic design, Advanced Excel"></div>
        <div class="col-md-4"><label class="form-label">Required level</label><select name="level" class="form-select form-select-sm"><?php foreach (MI_PROFICIENCY as $k => $l) { ?><option value="<?= $k; ?>" <?= (int) $pre['level'] === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
        <div class="col-md-6"><label class="form-label">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($depts as $k => $l) { ?><option value="<?= (int) $k; ?>" <?= (int) $pre['department_id'] === (int) $k ? 'selected' : ''; ?>><?= mi_e($l); ?></option><?php } ?></select></div>
        <div class="col-md-6"><label class="form-label">Needed within (days)</label><input type="number" name="within_days" min="0" max="365" class="form-control form-control-sm" value="<?= (int) $pre['within_days']; ?>"></div>
        <div class="col-12"><label class="form-label"><?= $standalone ? 'Employees who need this skill' : 'Employee(s) who could not deliver it'; ?></label><select name="employees[]" multiple size="5" class="form-select form-select-sm"><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= in_array((int) $u['id'], $pre['employees'], true) ? 'selected' : ''; ?>><?= mi_e($u['names']); ?><?= $u['department_id'] && isset($depts[$u['department_id']]) ? ' — ' . mi_e($depts[$u['department_id']]) : ''; ?></option><?php } ?></select><div class="text-muted">Ctrl / long-press to choose several.</div></div>
        <div class="col-md-6"><label><input type="checkbox" name="equipment_missing" value="1" class="form-check-input me-1" <?= $pre['equipment_missing'] ? 'checked' : ''; ?>>Required equipment is missing</label><input name="equipment" class="form-control form-control-sm mt-1" maxlength="160" value="<?= mi_e($pre['equipment']); ?>" placeholder="Which equipment?"></div>
        <div class="col-md-6"><label><input type="checkbox" name="software_missing" value="1" class="form-check-input me-1" <?= $pre['software_missing'] ? 'checked' : ''; ?>>Required software / licence is missing</label><input name="software" class="form-control form-control-sm mt-1" maxlength="160" value="<?= mi_e($pre['software']); ?>" placeholder="Which software?"></div>
        <div class="col-12"><label><input type="checkbox" name="dept_insufficient" value="1" class="form-check-input me-1" <?= $pre['dept_insufficient'] ? 'checked' : ''; ?>>The department does not have enough people or resources</label></div>
        <div class="col-md-6"><label class="form-label">Can existing employees learn it in time?</label><select name="can_learn" class="form-select form-select-sm"><?php foreach (['Not sure', 'Yes', 'No'] as $x) { ?><option <?= $pre['can_learn'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
        <div class="col-md-6"><label class="form-label">Can an external partner deliver it?</label><select name="partner" class="form-select form-select-sm"><?php foreach (['Not sure', 'Yes', 'No'] as $x) { ?><option <?= $pre['partner'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
        <div class="col-12 d-flex gap-2"><button name="do" value="check" class="btn btn-outline-primary btn-sm">Check</button><button name="do" value="save" class="btn btn-primary btn-sm">Confirm and save</button></div>
        <div class="col-12 text-muted">Skills come from <a href="skills.php">Employee skills</a>; availability from approved leave and open tasks.<?= $standalone ? '' : ' Costs for the comparison come from <a href="feasibility.php?id=' . $id . '">Feasibility</a>.'; ?></div>
    </div></form>
</div>
<div class="col-xl-6">
    <?php if ($res) { ?><div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #1E2FE0 !important"><div class="card-body small">
        <div class="text-muted">Result (not saved yet)</div>
        <div class="fw-semibold fs-6"><?= mi_e(MI_GAP_TYPES[$res['gap_type']]); ?></div><div class="my-1"><?= mi_rec_badge($res['recommendation']); ?></div>
        <ul class="ps-3 mb-2"><?php foreach ($res['why'] as $w) { ?><li><?= mi_e($w); ?></li><?php } ?></ul>
        <?php if ($res['qualified']) { ?><div class="fw-semibold">Employees with this skill</div><?php foreach ($res['qualified'] as $q) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= mi_e($q['names']); ?> · <?= MI_PROFICIENCY[(int) $q['proficiency']]; ?><?= $q['meets'] ? '' : ' <span class="text-muted">(below required)</span>'; ?></span><span><?= $q['on_leave'] ? '<span class="text-danger">on leave</span>' : ($q['available'] ? '<span class="text-success">available</span>' : '<span class="text-warning">busy: ' . (int) $q['open_tasks'] . ' tasks</span>'); ?></span></div><?php } } ?>
        <?php if ($res['alternatives']) { ?><div class="fw-semibold mt-2">Options compared over 12 months <span class="text-muted fw-normal">(estimates)</span></div><table class="table table-sm mb-0"><tr><th>Option</th><th class="text-end">Cost</th><th class="text-end">Gross profit</th></tr><?php foreach ($res['alternatives'] as $a) { ?><tr><td><?= mi_e($a['label']); ?></td><td class="text-end"><?= mi_money($a['cost12']); ?></td><td class="text-end"><?= $a['gp12'] === null ? '—' : mi_money($a['gp12']); ?></td></tr><?php } ?></table><?php } ?>
    </div></div><?php } ?>
    <?php foreach ($gaps as $g) { $emps = mysqli_fetch_all(mysqli_query($conn, 'SELECT u.names FROM mi_gap_employees ge JOIN users u ON u.id = ge.user_id WHERE ge.gap_id = ' . (int) $g['id']), MYSQLI_ASSOC); ?>
    <div class="card border-0 shadow-sm mb-2"><div class="card-body small">
        <div class="d-flex justify-content-between"><strong>#<?= (int) $g['id']; ?> <?= mi_e($g['skill']); ?> (<?= MI_PROFICIENCY[(int) $g['required_level']] ?? ''; ?>)</strong><span class="badge bg-<?= $g['status'] === 'Open' ? 'warning text-dark' : 'secondary'; ?>"><?= mi_e($g['status']); ?></span></div>
        <div><?= mi_e(MI_GAP_TYPES[$g['gap_type']] ?? $g['gap_type']); ?> → <?= mi_rec_badge((string) $g['recommendation']); ?></div>
        <?php if ($emps) { ?><div class="text-muted">Employees: <?= mi_e(implode(', ', array_column($emps, 'names'))); ?></div><?php } ?>
        <div class="text-muted" style="white-space:pre-line"><?= mi_e($g['why']); ?></div>
        <div class="text-muted mt-1">Confirmed by <?= mi_e($g['cname']); ?> · <?= mi_e(substr((string) $g['confirmed_at'], 0, 16)); ?></div>
        <?php if ($g['status'] === 'Open') { ?><form method="POST" class="mt-1 d-flex gap-1"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="close"><input type="hidden" name="gap_id" value="<?= (int) $g['id']; ?>"><button name="status" value="Addressed" class="btn btn-sm btn-light border py-0">Mark addressed</button><button name="status" value="Closed" class="btn btn-sm btn-light border py-0">Close</button></form><?php } ?>
    </div></div><?php } ?>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>