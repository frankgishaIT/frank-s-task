<?php
/**
 * NEW FILE: includes/lc_internship.php — INTERNSHIPS (stage 4): an HR workflow, NOT a sale and NOT an employee record.
 * Opportunities (department, branch, places, period, open to Learning Centre students / external applicants / both) →
 * applications (public website, or the Customer Portal for students) → review, shortlist, interview, approve / reject →
 * placement (existing department and branch, supervisor = existing MY MOTIVE user, start and end dates) → attendance,
 * evaluations, completion → completion letter (only when HR approves it). No employee record is ever created.
 */
require_once __DIR__ . '/lc_academic.php';

const LC_INT_SCHEMA = '1';
const LC_INT_STATUSES = ['Submitted', 'Shortlisted', 'Interview', 'Approved', 'Rejected', 'Withdrawn', 'Active', 'Completed', 'Discontinued'];
const LC_INT_OPEN = ['Submitted', 'Shortlisted', 'Interview', 'Approved', 'Active'];
const LC_INT_CRITERIA = ['punctuality' => 'Punctuality & attendance', 'quality' => 'Quality of work', 'teamwork' => 'Teamwork', 'initiative' => 'Initiative', 'communication' => 'Communication'];

function lc_int_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    lc_acad_ensure($conn);
    if (lc_setting($conn, 'int_schema') === LC_INT_SCHEMA) { return; }
    $q = function (string $sql) use ($conn) { try { mysqli_query($conn, $sql); } catch (Throwable $e) { error_log('lc_int_ensure: ' . $e->getMessage()); } };
    $q('CREATE TABLE IF NOT EXISTS lc_int_posts (id INT(11) NOT NULL AUTO_INCREMENT, title VARCHAR(150) NOT NULL, department_id INT(11) NULL, branch_id INT(11) NULL,
        description TEXT NULL, requirements TEXT NULL, places INT(11) NULL, start_date DATE NULL, end_date DATE NULL, apply_close DATE NULL,
        open_to VARCHAR(10) NOT NULL DEFAULT \'both\', status VARCHAR(10) NOT NULL DEFAULT \'Draft\', created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_int_apps (id INT(11) NOT NULL AUTO_INCREMENT, ref VARCHAR(30) NOT NULL, post_id INT(11) NOT NULL, customer_id INT(11) NULL, is_student TINYINT(1) NOT NULL DEFAULT 0,
        full_name VARCHAR(150) NOT NULL, phone VARCHAR(30) NOT NULL, phone_key VARCHAR(12) NOT NULL, email VARCHAR(150) NULL, education TEXT NULL, motivation TEXT NULL, cv_ref VARCHAR(30) NULL,
        channel VARCHAR(10) NOT NULL DEFAULT \'public\', status VARCHAR(14) NOT NULL DEFAULT \'Submitted\', department_id INT(11) NULL, branch_id INT(11) NULL, supervisor_user_id INT(11) NULL,
        start_date DATE NULL, end_date DATE NULL, completion_approved_by INT(11) NULL, completion_approved_at DATETIME NULL, decided_by INT(11) NULL, decided_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY ref (ref), KEY post (post_id), KEY phone (phone_key)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_int_log (id INT(11) NOT NULL AUTO_INCREMENT, app_id INT(11) NOT NULL, user_id INT(11) NULL, from_status VARCHAR(14) NULL, to_status VARCHAR(14) NULL,
        note TEXT NULL, public TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY app (app_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_int_attendance (app_id INT(11) NOT NULL, day DATE NOT NULL, status VARCHAR(12) NOT NULL, note VARCHAR(200) NULL, recorded_by INT(11) NULL,
        recorded_at DATETIME NULL, PRIMARY KEY (app_id, day)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_int_evals (id INT(11) NOT NULL AUTO_INCREMENT, app_id INT(11) NOT NULL, eval_date DATE NOT NULL, scores VARCHAR(300) NOT NULL, average DECIMAL(4,2) NOT NULL,
        strengths TEXT NULL, improve TEXT NULL, recommendation VARCHAR(40) NULL, by_user INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY app (app_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    lc_setting_set($conn, 'int_schema', LC_INT_SCHEMA);
}

function lc_int_post(mysqli $conn, int $id): ?array
{
    return mysqli_fetch_assoc(mysqli_query($conn, "SELECT p.*, d.name department FROM lc_int_posts p LEFT JOIN departments d ON d.id = p.department_id WHERE p.id = $id")) ?: null;
}

/** Can someone apply now? (status Open, before the closing date, places not all filled.) [bool, reason] */
function lc_int_post_open(mysqli $conn, array $p): array
{
    if ($p['status'] !== 'Open') { return [false, 'Applications are closed.']; }
    if ($p['apply_close'] && $p['apply_close'] < date('Y-m-d')) { return [false, 'The closing date has passed.']; }
    if ($p['places']) {
        $taken = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lc_int_apps WHERE post_id = ' . (int) $p['id'] . " AND status IN ('Approved', 'Active', 'Completed')"))[0];
        if ($taken >= (int) $p['places']) { return [false, 'All places are filled.']; }
    }
    return [true, ''];
}

function lc_int_app(mysqli $conn, int $id): ?array
{
    return mysqli_fetch_assoc(mysqli_query($conn, "SELECT a.*, p.title post_title, p.open_to, d.name department, u.names supervisor FROM lc_int_apps a JOIN lc_int_posts p ON p.id = a.post_id
        LEFT JOIN departments d ON d.id = a.department_id LEFT JOIN users u ON u.id = a.supervisor_user_id WHERE a.id = $id")) ?: null;
}

function lc_int_ref(mysqli $conn): string
{
    $fmt = lc_setting($conn, 'internship_ref_format');
    if (strpos($fmt, '{N') === false) { $fmt .= '-{NNNN}'; }
    $prefix = str_replace(['{YYYY}', '{YY}'], [date('Y'), date('y')], $fmt);
    preg_match('/\{(N+)\}/', $prefix, $m); $w = strlen($m[1] ?? 'NNNN'); $base = preg_replace('/\{N+\}/', '#', $prefix);
    do {
        $ref = str_replace('#', str_pad((string) lc_next_seq($conn, 'int_' . $base), $w, '0', STR_PAD_LEFT), $base);
        $ex = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_int_apps WHERE ref = '" . mysqli_real_escape_string($conn, $ref) . "'"))[0];
    } while ($ex);
    return $ref;
}

/**
 * Submits an internship application (public or portal). $customer = signed-in portal customer (name and phone from the record).
 * One open application per person and opportunity. Students of the Learning Centre are recognised (is_student).
 * Returns ['id', 'ref', 'duplicate'].
 */
function lc_int_submit(mysqli $conn, array $post, array $in, ?array $cvFile, ?array $customer, string $channel): array
{
    [$ok, $why] = lc_int_post_open($conn, $post);
    if (!$ok) { throw new RuntimeException($why); }
    $name = $customer ? (string) $customer['name'] : mb_substr(trim((string) ($in['full_name'] ?? '')), 0, 150);
    $phone = $customer ? (string) $customer['phone'] : mb_substr(trim((string) ($in['phone'] ?? '')), 0, 30);
    $key = lc_phone_key($phone);
    if (mb_strlen($name) < 3) { throw new RuntimeException('Enter your full name.'); }
    if (strlen($key) !== 9) { throw new RuntimeException('Enter a valid telephone number.'); }
    $email = mb_substr(trim((string) ($in['email'] ?? ($customer['email'] ?? ''))), 0, 150);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('The email address is not valid.'); }
    $pid = (int) $post['id'];
    $s = mysqli_prepare($conn, "SELECT id, ref FROM lc_int_apps WHERE post_id = ? AND phone_key = ? AND status IN ('Submitted', 'Shortlisted', 'Interview', 'Approved', 'Active') LIMIT 1");
    mysqli_stmt_bind_param($s, 'is', $pid, $key); mysqli_stmt_execute($s);
    if ($d = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) { return ['id' => (int) $d['id'], 'ref' => $d['ref'], 'duplicate' => true]; }
    // Learning Centre student? (customer linked by the portal, or same telephone as a registered student)
    $cid = $customer ? (int) $customer['id'] : null;
    if (!$cid) {
        $m = mysqli_fetch_row(mysqli_query($conn, "SELECT st.customer_id FROM lc_students st JOIN customers c ON c.id = st.customer_id WHERE RIGHT(REPLACE(REPLACE(REPLACE(c.phone, ' ', ''), '-', ''), '+', ''), 9) = '$key' LIMIT 1"));
        $studentCid = $m ? (int) $m[0] : null;
    } else { $studentCid = lc_is_student($conn, $cid) ? $cid : null; }
    $isStudent = $studentCid ? 1 : 0;
    if ($post['open_to'] === 'students' && !$isStudent) { throw new RuntimeException('This internship is reserved for RM Learning Centre students.'); }
    if ($post['open_to'] === 'external' && $isStudent) { throw new RuntimeException('This internship is for external applicants; RM Learning Centre students have their own opportunities.'); }
    $cv = null;
    if ($cvFile && ($cvFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        require_once __DIR__ . '/evidence_store.php';
        $cv = evidence_save($conn, $cvFile, null);
    }
    $edu = mb_substr(trim((string) ($in['education'] ?? '')), 0, 2000) ?: null; $mot = mb_substr(trim((string) ($in['motivation'] ?? '')), 0, 2000) ?: null;
    $ref = lc_int_ref($conn); $em = $email ?: null; $br = $post['branch_id'] ? (int) $post['branch_id'] : null; $dep = $post['department_id'] ? (int) $post['department_id'] : null;
    $s = mysqli_prepare($conn, 'INSERT INTO lc_int_apps (ref, post_id, customer_id, is_student, full_name, phone, phone_key, email, education, motivation, cv_ref, channel, department_id, branch_id, start_date, end_date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $sd = $post['start_date']; $ed = $post['end_date'];
    mysqli_stmt_bind_param($s, 'siiissssssssiiss', $ref, $pid, $cid, $isStudent, $name, $phone, $key, $em, $edu, $mot, $cv, $channel, $dep, $br, $sd, $ed);
    mysqli_stmt_execute($s);
    $id = (int) mysqli_insert_id($conn);
    lc_int_log($conn, $id, null, 'Submitted', 'Application received' . ($isStudent ? ' (RM Learning Centre student).' : '.'), true);
    lc_audit($conn, 'internship_apply', 'internship', $id, ['ref' => $ref, 'post' => $pid, 'channel' => $channel, 'student' => $isStudent], $cid);
    lc_int_notify($conn, $id, 'Thank you, we will contact you.');
    lc_notify_staff($conn, 'New internship application ' . $ref, $name . ' applied for ' . $post['title'] . '.');
    return ['id' => $id, 'ref' => $ref, 'duplicate' => false];
}

function lc_int_log(mysqli $conn, int $appId, ?string $from, ?string $to, ?string $note, bool $public): void
{
    $uid = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null; $p = $public ? 1 : 0;
    $s = mysqli_prepare($conn, 'INSERT INTO lc_int_log (app_id, user_id, from_status, to_status, note, public) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iisssi', $appId, $uid, $from, $to, $note, $p); mysqli_stmt_execute($s);
}

/** SMS + portal (for customers) about an internship application. */
function lc_int_notify(mysqli $conn, int $appId, string $message = ''): void
{
    $a = lc_int_app($conn, $appId);
    if (!$a) { return; }
    $text = strtr(lc_setting($conn, 'tpl_internship'), ['{ref}' => $a['ref'], '{post}' => $a['post_title'], '{status}' => $a['status'], '{message}' => $message, '{name}' => strtok($a['full_name'], ' ')]);
    try { if (lc_setting($conn, 'notify_sms') === '1' && is_file(__DIR__ . '/sms_helpers.php')) { require_once __DIR__ . '/sms_helpers.php'; if (function_exists('sms_send')) { sms_send($conn, $a['phone'], trim($text), 'learning_internship', 'lc_internship', $appId, null); } } } catch (Throwable $e) { error_log('lc int sms: ' . $e->getMessage()); }
    try {
        if ($a['customer_id'] && lc_setting($conn, 'notify_portal') === '1') {
            $t = 'Internship ' . $a['ref']; $b = mb_substr(trim($text), 0, 500); $l = 'learning.php?tab=internships'; $c = (int) $a['customer_id'];
            $s = mysqli_prepare($conn, 'INSERT INTO portal_notifications (customer_id, title, body, link) VALUES (?, ?, ?, ?)'); mysqli_stmt_bind_param($s, 'isss', $c, $t, $b, $l); mysqli_stmt_execute($s);
        }
    } catch (Throwable $e) { }
}

/** Attendance summary of an intern: [days, present, absent, excused, pct]. */
function lc_int_attendance(mysqli $conn, int $appId): array
{
    $map = lc_att_statuses($conn); $p = 0; $a = 0; $x = 0;
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT status FROM lc_int_attendance WHERE app_id = $appId"), MYSQLI_ASSOC) as $r) {
        $k = $map[$r['status']] ?? 'present'; if ($k === 'present') { $p++; } elseif ($k === 'excused') { $x++; } else { $a++; }
    }
    $den = $p + $a;
    return ['days' => $p + $a + $x, 'present' => $p, 'absent' => $a, 'excused' => $x, 'pct' => $den ? round($p / $den * 100, 1) : null];
}

/** Completion letter (only for Completed internships approved by HR). */
function lc_int_letter_html(mysqli $conn, array $a): string
{
    if ($a['status'] !== 'Completed' || !$a['completion_approved_at']) { throw new RuntimeException('The completion letter is available after HR approves the completion.'); }
    $h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $logo = __DIR__ . '/../assets/images/logo.jpg';
    $att = lc_int_attendance($conn, (int) $a['id']);
    $ev = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT AVG(average) av, COUNT(*) n FROM lc_int_evals WHERE app_id = ' . (int) $a['id']));
    $html = '<style>body{font-family:dejavusans;font-size:10.5pt;color:#1E2333;line-height:1.45}h1{font-size:15pt;color:#0B1A8C;margin:0}td,th{padding:5px 6px;border-bottom:0.5px solid #ccd;text-align:left}th{background:#EEF3FF;width:35%}.mut{color:#666;font-size:9pt}</style>';
    $html .= '<table style="width:100%;border:0"><tr><td style="border:0;width:70px;background:none">' . (is_file($logo) ? '<img src="' . $h($logo) . '" style="width:60px">' : '') . '</td><td style="border:0"><h1>RISE MOTIVE Ltd</h1><div class="mut">' . $h(implode(' · ', array_filter([lc_setting($conn, 'contact_address'), lc_setting($conn, 'contact_phone')]))) . '</div></td>'
        . '<td style="border:0;text-align:right" class="mut">' . date('d F Y') . '<br>Ref. ' . $h($a['ref']) . '</td></tr></table><br>';
    $html .= '<div style="font-size:13pt;font-weight:bold;margin:8px 0">INTERNSHIP COMPLETION LETTER</div><p>' . nl2br($h(lc_setting($conn, 'internship_letter'))) . '</p>';
    $html .= '<table style="width:100%;border-collapse:collapse"><tr><th>Name</th><td>' . $h($a['full_name']) . '</td></tr><tr><th>Internship</th><td>' . $h($a['post_title']) . '</td></tr>'
        . '<tr><th>Department</th><td>' . $h($a['department'] ?? '—') . '</td></tr><tr><th>Branch</th><td>' . $h(lc_branch_name($conn, $a['branch_id'] ? (int) $a['branch_id'] : null)) . '</td></tr>'
        . '<tr><th>Period</th><td>' . ($a['start_date'] ? date('d M Y', strtotime($a['start_date'])) : '—') . ' to ' . ($a['end_date'] ? date('d M Y', strtotime($a['end_date'])) : '—') . '</td></tr>'
        . '<tr><th>Supervisor</th><td>' . $h($a['supervisor'] ?? '—') . '</td></tr>'
        . ($att['days'] ? '<tr><th>Attendance</th><td>' . ($att['pct'] !== null ? $att['pct'] . '% (' . $att['present'] . ' days present)' : '—') . '</td></tr>' : '')
        . ((int) $ev['n'] ? '<tr><th>Average evaluation</th><td>' . number_format((float) $ev['av'], 1) . ' / 5 (' . (int) $ev['n'] . ' evaluation(s))</td></tr>' : '') . '</table>';
    $html .= '<br><p>This letter confirms an internship. It is not an offer of employment.</p><br><p>Human Resources<br>RISE MOTIVE Ltd</p>';
    return $html;
}