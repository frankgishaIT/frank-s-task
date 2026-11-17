<?php
/**
 * NEW FILE: modules/learning/class.php — ONE CLASS: Students · Timetable · Attendance · Assessments & marks · Results.
 * Academic staff ("manage"): everything, incl. publishing results and issuing certificates.
 * Assigned instructors ("teach"): attendance registers and marks for their class (marks are locked once results are published).
 * Every change of a mark after publication needs a reason and is kept in the audit trail.
 */
require __DIR__ . '/common.php';
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$k = lc_class($conn, $id);
if (!$k) { lcs_go('classes.php', 'error', 'Class not found.'); }
$role = lcs_class_role($conn, $k);
if (!$role) { lcs_deny(); }
$manage = $role === 'manage'; $teach = $manage || $role === 'teach';
$tab = in_array($_GET['tab'] ?? '', ['students', 'timetable', 'attendance', 'assessments', 'results'], true) ? $_GET['tab'] : 'students';
$back = 'class.php?id=' . $id . '&tab=';
$ins = lc_instructors($conn);
$mods = []; foreach (lc_modules($conn, (int) $k['course_id']) as $m) { if ($m['status'] !== 'Retired') { $mods[(int) $m['id']] = $m['name']; } }
$label = $k['course_title'] . ' (' . $k['code'] . ')';
$students = lc_class_students($conn, $id);
$notifyAll = function (string $tpl, array $vars) use ($conn, $students) { foreach (array_keys($students) as $sid) { lc_notify_student($conn, $sid, $tpl, $vars, 'learning.php?tab=learning'); } };
$time = function ($v) { $v = trim((string) $v); return preg_match('/^\d{2}:\d{2}$/', $v) ? $v . ':00' : null; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string) ($_POST['do'] ?? '');
    $t = (string) ($_POST['tab'] ?? 'students');
    if (!lcs_csrf_ok()) { lcs_go($back . $t, 'error', 'The page had expired.'); }
    $uid = (int) current_user_id(); $now = date('Y-m-d H:i:s');
    try {
        /* ---------- students (academic staff) */
        if ($do === 'add_students') {
            if (!$manage) { lcs_deny(); }
            $ok = array_column(lc_class_candidates($conn, $k), null, 'enrollment_id'); $n = 0;
            foreach ((array) ($_POST['enrollments'] ?? []) as $eid) {
                $eid = (int) $eid; if (!isset($ok[$eid])) { continue; }
                if ($k['capacity'] && count($students) + $n >= (int) $k['capacity']) { break; }
                mysqli_query($conn, "UPDATE lc_enrollments SET class_id = $id, cohort = COALESCE(cohort, '" . mysqli_real_escape_string($conn, $k['code']) . "') WHERE id = $eid AND class_id IS NULL"); $n++;
            }
            lc_audit($conn, 'class_add_students', 'class', $id, ['count' => $n]);
            lcs_go($back . 'students', 'success', $n . ' student(s) added.');
        }
        if ($do === 'remove_student') {
            if (!$manage) { lcs_deny(); }
            $eid = (int) ($_POST['enrollment_id'] ?? 0);
            mysqli_query($conn, "UPDATE lc_enrollments SET class_id = NULL WHERE id = $eid AND class_id = $id");
            lc_audit($conn, 'class_remove_student', 'class', $id, ['enrollment' => $eid]);
            lcs_go($back . 'students', 'success', 'Student removed from the class (marks and attendance are kept).');
        }
        if ($do === 'assign') {
            if (!$manage) { lcs_deny(); }
            $iid = (int) ($_POST['instructor_id'] ?? 0); $mid = (int) ($_POST['module_id'] ?? 0); $mid = isset($mods[$mid]) ? $mid : 0;
            if (!isset($ins[$iid])) { throw new RuntimeException('Choose the instructor.'); }
            mysqli_query($conn, "INSERT IGNORE INTO lc_class_instructors (class_id, instructor_id, module_id) VALUES ($id, $iid, $mid)");
            lc_audit($conn, 'class_assign_instructor', 'class', $id, ['instructor' => $iid, 'module' => $mid]);
            lcs_go($back . 'students', 'success', 'Instructor assigned.');
        }
        if ($do === 'unassign') {
            if (!$manage) { lcs_deny(); }
            $iid = (int) ($_POST['instructor_id'] ?? 0); $mid = (int) ($_POST['module_id'] ?? 0);
            mysqli_query($conn, "DELETE FROM lc_class_instructors WHERE class_id = $id AND instructor_id = $iid AND module_id = $mid");
            lc_audit($conn, 'class_unassign_instructor', 'class', $id, ['instructor' => $iid, 'module' => $mid]);
            lcs_go($back . 'students', 'success', 'Assignment removed.');
        }
        /* ---------- timetable (academic staff) */
        if (in_array($do, ['session_add', 'session_generate', 'session_edit'], true)) {
            if (!$manage) { lcs_deny(); }
            $mid = (int) ($_POST['module_id'] ?? 0); $mid = isset($mods[$mid]) ? $mid : null;
            $iid = (int) ($_POST['instructor_id'] ?? 0); $iid = isset($ins[$iid]) ? $iid : ($k['instructor_id'] ? (int) $k['instructor_id'] : null);
            $st = $time($_POST['start_time'] ?? ''); $en = $time($_POST['end_time'] ?? '');
            if ($st && $en && $en <= $st) { throw new RuntimeException('The end time must be after the start time.'); }
            $room = mb_substr(trim((string) ($_POST['classroom'] ?? '')), 0, 80) ?: $k['classroom'];
            $topic = mb_substr(trim((string) ($_POST['topic'] ?? '')), 0, 200) ?: null;
            $dates = [];
            if ($do === 'session_generate') {
                $a = (string) ($_POST['from'] ?? ''); $b = (string) ($_POST['to'] ?? ''); $days = array_map('intval', (array) ($_POST['weekdays'] ?? []));
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $a) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $b) || $b < $a || !$days) { throw new RuntimeException('Choose the period and at least one day of the week.'); }
                if ((strtotime($b) - strtotime($a)) / 86400 > 370) { throw new RuntimeException('The period may not be longer than one year.'); }
                for ($d = strtotime($a); $d <= strtotime($b); $d += 86400) { if (in_array((int) date('N', $d), $days, true)) { $dates[] = date('Y-m-d', $d); } }
            } else {
                $d = (string) ($_POST['session_date'] ?? '');
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { throw new RuntimeException('Choose the date.'); }
                $dates[] = $d;
            }
            if ($do === 'session_edit') {
                $sid = (int) ($_POST['session_id'] ?? 0);
                $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_sessions WHERE id = $sid AND class_id = $id"));
                if (!$old) { throw new RuntimeException('Session not found.'); }
                $s = mysqli_prepare($conn, 'UPDATE lc_sessions SET module_id = ?, session_date = ?, start_time = ?, end_time = ?, classroom = ?, instructor_id = ?, topic = ?, updated_at = ? WHERE id = ?');
                mysqli_stmt_bind_param($s, 'issssissi', $mid, $dates[0], $st, $en, $room, $iid, $topic, $now, $sid);
                mysqli_stmt_execute($s);
                $moved = $old['session_date'] !== $dates[0] || substr((string) $old['start_time'], 0, 5) !== substr((string) $st, 0, 5) || (string) $old['classroom'] !== (string) $room;
                lc_audit($conn, 'session_update', 'class', $id, ['session' => $sid, 'from' => $old['session_date'] . ' ' . $old['start_time'] . ' ' . $old['classroom'], 'to' => $dates[0] . ' ' . $st . ' ' . $room]);
                if ($moved && $old['status'] === 'Scheduled' && $dates[0] >= date('Y-m-d')) {
                    $notifyAll('tpl_timetable', ['{class}' => $label, '{message}' => 'the session of ' . date('d M', strtotime($old['session_date'])) . ' is moved to ' . date('D d M', strtotime($dates[0])) . ($st ? ' at ' . substr($st, 0, 5) : '') . ($room ? ', ' . $room : '') . '.']);
                }
                lcs_go($back . 'timetable', 'success', 'Session updated' . ($moved ? ' and students informed.' : '.'));
            }
            $n = 0;
            foreach ($dates as $d) {
                $s = mysqli_prepare($conn, 'INSERT INTO lc_sessions (class_id, module_id, session_date, start_time, end_time, classroom, instructor_id, topic, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'iissssisi', $id, $mid, $d, $st, $en, $room, $iid, $topic, $uid);
                mysqli_stmt_execute($s); $n++;
            }
            lc_audit($conn, 'sessions_add', 'class', $id, ['count' => $n, 'from' => $dates[0] ?? null, 'to' => end($dates) ?: null]);
            if ($n && !empty($_POST['notify'])) { $notifyAll('tpl_timetable', ['{class}' => $label, '{message}' => $n . ' new session(s) from ' . date('d M', strtotime($dates[0])) . '. See your timetable in the Customer Portal.']); }
            lcs_go($back . 'timetable', 'success', $n . ' session(s) added.');
        }
        if ($do === 'session_cancel') {
            if (!$manage) { lcs_deny(); }
            $sid = (int) ($_POST['session_id'] ?? 0); $why = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300);
            $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_sessions WHERE id = $sid AND class_id = $id"));
            if (!$old || $old['status'] !== 'Scheduled') { throw new RuntimeException('Only a scheduled session can be cancelled.'); }
            $s = mysqli_prepare($conn, "UPDATE lc_sessions SET status = 'Cancelled', note = ?, updated_at = ? WHERE id = ?"); mysqli_stmt_bind_param($s, 'ssi', $why, $now, $sid); mysqli_stmt_execute($s);
            lc_audit($conn, 'session_cancel', 'class', $id, ['session' => $sid, 'date' => $old['session_date'], 'reason' => $why]);
            if ($old['session_date'] >= date('Y-m-d')) { $notifyAll('tpl_timetable', ['{class}' => $label, '{message}' => 'the session of ' . date('D d M', strtotime($old['session_date'])) . ' is cancelled' . ($why ? ' (' . $why . ')' : '') . '.']); }
            lcs_go($back . 'timetable', 'success', 'Session cancelled and students informed.');
        }
        if ($do === 'session_delete') {
            if (!$manage) { lcs_deny(); }
            $sid = (int) ($_POST['session_id'] ?? 0);
            if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_attendance WHERE session_id = $sid"))[0]) { throw new RuntimeException('This session has attendance: cancel it instead.'); }
            mysqli_query($conn, "DELETE FROM lc_sessions WHERE id = $sid AND class_id = $id AND status <> 'Held'");
            lc_audit($conn, 'session_delete', 'class', $id, ['session' => $sid]);
            lcs_go($back . 'timetable', 'success', 'Session deleted.');
        }
        /* ---------- attendance (instructors and academic staff) */
        if ($do === 'attendance') {
            if (!$teach) { lcs_deny(); }
            $sid = (int) ($_POST['session_id'] ?? 0);
            $sess = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_sessions WHERE id = $sid AND class_id = $id"));
            if (!$sess || $sess['status'] === 'Cancelled') { throw new RuntimeException('Choose a session of this class.'); }
            if ($sess['session_date'] > date('Y-m-d')) { throw new RuntimeException('Attendance can be recorded on the day of the session or later.'); }
            if ($k['results_published_at'] && !$manage) { throw new RuntimeException('Results are published: only academic staff can change attendance now.'); }
            $map = lc_att_statuses($conn); $n = 0; $chg = [];
            $old = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT customer_id, status FROM lc_attendance WHERE session_id = $sid"), MYSQLI_ASSOC) as $r) { $old[(int) $r['customer_id']] = $r['status']; }
            foreach ($students as $cid => $st) {
                $v = (string) ($_POST['att'][$cid] ?? ''); if (!isset($map[$v])) { continue; }
                $note = mb_substr(trim((string) ($_POST['note'][$cid] ?? '')), 0, 200) ?: null;
                if (isset($old[$cid]) && $old[$cid] !== $v) { $chg[] = [$cid, $old[$cid], $v]; }
                mysqli_query($conn, "DELETE FROM lc_attendance WHERE session_id = $sid AND customer_id = $cid");
                $s = mysqli_prepare($conn, 'INSERT INTO lc_attendance (session_id, customer_id, status, note, recorded_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'iissis', $sid, $cid, $v, $note, $uid, $now); mysqli_stmt_execute($s); $n++;
            }
            mysqli_query($conn, "UPDATE lc_sessions SET status = 'Held', updated_at = '$now' WHERE id = $sid");
            lc_audit($conn, $old ? 'attendance_update' : 'attendance_record', 'class', $id, ['session' => $sid, 'date' => $sess['session_date'], 'marked' => $n, 'changed' => $chg]);
            $alerts = lc_attendance_alerts($conn, lc_class($conn, $id));
            lcs_go($back . 'attendance&session=' . $sid, 'success', 'Attendance saved (' . $n . ' student(s))' . ($alerts ? '; ' . $alerts . ' attendance alert(s) sent.' : '.'));
        }
        /* ---------- assessments (academic staff) */
        if ($do === 'assessment') {
            if (!$manage) { lcs_deny(); }
            $aid = (int) ($_POST['assessment_id'] ?? 0);
            $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120); $type = (string) ($_POST['type'] ?? '');
            if (mb_strlen($name) < 2 || !in_array($type, lc_assessment_types($conn), true)) { throw new RuntimeException('Give the assessment a name and a type.'); }
            $mid = (int) ($_POST['module_id'] ?? 0); $mid = isset($mods[$mid]) ? $mid : null;
            $max = max(1, (float) ($_POST['max_score'] ?? 100)); $w = max(0, min(100, (float) ($_POST['weight'] ?? 0)));
            $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['due_date'] ?? '') ? $_POST['due_date'] : null; $req = !empty($_POST['required']) ? 1 : 0;
            if ($aid) {
                $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_assessments WHERE id = $aid AND class_id = $id"));
                if (!$old) { throw new RuntimeException('Assessment not found.'); }
                $s = mysqli_prepare($conn, 'UPDATE lc_assessments SET name = ?, type = ?, module_id = ?, max_score = ?, weight = ?, due_date = ?, required = ? WHERE id = ?');
                mysqli_stmt_bind_param($s, 'ssiddsii', $name, $type, $mid, $max, $w, $due, $req, $aid); mysqli_stmt_execute($s);
                lc_audit($conn, 'assessment_update', 'class', $id, ['assessment' => $aid, 'from' => ['max' => $old['max_score'], 'weight' => $old['weight'], 'date' => $old['due_date']], 'to' => ['max' => $max, 'weight' => $w, 'date' => $due]]);
                $isNewDate = $due && $due !== $old['due_date'];
            } else {
                $s = mysqli_prepare($conn, 'INSERT INTO lc_assessments (class_id, module_id, name, type, max_score, weight, due_date, required, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'iissddsii', $id, $mid, $name, $type, $max, $w, $due, $req, $uid); mysqli_stmt_execute($s);
                $aid = (int) mysqli_insert_id($conn);
                lc_audit($conn, 'assessment_add', 'class', $id, ['assessment' => $aid, 'name' => $name, 'weight' => $w]);
                $isNewDate = (bool) $due;
            }
            $sent = false;
            if ($isNewDate && $due >= date('Y-m-d') && stripos($type, 'exam') !== false) { $notifyAll('tpl_exam', ['{class}' => $label, '{assessment}' => $name, '{date}' => date('D d M Y', strtotime($due))]); $sent = true; }
            lcs_go($back . 'assessments', 'success', 'Assessment saved' . ($sent ? ' and the examination date sent to the students.' : '.'));
        }
        if ($do === 'assessment_delete') {
            if (!$manage) { lcs_deny(); }
            $aid = (int) ($_POST['assessment_id'] ?? 0);
            if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_scores WHERE assessment_id = $aid"))[0]) { throw new RuntimeException('This assessment has marks: it cannot be deleted.'); }
            mysqli_query($conn, "DELETE FROM lc_assessments WHERE id = $aid AND class_id = $id");
            lc_audit($conn, 'assessment_delete', 'class', $id, ['assessment' => $aid]);
            lcs_go($back . 'assessments', 'success', 'Assessment deleted.');
        }
        /* ---------- marks (instructors and academic staff) */
        if ($do === 'scores') {
            if (!$teach) { lcs_deny(); }
            $aid = (int) ($_POST['assessment_id'] ?? 0);
            $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_assessments WHERE id = $aid AND class_id = $id"));
            if (!$a) { throw new RuntimeException('Assessment not found.'); }
            $reason = mb_substr(trim((string) ($_POST['reason'] ?? '')), 0, 300);
            if ($k['results_published_at']) {
                if (!$manage) { throw new RuntimeException('Results are published: marks are locked. Ask the academic officer.'); }
                if (mb_strlen($reason) < 5) { throw new RuntimeException('Results are already published: give the reason of the correction.'); }
            }
            $old = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_scores WHERE assessment_id = $aid"), MYSQLI_ASSOC) as $r) { $old[(int) $r['customer_id']] = $r; }
            $chg = []; $n = 0;
            foreach ($students as $cid => $st) {
                $raw = trim((string) ($_POST['score'][$cid] ?? '')); $abs = !empty($_POST['absent'][$cid]) ? 1 : 0;
                if ($raw === '' && !$abs) {
                    if (isset($old[$cid])) { mysqli_query($conn, "DELETE FROM lc_scores WHERE assessment_id = $aid AND customer_id = $cid"); $chg[] = [$cid, $old[$cid]['absent'] ? 'absent' : $old[$cid]['score'], null]; }
                    continue;
                }
                $sc = $abs ? null : (float) str_replace(',', '.', $raw);
                if (!$abs && ($sc < 0 || $sc > (float) $a['max_score'])) { throw new RuntimeException('Marks must be between 0 and ' . (float) $a['max_score'] . ' (' . $st['name'] . ').'); }
                $prev = isset($old[$cid]) ? ($old[$cid]['absent'] ? 'absent' : (float) $old[$cid]['score']) : null;
                $new = $abs ? 'absent' : $sc;
                if ($prev !== null && $prev !== $new) { $chg[] = [$cid, $prev, $new]; }
                if ($prev === $new) { continue; }
                $note = mb_substr(trim((string) ($_POST['note'][$cid] ?? '')), 0, 200) ?: null;
                mysqli_query($conn, "DELETE FROM lc_scores WHERE assessment_id = $aid AND customer_id = $cid");
                $s = mysqli_prepare($conn, 'INSERT INTO lc_scores (assessment_id, customer_id, score, absent, note, recorded_by, recorded_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
                mysqli_stmt_bind_param($s, 'iidisis', $aid, $cid, $sc, $abs, $note, $uid, $now); mysqli_stmt_execute($s); $n++;
            }
            lc_audit($conn, $chg ? 'scores_change' : 'scores_record', 'class', $id, ['assessment' => $aid, 'name' => $a['name'], 'saved' => $n, 'changes' => $chg, 'reason' => $reason ?: null]);
            $msg = 'Marks saved.';
            if ($k['results_published_at'] && $chg) { $msg .= ' Results are published: re-publish them so that students see the correction.'; }
            lcs_go($back . 'assessments&a=' . $aid, 'success', $msg);
        }
        /* ---------- results and certificates (academic staff) */
        if ($do === 'publish') {
            if (!$manage) { lcs_deny(); }
            if (!$students) { throw new RuntimeException('There is no student in this class.'); }
            if (empty($_POST['confirm'])) { throw new RuntimeException('Tick the box to confirm.'); }
            $r = lc_publish_results($conn, $k);
            lcs_go($back . 'results', 'success', 'Results published for ' . $r['students'] . ' student(s)' . ($r['changed'] ? ' (' . $r['changed'] . ' changed since the last publication)' : '') . '. Students can see them in the Customer Portal.');
        }
        if ($do === 'certificates') {
            if (!$manage) { lcs_deny(); }
            $n = 0; $skip = 0;
            foreach ((array) ($_POST['customers'] ?? []) as $cid) {
                try { lc_issue_certificate($conn, $k, (int) $cid); $n++; } catch (RuntimeException $e) { $skip++; }
            }
            lcs_go($back . 'results', $n ? 'success' : 'error', $n ? $n . ' certificate(s) issued' . ($skip ? ', ' . $skip . ' skipped (not passed or already issued).' : '.') : 'No certificate issued (only published Pass results without a certificate).');
        }
    } catch (RuntimeException $e) { lcs_go($back . $t, 'error', $e->getMessage()); }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'classes.php', 'Class ' . $k['code'], $manage ? '<a href="classes.php?edit=' . $id . '#form" class="btn btn-sm btn-light border"><i class="bi bi-pencil"></i> Edit class</a>' : '');
