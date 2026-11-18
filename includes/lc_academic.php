<?php
/**
 * NEW FILE: includes/lc_academic.php — RM Learning Centre ACADEMIC OPERATIONS (stage 2), shared by the staff workspace,
 * the Customer Portal and the public certificate verification page.
 * Academic years, classes (cohorts) with classroom and instructors, timetable sessions, attendance with configurable
 * statuses, weighted assessments and grading scale, module and course results, pass / fail with attendance requirement,
 * published results, transcripts (provisional → final) and certificates with unique number, QR verification,
 * revocation and reissue. Instructors are existing MY MOTIVE users (no duplicate employee records) or external
 * instructors (no employee record at all).
 */
require_once __DIR__ . '/lc_helpers.php';

const LC_ACAD_SCHEMA = '1';
const LC_CLASS_STATUSES = ['Planned', 'Running', 'Completed', 'Cancelled'];

function lc_acad_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    lc_ensure($conn);
    if (lc_setting($conn, 'acad_schema') === LC_ACAD_SCHEMA) { return; }
    $q = function (string $sql) use ($conn) { try { mysqli_query($conn, $sql); } catch (Throwable $e) { error_log('lc_acad_ensure: ' . $e->getMessage()); } };
    $q('CREATE TABLE IF NOT EXISTS lc_years (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(40) NOT NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
        is_current TINYINT(1) NOT NULL DEFAULT 0, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_instructors (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NULL, name VARCHAR(150) NOT NULL, phone VARCHAR(30) NULL,
        email VARCHAR(150) NULL, kind VARCHAR(10) NOT NULL DEFAULT \'internal\', specialty VARCHAR(200) NULL, status VARCHAR(10) NOT NULL DEFAULT \'Active\',
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY user (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_classes (id INT(11) NOT NULL AUTO_INCREMENT, code VARCHAR(40) NOT NULL, name VARCHAR(120) NOT NULL, course_id INT(11) NOT NULL,
        intake_id INT(11) NULL, branch_id INT(11) NULL, year_id INT(11) NULL, classroom VARCHAR(80) NULL, instructor_id INT(11) NULL, capacity INT(11) NULL,
        status VARCHAR(12) NOT NULL DEFAULT \'Planned\', results_published_at DATETIME NULL, results_published_by INT(11) NULL, created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY code (code), KEY course (course_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_class_instructors (class_id INT(11) NOT NULL, instructor_id INT(11) NOT NULL, module_id INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (class_id, instructor_id, module_id)) ENGINE=InnoDB');
    $q('CREATE TABLE IF NOT EXISTS lc_sessions (id INT(11) NOT NULL AUTO_INCREMENT, class_id INT(11) NOT NULL, module_id INT(11) NULL, session_date DATE NOT NULL,
        start_time TIME NULL, end_time TIME NULL, classroom VARCHAR(80) NULL, instructor_id INT(11) NULL, topic VARCHAR(200) NULL, status VARCHAR(12) NOT NULL DEFAULT \'Scheduled\',
        note VARCHAR(300) NULL, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY class (class_id, session_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_attendance (session_id INT(11) NOT NULL, customer_id INT(11) NOT NULL, status VARCHAR(20) NOT NULL, note VARCHAR(200) NULL,
        recorded_by INT(11) NULL, recorded_at DATETIME NULL, PRIMARY KEY (session_id, customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_assessments (id INT(11) NOT NULL AUTO_INCREMENT, class_id INT(11) NOT NULL, module_id INT(11) NULL, name VARCHAR(120) NOT NULL,
        type VARCHAR(60) NOT NULL, max_score DECIMAL(7,2) NOT NULL DEFAULT 100, weight DECIMAL(6,2) NOT NULL DEFAULT 0, due_date DATE NULL, required TINYINT(1) NOT NULL DEFAULT 1,
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY class (class_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_scores (assessment_id INT(11) NOT NULL, customer_id INT(11) NOT NULL, score DECIMAL(7,2) NULL, absent TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(200) NULL, recorded_by INT(11) NULL, recorded_at DATETIME NULL, PRIMARY KEY (assessment_id, customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_results (class_id INT(11) NOT NULL, customer_id INT(11) NOT NULL, enrollment_id INT(11) NOT NULL, final_pct DECIMAL(6,2) NULL,
        grade VARCHAR(10) NULL, attendance_pct DECIMAL(6,2) NULL, decision VARCHAR(12) NOT NULL, reason VARCHAR(300) NULL, modules TEXT NULL,
        published_at DATETIME NOT NULL, published_by INT(11) NULL, PRIMARY KEY (class_id, customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_certificates (id INT(11) NOT NULL AUTO_INCREMENT, number VARCHAR(40) NOT NULL, token VARCHAR(32) NOT NULL, customer_id INT(11) NOT NULL,
        enrollment_id INT(11) NOT NULL, class_id INT(11) NOT NULL, course_id INT(11) NOT NULL, student_name VARCHAR(150) NOT NULL, course_title VARCHAR(160) NOT NULL,
        grade VARCHAR(10) NULL, completed_on DATE NULL, issued_at DATETIME NOT NULL, issued_by INT(11) NULL, status VARCHAR(10) NOT NULL DEFAULT \'Valid\',
        revoked_at DATETIME NULL, revoked_by INT(11) NULL, reason VARCHAR(300) NULL, replaces_id INT(11) NULL, replaced_by_id INT(11) NULL,
        PRIMARY KEY (id), UNIQUE KEY number (number), KEY customer (customer_id), KEY enrollment (enrollment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_alerts (class_id INT(11) NOT NULL, customer_id INT(11) NOT NULL, kind VARCHAR(20) NOT NULL, sent_at DATETIME NOT NULL,
        PRIMARY KEY (class_id, customer_id, kind)) ENGINE=InnoDB');
    // Enrollment → class (one class per enrollment).
    $has = false;
    try { $has = (bool) mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM lc_enrollments LIKE 'class_id'")); } catch (Throwable $e) { }
    if (!$has) { $q('ALTER TABLE lc_enrollments ADD COLUMN class_id INT(11) NULL'); }
    lc_setting_set($conn, 'acad_schema', LC_ACAD_SCHEMA);
}

/* ------------------------------------------------------------------ settings */

/** Attendance statuses: name => 'present' | 'absent' | 'excused' (excused sessions are not counted). */
function lc_att_statuses(mysqli $conn): array
{
    $o = [];
    foreach (lc_lines(lc_setting($conn, 'att_statuses')) as $l) {
        [$n, $k] = array_pad(array_map('trim', explode('=', $l, 2)), 2, 'present');
        $k = strtolower($k);
        if ($n !== '') { $o[$n] = in_array($k, ['present', 'absent', 'excused'], true) ? $k : 'present'; }
    }
    return $o ?: ['Present' => 'present', 'Late' => 'present', 'Excused' => 'excused', 'Absent' => 'absent'];
}

/** Grading scale: minimum % => grade, highest first. */
function lc_grade_scale(mysqli $conn): array
{
    $o = [];
    foreach (lc_lines(lc_setting($conn, 'grade_scale')) as $l) { [$min, $g] = array_pad(array_map('trim', explode('=', $l, 2)), 2, ''); if ($g !== '' && is_numeric($min)) { $o[] = [(float) $min, $g]; } }
    usort($o, function ($a, $b) { return $b[0] <=> $a[0]; });
    return $o ?: [[80, 'A'], [70, 'B'], [60, 'C'], [50, 'D'], [0, 'F']];
}

function lc_grade(mysqli $conn, ?float $pct): ?string
{
    if ($pct === null) { return null; }
    foreach (lc_grade_scale($conn) as [$min, $g]) { if ($pct + 1e-9 >= $min) { return $g; } }
    return null;
}

function lc_assessment_types(mysqli $conn): array { return lc_lines(lc_setting($conn, 'assessment_types')) ?: ['Assignment', 'Final examination']; }

/* ------------------------------------------------------------------ instructors */

function lc_instructors(mysqli $conn, bool $activeOnly = true): array
{
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_instructors' . ($activeOnly ? " WHERE status = 'Active'" : '') . ' ORDER BY name'), MYSQLI_ASSOC) as $r) { $o[(int) $r['id']] = $r; }
    return $o;
}

/** The instructor record of the signed-in user (null if the user is not an instructor). */
function lc_my_instructor_id(mysqli $conn): ?int
{
    $uid = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
    if (!$uid) { return null; }
    $r = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM lc_instructors WHERE user_id = $uid AND status = 'Active'"));
    return $r ? (int) $r[0] : null;
}

/** Class ids the instructor teaches (lead instructor, assigned to the class or a module, or to a session). */
function lc_instructor_class_ids(mysqli $conn, int $instructorId): array
{
    $ids = [];
    foreach ([
        "SELECT id FROM lc_classes WHERE instructor_id = $instructorId",
        "SELECT class_id FROM lc_class_instructors WHERE instructor_id = $instructorId",
        "SELECT DISTINCT class_id FROM lc_sessions WHERE instructor_id = $instructorId",
    ] as $sql) { foreach (mysqli_fetch_all(mysqli_query($conn, $sql)) as $r) { $ids[(int) $r[0]] = true; } }
    return array_keys($ids);
}

/* ------------------------------------------------------------------ classes */

function lc_class(mysqli $conn, int $id): ?array
{
    $r = mysqli_query($conn, 'SELECT k.*, c.code course_code, c.title course_title, c.pass_mark, c.attendance_min, c.certificate_type, i.name intake_name, i.start_date intake_start, i.end_date intake_end, y.name year_name
        FROM lc_classes k JOIN lc_courses c ON c.id = k.course_id LEFT JOIN lc_intakes i ON i.id = k.intake_id LEFT JOIN lc_years y ON y.id = k.year_id WHERE k.id = ' . (int) $id);
    return mysqli_fetch_assoc($r) ?: null;
}

/** Class code from the course code and the intake / start month: WDF-001-JAN27-A. */
function lc_class_code(mysqli $conn, array $course, ?array $intake): string
{
    $base = $course['code'] . '-' . strtoupper(date('My', strtotime($intake['start_date'] ?? 'now')));
    foreach (range('A', 'Z') as $l) {
        $code = $base . '-' . $l;
        if (!(int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_classes WHERE code = '" . mysqli_real_escape_string($conn, $code) . "'"))[0]) { return $code; }
    }
    return $base . '-' . lc_next_seq($conn, 'class_' . $base);
}

/** Students of a class: their enrollment, Student ID, name, phone. */
function lc_class_students(mysqli $conn, int $classId): array
{
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT e.id enrollment_id, e.customer_id, e.status, e.modules, cu.name, cu.phone FROM lc_enrollments e JOIN customers cu ON cu.id = e.customer_id
        WHERE e.class_id = $classId ORDER BY cu.name"), MYSQLI_ASSOC) as $r) { $o[(int) $r['customer_id']] = $r; }
    return $o;
}

/** Registered students of the class's course (and intake) not yet in any class. */
function lc_class_candidates(mysqli $conn, array $class): array
{
    $w = 'e.course_id = ' . (int) $class['course_id'] . ' AND e.class_id IS NULL';
    if ($class['intake_id']) { $w .= ' AND (e.intake_id = ' . (int) $class['intake_id'] . ' OR e.intake_id IS NULL)'; }
    return mysqli_fetch_all(mysqli_query($conn, "SELECT e.id enrollment_id, e.customer_id, e.cohort, cu.name, cu.phone FROM lc_enrollments e JOIN customers cu ON cu.id = e.customer_id WHERE $w AND e.status = 'Registered' ORDER BY cu.name"), MYSQLI_ASSOC);
}

/* ------------------------------------------------------------------ attendance */

/**
 * Attendance per student of a class: [customer_id => [held, present, absent, excused, pct]].
 * Held session = status Held (set when attendance is recorded). A student without a mark on a held session counts as absent.
 * pct = present / (held - excused) × 100; null when nothing counts yet.
 */
function lc_class_attendance(mysqli $conn, int $classId): array
{
    $map = lc_att_statuses($conn);
    $students = lc_class_students($conn, $classId);
    $held = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM lc_sessions WHERE class_id = $classId AND status = 'Held'"), MYSQLI_ASSOC), 'id');
    $marks = [];
    if ($held) {
        foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT session_id, customer_id, status FROM lc_attendance WHERE session_id IN (' . implode(',', array_map('intval', $held)) . ')'), MYSQLI_ASSOC) as $r) {
            $marks[(int) $r['customer_id']][(int) $r['session_id']] = $r['status'];
        }
    }
    $o = [];
    foreach ($students as $cid => $s) {
        $p = 0; $a = 0; $x = 0;
        foreach ($held as $sid) {
            $st = $marks[$cid][(int) $sid] ?? null;
            $k = $st === null ? 'absent' : ($map[$st] ?? 'present');
            if ($k === 'present') { $p++; } elseif ($k === 'excused') { $x++; } else { $a++; }
        }
        $den = count($held) - $x;
        $o[$cid] = ['held' => count($held), 'present' => $p, 'absent' => $a, 'excused' => $x, 'pct' => $den > 0 ? round($p / $den * 100, 1) : null];
    }
    return $o;
}

/* ------------------------------------------------------------------ assessments and results */

function lc_class_assessments(mysqli $conn, int $classId): array
{
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, m.name module_name FROM lc_assessments a LEFT JOIN lc_modules m ON m.id = a.module_id WHERE a.class_id = $classId ORDER BY COALESCE(a.due_date, '9999-12-31'), a.id"), MYSQLI_ASSOC) as $r) { $o[(int) $r['id']] = $r; }
    return $o;
}

/** Scores of a class: [assessment_id][customer_id] => row. */
function lc_class_scores(mysqli $conn, int $classId): array
{
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT s.* FROM lc_scores s JOIN lc_assessments a ON a.id = s.assessment_id WHERE a.class_id = $classId"), MYSQLI_ASSOC) as $r) {
        $o[(int) $r['assessment_id']][(int) $r['customer_id']] = $r;
    }
    return $o;
}

/**
 * Calculates every student's results (nothing is saved): per assessment %, per module %, overall weighted %, grade,
 * attendance %, decision (Pass / Fail / Incomplete) with the reason, and academic progress.
 * Weights: each assessment's weight; if all weights are 0, assessments count equally. Absent = 0.
 * A required assessment without a score makes the result Incomplete.
 */
function lc_compute_results(mysqli $conn, array $class): array
{
    $cid = (int) $class['id'];
    $as = lc_class_assessments($conn, $cid);
    $sc = lc_class_scores($conn, $cid);
    $att = lc_class_attendance($conn, $cid);
    $mods = [];
    foreach (lc_modules($conn, (int) $class['course_id']) as $m) { $mods[(int) $m['id']] = $m['name']; }
    $equal = array_sum(array_map(function ($a) { return (float) $a['weight']; }, $as)) <= 0;
    $sessions = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_sessions WHERE class_id = $cid AND status <> 'Cancelled'"))[0];
    $out = [];
    foreach (lc_class_students($conn, $cid) as $sid => $st) {
        $per = []; $wSum = 0; $wTot = 0; $missing = []; $byMod = []; $done = 0;
        foreach ($as as $aid => $a) {
            $row = $sc[$aid][$sid] ?? null;
            $w = $equal ? 1.0 : (float) $a['weight'];
            $pct = null;
            if ($row && (int) $row['absent'] === 1) { $pct = 0.0; $done++; }
            elseif ($row && $row['score'] !== null && (float) $a['max_score'] > 0) { $pct = min(100, max(0, (float) $row['score'] / (float) $a['max_score'] * 100)); $done++; }
            $per[$aid] = $pct;
            if ($pct === null) { if ((int) $a['required'] === 1) { $missing[] = $a['name']; } $pct = 0.0; }
            $wSum += $w * $pct; $wTot += $w;
            $mk = (int) $a['module_id'];
            $byMod[$mk]['s'] = ($byMod[$mk]['s'] ?? 0) + $w * $pct; $byMod[$mk]['w'] = ($byMod[$mk]['w'] ?? 0) + $w;
        }
        $final = $wTot > 0 ? round($wSum / $wTot, 2) : null;
        $modRes = [];
        foreach (array_keys($mods) as $mk) { $v = $byMod[$mk] ?? null; if ($v && $v['w'] > 0) { $p = round($v['s'] / $v['w'], 2); $modRes[] = ['module' => $mods[$mk] ?? 'Module', 'pct' => $p, 'grade' => lc_grade($conn, $p)]; } }
        $ap = $att[$sid]['pct'] ?? null;
        $reasons = [];
        if (!$as) { $decision = 'Incomplete'; $reasons[] = 'No assessment yet.'; }
        elseif ($missing) { $decision = 'Incomplete'; $reasons[] = 'Missing: ' . implode(', ', $missing) . '.'; }
        else {
            $okScore = $final !== null && $final + 1e-9 >= (float) $class['pass_mark'];
            $okAtt = $ap === null || $ap + 1e-9 >= (float) $class['attendance_min'];
            if (!$okScore) { $reasons[] = 'Final mark below the pass mark (' . (float) $class['pass_mark'] . '%).'; }
            if (!$okAtt) { $reasons[] = 'Attendance below the minimum (' . (float) $class['attendance_min'] . '%).'; }
            $decision = $okScore && $okAtt ? 'Pass' : 'Fail';
        }
        $out[$sid] = $st + ['per' => $per, 'final' => $final, 'grade' => lc_grade($conn, $final), 'attendance' => $att[$sid] ?? null, 'attendance_pct' => $ap,
            'decision' => $decision, 'reason' => implode(' ', $reasons), 'modules_res' => $modRes,
            'progress' => ['assessments' => $as ? round($done / count($as) * 100) : 0, 'sessions' => $sessions ? round(($att[$sid]['held'] ?? 0) / $sessions * 100) : 0]];
    }
    return $out;
}

/** Published (final) result of a student in a class, or null. */
function lc_published_result(mysqli $conn, int $classId, int $customerId): ?array
{
    return mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_results WHERE class_id = $classId AND customer_id = $customerId")) ?: null;
}

/**
 * Publishes (or re-publishes) the results of a class: final snapshot per student, enrollment Completed / Failed,
 * students notified. Re-publishing after a correction keeps the audit trail of what changed.
 */
function lc_publish_results(mysqli $conn, array $class): array
{
    $cid = (int) $class['id']; $uid = (int) current_user_id(); $now = date('Y-m-d H:i:s');
    $res = lc_compute_results($conn, $class);
    $changed = []; $n = 0;
    foreach ($res as $sid => $r) {
        $old = lc_published_result($conn, $cid, $sid);
        $mods = json_encode($r['modules_res'], JSON_UNESCAPED_UNICODE);
        $vals = [$cid, $sid, (int) $r['enrollment_id'], $r['final'], $r['grade'], $r['attendance_pct'], $r['decision'], $r['reason'] ?: null, $mods, $now, $uid];
        mysqli_query($conn, "DELETE FROM lc_results WHERE class_id = $cid AND customer_id = $sid");
        $s = mysqli_prepare($conn, 'INSERT INTO lc_results (class_id, customer_id, enrollment_id, final_pct, grade, attendance_pct, decision, reason, modules, published_at, published_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iiidsdssssi', ...$vals);
        mysqli_stmt_execute($s);
        $eid = (int) $r['enrollment_id'];
        $es = $r['decision'] === 'Pass' ? 'Completed' : ($r['decision'] === 'Fail' ? 'Failed' : 'Registered');
        mysqli_query($conn, "UPDATE lc_enrollments SET status = '$es' WHERE id = $eid AND status IN ('Registered', 'Completed', 'Failed')");
        $differs = !$old || (string) $old['decision'] !== $r['decision'] || (float) $old['final_pct'] !== (float) $r['final'];
        if ($old && $differs) { $changed[] = ['student' => $sid, 'from' => $old['decision'] . ' ' . $old['final_pct'], 'to' => $r['decision'] . ' ' . $r['final']]; }
        if ($differs) {
            lc_notify_student($conn, $sid, 'tpl_results', ['{class}' => $class['course_title'] . ' (' . $class['code'] . ')', '{decision}' => $r['decision'] . ($r['grade'] ? ' — grade ' . $r['grade'] : '')], 'learning.php?tab=learning');
        }
        $n++;
    }
    mysqli_query($conn, "UPDATE lc_classes SET results_published_at = '$now', results_published_by = $uid WHERE id = $cid");
    lc_audit($conn, $class['results_published_at'] ? 'results_republish' : 'results_publish', 'class', $cid, ['students' => $n, 'changed' => $changed]);
    return ['students' => $n, 'changed' => count($changed)];
}

/* ------------------------------------------------------------------ certificates */

function lc_cert_number(mysqli $conn): string
{
    $fmt = lc_setting($conn, 'cert_format');
    if (strpos($fmt, '{N') === false) { $fmt .= '-{NNNNN}'; }
    $prefix = str_replace(['{YYYY}', '{YY}'], [date('Y'), date('y')], $fmt);
    preg_match('/\{(N+)\}/', $prefix, $m);
    $w = strlen($m[1] ?? 'NNNNN');
    $base = preg_replace('/\{N+\}/', '#', $prefix);
    do {
        $no = str_replace('#', str_pad((string) lc_next_seq($conn, 'cert_' . $base), $w, '0', STR_PAD_LEFT), $base);
        $exists = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_certificates WHERE number = '" . mysqli_real_escape_string($conn, $no) . "'"))[0];
    } while ($exists);
    return $no;
}

function lc_cert_verify_url(mysqli $conn, array $cert): string
{
    return lc_public_base($conn) . '/verify.php?c=' . rawurlencode($cert['number']) . '&t=' . rawurlencode($cert['token']);
}

/** Short code printed on the certificate for manual verification. */
function lc_cert_code(array $cert): string { return strtoupper(substr($cert['token'], 0, 8)); }

/** Valid certificate of an enrollment, if any. */
function lc_enrollment_certificate(mysqli $conn, int $enrollmentId): ?array
{
    return mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_certificates WHERE enrollment_id = $enrollmentId AND status = 'Valid' ORDER BY id DESC LIMIT 1")) ?: null;
}

/** Issues the certificate of a student who PASSED a class whose results are published. $replaces = reissue of that certificate. */
function lc_issue_certificate(mysqli $conn, array $class, int $customerId, ?array $replaces = null): array
{
    $r = lc_published_result($conn, (int) $class['id'], $customerId);
    if (!$r || $r['decision'] !== 'Pass') { throw new RuntimeException('Certificates are issued only to students with a published Pass result.'); }
    $eid = (int) $r['enrollment_id'];
    if (!$replaces && lc_enrollment_certificate($conn, $eid)) { throw new RuntimeException('This student already has a valid certificate for this course.'); }
    // NEW (stage 3): financial clearance policy (Settings); by default fees never block a certificate.
    if (function_exists('lc_clearance_ok') && !lc_clearance_ok($conn, $customerId, 'certificate')) { throw new RuntimeException('Fees are overdue and the clearance policy requires payment before the certificate.'); }
    $name = (string) mysqli_fetch_row(mysqli_query($conn, 'SELECT name FROM customers WHERE id = ' . $customerId))[0];
    $no = lc_cert_number($conn); $tok = bin2hex(random_bytes(12)); $now = date('Y-m-d H:i:s'); $uid = (int) current_user_id();
    $pubDay = substr((string) $r['published_at'], 0, 10);
    $done = $class['intake_end'] && $class['intake_end'] < $pubDay ? $class['intake_end'] : $pubDay; // never a date in the future
    $cid = (int) $class['id']; $course = (int) $class['course_id']; $title = $class['course_title']; $grade = $r['grade']; $rep = $replaces ? (int) $replaces['id'] : null;
    $s = mysqli_prepare($conn, 'INSERT INTO lc_certificates (number, token, customer_id, enrollment_id, class_id, course_id, student_name, course_title, grade, completed_on, issued_at, issued_by, replaces_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'ssiiiisssssii', $no, $tok, $customerId, $eid, $cid, $course, $name, $title, $grade, $done, $now, $uid, $rep);
    mysqli_stmt_execute($s);
    $id = (int) mysqli_insert_id($conn);
    // NEW: the certificate keeps the APPROVED template version used today; later template edits never change it.
    require_once __DIR__ . '/lc_docs.php';
    $tpl = lc_doc_pick($conn, 'certificate', $course);
    if ($tpl) { mysqli_query($conn, 'UPDATE lc_certificates SET template_id = ' . $tpl['id'] . ', template_version = ' . $tpl['version'] . ' WHERE id = ' . $id); }
    if ($replaces) {
        mysqli_query($conn, "UPDATE lc_certificates SET status = 'Replaced', replaced_by_id = $id WHERE id = " . (int) $replaces['id']);
    }
    lc_audit($conn, $replaces ? 'certificate_reissue' : 'certificate_issue', 'certificate', $id, ['number' => $no, 'replaces' => $replaces['number'] ?? null], $customerId);
    lc_notify_student($conn, $customerId, 'tpl_certificate', ['{number}' => $no, '{course}' => $title], 'learning.php?tab=learning');
    return mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lc_certificates WHERE id = $id"));
}

function lc_revoke_certificate(mysqli $conn, array $cert, string $reason): void
{
    if ($cert['status'] !== 'Valid') { throw new RuntimeException('Only a valid certificate can be revoked.'); }
    if (mb_strlen(trim($reason)) < 5) { throw new RuntimeException('Please give the reason of the revocation.'); }
    $uid = (int) current_user_id(); $now = date('Y-m-d H:i:s'); $id = (int) $cert['id'];
    $s = mysqli_prepare($conn, "UPDATE lc_certificates SET status = 'Revoked', revoked_at = ?, revoked_by = ?, reason = ? WHERE id = ?");
    $rs = mb_substr(trim($reason), 0, 300);
    mysqli_stmt_bind_param($s, 'sisi', $now, $uid, $rs, $id);
    mysqli_stmt_execute($s);
    lc_audit($conn, 'certificate_revoke', 'certificate', $id, ['number' => $cert['number'], 'reason' => $rs], (int) $cert['customer_id']);
}

/* ------------------------------------------------------------------ notifications to students */

/** SMS + Customer Portal notification to a student (customer). $tpl = setting key; {name} is added. */
function lc_notify_student(mysqli $conn, int $customerId, string $tpl, array $vars, string $link = 'learning.php'): void
{
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, name, phone FROM customers WHERE id = $customerId"));
    if (!$c) { return; }
    $text = strtr(lc_setting($conn, $tpl), $vars + ['{name}' => strtok((string) $c['name'], ' ')]);
    try {
        if (is_file(__DIR__ . '/sms_helpers.php')) { require_once __DIR__ . '/sms_helpers.php'; if (function_exists('sms_send') && $c['phone'] && lc_setting($conn, 'notify_sms') === '1') { sms_send($conn, $c['phone'], $text, 'learning_' . $tpl, 'lc_student', $customerId, null); } }
    } catch (Throwable $e) { error_log('lc student sms: ' . $e->getMessage()); }
    if (lc_setting($conn, 'notify_portal') !== '1') { return; }
    try {
        $t = 'RM Learning Centre'; $b = mb_substr($text, 0, 500);
        $s = mysqli_prepare($conn, 'INSERT INTO portal_notifications (customer_id, title, body, link) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'isss', $customerId, $t, $b, $link);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) { /* portal notifications not set up */ }
}

/** Attendance alerts after a register: students under the minimum (after N held sessions), at most once a week each. */
function lc_attendance_alerts(mysqli $conn, array $class): int
{
    $min = (float) $class['attendance_min']; $after = max(1, (int) lc_setting($conn, 'att_alert_min_sessions')); $n = 0; $cid = (int) $class['id'];
    foreach (lc_class_attendance($conn, $cid) as $sid => $a) {
        if ($a['pct'] === null || $a['held'] < $after || $a['pct'] + 1e-9 >= $min) { continue; }
        $last = mysqli_fetch_row(mysqli_query($conn, "SELECT sent_at FROM lc_alerts WHERE class_id = $cid AND customer_id = $sid AND kind = 'attendance'"));
        if ($last && strtotime($last[0]) > time() - 7 * 86400) { continue; }
        lc_notify_student($conn, $sid, 'tpl_attendance', ['{class}' => $class['course_title'], '{pct}' => (string) $a['pct'], '{min}' => (string) $min], 'learning.php?tab=learning');
        $now = date('Y-m-d H:i:s');
        mysqli_query($conn, "DELETE FROM lc_alerts WHERE class_id = $cid AND customer_id = $sid AND kind = 'attendance'");
        mysqli_query($conn, "INSERT INTO lc_alerts (class_id, customer_id, kind, sent_at) VALUES ($cid, $sid, 'attendance', '$now')");
        $n++;
    }
    return $n;
}

/* ------------------------------------------------------------------ documents (PDF) */

/** Transcript HTML of an enrollment: FINAL when the class results are published, otherwise PROVISIONAL. */
function lc_transcript_html(mysqli $conn, int $enrollmentId): string
{
    $e = mysqli_fetch_assoc(mysqli_query($conn, "SELECT e.*, cu.name, c.title, c.code ccode, c.certificate_type, i.name intake_name FROM lc_enrollments e JOIN customers cu ON cu.id = e.customer_id
        JOIN lc_courses c ON c.id = e.course_id LEFT JOIN lc_intakes i ON i.id = e.intake_id WHERE e.id = $enrollmentId"));
    if (!$e) { throw new RuntimeException('Enrollment not found.'); }
    $class = $e['class_id'] ? lc_class($conn, (int) $e['class_id']) : null;
    $pub = $class ? lc_published_result($conn, (int) $class['id'], (int) $e['customer_id']) : null;
    $calc = $class ? (lc_compute_results($conn, $class)[(int) $e['customer_id']] ?? null) : null;
    $final = (bool) $pub;
    $as = $class ? lc_class_assessments($conn, (int) $class['id']) : [];
    $cert = lc_enrollment_certificate($conn, $enrollmentId);
    $h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $pct = function ($v) { return $v === null ? '—' : number_format((float) $v, 1) . '%'; };
    $logo = __DIR__ . '/../assets/images/logo.jpg';
    $mods = $final ? (json_decode((string) $pub['modules'], true) ?: []) : ($calc['modules_res'] ?? []);
    $html = '<style>body{font-family:dejavusans;font-size:10pt;color:#1E2333}h1{font-size:16pt;color:#0B1A8C;margin:0}table{border-collapse:collapse;width:100%}td,th{padding:4px 6px;border-bottom:0.5px solid #ccd}th{background:#EEF3FF;text-align:left;font-size:9pt}.mut{color:#666;font-size:9pt}.wm{color:#C0392B;font-weight:bold}</style>';
    $html .= '<table style="border:0"><tr><td style="border:0;width:70px">' . (is_file($logo) ? '<img src="' . $h($logo) . '" style="width:60px">' : '') . '</td><td style="border:0"><h1>' . $h(lc_setting($conn, 'title')) . '</h1><div class="mut">RISE MOTIVE Ltd · ' . $h(lc_setting($conn, 'contact_address')) . '</div></td>'
        . '<td style="border:0;text-align:right"><div style="font-size:13pt;font-weight:bold">ACADEMIC TRANSCRIPT</div><div class="' . ($final ? '' : 'wm') . '">' . ($final ? 'FINAL' : 'PROVISIONAL — results not yet published') . '</div></td></tr></table><br>';
    $html .= '<table><tr><th>Student</th><td>' . $h($e['name']) . '</td><th>Student ID</th><td>' . $h(lc_customer_id_label($conn, (int) $e['customer_id'])) . '</td></tr>'
        . '<tr><th>Course</th><td>' . $h($e['title']) . ' (' . $h($e['ccode']) . ')</td><th>Intake</th><td>' . $h($e['intake_name'] ?? '—') . '</td></tr>'
        . '<tr><th>Class</th><td>' . $h($class['code'] ?? '—') . '</td><th>Branch</th><td>' . $h(lc_branch_name($conn, $e['branch_id'] ? (int) $e['branch_id'] : null)) . '</td></tr></table><br>';
    if ($mods) {
        $html .= '<div style="font-weight:bold;margin:4px 0">Modules</div><table><tr><th>Module</th><th style="text-align:right">Mark</th><th>Grade</th></tr>';
        foreach ($mods as $m) { $html .= '<tr><td>' . $h($m['module']) . '</td><td style="text-align:right">' . $pct($m['pct']) . '</td><td>' . $h($m['grade']) . '</td></tr>'; }
        $html .= '</table><br>';
    }
    if ($as && $calc) {
        $html .= '<div style="font-weight:bold;margin:4px 0">Assessments</div><table><tr><th>Assessment</th><th>Type</th><th>Module</th><th style="text-align:right">Weight</th><th style="text-align:right">Mark</th></tr>';
        foreach ($as as $aid => $a) { $html .= '<tr><td>' . $h($a['name']) . '</td><td>' . $h($a['type']) . '</td><td>' . $h($a['module_name'] ?? '—') . '</td><td style="text-align:right">' . (float) $a['weight'] . '</td><td style="text-align:right">' . $pct($calc['per'][$aid] ?? null) . '</td></tr>'; }
        $html .= '</table><br>';
    }
    $fp = $final ? $pub['final_pct'] : ($calc['final'] ?? null); $gr = $final ? $pub['grade'] : ($calc['grade'] ?? null);
    $ap = $final ? $pub['attendance_pct'] : ($calc['attendance_pct'] ?? null); $dec = $final ? $pub['decision'] : ($calc['decision'] ?? '—');
    $html .= '<table><tr><th>Final mark</th><td>' . $pct($fp) . '</td><th>Grade</th><td>' . $h($gr ?? '—') . '</td><th>Attendance</th><td>' . $pct($ap) . '</td><th>Result</th><td><b>' . $h($final ? $dec : $dec . ' (provisional)') . '</b></td></tr></table>';
    $html .= '<div class="mut" style="margin-top:6px">Pass mark ' . (float) ($class['pass_mark'] ?? 0) . '% · minimum attendance ' . (float) ($class['attendance_min'] ?? 0) . '% · grading: '
        . $h(implode(', ', array_map(function ($g) { return $g[1] . ' ≥ ' . $g[0] . '%'; }, lc_grade_scale($conn)))) . '</div>';
    if ($cert) { $html .= '<div class="mut">Certificate ' . $h($cert['number']) . ' issued ' . $h(substr($cert['issued_at'], 0, 10)) . '.</div>'; }
    $html .= '<div class="mut" style="margin-top:20px">Issued ' . date('d M Y H:i') . ($final ? ' · results published ' . $h(substr($pub['published_at'], 0, 10)) : '') . '.</div>';
    if (lc_setting($conn, 'accreditation_verified') === '1' && lc_setting($conn, 'accreditation_note') !== '') { $html .= '<div class="mut">' . $h(lc_setting($conn, 'accreditation_note')) . '</div>'; }
    return $html;
}

function lc_certificate_html(mysqli $conn, array $cert): string
{
    $h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    require_once __DIR__ . '/lc_qr.php';
    $qr = lc_qr_data_uri(lc_cert_verify_url($conn, $cert), 5);
    $logo = __DIR__ . '/../assets/images/logo.jpg';
    $course = lc_course($conn, (int) $cert['course_id']);
    $type = ($course['certificate_type'] ?? '') ?: 'Certificate of Completion';
    $void = $cert['status'] !== 'Valid';
    $html = '<style>body{font-family:dejavuserif;color:#1E2333}.c{text-align:center}.big{font-size:30pt;color:#0B1A8C;font-weight:bold}.nm{font-size:26pt;font-weight:bold;margin:8px 0}.t{font-size:13pt}.mut{font-family:dejavusans;font-size:8.5pt;color:#555}</style>';
    $html .= '<div style="border:3px double #0B1A8C;padding:22px 30px;height:165mm">';
    if ($void) { $html .= '<div class="c" style="color:#C0392B;font-size:16pt;font-weight:bold">' . ($cert['status'] === 'Revoked' ? 'REVOKED' : 'REPLACED — NOT VALID') . '</div>'; }
    $html .= '<div class="c">' . (is_file($logo) ? '<img src="' . $h($logo) . '" style="height:60px">' : '') . '</div>'
        . '<div class="c t" style="letter-spacing:2px">RISE MOTIVE LTD · ' . $h(strtoupper(lc_setting($conn, 'title'))) . '</div>'
        . '<div class="c big" style="margin-top:14px">' . $h($type) . '</div>'
        . '<div class="c t" style="margin-top:14px">This is to certify that</div>'
        . '<div class="c nm">' . $h($cert['student_name']) . '</div>'
        . '<div class="c t">Student ID ' . $h(lc_customer_id_label($conn, (int) $cert['customer_id'])) . '</div>'
        . '<div class="c t" style="margin-top:10px">has successfully completed the course</div>'
        . '<div class="c" style="font-size:18pt;font-weight:bold;margin:6px 0">' . $h($cert['course_title']) . '</div>'
        . '<div class="c t">' . ($cert['grade'] ? 'with grade ' . $h($cert['grade']) . ' · ' : '') . 'completed on ' . $h(date('d F Y', strtotime((string) $cert['completed_on']))) . '</div>';
    $html .= '<table style="width:100%;margin-top:24px"><tr><td style="width:35%;vertical-align:bottom"><div style="border-top:1px solid #333;width:60mm;padding-top:3px" class="mut">' . $h(lc_setting($conn, 'cert_signatory')) . '</div></td>'
        . '<td style="width:30%;text-align:center;vertical-align:bottom" class="mut">Issued ' . $h(date('d M Y', strtotime($cert['issued_at']))) . '<br>Certificate No. <b>' . $h($cert['number']) . '</b><br>Verification code ' . $h(lc_cert_code($cert)) . '</td>'
        . '<td style="width:35%;text-align:right;vertical-align:bottom"><img src="' . $qr . '" style="width:30mm"><div class="mut">Scan to verify, or visit<br>' . $h(lc_public_base($conn)) . '/verify.php</div></td></tr></table>';
    if (lc_setting($conn, 'accreditation_verified') === '1' && lc_setting($conn, 'accreditation_note') !== '') { $html .= '<div class="c mut" style="margin-top:6px">' . $h(lc_setting($conn, 'accreditation_note')) . '</div>'; }
    return $html . '</div>';
}

/** Streams a PDF (mPDF). $landscape for certificates. */
function lc_pdf_output(string $html, string $filename, bool $landscape = false): void
{
    require_once __DIR__ . '/../vendor/autoload.php';
    $m = new \Mpdf\Mpdf(['format' => $landscape ? 'A4-L' : 'A4', 'margin_top' => 12, 'margin_bottom' => 12, 'margin_left' => 14, 'margin_right' => 14, 'tempDir' => sys_get_temp_dir()]);
    $m->SetTitle($filename);
    $m->WriteHTML($html);
    $m->Output($filename, \Mpdf\Output\Destination::INLINE);
}