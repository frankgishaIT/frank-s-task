<?php
/**
 * NEW FILE: modules/learning/centres.php — TRAINING CENTRES: which branches (Branch Management) are training locations,
 * their capacity, facilities, classrooms and accommodation. Same branches, same database, same staff accounts.
 */
require __DIR__ . '/common.php';
lcs_require($conn, 'courses');
$branches = lc_training_branches($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('centres.php', 'error', 'The page had expired.'); }
    $bid = (int) ($_POST['branch_id'] ?? -1);
    if (!isset($branches[$bid]) || !lcs_branch_ok($conn, $bid ?: null)) { lcs_deny(); }
    $en = !empty($_POST['training_enabled']) ? 1 : 0; $acc = !empty($_POST['accommodation']) ? 1 : 0; $cap = (int) ($_POST['capacity'] ?? 0) ?: null;
    $fac = mb_substr(trim((string) ($_POST['facilities'] ?? '')), 0, 3000); $cls = mb_substr(trim((string) ($_POST['classrooms'] ?? '')), 0, 3000); $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 3000);
    $uid = (int) current_user_id(); $now = date('Y-m-d H:i:s');
    if (!$en) {
        $open = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_intakes WHERE branch_id = $bid AND status = 'Open'"))[0];
        if ($open) { lcs_go('centres.php', 'error', 'Close or move the ' . $open . ' open intake(s) of this branch first.'); }
    }
    $s = mysqli_prepare($conn, 'INSERT INTO lc_branch_settings (branch_id, training_enabled, capacity, facilities, classrooms, accommodation, notes, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE training_enabled = VALUES(training_enabled), capacity = VALUES(capacity), facilities = VALUES(facilities), classrooms = VALUES(classrooms),
        accommodation = VALUES(accommodation), notes = VALUES(notes), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    mysqli_stmt_bind_param($s, 'iiissisis', $bid, $en, $cap, $fac, $cls, $acc, $notes, $uid, $now);
    mysqli_stmt_execute($s);
    lc_audit($conn, 'centre_settings', 'branch', $bid, ['training_enabled' => $en, 'accommodation' => $acc, 'capacity' => $cap]);
    lcs_go('centres.php', 'success', $branches[$bid]['name'] . ' saved.');
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'centres.php', 'Training Centres');
?>
<div class="small text-muted mb-2">Branches come from Branch Management. Enable those that run training; the public website only offers branches that are enabled AND have an open intake of the course. Branch transfers of students follow the normal approved transfer process (academic stage).</div>
<div class="row g-3">
<?php foreach ($branches as $b) { if (!lcs_branch_ok($conn, $b['id'] ?: null)) { continue; } $s = $b['settings'] ?: []; ?>
<div class="col-xl-6"><form method="POST" class="card border-0 shadow-sm h-100"><div class="card-body row g-2"><?= lcs_csrf_field(); ?><input type="hidden" name="branch_id" value="<?= (int) $b['id']; ?>">
    <div class="d-flex justify-content-between"><h6 class="mb-0"><?= lc_e($b['name']); ?> <span class="small text-muted"><?= lc_e($b['district']); ?></span></h6><?= $b['enabled'] ? '<span class="badge bg-success">Training centre</span>' : '<span class="badge bg-secondary">Not a training centre</span>'; ?></div>
    <?php if ($b['status'] !== 'Active') { ?><div class="small text-danger">Branch status: <?= lc_e($b['status']); ?> — it cannot take new students while not Active.</div><?php } ?>
    <div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="training_enabled" value="1" id="t<?= (int) $b['id']; ?>" <?= !empty($s['training_enabled']) ? 'checked' : ''; ?>><label class="form-check-label small" for="t<?= (int) $b['id']; ?>">Training centre enabled</label></div></div>
    <div class="col-6"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="accommodation" value="1" id="a<?= (int) $b['id']; ?>" <?= !empty($s['accommodation']) ? 'checked' : ''; ?>><label class="form-check-label small" for="a<?= (int) $b['id']; ?>">Offers accommodation</label></div></div>
    <div class="col-4"><label class="form-label small">Student capacity</label><input type="number" min="0" name="capacity" class="form-control form-control-sm" value="<?= lc_e($s['capacity'] ?? ''); ?>"></div>
    <div class="col-8"><label class="form-label small">Classrooms (one per line, e.g. "Room A – 25 seats")</label><textarea name="classrooms" rows="2" class="form-control form-control-sm"><?= lc_e($s['classrooms'] ?? ''); ?></textarea></div>
    <div class="col-12"><label class="form-label small">Training facilities</label><textarea name="facilities" rows="2" class="form-control form-control-sm"><?= lc_e($s['facilities'] ?? ''); ?></textarea></div>
    <div class="col-12"><label class="form-label small">Notes</label><input name="notes" class="form-control form-control-sm" value="<?= lc_e($s['notes'] ?? ''); ?>"></div>
    <div class="col-12"><button class="btn btn-sm btn-primary">Save</button></div>
</div></form></div>
<?php } ?>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>