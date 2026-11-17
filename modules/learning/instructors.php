<?php
/**
 * NEW FILE: modules/learning/instructors.php — INSTRUCTORS.
 * Internal instructor = an existing MY MOTIVE user (employee record reused, never duplicated).
 * External instructor = name and contacts only (no employee record). To give an external instructor a limited sign-in,
 * create a user for them in Users (any role with no other permission) and choose that user here: they then see only
 * their own classes (attendance and marks).
 */
require __DIR__ . '/common.php';
lcs_require($conn, 'academic');
$users = lcs_users($conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('instructors.php', 'error', 'The page had expired.'); }
    $id = (int) ($_POST['id'] ?? 0);
    $kind = ($_POST['kind'] ?? '') === 'external' ? 'external' : 'internal';
    $uid = (int) ($_POST['user_id'] ?? 0);
    $uid = isset($users[$uid]) ? $uid : null;
    if ($kind === 'internal' && !$uid) { lcs_go('instructors.php', 'error', 'Choose the employee (MY MOTIVE user).'); }
    $name = $kind === 'internal' ? $users[$uid] : mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 150);
    if (mb_strlen($name) < 3) { lcs_go('instructors.php', 'error', 'Enter the instructor\'s name.'); }
    $phone = mb_substr(trim((string) ($_POST['phone'] ?? '')), 0, 30) ?: null; $email = mb_substr(trim((string) ($_POST['email'] ?? '')), 0, 150) ?: null;
    $spec = mb_substr(trim((string) ($_POST['specialty'] ?? '')), 0, 200) ?: null; $st = ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active';
    if ($uid) {
        $dup = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM lc_instructors WHERE user_id = $uid AND id <> $id"));
        if ($dup) { lcs_go('instructors.php', 'error', 'This user is already an instructor.'); }
    }
    $me = (int) current_user_id();
    if ($id) {
        $s = mysqli_prepare($conn, 'UPDATE lc_instructors SET user_id = ?, name = ?, phone = ?, email = ?, kind = ?, specialty = ?, status = ? WHERE id = ?');
        mysqli_stmt_bind_param($s, 'issssssi', $uid, $name, $phone, $email, $kind, $spec, $st, $id);
    } else {
        $s = mysqli_prepare($conn, 'INSERT INTO lc_instructors (user_id, name, phone, email, kind, specialty, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'issssssi', $uid, $name, $phone, $email, $kind, $spec, $st, $me);
    }
    mysqli_stmt_execute($s);
    lc_audit($conn, $id ? 'instructor_update' : 'instructor_add', 'instructor', $id ?: (int) mysqli_insert_id($conn), ['name' => $name, 'kind' => $kind, 'user' => $uid, 'status' => $st]);
    lcs_go('instructors.php', 'success', 'Instructor saved.');
}
$rows = lc_instructors($conn, false);
$edit = isset($_GET['edit']) ? ($rows[(int) $_GET['edit']] ?? null) : null;
$count = [];
foreach ($rows as $i => $r) { $count[$i] = count(lc_instructor_class_ids($conn, $i)); }
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'instructors.php', 'Instructors');
$v = $edit ?: ['id' => 0, 'kind' => 'internal', 'user_id' => null, 'name' => '', 'phone' => '', 'email' => '', 'specialty' => '', 'status' => 'Active'];
?>
<div class="row g-3">
<div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Instructor</th><th>Type</th><th>Specialty</th><th>Contacts</th><th>Classes</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $i => $r) { ?><tr><td class="ps-3 fw-semibold"><?= lc_e($r['name']); ?></td>
        <td><?= $r['kind'] === 'internal' ? 'Employee' : 'External' . ($r['user_id'] ? ' <span class="small text-muted">(sign-in)</span>' : ''); ?></td><td class="small"><?= lc_e($r['specialty']); ?></td>
        <td class="small"><?= lc_e(trim($r['phone'] . ' ' . $r['email'])); ?></td><td><?= $count[$i]; ?></td><td><?= lcs_badge($r['status']); ?></td><td><a href="?edit=<?= $i; ?>" class="small">Edit</a></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-4">No instructor yet.</td></tr><?php } ?>
</table></div></div></div>
<div class="col-lg-4"><form method="POST" class="card border-0 shadow-sm"><div class="card-body"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $v['id']; ?>">
    <div class="fw-semibold mb-2"><?= $edit ? 'Edit instructor' : 'Add an instructor'; ?></div>
    <div class="btn-group btn-group-sm w-100 mb-2" role="group"><input type="radio" class="btn-check" name="kind" value="internal" id="kI" <?= $v['kind'] === 'internal' ? 'checked' : ''; ?>><label class="btn btn-outline-primary" for="kI">Employee</label>
        <input type="radio" class="btn-check" name="kind" value="external" id="kE" <?= $v['kind'] === 'external' ? 'checked' : ''; ?>><label class="btn btn-outline-primary" for="kE">External</label></div>
    <label class="form-label small mb-0">MY MOTIVE user <span class="text-muted">(employee; optional sign-in for external)</span></label>
    <select name="user_id" class="form-select form-select-sm mb-2"><option value="0">—</option><?php foreach ($users as $uid => $n) { ?><option value="<?= $uid; ?>" <?= (int) $v['user_id'] === $uid ? 'selected' : ''; ?>><?= lc_e($n); ?></option><?php } ?></select>
    <label class="form-label small mb-0">Name <span class="text-muted">(external)</span></label><input name="name" class="form-control form-control-sm mb-2" value="<?= lc_e($v['name']); ?>">
    <div class="row g-2 mb-2"><div class="col-6"><input name="phone" class="form-control form-control-sm" placeholder="Telephone" value="<?= lc_e($v['phone']); ?>"></div><div class="col-6"><input name="email" class="form-control form-control-sm" placeholder="Email" value="<?= lc_e($v['email']); ?>"></div></div>
    <input name="specialty" class="form-control form-control-sm mb-2" placeholder="Specialty (e.g. Web development)" value="<?= lc_e($v['specialty']); ?>">
    <select name="status" class="form-select form-select-sm mb-2"><option <?= $v['status'] === 'Active' ? 'selected' : ''; ?>>Active</option><option <?= $v['status'] === 'Inactive' ? 'selected' : ''; ?>>Inactive</option></select>
    <button class="btn btn-sm btn-primary">Save</button> <?php if ($edit) { ?><a href="instructors.php" class="btn btn-sm btn-light border">Cancel</a><?php } ?>
    <div class="small text-muted mt-2">Instructors see only their own classes: students, timetable, attendance and marks. Publishing results and certificates is for academic staff.</div>
</div></form></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>