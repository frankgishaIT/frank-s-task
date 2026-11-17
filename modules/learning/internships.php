<?php
/**
 * NEW FILE: modules/learning/internships.php — INTERNSHIPS (HR workflow): Opportunities · Applications.
 * HR / Internship officers (permission learning.internship) publish opportunities and manage applications.
 * Supervisors (any MY MOTIVE user named as supervisor) see their own interns only.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/lc_internship.php';
lc_int_ensure($conn);
$hr = lcs_can($conn, 'internship');
$me = (int) current_user_id();
$supervises = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_int_apps WHERE supervisor_user_id = $me"))[0] > 0;
if (!$hr && !$supervises) { lcs_deny(); }
$tab = $hr && ($_GET['tab'] ?? '') === 'posts' ? 'posts' : 'apps';
$depts = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name'), MYSQLI_ASSOC);
require_once __DIR__ . '/../../includes/branch_helpers.php';
$brs = br_enabled($conn) ? br_all($conn) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $hr) {
    if (!lcs_csrf_ok()) { lcs_go('internships.php?tab=posts', 'error', 'The page had expired.'); }
    $id = (int) ($_POST['id'] ?? 0);
    $t = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 150); if (mb_strlen($t) < 3) { lcs_go('internships.php?tab=posts&new=1', 'error', 'Give the opportunity a title.'); }
    $dep = (int) ($_POST['department_id'] ?? 0) ?: null; $br = (int) ($_POST['branch_id'] ?? 0) ?: null;
    if ($br && !lcs_branch_ok($conn, $br)) { lcs_deny(); }
    $desc = trim((string) ($_POST['description'] ?? '')) ?: null; $req = trim((string) ($_POST['requirements'] ?? '')) ?: null;
    $pl = (int) ($_POST['places'] ?? 0) ?: null; $d = function ($k) { $v = (string) ($_POST[$k] ?? ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null; };
    $sd = $d('start_date'); $ed = $d('end_date'); $cl = $d('apply_close');
    if ($sd && $ed && $ed <= $sd) { lcs_go('internships.php?tab=posts', 'error', 'The end date must be after the start date.'); }
    $ot = in_array($_POST['open_to'] ?? '', ['students', 'external', 'both'], true) ? $_POST['open_to'] : 'both'; $st = in_array($_POST['status'] ?? '', ['Draft', 'Open', 'Closed'], true) ? $_POST['status'] : 'Draft';
    if ($id) { $s = mysqli_prepare($conn, 'UPDATE lc_int_posts SET title = ?, department_id = ?, branch_id = ?, description = ?, requirements = ?, places = ?, start_date = ?, end_date = ?, apply_close = ?, open_to = ?, status = ? WHERE id = ?');
        mysqli_stmt_bind_param($s, 'siississsssi', $t, $dep, $br, $desc, $req, $pl, $sd, $ed, $cl, $ot, $st, $id); }
    else { $s = mysqli_prepare($conn, 'INSERT INTO lc_int_posts (title, department_id, branch_id, description, requirements, places, start_date, end_date, apply_close, open_to, status, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'siississsssi', $t, $dep, $br, $desc, $req, $pl, $sd, $ed, $cl, $ot, $st, $me); }
    mysqli_stmt_execute($s);
    lc_audit($conn, $id ? 'internship_post_update' : 'internship_post_add', 'internship_post', $id ?: (int) mysqli_insert_id($conn), ['title' => $t, 'status' => $st, 'open_to' => $ot]);
    lcs_go('internships.php?tab=posts', 'success', 'Opportunity saved' . ($st === 'Open' ? ' and published on the public website.' : '.'));
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'internships.php', 'Internships', $hr ? '<a href="' . lc_e(lc_public_base($conn)) . '/internships.php" target="_blank" class="btn btn-sm btn-light border"><i class="bi bi-box-arrow-up-right"></i> Public page</a>' : '');
if ($hr) { ?><ul class="nav nav-pills small mb-3"><li class="nav-item"><a class="nav-link py-1 <?= $tab === 'apps' ? 'active' : ''; ?>" href="?tab=apps">Applications &amp; interns</a></li><li class="nav-item"><a class="nav-link py-1 <?= $tab === 'posts' ? 'active' : ''; ?>" href="?tab=posts">Opportunities</a></li></ul><?php }

if ($tab === 'posts') {
    $posts = mysqli_fetch_all(mysqli_query($conn, 'SELECT p.*, d.name department, (SELECT COUNT(*) FROM lc_int_apps a WHERE a.post_id = p.id) apps, (SELECT COUNT(*) FROM lc_int_apps a WHERE a.post_id = p.id AND a.status IN (\'Approved\', \'Active\', \'Completed\')) placed
        FROM lc_int_posts p LEFT JOIN departments d ON d.id = p.department_id WHERE ' . lcs_branch_sql($conn, 'p.branch_id') . ' ORDER BY p.id DESC'), MYSQLI_ASSOC);
    $ep = !empty($_GET['edit']) ? lc_int_post($conn, (int) $_GET['edit']) : null;
    $v = $ep ?: ['id' => 0, 'title' => '', 'department_id' => null, 'branch_id' => null, 'description' => '', 'requirements' => '', 'places' => '', 'start_date' => '', 'end_date' => '', 'apply_close' => '', 'open_to' => 'both', 'status' => 'Draft'];
    ?>
<div class="row g-3"><div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Opportunity</th><th>Department · branch</th><th>Period</th><th>Open to</th><th class="text-end">Applications</th><th class="text-end">Placed</th><th>Status</th><th></th></tr>
    <?php foreach ($posts as $p) { ?><tr><td class="ps-3 fw-semibold"><?= lc_e($p['title']); ?><div class="small text-muted"><?= $p['apply_close'] ? 'apply by ' . date('d M Y', strtotime($p['apply_close'])) : ''; ?></div></td>
        <td class="small"><?= lc_e($p['department'] ?? '—'); ?> · <?= lc_e(lc_branch_name($conn, (int) $p['branch_id'] ?: null)); ?></td><td class="small"><?= $p['start_date'] ? date('d M', strtotime($p['start_date'])) . ' – ' . ($p['end_date'] ? date('d M Y', strtotime($p['end_date'])) : '') : '—'; ?></td>
        <td class="small"><?= ['students' => 'LC students', 'external' => 'External', 'both' => 'Both'][$p['open_to']]; ?></td><td class="text-end"><a href="?tab=apps&post=<?= (int) $p['id']; ?>"><?= (int) $p['apps']; ?></a></td><td class="text-end"><?= (int) $p['placed']; ?><?= $p['places'] ? ' / ' . (int) $p['places'] : ''; ?></td>
        <td><?= lcs_badge($p['status']); ?></td><td><a class="small" href="?tab=posts&edit=<?= (int) $p['id']; ?>">Edit</a></td></tr><?php } ?>
    <?php if (!$posts) { ?><tr><td colspan="8" class="text-center text-muted py-4">No opportunity yet.</td></tr><?php } ?>
</table></div></div></div>
<div class="col-lg-5"><form method="POST" class="card border-0 shadow-sm"><div class="card-body row g-2"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $v['id']; ?>">
    <div class="col-12 fw-semibold"><?= $ep ? 'Edit opportunity' : 'New internship opportunity'; ?></div>
    <div class="col-12"><input name="title" class="form-control form-control-sm" placeholder="Title (e.g. Web development intern)" value="<?= lc_e($v['title']); ?>" required></div>
    <div class="col-6"><select name="department_id" class="form-select form-select-sm"><option value="0">Department…</option><?php foreach ($depts as $dp) { ?><option value="<?= (int) $dp['id']; ?>" <?= (int) $v['department_id'] === (int) $dp['id'] ? 'selected' : ''; ?>><?= lc_e($dp['name']); ?></option><?php } ?></select></div>
    <div class="col-6"><select name="branch_id" class="form-select form-select-sm"><option value="0">Main branch</option><?php foreach ($brs as $bid => $b) { if (lcs_branch_ok($conn, (int) $bid)) { ?><option value="<?= (int) $bid; ?>" <?= (int) $v['branch_id'] === (int) $bid ? 'selected' : ''; ?>><?= lc_e($b['name']); ?></option><?php } } ?></select></div>
    <div class="col-12"><textarea name="description" class="form-control form-control-sm" rows="3" placeholder="What the intern will do"><?= lc_e($v['description']); ?></textarea></div>
    <div class="col-12"><textarea name="requirements" class="form-control form-control-sm" rows="2" placeholder="Requirements (one per line)"><?= lc_e($v['requirements']); ?></textarea></div>
    <div class="col-4"><label class="form-label small mb-0">Places</label><input type="number" min="0" name="places" class="form-control form-control-sm" value="<?= lc_e($v['places']); ?>"></div>
    <div class="col-4"><label class="form-label small mb-0">Start</label><input type="date" name="start_date" class="form-control form-control-sm" value="<?= lc_e($v['start_date']); ?>"></div>
    <div class="col-4"><label class="form-label small mb-0">End</label><input type="date" name="end_date" class="form-control form-control-sm" value="<?= lc_e($v['end_date']); ?>"></div>
    <div class="col-4"><label class="form-label small mb-0">Apply by</label><input type="date" name="apply_close" class="form-control form-control-sm" value="<?= lc_e($v['apply_close']); ?>"></div>
    <div class="col-4"><label class="form-label small mb-0">Open to</label><select name="open_to" class="form-select form-select-sm"><?php foreach (['both' => 'Both', 'students' => 'LC students', 'external' => 'External'] as $k => $l) { ?><option value="<?= $k; ?>" <?= $v['open_to'] === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-4"><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm"><?php foreach (['Draft', 'Open', 'Closed'] as $s) { ?><option <?= $v['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div>
    <div class="col-12"><button class="btn btn-sm btn-primary">Save</button> <?php if ($ep) { ?><a href="?tab=posts" class="btn btn-sm btn-light border">Close</a><?php } ?></div>
    <div class="col-12 small text-muted">"Open" publishes it on the public Internships page and in the Customer Portal. Interns never become employee records.</div>
</div></form></div></div>
<?php } else {
    $w = [lcs_branch_sql($conn, 'a.branch_id')];
    if (!$hr) { $w[] = "a.supervisor_user_id = $me"; }
    $fp = (int) ($_GET['post'] ?? 0); if ($fp) { $w[] = 'a.post_id = ' . $fp; }
    $fs = (string) ($_GET['status'] ?? ''); if (in_array($fs, LC_INT_STATUSES, true)) { $w[] = "a.status = '$fs'"; }
    $q = trim((string) ($_GET['q'] ?? '')); if ($q !== '') { $x = mysqli_real_escape_string($conn, $q); $w[] = "(a.full_name LIKE '%$x%' OR a.phone LIKE '%$x%' OR a.ref LIKE '%$x%')"; }
    $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT a.*, p.title, d.name department, u.names supervisor FROM lc_int_apps a JOIN lc_int_posts p ON p.id = a.post_id LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN users u ON u.id = a.supervisor_user_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY a.id DESC LIMIT 500'), MYSQLI_ASSOC);
    ?>
<form class="row g-2 mb-3"><input type="hidden" name="tab" value="apps"><div class="col-md-4"><input name="q" value="<?= lc_e($q); ?>" class="form-control form-control-sm" placeholder="Name, telephone or reference"></div>
    <div class="col-md-3"><select name="status" class="form-select form-select-sm"><option value="">All statuses</option><?php foreach (LC_INT_STATUSES as $s) { ?><option <?= $fs === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select></div><div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filter</button></div></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Reference</th><th>Applicant</th><th>Opportunity</th><th>Department · supervisor</th><th>Period</th><th>Status</th></tr>
    <?php foreach ($rows as $r) { ?><tr><td class="ps-3"><a href="internship.php?id=<?= (int) $r['id']; ?>" class="fw-semibold"><?= lc_e($r['ref']); ?></a><div class="small text-muted"><?= lc_e(substr($r['created_at'], 0, 10)); ?></div></td>
        <td><?= lc_e($r['full_name']); ?><?= $r['is_student'] ? ' <span class="badge bg-info text-dark">LC student</span>' : ''; ?><div class="small text-muted"><?= lc_e($r['phone']); ?></div></td><td class="small"><?= lc_e($r['title']); ?></td>
        <td class="small"><?= lc_e($r['department'] ?? '—'); ?> · <?= lc_e($r['supervisor'] ?? '—'); ?></td><td class="small"><?= $r['start_date'] ? date('d M', strtotime($r['start_date'])) . ' – ' . ($r['end_date'] ? date('d M Y', strtotime($r['end_date'])) : '') : '—'; ?></td>
        <td><?= lcs_badge($r['status']); ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-4">No internship application.</td></tr><?php } ?>
</table></div></div>
<?php }
include __DIR__ . '/../../includes/footer.php';