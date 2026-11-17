<?php
/**
 * NEW FILE: modules/learning/internship.php — ONE internship application / intern.
 * HR: status (shortlist, interview, approve, reject…), placement (department, branch, supervisor, dates), completion
 * approval and the completion letter. Supervisor (and HR): daily attendance and evaluations. Every change is logged.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/lc_internship.php';
lc_int_ensure($conn);
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$a = lc_int_app($conn, $id);
if (!$a) { lcs_go('internships.php', 'error', 'Not found.'); }
$me = (int) current_user_id();
$hr = lcs_can($conn, 'internship') && lcs_branch_ok($conn, (int) $a['branch_id'] ?: null);
$sup = (int) $a['supervisor_user_id'] === $me;
if (!$hr && !$sup) { lcs_deny(); }
$back = 'internship.php?id=' . $id;
$users = lcs_users($conn);
$depts = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name'), MYSQLI_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go($back, 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? ''); $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if ($do === 'status') {
            if (!$hr) { lcs_deny(); }
            $new = (string) ($_POST['status'] ?? '');
            $allowed = ['Submitted' => ['Shortlisted', 'Interview', 'Approved', 'Rejected', 'Withdrawn'], 'Shortlisted' => ['Interview', 'Approved', 'Rejected', 'Withdrawn'], 'Interview' => ['Approved', 'Rejected', 'Withdrawn'],
                        'Approved' => ['Active', 'Withdrawn', 'Rejected'], 'Active' => ['Completed', 'Discontinued'], 'Completed' => [], 'Rejected' => [], 'Withdrawn' => [], 'Discontinued' => []][$a['status']] ?? [];
            if (!in_array($new, $allowed, true)) { throw new RuntimeException('From "' . $a['status'] . '" the next steps are: ' . ($allowed ? implode(', ', $allowed) : 'none') . '.'); }
            if (in_array($new, ['Rejected', 'Withdrawn', 'Discontinued'], true) && mb_strlen($note) < 3) { throw new RuntimeException('Give the reason.'); }
            if ($new === 'Approved') {
                $post = lc_int_post($conn, (int) $a['post_id']);
                if ($post['places'] && (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lc_int_apps WHERE post_id = ' . (int) $a['post_id'] . " AND status IN ('Approved', 'Active', 'Completed')"))[0] >= (int) $post['places']) { throw new RuntimeException('All places of this opportunity are filled.'); }
            }
            if ($new === 'Active' && (!$a['supervisor_user_id'] || !$a['department_id'] || !$a['start_date'] || !$a['end_date'])) { throw new RuntimeException('Before starting: set the department, the supervisor and the dates (Placement).'); }
            $s = mysqli_prepare($conn, 'UPDATE lc_int_apps SET status = ?, decided_by = ?, decided_at = NOW() WHERE id = ?'); mysqli_stmt_bind_param($s, 'sii', $new, $me, $id); mysqli_stmt_execute($s);
            $pub = !empty($_POST['public']) || in_array($new, ['Approved', 'Rejected', 'Active', 'Completed'], true);
            lc_int_log($conn, $id, $a['status'], $new, $note ?: null, $pub);
            lc_audit($conn, 'internship_status', 'internship', $id, ['from' => $a['status'], 'to' => $new, 'note' => $note ?: null]);
            if ($pub) { lc_int_notify($conn, $id, $pub && $note ? $note : ''); }
            lcs_go($back, 'success', 'Status: ' . $new . '.');
        }
        if ($do === 'placement') {
            if (!$hr) { lcs_deny(); }
            if (in_array($a['status'], ['Completed', 'Rejected', 'Withdrawn', 'Discontinued'], true)) { throw new RuntimeException('This internship is closed.'); }
            $dep = (int) ($_POST['department_id'] ?? 0) ?: null; $br = (int) ($_POST['branch_id'] ?? 0) ?: null; $su = isset($users[(int) ($_POST['supervisor_user_id'] ?? 0)]) ? (int) $_POST['supervisor_user_id'] : null;
            $sd = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['start_date'] ?? '') ? $_POST['start_date'] : null; $ed = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['end_date'] ?? '') ? $_POST['end_date'] : null;
            if ($sd && $ed && $ed <= $sd) { throw new RuntimeException('The end date must be after the start date.'); }
            if ($br && !lcs_branch_ok($conn, $br)) { lcs_deny(); }
            $s = mysqli_prepare($conn, 'UPDATE lc_int_apps SET department_id = ?, branch_id = ?, supervisor_user_id = ?, start_date = ?, end_date = ? WHERE id = ?');
            mysqli_stmt_bind_param($s, 'iiissi', $dep, $br, $su, $sd, $ed, $id); mysqli_stmt_execute($s);
            lc_int_log($conn, $id, null, null, 'Placement: ' . implode(', ', array_filter([$su ? 'supervisor ' . $users[$su] : null, $sd ? $sd . ' → ' . $ed : null])), false);
            lc_audit($conn, 'internship_placement', 'internship', $id, ['department' => $dep, 'branch' => $br, 'supervisor' => $su, 'from' => $sd, 'to' => $ed, 'before' => ['department' => $a['department_id'], 'supervisor' => $a['supervisor_user_id'], 'from' => $a['start_date'], 'to' => $a['end_date']]]);
            if ($su && $su !== (int) $a['supervisor_user_id'] && function_exists('notifyUser')) { try { notifyUser($conn, $su, 'New intern: ' . $a['full_name'], 'You supervise ' . $a['full_name'] . ' (' . $a['post_title'] . '). RM Learning Centre → Internships.'); } catch (Throwable $e2) { } }
            lcs_go($back, 'success', 'Placement saved.');
        }
        if ($do === 'attendance') {
            if (!in_array($a['status'], ['Active', 'Completed'], true)) { throw new RuntimeException('Attendance is recorded during the internship (status Active).'); }
            $day = (string) ($_POST['day'] ?? ''); $st = (string) ($_POST['att'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day > date('Y-m-d') || ($a['start_date'] && $day < $a['start_date'])) { throw new RuntimeException('Choose a day of the internship, today or before.'); }
            if (!isset(lc_att_statuses($conn)[$st])) { throw new RuntimeException('Choose the status.'); }
            $old = mysqli_fetch_row(mysqli_query($conn, "SELECT status FROM lc_int_attendance WHERE app_id = $id AND day = '$day'"));
            mysqli_query($conn, "DELETE FROM lc_int_attendance WHERE app_id = $id AND day = '$day'");
            $nt = mb_substr($note, 0, 200) ?: null; $now = date('Y-m-d H:i:s');
            $s = mysqli_prepare($conn, 'INSERT INTO lc_int_attendance (app_id, day, status, note, recorded_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?)'); mysqli_stmt_bind_param($s, 'isssis', $id, $day, $st, $nt, $me, $now); mysqli_stmt_execute($s);
            lc_audit($conn, 'internship_attendance', 'internship', $id, ['day' => $day, 'from' => $old[0] ?? null, 'to' => $st]);
            lcs_go($back, 'success', 'Attendance saved for ' . date('d M', strtotime($day)) . '.');
        }
        if ($do === 'evaluation') {
            if (!in_array($a['status'], ['Active', 'Completed'], true)) { throw new RuntimeException('Evaluations are for interns (status Active).'); }
            $sc = []; foreach (LC_INT_CRITERIA as $k => $l) { $v = (int) ($_POST['c'][$k] ?? 0); if ($v < 1 || $v > 5) { throw new RuntimeException('Rate every criterion from 1 to 5.'); } $sc[$k] = $v; }
            $avg = round(array_sum($sc) / count($sc), 2); $js = json_encode($sc); $d = date('Y-m-d');
            $str = trim((string) ($_POST['strengths'] ?? '')) ?: null; $imp = trim((string) ($_POST['improve'] ?? '')) ?: null; $rec = mb_substr(trim((string) ($_POST['recommendation'] ?? '')), 0, 40) ?: null;
            $s = mysqli_prepare($conn, 'INSERT INTO lc_int_evals (app_id, eval_date, scores, average, strengths, improve, recommendation, by_user) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'issdsssi', $id, $d, $js, $avg, $str, $imp, $rec, $me); mysqli_stmt_execute($s);
            lc_audit($conn, 'internship_evaluation', 'internship', $id, ['average' => $avg]);
            lcs_go($back, 'success', 'Evaluation saved (' . $avg . ' / 5).');
        }
        if ($do === 'approve_completion') {
            if (!$hr) { lcs_deny(); }
            if ($a['status'] !== 'Completed') { throw new RuntimeException('Mark the internship Completed first.'); }
            mysqli_query($conn, "UPDATE lc_int_apps SET completion_approved_by = $me, completion_approved_at = NOW() WHERE id = $id");
            lc_int_log($conn, $id, null, null, 'Completion letter approved.', true);
            lc_audit($conn, 'internship_completion_approved', 'internship', $id);
            lc_int_notify($conn, $id, 'Your completion letter is ready.');
            lcs_go($back, 'success', 'Completion approved: the letter can be printed.');
        }
        if ($do === 'link_customer') {
            if (!$hr) { lcs_deny(); }
            if ($a['customer_id']) { throw new RuntimeException('Already linked.'); }
            $m = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM customers WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), 9) = '" . mysqli_real_escape_string($conn, $a['phone_key']) . "' ORDER BY id LIMIT 1"));
            if (!$m) { throw new RuntimeException('No customer has this telephone. External interns do not need a customer record.'); }
            mysqli_query($conn, 'UPDATE lc_int_apps SET customer_id = ' . (int) $m[0] . ', is_student = ' . (lc_is_student($conn, (int) $m[0]) ? 1 : 0) . " WHERE id = $id");
            lc_audit($conn, 'internship_link_customer', 'internship', $id, ['customer' => (int) $m[0]], (int) $m[0]);
            lcs_go($back, 'success', 'Linked to customer ' . lc_customer_id_label($conn, (int) $m[0]) . ' (the application is now visible in their Customer Portal).');
        }
    } catch (RuntimeException $e2) { lcs_go($back, 'error', $e2->getMessage()); }
}
if (($_GET['letter'] ?? '') === '1') {
    if (!$hr) { lcs_deny(); }
    try { $html = lc_int_letter_html($conn, $a); } catch (RuntimeException $e2) { lcs_go($back, 'error', $e2->getMessage()); }
    lc_audit($conn, 'internship_letter', 'internship', $id);
    lc_pdf_output($html, 'internship-' . $a['ref'] . '.pdf'); exit;
}
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT l.*, u.names FROM lc_int_log l LEFT JOIN users u ON u.id = l.user_id WHERE l.app_id = $id ORDER BY l.id DESC"), MYSQLI_ASSOC);
$att = lc_int_attendance($conn, $id);
$days = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_int_attendance WHERE app_id = $id ORDER BY day DESC LIMIT 30"), MYSQLI_ASSOC);
$evals = mysqli_fetch_all(mysqli_query($conn, "SELECT e.*, u.names FROM lc_int_evals e LEFT JOIN users u ON u.id = e.by_user WHERE e.app_id = $id ORDER BY e.id DESC"), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'internships.php', $a['ref'] . ' · ' . $a['full_name']);
require_once __DIR__ . '/../../includes/branch_helpers.php';
$brs = br_enabled($conn) ? br_all($conn) : [];
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3"><?= lcs_badge($a['status']); ?><?= $a['is_student'] ? '<span class="badge bg-info text-dark">RM Learning Centre student</span>' : '<span class="badge bg-light text-dark border">External applicant</span>'; ?>
    <span class="small text-muted"><?= lc_e($a['post_title']); ?> · <?= lc_e($a['department'] ?? 'no department'); ?> · <?= lc_e(lc_branch_name($conn, (int) $a['branch_id'] ?: null)); ?> · supervisor <?= lc_e($a['supervisor'] ?? '—'); ?> · <?= $a['channel'] === 'portal' ? 'Customer Portal' : 'public website'; ?></span></div>
<div class="row g-3"><div class="col-xl-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Applicant</h6><table class="table table-sm small mb-0">
        <tr><td class="text-muted" style="width:170px">Name</td><td><?= lc_e($a['full_name']); ?><?= $a['customer_id'] ? ' · Customer ' . lc_e(lc_customer_id_label($conn, (int) $a['customer_id'])) : ''; ?></td></tr>
        <tr><td class="text-muted">Telephone / email</td><td><?= lc_e($a['phone']); ?> <?= lc_e($a['email']); ?></td></tr>
        <tr><td class="text-muted">Education</td><td><?= nl2br(lc_e($a['education'])); ?></td></tr><tr><td class="text-muted">Motivation</td><td><?= nl2br(lc_e($a['motivation'])); ?></td></tr>
        <tr><td class="text-muted">CV</td><td><?= $a['cv_ref'] ? '<a href="file.php?internship=' . $id . '" target="_blank">Open the CV</a>' : '—'; ?></td></tr>
        <tr><td class="text-muted">Period</td><td><?= $a['start_date'] ? date('d M Y', strtotime($a['start_date'])) . ' → ' . ($a['end_date'] ? date('d M Y', strtotime($a['end_date'])) : '') : '—'; ?></td></tr></table>
        <?php if ($hr && !$a['customer_id']) { ?><form method="POST" class="mt-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="link_customer"><button class="btn btn-sm btn-link p-0">Link to the existing customer with this telephone</button></form><?php } ?>
    </div></div>
    <?php if (in_array($a['status'], ['Active', 'Completed'], true)) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><div class="d-flex justify-content-between"><h6>Attendance</h6><span class="small"><?= $att['pct'] !== null ? $att['pct'] . '% present' : ''; ?> · <?= $att['days']; ?> day(s) recorded</span></div>
        <form method="POST" class="d-flex flex-wrap gap-1 mb-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="attendance"><input type="date" name="day" value="<?= date('Y-m-d'); ?>" max="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" style="width:150px">
            <select name="att" class="form-select form-select-sm" style="width:130px"><?php foreach (lc_att_statuses($conn) as $s => $k) { ?><option><?= lc_e($s); ?></option><?php } ?></select><input name="note" class="form-control form-control-sm" style="width:200px" placeholder="Note"><button class="btn btn-sm btn-primary">Save</button></form>
        <div class="d-flex flex-wrap gap-1"><?php foreach ($days as $d) { ?><span class="badge <?= (lc_att_statuses($conn)[$d['status']] ?? 'present') === 'present' ? 'bg-success' : ((lc_att_statuses($conn)[$d['status']] ?? '') === 'excused' ? 'bg-secondary' : 'bg-danger'); ?>" title="<?= lc_e($d['note']); ?>"><?= date('d M', strtotime($d['day'])); ?> <?= lc_e($d['status']); ?></span><?php } ?></div>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Evaluations</h6>
        <?php foreach ($evals as $ev) { $s = json_decode($ev['scores'], true) ?: []; ?><div class="small border-top py-1"><strong><?= number_format((float) $ev['average'], 1); ?> / 5</strong> · <?= lc_e($ev['eval_date']); ?> · <?= lc_e($ev['names']); ?><?= $ev['recommendation'] ? ' · ' . lc_e($ev['recommendation']) : ''; ?>
            <div class="text-muted"><?= lc_e(implode(' · ', array_map(function ($k) use ($s) { return LC_INT_CRITERIA[$k] . ' ' . ($s[$k] ?? '—'); }, array_keys(LC_INT_CRITERIA)))); ?></div><?= $ev['strengths'] ? '<div>+ ' . lc_e($ev['strengths']) . '</div>' : ''; ?><?= $ev['improve'] ? '<div>→ ' . lc_e($ev['improve']) . '</div>' : ''; ?></div><?php } ?>
        <form method="POST" class="border-top pt-2 mt-1"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="evaluation">
            <div class="row g-1 small"><?php foreach (LC_INT_CRITERIA as $k => $l) { ?><div class="col-md-6 d-flex justify-content-between align-items-center"><span><?= $l; ?></span><span><?php for ($i = 1; $i <= 5; $i++) { ?><label class="ms-1"><input type="radio" name="c[<?= $k; ?>]" value="<?= $i; ?>" required> <?= $i; ?></label><?php } ?></span></div><?php } ?></div>
            <div class="row g-1 mt-1"><div class="col-md-6"><input name="strengths" class="form-control form-control-sm" placeholder="Strengths"></div><div class="col-md-6"><input name="improve" class="form-control form-control-sm" placeholder="To improve"></div>
                <div class="col-md-6"><select name="recommendation" class="form-select form-select-sm"><option value="">Recommendation…</option><option>Excellent</option><option>Good</option><option>Satisfactory</option><option>Needs improvement</option></select></div><div class="col-md-6"><button class="btn btn-sm btn-primary">Save evaluation</button></div></div></form>
    </div></div>
    <?php } ?>
</div>
<div class="col-xl-5">
    <?php if ($hr) { ?>
    <div class="card border-0 shadow-sm mb-3 border-start border-4 border-primary"><div class="card-body"><h6>Decision</h6>
        <form method="POST"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="status"><select name="status" class="form-select form-select-sm mb-1"><?php foreach (LC_INT_STATUSES as $s) { if ($s !== $a['status']) { ?><option><?= $s; ?></option><?php } } ?></select>
            <textarea name="note" class="form-control form-control-sm mb-1" rows="2" placeholder="Message / reason (interview date, instructions…)"></textarea>
            <div class="form-check small"><input class="form-check-input" type="checkbox" name="public" value="1" id="pb"><label class="form-check-label" for="pb">Send the message to the applicant (SMS / portal)</label></div><button class="btn btn-sm btn-primary mt-1">Change status</button></form>
        <div class="small text-muted mt-1">Approved → set the placement → Active → Completed. Interns are never added as employees.</div>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Placement</h6>
        <form method="POST" class="row g-2"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="placement">
            <div class="col-6"><select name="department_id" class="form-select form-select-sm"><option value="0">Department…</option><?php foreach ($depts as $d) { ?><option value="<?= (int) $d['id']; ?>" <?= (int) $a['department_id'] === (int) $d['id'] ? 'selected' : ''; ?>><?= lc_e($d['name']); ?></option><?php } ?></select></div>
            <div class="col-6"><select name="branch_id" class="form-select form-select-sm"><option value="0">Main branch</option><?php foreach ($brs as $bid => $b) { ?><option value="<?= (int) $bid; ?>" <?= (int) $a['branch_id'] === (int) $bid ? 'selected' : ''; ?>><?= lc_e($b['name']); ?></option><?php } ?></select></div>
            <div class="col-12"><select name="supervisor_user_id" class="form-select form-select-sm"><option value="0">Supervisor (employee)…</option><?php foreach ($users as $uid => $n) { ?><option value="<?= $uid; ?>" <?= (int) $a['supervisor_user_id'] === $uid ? 'selected' : ''; ?>><?= lc_e($n); ?></option><?php } ?></select></div>
            <div class="col-6"><input type="date" name="start_date" class="form-control form-control-sm" value="<?= lc_e($a['start_date']); ?>"></div><div class="col-6"><input type="date" name="end_date" class="form-control form-control-sm" value="<?= lc_e($a['end_date']); ?>"></div>
            <div class="col-12"><button class="btn btn-sm btn-outline-primary">Save placement</button></div></form>
    </div></div>
    <?php if ($a['status'] === 'Completed') { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Completion letter</h6>
        <?php if ($a['completion_approved_at']) { ?><a class="btn btn-sm btn-primary" href="?id=<?= $id; ?>&letter=1" target="_blank"><i class="bi bi-file-earmark-pdf"></i> Print the letter</a><div class="small text-muted mt-1">Approved <?= lc_e($a['completion_approved_at']); ?>.</div>
        <?php } else { ?><form method="POST"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="approve_completion"><button class="btn btn-sm btn-success" onclick="return confirm('Approve the completion letter?');">Approve the completion letter</button></form><?php } ?>
    </div></div><?php } ?>
    <?php } ?>
    <div class="card border-0 shadow-sm"><div class="card-body"><h6>History</h6>
        <?php foreach ($log as $l) { ?><div class="small border-top py-1"><strong><?= lc_e($l['to_status'] ?: 'Note'); ?></strong> · <?= lc_e($l['names'] ?: 'Applicant / system'); ?> · <span class="text-muted"><?= lc_e($l['created_at']); ?></span> <?= $l['public'] ? '<span class="badge bg-light text-dark border">sent</span>' : ''; ?><?= $l['note'] ? '<div>' . lc_e($l['note']) . '</div>' : ''; ?></div><?php } ?>
    </div></div>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>