$ctabs = ['students' => 'Students', 'timetable' => 'Timetable', 'attendance' => 'Attendance', 'assessments' => 'Assessments & marks', 'results' => 'Results'];
$fmtPct = function ($v) { return $v === null ? '—' : number_format((float) $v, 1) . '%'; };
?>
<div class="small text-muted mb-2"><?= lc_e($k['name']); ?> · <?= lc_e($k['course_code'] . ' ' . $k['course_title']); ?> · <?= lc_e($k['intake_name'] ?? 'no intake'); ?> · <?= lc_e(lc_branch_name($conn, (int) $k['branch_id'] ?: null)); ?>
    <?= $k['classroom'] ? ' · ' . lc_e($k['classroom']) : ''; ?> · lead <?= lc_e($ins[(int) $k['instructor_id']]['name'] ?? '—'); ?> · <?= lcs_badge($k['status']); ?> · pass mark <?= (float) $k['pass_mark']; ?>%, attendance ≥ <?= (float) $k['attendance_min']; ?>%
    <?= $role === 'teach' ? ' · <span class="badge bg-info text-dark">Instructor view</span>' : ''; ?></div>
<ul class="nav nav-pills small mb-3"><?php foreach ($ctabs as $t => $l) { ?><li class="nav-item"><a class="nav-link py-1 <?= $tab === $t ? 'active' : ''; ?>" href="<?= $back . $t; ?>"><?= $l; ?></a></li><?php } ?></ul>
<?php
/* ================================================================== STUDENTS */
if ($tab === 'students') {
    $att = lc_class_attendance($conn, $id);
    $assign = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_class_instructors WHERE class_id = $id"), MYSQLI_ASSOC);
    ?>
<div class="row g-3"><div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Student ID</th><th>Student</th><th>Telephone</th><th class="text-end">Attendance</th><th>Enrollment</th><?= $manage ? '<th></th>' : ''; ?></tr>
    <?php foreach ($students as $cid => $s) { ?><tr><td class="ps-3 fw-semibold"><?= lc_e(lc_customer_id_label($conn, $cid)); ?></td><td><?= lc_e($s['name']); ?></td><td class="small"><?= lc_e($s['phone']); ?></td>
        <td class="text-end"><?= $fmtPct($att[$cid]['pct'] ?? null); ?></td><td><?= lcs_badge($s['status']); ?></td>
        <?php if ($manage) { ?><td class="text-end pe-2"><form method="POST" onsubmit="return confirm('Remove this student from the class?');"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="remove_student"><input type="hidden" name="enrollment_id" value="<?= (int) $s['enrollment_id']; ?>"><button class="btn btn-link btn-sm text-danger p-0">Remove</button></form></td><?php } ?></tr><?php } ?>
    <?php if (!$students) { ?><tr><td colspan="6" class="text-center text-muted py-4">No student in this class yet.</td></tr><?php } ?>
</table></div></div></div>
<div class="col-lg-4">
    <?php if ($manage) { $cand = lc_class_candidates($conn, $k); ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="add_students">
        <div class="fw-semibold mb-1">Add registered students</div><div class="small text-muted mb-2">Registered students of <?= lc_e($k['course_code']); ?><?= $k['intake_id'] ? ' (this intake)' : ''; ?> who are not in a class yet.</div>
        <?php foreach ($cand as $c) { ?><div class="form-check small"><input class="form-check-input" type="checkbox" name="enrollments[]" value="<?= (int) $c['enrollment_id']; ?>" id="e<?= (int) $c['enrollment_id']; ?>" checked><label class="form-check-label" for="e<?= (int) $c['enrollment_id']; ?>"><?= lc_e($c['name']); ?> <span class="text-muted"><?= lc_e(lc_customer_id_label($conn, (int) $c['customer_id'])); ?></span></label></div><?php } ?>
        <?php if ($cand) { ?><button class="btn btn-sm btn-primary mt-2">Add to the class</button><?php } else { ?><div class="small text-muted">None waiting.</div><?php } ?>
    </div></form>
    <?php } ?>
    <div class="card border-0 shadow-sm"><div class="card-body"><div class="fw-semibold mb-1">Instructors</div>
        <div class="small mb-1"><i class="bi bi-star-fill text-warning"></i> <?= lc_e($ins[(int) $k['instructor_id']]['name'] ?? 'No lead instructor'); ?> <span class="text-muted">(lead)</span></div>
        <?php foreach ($assign as $as) { ?><div class="small d-flex justify-content-between border-top py-1"><span><?= lc_e($ins[(int) $as['instructor_id']]['name'] ?? '?'); ?> <span class="text-muted"><?= (int) $as['module_id'] ? lc_e($mods[(int) $as['module_id']] ?? '') : 'whole class'; ?></span></span>
            <?php if ($manage) { ?><form method="POST"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="unassign"><input type="hidden" name="instructor_id" value="<?= (int) $as['instructor_id']; ?>"><input type="hidden" name="module_id" value="<?= (int) $as['module_id']; ?>"><button class="btn btn-link btn-sm p-0 text-danger">×</button></form><?php } ?></div><?php } ?>
        <?php if ($manage) { ?><form method="POST" class="mt-2 d-flex gap-1"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="assign">
            <select name="instructor_id" class="form-select form-select-sm"><option value="">Instructor…</option><?php foreach ($ins as $i => $r) { ?><option value="<?= $i; ?>"><?= lc_e($r['name']); ?></option><?php } ?></select>
            <select name="module_id" class="form-select form-select-sm"><option value="0">Whole class</option><?php foreach ($mods as $mi => $mn) { ?><option value="<?= $mi; ?>"><?= lc_e($mn); ?></option><?php } ?></select><button class="btn btn-sm btn-outline-primary">Add</button></form>
            <?php if (!$ins) { ?><div class="small text-muted mt-1">Add instructors first (Instructors tab).</div><?php } ?><?php } ?>
    </div></div>
</div></div>
<?php
/* ================================================================== TIMETABLE */
} elseif ($tab === 'timetable') {
    $sess = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_sessions WHERE class_id = $id ORDER BY session_date, start_time"), MYSQLI_ASSOC);
    $editS = null; if (!empty($_GET['edit'])) { foreach ($sess as $x) { if ((int) $x['id'] === (int) $_GET['edit']) { $editS = $x; } } }
    $hidden = lcs_csrf_field() . '<input type="hidden" name="id" value="' . $id . '"><input type="hidden" name="tab" value="timetable">';
    $fields = function (?array $v) use ($mods, $ins, $k) { ?>
        <div class="col-md-3"><label class="form-label small mb-0">Start</label><input type="time" name="start_time" class="form-control form-control-sm" value="<?= lc_e(substr((string) ($v['start_time'] ?? '08:30'), 0, 5)); ?>"></div>
        <div class="col-md-3"><label class="form-label small mb-0">End</label><input type="time" name="end_time" class="form-control form-control-sm" value="<?= lc_e(substr((string) ($v['end_time'] ?? '12:30'), 0, 5)); ?>"></div>
        <div class="col-md-6"><label class="form-label small mb-0">Classroom</label><input name="classroom" class="form-control form-control-sm" value="<?= lc_e($v['classroom'] ?? $k['classroom']); ?>"></div>
        <div class="col-md-6"><label class="form-label small mb-0">Module</label><select name="module_id" class="form-select form-select-sm"><option value="0">—</option><?php foreach ($mods as $mi => $mn) { ?><option value="<?= $mi; ?>" <?= (int) ($v['module_id'] ?? 0) === $mi ? 'selected' : ''; ?>><?= lc_e($mn); ?></option><?php } ?></select></div>
        <div class="col-md-6"><label class="form-label small mb-0">Instructor</label><select name="instructor_id" class="form-select form-select-sm"><option value="0">Lead instructor</option><?php foreach ($ins as $i => $r) { ?><option value="<?= $i; ?>" <?= (int) ($v['instructor_id'] ?? 0) === $i ? 'selected' : ''; ?>><?= lc_e($r['name']); ?></option><?php } ?></select></div>
        <div class="col-12"><input name="topic" class="form-control form-control-sm" placeholder="Topic (optional)" value="<?= lc_e($v['topic'] ?? ''); ?>"></div>
    <?php };
    ?>
<div class="row g-3"><div class="col-lg-8"><div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
    <tr class="table-light"><th class="ps-3">Date</th><th>Time</th><th>Module · topic</th><th>Classroom</th><th>Instructor</th><th>Status</th><th></th></tr>
    <?php foreach ($sess as $s) { $past = $s['session_date'] < date('Y-m-d'); ?><tr class="<?= $s['status'] === 'Cancelled' ? 'text-muted' : ''; ?>"><td class="ps-3 fw-semibold"><?= date('D d M Y', strtotime($s['session_date'])); ?></td>
        <td class="small"><?= lc_e(substr((string) $s['start_time'], 0, 5)); ?><?= $s['end_time'] ? '–' . lc_e(substr($s['end_time'], 0, 5)) : ''; ?></td><td class="small"><?= lc_e($mods[(int) $s['module_id']] ?? ''); ?><?= $s['topic'] ? ' · ' . lc_e($s['topic']) : ''; ?><?= $s['note'] ? '<div class="text-danger">' . lc_e($s['note']) . '</div>' : ''; ?></td>
        <td class="small"><?= lc_e($s['classroom']); ?></td><td class="small"><?= lc_e($ins[(int) $s['instructor_id']]['name'] ?? '—'); ?></td><td><?= lcs_badge($s['status']); ?></td>
        <td class="text-end pe-2 text-nowrap"><?php if ($s['status'] !== 'Cancelled' && $s['session_date'] <= date('Y-m-d') && $teach) { ?><a class="small" href="<?= $back; ?>attendance&session=<?= (int) $s['id']; ?>">Register</a><?php } ?>
            <?php if ($manage && $s['status'] === 'Scheduled') { ?> <a class="small ms-2" href="<?= $back; ?>timetable&edit=<?= (int) $s['id']; ?>">Change</a>
                <form method="POST" class="d-inline" onsubmit="var r=prompt('Reason of the cancellation (sent to students):');if(r===null)return false;this.note.value=r;return true;"><?= $hidden; ?><input type="hidden" name="do" value="session_cancel"><input type="hidden" name="session_id" value="<?= (int) $s['id']; ?>"><input type="hidden" name="note"><button class="btn btn-link btn-sm p-0 ms-2 text-danger">Cancel</button></form><?php } ?></td></tr><?php } ?>
    <?php if (!$sess) { ?><tr><td colspan="7" class="text-center text-muted py-4">No session yet.</td></tr><?php } ?>
</table></div></div></div>
<?php if ($manage) { ?><div class="col-lg-4">
    <?php if ($editS) { ?>
    <form method="POST" class="card border-0 shadow-sm mb-3 border-start border-warning border-3"><div class="card-body row g-2"><?= $hidden; ?><input type="hidden" name="do" value="session_edit"><input type="hidden" name="session_id" value="<?= (int) $editS['id']; ?>">
        <div class="col-12 fw-semibold">Change the session of <?= date('d M', strtotime($editS['session_date'])); ?></div>
        <div class="col-12"><label class="form-label small mb-0">Date</label><input type="date" name="session_date" class="form-control form-control-sm" value="<?= lc_e($editS['session_date']); ?>" required></div>
        <?php $fields($editS); ?><div class="col-12 small text-muted">If the date, time or room changes, students are informed by SMS and in the portal.</div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Save</button> <a href="<?= $back; ?>timetable" class="btn btn-sm btn-light border">Close</a></div></div></form>
    <?php } ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2"><?= $hidden; ?><input type="hidden" name="do" value="session_generate">
        <div class="col-12 fw-semibold">Weekly timetable</div>
        <div class="col-6"><label class="form-label small mb-0">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= lc_e($k['intake_start'] ?? ''); ?>" required></div>
        <div class="col-6"><label class="form-label small mb-0">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= lc_e($k['intake_end'] ?? ''); ?>" required></div>
        <div class="col-12 small"><?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $d => $l) { ?><label class="me-2"><input type="checkbox" name="weekdays[]" value="<?= $d; ?>"> <?= $l; ?></label><?php } ?></div>
        <?php $fields(null); ?>
        <div class="col-12 form-check small ms-2"><input class="form-check-input" type="checkbox" name="notify" value="1" id="nt"><label class="form-check-label" for="nt">Inform the students</label></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Create sessions</button></div></div></form>
    <form method="POST" class="card border-0 shadow-sm"><div class="card-body row g-2"><?= $hidden; ?><input type="hidden" name="do" value="session_add">
        <div class="col-12 fw-semibold">One session</div><div class="col-12"><input type="date" name="session_date" class="form-control form-control-sm" required></div>
        <?php $fields(null); ?><div class="col-12"><button class="btn btn-sm btn-outline-primary">Add session</button></div></div></form>
</div><?php } ?></div>
<?php
/* ================================================================== ATTENDANCE */
} elseif ($tab === 'attendance') {
    $sess = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_sessions WHERE class_id = $id AND status <> 'Cancelled' AND session_date <= '" . date('Y-m-d') . "' ORDER BY session_date DESC, start_time DESC"), MYSQLI_ASSOC);
    $sid = (int) ($_GET['session'] ?? ($sess[0]['id'] ?? 0));
    $cur = null; foreach ($sess as $x) { if ((int) $x['id'] === $sid) { $cur = $x; } }
    $map = lc_att_statuses($conn);
    $marks = []; if ($cur) { foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_attendance WHERE session_id = $sid"), MYSQLI_ASSOC) as $r) { $marks[(int) $r['customer_id']] = $r; } }
    $att = lc_class_attendance($conn, $id);
    $locked = $k['results_published_at'] && !$manage;
    ?>
<div class="row g-3"><div class="col-lg-8">
    <?php if (!$sess) { ?><div class="card border-0 shadow-sm"><div class="card-body text-muted small">No session today or before. Create the timetable first (Timetable tab).</div></div>
    <?php } else { ?>
    <form class="d-flex gap-2 mb-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="tab" value="attendance"><select name="session" class="form-select form-select-sm" onchange="this.form.submit()">
        <?php foreach ($sess as $x) { ?><option value="<?= (int) $x['id']; ?>" <?= (int) $x['id'] === $sid ? 'selected' : ''; ?>><?= date('D d M Y', strtotime($x['session_date'])) . ' ' . substr((string) $x['start_time'], 0, 5) . ' · ' . lc_e($mods[(int) $x['module_id']] ?? ($x['topic'] ?: 'Session')) . ($x['status'] === 'Held' ? ' ✓' : ''); ?></option><?php } ?></select></form>
    <?php if ($cur) { ?>
    <form method="POST" class="card border-0 shadow-sm"><div class="card-body p-0"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="tab" value="attendance"><input type="hidden" name="do" value="attendance"><input type="hidden" name="session_id" value="<?= $sid; ?>">
        <table class="table table-sm align-middle mb-0" style="font-size:13px;"><tr class="table-light"><th class="ps-3">Student</th><th>Status</th><th>Note</th></tr>
        <?php foreach ($students as $cid => $s) { $cv = $marks[$cid]['status'] ?? array_key_first($map); ?><tr><td class="ps-3"><?= lc_e($s['name']); ?> <span class="small text-muted"><?= lc_e(lc_customer_id_label($conn, $cid)); ?></span></td>
            <td class="text-nowrap"><?php foreach ($map as $st => $kind) { ?><label class="me-2 small"><input type="radio" name="att[<?= $cid; ?>]" value="<?= lc_e($st); ?>" <?= $cv === $st ? 'checked' : ''; ?> <?= $locked ? 'disabled' : ''; ?>> <?= lc_e($st); ?></label><?php } ?></td>
            <td><input name="note[<?= $cid; ?>]" class="form-control form-control-sm" value="<?= lc_e($marks[$cid]['note'] ?? ''); ?>" <?= $locked ? 'disabled' : ''; ?>></td></tr><?php } ?>
        <?php if (!$students) { ?><tr><td colspan="3" class="text-muted text-center py-3">No student in the class.</td></tr><?php } ?></table>
        <div class="p-2 d-flex justify-content-between align-items-center"><span class="small text-muted"><?= $cur['status'] === 'Held' ? 'Recorded — saving again updates it (kept in the audit trail).' : 'Not recorded yet.'; ?></span>
            <?php if ($teach && !$locked && $students) { ?><button class="btn btn-sm btn-primary">Save attendance</button><?php } elseif ($locked) { ?><span class="small text-muted">Results published: locked.</span><?php } ?></div>
    </div></form>
    <?php } } ?>
</div>
<div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-body"><div class="fw-semibold mb-2">Attendance so far</div>
    <?php foreach ($students as $cid => $s) { $a = $att[$cid]; $low = $a['pct'] !== null && $a['pct'] < (float) $k['attendance_min']; ?>
    <div class="d-flex justify-content-between small border-bottom py-1"><span><?= lc_e($s['name']); ?></span><span class="<?= $low ? 'text-danger fw-semibold' : ''; ?>"><?= $fmtPct($a['pct']); ?> <span class="text-muted">(<?= $a['present']; ?>/<?= $a['held'] - $a['excused']; ?>)</span></span></div><?php } ?>
    <div class="small text-muted mt-2">% = present ÷ (sessions held − excused). Statuses and how they count: Settings. Students under <?= (float) $k['attendance_min']; ?>% after <?= (int) lc_setting($conn, 'att_alert_min_sessions'); ?> sessions get an alert (once a week at most).</div>
</div></div></div></div>
<?php
/* ================================================================== ASSESSMENTS */
} elseif ($tab === 'assessments') {
    $as = lc_class_assessments($conn, $id);
    $sc = lc_class_scores($conn, $id);
    $aid = (int) ($_GET['a'] ?? 0); $cur = $as[$aid] ?? null;
    $editA = !empty($_GET['edit']) ? ($as[(int) $_GET['edit']] ?? null) : null;
    $wt = array_sum(array_map(function ($a) { return (float) $a['weight']; }, $as));
    $locked = $k['results_published_at'] && !$manage;
    ?>
<div class="row g-3"><div class="col-lg-8">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
        <tr class="table-light"><th class="ps-3">Assessment</th><th>Type</th><th>Module</th><th>Date</th><th class="text-end">Out of</th><th class="text-end">Weight</th><th class="text-end">Marked</th><th></th></tr>
        <?php foreach ($as as $i => $a) { ?><tr class="<?= $i === $aid ? 'table-primary' : ''; ?>"><td class="ps-3 fw-semibold"><?= lc_e($a['name']); ?><?= $a['required'] ? '' : ' <span class="small text-muted">(optional)</span>'; ?></td><td class="small"><?= lc_e($a['type']); ?></td>
            <td class="small"><?= lc_e($a['module_name'] ?? '—'); ?></td><td class="small"><?= $a['due_date'] ? date('d M Y', strtotime($a['due_date'])) : '—'; ?></td><td class="text-end"><?= (float) $a['max_score']; ?></td><td class="text-end"><?= (float) $a['weight']; ?>%</td>
            <td class="text-end"><?= count($sc[$i] ?? []); ?>/<?= count($students); ?></td><td class="text-end pe-2 text-nowrap"><a class="small" href="<?= $back; ?>assessments&a=<?= $i; ?>">Marks</a><?php if ($manage) { ?> <a class="small ms-2" href="<?= $back; ?>assessments&edit=<?= $i; ?>">Edit</a><?php } ?></td></tr><?php } ?>
        <?php if (!$as) { ?><tr><td colspan="8" class="text-center text-muted py-4">No assessment yet.</td></tr><?php } ?>
        <?php if ($as) { ?><tr class="table-light"><td colspan="5" class="ps-3 small">Total weight</td><td class="text-end fw-semibold <?= abs($wt - 100) > 0.01 && $wt > 0 ? 'text-danger' : ''; ?>"><?= $wt; ?>%</td><td colspan="2" class="small text-muted"><?= $wt <= 0 ? 'all weights 0: assessments count equally' : (abs($wt - 100) > 0.01 ? 'should be 100%' : ''); ?></td></tr><?php } ?>
    </table></div></div>
    <?php if ($cur) { ?>
    <form method="POST" class="card border-0 shadow-sm"><div class="card-body p-0"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="tab" value="assessments"><input type="hidden" name="do" value="scores"><input type="hidden" name="assessment_id" value="<?= $aid; ?>">
        <div class="p-2 fw-semibold">Marks: <?= lc_e($cur['name']); ?> <span class="small text-muted">(out of <?= (float) $cur['max_score']; ?>)</span></div>
        <table class="table table-sm align-middle mb-0" style="font-size:13px;"><tr class="table-light"><th class="ps-3">Student</th><th style="width:120px">Mark</th><th>Absent</th><th>Note</th><th class="text-end pe-3">%</th></tr>
        <?php foreach ($students as $cid => $s) { $r = $sc[$aid][$cid] ?? null; ?><tr><td class="ps-3"><?= lc_e($s['name']); ?></td>
            <td><input name="score[<?= $cid; ?>]" class="form-control form-control-sm" inputmode="decimal" value="<?= $r && $r['score'] !== null ? (float) $r['score'] : ''; ?>" <?= $locked ? 'disabled' : ''; ?>></td>
            <td><input type="checkbox" class="form-check-input" name="absent[<?= $cid; ?>]" value="1" <?= $r && $r['absent'] ? 'checked' : ''; ?> <?= $locked ? 'disabled' : ''; ?>></td>
            <td><input name="note[<?= $cid; ?>]" class="form-control form-control-sm" value="<?= lc_e($r['note'] ?? ''); ?>" <?= $locked ? 'disabled' : ''; ?>></td>
            <td class="text-end pe-3 small"><?= $r ? ($r['absent'] ? '0% (absent)' : number_format((float) $r['score'] / (float) $cur['max_score'] * 100, 1) . '%') : '—'; ?></td></tr><?php } ?></table>
        <?php if ($k['results_published_at'] && $manage) { ?><div class="p-2"><input name="reason" class="form-control form-control-sm" placeholder="Results are published: reason of the correction (required, kept in the audit trail)"></div><?php } ?>
        <div class="p-2 text-end"><?php if ($teach && !$locked && $students) { ?><button class="btn btn-sm btn-primary">Save marks</button><?php } elseif ($locked) { ?><span class="small text-muted">Results published: marks are locked.</span><?php } ?></div>
    </div></form>
    <?php } ?>
</div>
<?php if ($manage) { $v = $editA ?: ['id' => 0, 'name' => '', 'type' => '', 'module_id' => null, 'max_score' => 100, 'weight' => '', 'due_date' => '', 'required' => 1]; ?>
<div class="col-lg-4"><form method="POST" class="card border-0 shadow-sm"><div class="card-body row g-2"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="tab" value="assessments"><input type="hidden" name="do" value="assessment"><input type="hidden" name="assessment_id" value="<?= (int) $v['id']; ?>">
    <div class="col-12 fw-semibold"><?= $editA ? 'Edit assessment' : 'New assessment'; ?></div>
    <div class="col-12"><input name="name" class="form-control form-control-sm" placeholder="Name (e.g. Project 1)" value="<?= lc_e($v['name']); ?>" required></div>
    <div class="col-12"><select name="type" class="form-select form-select-sm" required><option value="">Type…</option><?php foreach (lc_assessment_types($conn) as $ty) { ?><option <?= $v['type'] === $ty ? 'selected' : ''; ?>><?= lc_e($ty); ?></option><?php } ?></select></div>
    <div class="col-12"><select name="module_id" class="form-select form-select-sm"><option value="0">Whole course (no module)</option><?php foreach ($mods as $mi => $mn) { ?><option value="<?= $mi; ?>" <?= (int) $v['module_id'] === $mi ? 'selected' : ''; ?>><?= lc_e($mn); ?></option><?php } ?></select></div>
    <div class="col-4"><label class="form-label small mb-0">Out of</label><input type="number" step="0.5" min="1" name="max_score" class="form-control form-control-sm" value="<?= (float) $v['max_score']; ?>"></div>
    <div class="col-4"><label class="form-label small mb-0">Weight %</label><input type="number" step="0.5" min="0" max="100" name="weight" class="form-control form-control-sm" value="<?= lc_e($v['weight']); ?>"></div>
    <div class="col-4"><label class="form-label small mb-0">Date</label><input type="date" name="due_date" class="form-control form-control-sm" value="<?= lc_e($v['due_date']); ?>"></div>
    <div class="col-12 form-check small ms-2"><input class="form-check-input" type="checkbox" name="required" value="1" id="rq" <?= $v['required'] ? 'checked' : ''; ?>><label class="form-check-label" for="rq">Required (missing mark = result Incomplete)</label></div>
    <div class="col-12 small text-muted">Examination types with a date are sent to the students (SMS and portal).</div>
    <div class="col-12"><button class="btn btn-sm btn-primary">Save</button> <?php if ($editA) { ?><a href="<?= $back; ?>assessments" class="btn btn-sm btn-light border">Close</a><?php } ?></div>
</div></form>
<?php if ($editA && empty($sc[(int) $editA['id']])) { ?><form method="POST" class="mt-2" onsubmit="return confirm('Delete this assessment?');"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="tab" value="assessments"><input type="hidden" name="do" value="assessment_delete"><input type="hidden" name="assessment_id" value="<?= (int) $editA['id']; ?>"><button class="btn btn-sm btn-link text-danger p-0">Delete this assessment</button></form><?php } ?>
</div><?php } ?></div>
<?php
/* ================================================================== RESULTS */
} else {
    $res = lc_compute_results($conn, $k);
    $as = lc_class_assessments($conn, $id);
    $pubAt = $k['results_published_at'];
    $pub = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_results WHERE class_id = $id"), MYSQLI_ASSOC) as $r) { $pub[(int) $r['customer_id']] = $r; }
    $certs = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM lc_certificates WHERE class_id = $id AND status = 'Valid'"), MYSQLI_ASSOC) as $c) { $certs[(int) $c['customer_id']] = $c; }
    $cnt = array_count_values(array_column($res, 'decision'));
    ?>
<div class="row g-2 mb-3">
    <?php foreach (['Students' => count($res), 'Pass' => $cnt['Pass'] ?? 0, 'Fail' => $cnt['Fail'] ?? 0, 'Incomplete' => $cnt['Incomplete'] ?? 0, 'Pass rate' => ($cnt['Pass'] ?? 0) + ($cnt['Fail'] ?? 0) ? round(($cnt['Pass'] ?? 0) / (($cnt['Pass'] ?? 0) + ($cnt['Fail'] ?? 0)) * 100) . '%' : '—'] as $l => $v) { ?>
    <div class="col"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl"><?= $l; ?></div><div class="val"><?= $v; ?></div></div></div></div><?php } ?>
</div>
<div class="alert <?= $pubAt ? 'alert-success' : 'alert-secondary'; ?> py-2 small"><?= $pubAt ? 'Results published on ' . lc_e($pubAt) . '. The table shows the current calculation; students see the published results. After a correction, publish again.' : 'Provisional: calculated from the marks and attendance recorded so far. Students do not see results until they are published.'; ?></div>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3">Student</th><?php foreach ($as as $a) { ?><th class="text-end" title="<?= lc_e($a['type']); ?>"><?= lc_e(mb_strimwidth($a['name'], 0, 14, '…')); ?><div class="text-muted fw-normal"><?= (float) $a['weight']; ?>%</div></th><?php } ?>
        <th class="text-end">Final</th><th>Grade</th><th class="text-end">Attend.</th><th>Result</th><th>Progress</th><?= $pubAt ? '<th>Published</th><th>Certificate</th>' : ''; ?></tr>
    <?php foreach ($res as $cid => $r) { $p = $pub[$cid] ?? null; ?><tr><td class="ps-3"><?= lc_e($r['name']); ?><div class="small text-muted"><?= lc_e(lc_customer_id_label($conn, $cid)); ?></div></td>
        <?php foreach ($as as $ai => $a) { ?><td class="text-end"><?= $fmtPct($r['per'][$ai] ?? null); ?></td><?php } ?>
        <td class="text-end fw-semibold"><?= $fmtPct($r['final']); ?></td><td><?= lc_e($r['grade'] ?? '—'); ?></td><td class="text-end"><?= $fmtPct($r['attendance_pct']); ?></td>
        <td><?= lcs_badge($r['decision']); ?><?= $r['reason'] ? '<div class="small text-muted" style="max-width:200px">' . lc_e($r['reason']) . '</div>' : ''; ?></td>
        <td class="small text-muted">marks <?= $r['progress']['assessments']; ?>% · sessions <?= $r['progress']['sessions']; ?>%</td>
        <?php if ($pubAt) { ?><td><?= $p ? lcs_badge($p['decision']) . ' ' . $fmtPct($p['final_pct']) . ($p['decision'] !== $r['decision'] || (float) $p['final_pct'] !== (float) $r['final'] ? ' <span class="badge bg-warning text-dark">changed</span>' : '') : '—'; ?></td>
            <td class="small"><?= isset($certs[$cid]) ? '<a href="certificates.php?q=' . rawurlencode($certs[$cid]['number']) . '">' . lc_e($certs[$cid]['number']) . '</a>' : '—'; ?></td><?php } ?></tr><?php } ?>
    <?php if (!$res) { ?><tr><td colspan="<?= 8 + count($as); ?>" class="text-center text-muted py-4">No student in this class.</td></tr><?php } ?>
</table></div></div></div>
<div class="d-flex flex-wrap gap-2 align-items-start">
<?php if ($manage && $res) { ?>
    <form method="POST" class="card border-0 shadow-sm"><div class="card-body py-2"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="tab" value="results"><input type="hidden" name="do" value="publish">
        <div class="form-check small"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="pc"><label class="form-check-label" for="pc">I checked the marks and attendance. Publish the results to the students.</label></div>
        <button class="btn btn-sm btn-success mt-1"><?= $pubAt ? 'Publish again (correction)' : 'Publish results'; ?></button></div></form>
    <?php $eligible = array_filter($pub, function ($p) use ($certs) { return $p['decision'] === 'Pass' && !isset($certs[(int) $p['customer_id']]); }); if ($eligible) { ?>
    <form method="POST" class="card border-0 shadow-sm"><div class="card-body py-2"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="tab" value="results"><input type="hidden" name="do" value="certificates">
        <?php foreach ($eligible as $p) { ?><input type="hidden" name="customers[]" value="<?= (int) $p['customer_id']; ?>"><?php } ?>
        <div class="small mb-1"><?= count($eligible); ?> student(s) passed and have no certificate yet.</div><button class="btn btn-sm btn-primary" onclick="return confirm('Issue the certificates now? Students are informed.');"><i class="bi bi-patch-check"></i> Issue certificates</button></div></form>
    <?php } ?>
<?php } ?>
</div>
<div class="small text-muted mt-2">Final = Σ(weight × mark %) ÷ Σ weights (absent = 0). Pass = final ≥ <?= (float) $k['pass_mark']; ?>% and attendance ≥ <?= (float) $k['attendance_min']; ?>%. A required assessment without a mark = Incomplete. Grades: <?= lc_e(implode(', ', array_map(function ($g) { return $g[1] . ' ≥ ' . $g[0] . '%'; }, lc_grade_scale($conn)))); ?>.</div>
<?php }
include __DIR__ . '/../../includes/footer.php';