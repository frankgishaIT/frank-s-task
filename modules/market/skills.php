<?php
/**
 * NEW FILE: modules/market/skills.php — EMPLOYEE SKILLS REGISTER used by the capability assessment: which employee has
 * which skill and at what level (Basic, Intermediate, Advanced, Expert). Managers keep it up to date; every change is
 * in the history. Uses the existing employees (users) — no duplicate staff records.
 */
require __DIR__ . '/common.php';
mi_require($conn, 'research');
$f = trim((string) ($_GET['q'] ?? ''));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mi_csrf_ok()) { mi_go('skills.php', 'error', 'The page had expired.'); }
    $u = (int) current_user_id(); $now = date('Y-m-d H:i:s');
    if (($_POST['do'] ?? '') === 'remove') {
        $sid = (int) $_POST['skill_id']; $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT s.skill, u.names FROM mi_skills s JOIN users u ON u.id = s.user_id WHERE s.id = $sid"));
        mysqli_query($conn, "DELETE FROM mi_skills WHERE id = $sid");
        if ($r) { mi_audit($conn, null, null, 'skill_removed', $r['names'] . ': ' . $r['skill'] . ' removed'); }
        mi_go('skills.php', 'success', 'Removed.');
    }
    $uids = array_values(array_filter(array_map('intval', (array) ($_POST['user_ids'] ?? []))));
    $skill = mb_substr(trim((string) ($_POST['skill'] ?? '')), 0, 120); $lv = max(1, min(4, (int) ($_POST['level'] ?? 2)));
    if (!$uids || $skill === '') { mi_go('skills.php', 'error', 'Choose the employee(s) and write the skill.'); }
    // keep the spelling already used for the same skill (no "Excel" / "excel" / "MS Excel" variants)
    $key = mi_key($skill);
    $same = mysqli_fetch_assoc(mysqli_query($conn, "SELECT skill, skill_key FROM mi_skills WHERE skill_key = '" . mysqli_real_escape_string($conn, $key) . "' LIMIT 1"));
    if ($same) { $skill = $same['skill']; }
    foreach ($uids as $uid) {
        mysqli_query($conn, "DELETE FROM mi_skills WHERE user_id = $uid AND skill_key = '" . mysqli_real_escape_string($conn, $key) . "'");
        $s = mysqli_prepare($conn, 'INSERT INTO mi_skills (user_id, skill, skill_key, proficiency, verified_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'issiis', $uid, $skill, $key, $lv, $u, $now); mysqli_stmt_execute($s);
    }
    $names = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT names FROM users WHERE id IN (' . implode(',', $uids) . ')'), MYSQLI_ASSOC), 'names');
    mi_audit($conn, null, null, 'skill', implode(', ', $names) . ': ' . $skill . ' (' . MI_PROFICIENCY[$lv] . ')');
    mi_go('skills.php', 'success', 'Saved: ' . $skill . ' (' . MI_PROFICIENCY[$lv] . ') for ' . count($uids) . ' employee(s).');
}
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT s.*, u.names, u.is_active FROM mi_skills s JOIN users u ON u.id = s.user_id ORDER BY s.skill, s.proficiency DESC, u.names'), MYSQLI_ASSOC);
if ($f !== '') { $k = mi_key($f); $rows = array_values(array_filter($rows, function ($r) use ($k, $f) { return mi_similarity($k, $r['skill_key']) >= 60 || stripos($r['names'], $f) !== false; })); }
$by = []; foreach ($rows as $r) { $by[$r['skill']][] = $r; }
$users = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC);
$known = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT skill FROM mi_skills ORDER BY skill'), MYSQLI_ASSOC), 'skill');
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'skills.php', 'Employee skills');
?>
<div class="row g-3">
<div class="col-lg-4"><form method="POST" class="card border-0 shadow-sm"><div class="card-body small"><?= mi_csrf_field(); ?>
    <div class="fw-semibold mb-2">Add or update a skill</div>
    <label class="form-label">Employee(s)</label><select name="user_ids[]" multiple size="6" class="form-select form-select-sm mb-2" required><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>"><?= mi_e($u['names']); ?></option><?php } ?></select>
    <label class="form-label">Skill</label><input name="skill" list="miSkillList" class="form-control form-control-sm mb-2" maxlength="120" required placeholder="e.g. Graphic design"><datalist id="miSkillList"><?php foreach ($known as $k) { ?><option value="<?= mi_e($k); ?>"><?php } ?></datalist>
    <label class="form-label">Level</label><select name="level" class="form-select form-select-sm mb-2"><?php foreach (MI_PROFICIENCY as $k => $l) { ?><option value="<?= $k; ?>" <?= $k === 2 ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select>
    <button class="btn btn-primary btn-sm">Save</button>
    <div class="text-muted mt-2">The capability assessment uses this list to find colleagues who can deliver a service before concluding that RISE MOTIVE lacks a skill.</div>
</div></form></div>
<div class="col-lg-8">
    <form class="mb-2"><input name="q" value="<?= mi_e($f); ?>" class="form-control form-control-sm" placeholder="Search a skill or an employee"></form>
    <?php foreach ($by as $skill => $list) { ?><div class="card border-0 shadow-sm mb-2"><div class="card-body py-2 small"><div class="fw-semibold"><?= mi_e($skill); ?> <span class="text-muted fw-normal">· <?= count($list); ?> employee(s)</span></div>
        <div class="d-flex flex-wrap gap-1 mt-1"><?php foreach ($list as $r) { ?><span class="border rounded-pill px-2 py-1 <?= (int) $r['is_active'] ? '' : 'text-muted'; ?>"><?= mi_e($r['names']); ?> · <strong><?= MI_PROFICIENCY[(int) $r['proficiency']]; ?></strong>
            <form method="POST" class="d-inline" onsubmit="return confirm('Remove?')"><?= mi_csrf_field(); ?><input type="hidden" name="do" value="remove"><input type="hidden" name="skill_id" value="<?= (int) $r['id']; ?>"><button class="btn btn-link p-0 text-danger small" style="font-size:12px"><i class="bi bi-x"></i></button></form></span><?php } ?></div></div></div><?php } ?>
    <?php if (!$by) { ?><div class="text-muted small text-center py-5">No skill recorded yet.</div><?php } ?>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>