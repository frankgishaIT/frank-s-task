<?php
/**
 * NEW FILE: modules/market/gaps.php — CAPABILITY GAPS across RISE MOTIVE, grouped by skill: the services affected, the
 * employees who need the skill (training), the gap type (skill, availability, department, equipment, software,
 * organisation-wide), customer requests, first and latest occurrence, the recommendation and matching RM Learning
 * Centre courses (RM SkillBank).
 */
require __DIR__ . '/common.php';
mi_require($conn, 'research');
$ft = isset(MI_GAP_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$fs = in_array($_GET['status'] ?? '', ['Open', 'Addressed', 'Closed', 'all'], true) ? $_GET['status'] : 'Open';
$fd = (int) ($_GET['dept'] ?? 0);
$w = ['1 = 1']; if ($fs !== 'all') { $w[] = "g.status = '$fs'"; } if ($ft) { $w[] = "g.gap_type = '$ft'"; } if ($fd) { $w[] = 'g.department_id = ' . $fd; }
$gaps = mysqli_fetch_all(mysqli_query($conn, 'SELECT g.*, o.name oname FROM mi_gaps g LEFT JOIN mi_opportunities o ON o.id = g.opportunity_id WHERE ' . implode(' AND ', $w) . ' ORDER BY g.id DESC'), MYSQLI_ASSOC);
$depts = []; try { $depts = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC), 'name', 'id'); } catch (Throwable $e) { }
$groups = [];
foreach ($gaps as $g) {
    $k = $g['skill_key']; $G = &$groups[$k];
    if ($G === null) { $G = ['skill' => $g['skill'], 'gaps' => [], 'types' => [], 'services' => [], 'emps' => [], 'depts' => [], 'first' => $g['created_at'], 'last' => $g['created_at'], 'requests' => 0, 'recs' => [], 'equip' => []]; }
    $G['gaps'][] = $g; $G['types'][$g['gap_type']] = true; if ($g['oname']) { $G['services'][(int) $g['opportunity_id']] = $g['oname']; }
    if ($g['department_id'] && isset($depts[$g['department_id']])) { $G['depts'][$depts[$g['department_id']]] = true; }
    if ($g['equipment']) { $G['equip'][$g['equipment']] = true; } if ($g['software']) { $G['equip'][$g['software']] = true; }
    $G['first'] = min($G['first'], $g['created_at']); $G['last'] = max($G['last'], $g['created_at']); $G['recs'][$g['recommendation']] = ($G['recs'][$g['recommendation']] ?? 0) + 1;
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT u.id, u.names, ge.current_level FROM mi_gap_employees ge JOIN users u ON u.id = ge.user_id WHERE ge.gap_id = ' . (int) $g['id']), MYSQLI_ASSOC) as $e) { $G['emps'][(int) $e['id']] = $e; }
    unset($G);
}
foreach ($groups as &$G) { if ($G['services']) { $G['requests'] = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM mi_requests WHERE opportunity_id IN (' . implode(',', array_keys($G['services'])) . ')'))[0]; } $G['courses'] = mi_courses_for($conn, $G['skill']); arsort($G['recs']); } unset($G);
$tot = ['emps' => [], 'types' => []]; foreach ($gaps as $g) { $tot['types'][$g['gap_type']] = ($tot['types'][$g['gap_type']] ?? 0) + 1; } foreach ($groups as $G) { $tot['emps'] += $G['emps']; }
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'gaps.php', 'Capability gaps', '<a href="capability.php?new=1" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Record an internal skill need</a>');
?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm mi-k"><div class="card-body py-2"><div class="lbl">Skills with gaps</div><div class="val"><?= count($groups); ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm mi-k"><div class="card-body py-2"><div class="lbl">Employees needing training</div><div class="val"><?= count($tot['emps']); ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm mi-k"><div class="card-body py-2"><div class="lbl">Missing equipment / software</div><div class="val"><?= (int) ($tot['types']['equipment'] ?? 0) + (int) ($tot['types']['software'] ?? 0); ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm mi-k"><div class="card-body py-2"><div class="lbl">Organisation-wide gaps</div><div class="val"><?= (int) ($tot['types']['organization'] ?? 0); ?></div></div></div></div>
</div>
<form class="d-flex flex-wrap gap-1 mb-3 small"><select name="status" class="form-select form-select-sm" style="width:auto"><?php foreach (['Open' => 'Open', 'Addressed' => 'Addressed', 'Closed' => 'Closed', 'all' => 'All'] as $k => $l) { ?><option value="<?= $k; ?>" <?= $fs === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select>
    <select name="type" class="form-select form-select-sm" style="width:auto"><option value="">Any gap type</option><?php foreach (MI_GAP_TYPES as $k => $l) { ?><option value="<?= $k; ?>" <?= $ft === $k ? 'selected' : ''; ?>><?= mi_e($l); ?></option><?php } ?></select>
    <select name="dept" class="form-select form-select-sm" style="width:auto"><option value="">Any department</option><?php foreach ($depts as $k => $l) { ?><option value="<?= (int) $k; ?>" <?= $fd === (int) $k ? 'selected' : ''; ?>><?= mi_e($l); ?></option><?php } ?></select><button class="btn btn-sm btn-primary">Filter</button></form>
<?php foreach ($groups as $G) { ?>
<div class="card border-0 shadow-sm mb-2"><div class="card-body small">
    <div class="d-flex justify-content-between flex-wrap gap-2"><div><span class="fw-semibold fs-6"><?= mi_e($G['skill']); ?></span> <span class="text-muted">· <?= count($G['gaps']); ?> assessment(s) · first <?= date('d M Y', strtotime($G['first'])); ?>, latest <?= date('d M Y', strtotime($G['last'])); ?></span></div>
        <div><?php foreach (array_keys($G['recs']) as $r) { echo mi_rec_badge((string) $r) . ' '; } ?></div></div>
    <div class="mt-1"><?= mi_e(implode(' · ', array_map(function ($t) { return MI_GAP_TYPES[$t] ?? $t; }, array_keys($G['types'])))); ?></div>
    <?php if ($G['services']) { ?><div>Services: <?php foreach ($G['services'] as $oid => $n) { ?><a href="capability.php?id=<?= (int) $oid; ?>"><?= mi_e($n); ?></a> <?php } ?><span class="text-muted">· <?= (int) $G['requests']; ?> customer request(s)</span></div><?php } ?>
    <?php if ($G['emps']) { ?><div>Employees (<?= count($G['emps']); ?>): <?= mi_e(implode(', ', array_map(function ($e) { return $e['names'] . ($e['current_level'] ? ' (' . MI_PROFICIENCY[(int) $e['current_level']] . ')' : ''); }, $G['emps']))); ?></div><?php } ?>
    <?php if ($G['depts']) { ?><div>Departments: <?= mi_e(implode(', ', array_keys($G['depts']))); ?></div><?php } ?>
    <?php if ($G['equip']) { ?><div>Missing: <?= mi_e(implode(', ', array_keys($G['equip']))); ?></div><?php } ?>
    <?php if (array_intersect(array_keys($G['types']), ['individual', 'organization', 'none']) || isset($G['recs']['Train existing employees'])) { ?><div class="text-muted">RM Learning Centre: <?= $G['courses'] ? mi_e(implode(', ', array_map(function ($c) { return $c['title'] . ' (' . $c['status'] . ')'; }, $G['courses']))) : 'no matching course yet — a new course or external training is needed'; ?></div><?php } ?>
    <details class="mt-1"><summary class="text-primary">Assessments</summary><?php foreach ($G['gaps'] as $g) { ?><div class="border-top pt-1 mt-1"><strong>#<?= (int) $g['id']; ?></strong> <?= mi_e(MI_GAP_TYPES[$g['gap_type']] ?? ''); ?> → <?= mi_e($g['recommendation']); ?> · <?= mi_e($g['status']); ?><div class="text-muted" style="white-space:pre-line"><?= mi_e($g['why']); ?></div></div><?php } ?></details>
</div></div>
<?php } ?>
<?php if (!$groups) { ?><div class="text-center text-muted small py-5">No capability gap<?= $fs !== 'all' ? ' with this status' : ''; ?>. Gaps come from service requests (Capability page of a service) or internal skill needs.</div><?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>