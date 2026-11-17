<?php
/**
 * NEW FILE: includes/lc_helpers.php — RM LEARNING CENTRE (shared by the staff workspace, the public website and the
 * Customer Portal: ONE database, ONE application system, ONE customer identity).
 *
 * Identity rule: One person = one customer record = one Customer ID = the Student ID (once admitted and registered).
 *   - An application may exist without a customer (guest). It is linked to a customer only when the identity is
 *     verified (signed-in portal customer, or staff check), never by guessing.
 *   - Registration reuses the existing customer (same Customer ID) or creates ONE new customer through the normal
 *     customer record. No second Student ID is ever generated.
 * Money: no ledger here. Fees will be invoiced through RM Offerings / Sales (stage 3).
 */
require_once __DIR__ . '/rm_org.php';

const LC_SCHEMA = '1';
const LC_PUB_STATUSES = ['Draft', 'Published', 'Applications Open', 'Applications Closed', 'Fully Booked', 'Temporarily Unavailable', 'Archived'];
const LC_PUBLIC_VISIBLE = ['Published', 'Applications Open', 'Applications Closed', 'Fully Booked', 'Temporarily Unavailable'];
const LC_ACCEPTING = ['Published', 'Applications Open'];
const LC_DELIVERY = ['Planned', 'Running', 'Completed', 'Suspended'];
const LC_MODES = ['In-person', 'Online', 'Blended', 'Weekend classes', 'Evening classes'];
const LC_APP_STATUSES = ['Draft', 'Submitted', 'Under Review', 'Additional Information Required', 'Application Fee Pending', 'Application Fee Paid',
                         'Accepted', 'Rejected', 'Waitlisted', 'Withdrawn'];
const LC_APP_OPEN = ['Submitted', 'Under Review', 'Additional Information Required', 'Application Fee Pending', 'Application Fee Paid', 'Waitlisted', 'Accepted'];
const LC_SOURCES = ['website' => 'Website', 'whatsapp' => 'WhatsApp', 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn',
                    'x' => 'X / Twitter', 'qr' => 'QR code', 'referral' => 'Referral', 'campaign' => 'Campaign', 'portal' => 'Customer Portal', 'other' => 'Other'];
/** Optional application fields a course may ask for (the rest is never collected). */
const LC_FORM_FIELDS = ['email' => 'Email address', 'dob' => 'Date of birth', 'gender' => 'Gender', 'address' => 'Address (province, district, sector)',
                        'national_id' => 'National ID / passport number', 'education' => 'Educational background', 'employment' => 'Current occupation',
                        'motivation' => 'Why do you want to take this course?', 'accommodation' => 'Accommodation interest', 'modules' => 'Choice of modules'];
const LC_DEFAULTS = [
    'ref_format' => 'RMLC{YYYY}-{NNNN}',
    'public_enabled' => '1',
    'public_url' => '',
    'portal_url' => '',
    'title' => 'RM Learning Centre',
    'tagline' => 'Practical training that opens doors.',
    'intro' => 'Learn practical, job-ready skills with RISE MOTIVE. Choose a course, check the requirements and fees, and apply online in a few minutes — no account needed.',
    'benefits' => "Practical training with experienced instructors\nSmall classes and close follow-up\nFlexible intakes and training modes\nCertificate of completion for successful students",
    'contact_phone' => '0795344768',
    'contact_email' => '',
    'contact_whatsapp' => '250795344768',
    'contact_address' => 'Kigali, Rwanda',
    'tracking_otp' => '0',
    'categories' => "ICT & Digital Skills\nBusiness & Entrepreneurship\nTechnical & Vocational\nLanguages\nOther",
    'accreditation_note' => '',
    'tpl_submitted' => 'Thank you {name}. Your application {ref} for {course} was received. Track it at {track_url}',
    'tpl_status' => 'RISE MOTIVE: your application {ref} for {course} is now "{status}". Details: {track_url}',
    'tpl_info' => 'RISE MOTIVE: we need more information for application {ref}: {message}. Please reply at {track_url}',
    'tpl_admitted' => 'Congratulations {name}! You are admitted to {course} ({intake}). Please accept your offer at {track_url}',
    'tpl_registered' => 'Welcome to RM Learning Centre, {name}! Your Student ID is {student_id} (same as your Customer ID).',
    // Academic (stage 2)
    'att_statuses' => "Present=present\nLate=present\nExcused=excused\nAbsent=absent",
    'grade_scale' => "80=A\n70=B\n60=C\n50=D\n0=F",
    'assessment_types' => "Assignment\nPractical examination\nWritten examination\nContinuous assessment\nFinal examination",
    'att_alert_min_sessions' => '3',
    'cert_format' => 'RMLC-C{YYYY}-{NNNNN}',
    'cert_signatory' => 'Director, RM Learning Centre',
    'accreditation_verified' => '0',
    'tpl_timetable' => 'RM Learning Centre: {class} — {message}',
    'tpl_exam' => 'RM Learning Centre: {assessment} for {class} is on {date}. Good luck!',
    'tpl_attendance' => 'RM Learning Centre: {name}, your attendance in {class} is {pct}%. The minimum is {min}%. Please contact us if you need help.',
    'tpl_results' => 'RM Learning Centre: {name}, your results for {class} are published: {decision}. See the Customer Portal.',
    'tpl_certificate' => 'Congratulations {name}! Your certificate {number} for {course} is ready in the Customer Portal.',
];

/* ------------------------------------------------------------------ schema */

function lc_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $v = null;
    try { $v = mysqli_fetch_row(mysqli_query($conn, "SELECT value FROM lc_settings WHERE name = 'schema'"))[0] ?? null; } catch (Throwable $e) { $v = null; }
    if ($v === LC_SCHEMA) { return; }
    $q = function (string $sql) use ($conn) { mysqli_query($conn, $sql); };
    $q('CREATE TABLE IF NOT EXISTS lc_settings (name VARCHAR(40) NOT NULL, value TEXT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_sequences (prefix VARCHAR(30) NOT NULL, last INT(11) NOT NULL DEFAULT 0, PRIMARY KEY (prefix)) ENGINE=InnoDB');
    $q('CREATE TABLE IF NOT EXISTS lc_courses (id INT(11) NOT NULL AUTO_INCREMENT, code VARCHAR(20) NOT NULL, title VARCHAR(160) NOT NULL, category VARCHAR(80) NULL,
        summary VARCHAR(300) NULL, description TEXT NULL, objectives TEXT NULL, outcomes TEXT NULL, entry_requirements TEXT NULL,
        duration_value INT(11) NULL, duration_unit VARCHAR(10) NOT NULL DEFAULT \'weeks\', modes VARCHAR(200) NULL,
        fee_structure VARCHAR(10) NOT NULL DEFAULT \'course\', course_fee DECIMAL(14,2) NOT NULL DEFAULT 0, application_fee DECIMAL(14,2) NOT NULL DEFAULT 0,
        registration_fee DECIMAL(14,2) NOT NULL DEFAULT 0, exam_fee DECIMAL(14,2) NOT NULL DEFAULT 0, fees_public TINYINT(1) NOT NULL DEFAULT 1,
        installments_allowed TINYINT(1) NOT NULL DEFAULT 0, payment_terms TEXT NULL, required_documents TEXT NULL, assessment_info TEXT NULL,
        attendance_min DECIMAL(5,2) NOT NULL DEFAULT 75, pass_mark DECIMAL(5,2) NOT NULL DEFAULT 50, certificate_type VARCHAR(120) NULL,
        form_fields VARCHAR(300) NULL, custom_questions TEXT NULL, capacity INT(11) NULL, image_ref VARCHAR(30) NULL,
        status VARCHAR(25) NOT NULL DEFAULT \'Draft\', delivery_status VARCHAR(12) NOT NULL DEFAULT \'Planned\', offering_id INT(11) NULL,
        instructors VARCHAR(200) NULL, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, published_at DATETIME NULL,
        PRIMARY KEY (id), UNIQUE KEY code (code), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_modules (id INT(11) NOT NULL AUTO_INCREMENT, course_id INT(11) NOT NULL, code VARCHAR(30) NOT NULL, name VARCHAR(160) NOT NULL,
        description TEXT NULL, outcomes TEXT NULL, duration VARCHAR(60) NULL, hours DECIMAL(7,2) NULL, instructor_user_id INT(11) NULL,
        fee DECIMAL(14,2) NOT NULL DEFAULT 0, in_bundle TINYINT(1) NOT NULL DEFAULT 1, optional TINYINT(1) NOT NULL DEFAULT 0, assessment TEXT NULL,
        timetable TEXT NULL, status VARCHAR(12) NOT NULL DEFAULT \'Planned\', sort INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (id), KEY course (course_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_branch_settings (branch_id INT(11) NOT NULL, training_enabled TINYINT(1) NOT NULL DEFAULT 0, capacity INT(11) NULL,
        facilities TEXT NULL, classrooms TEXT NULL, accommodation TINYINT(1) NOT NULL DEFAULT 0, notes TEXT NULL, updated_by INT(11) NULL,
        updated_at DATETIME NULL, PRIMARY KEY (branch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_intakes (id INT(11) NOT NULL AUTO_INCREMENT, course_id INT(11) NOT NULL, branch_id INT(11) NULL, name VARCHAR(80) NOT NULL,
        start_date DATE NOT NULL, end_date DATE NULL, apply_open DATE NULL, apply_close DATE NULL, capacity INT(11) NULL, modes VARCHAR(200) NULL,
        accommodation TINYINT(1) NOT NULL DEFAULT 0, course_fee DECIMAL(14,2) NULL, application_fee DECIMAL(14,2) NULL, status VARCHAR(12) NOT NULL DEFAULT \'Open\',
        notes VARCHAR(300) NULL, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY course (course_id), KEY branch (branch_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_applications (id INT(11) NOT NULL AUTO_INCREMENT, ref VARCHAR(30) NOT NULL, course_id INT(11) NOT NULL, intake_id INT(11) NULL,
        branch_id INT(11) NULL, mode VARCHAR(40) NULL, modules VARCHAR(300) NULL, customer_id INT(11) NULL, full_name VARCHAR(150) NOT NULL,
        phone VARCHAR(30) NOT NULL, phone_key VARCHAR(12) NOT NULL, email VARCHAR(150) NULL, dob DATE NULL, gender VARCHAR(10) NULL, national_id VARCHAR(30) NULL,
        province VARCHAR(60) NULL, district VARCHAR(60) NULL, sector VARCHAR(60) NULL, address VARCHAR(200) NULL, education TEXT NULL, employment VARCHAR(200) NULL,
        motivation TEXT NULL, accommodation TINYINT(1) NOT NULL DEFAULT 0, answers TEXT NULL, channel VARCHAR(10) NOT NULL DEFAULT \'public\',
        source VARCHAR(20) NOT NULL DEFAULT \'website\', campaign VARCHAR(100) NULL, referrer VARCHAR(255) NULL, status VARCHAR(40) NOT NULL DEFAULT \'Submitted\',
        offer_status VARCHAR(10) NULL, offer_note VARCHAR(500) NULL, decided_by INT(11) NULL, decided_at DATETIME NULL, registered_at DATETIME NULL, registered_by INT(11) NULL,
        cohort VARCHAR(60) NULL, ip VARCHAR(45) NULL, submitted_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), UNIQUE KEY ref (ref), KEY course (course_id), KEY customer (customer_id), KEY phone (phone_key), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_application_docs (id INT(11) NOT NULL AUTO_INCREMENT, application_id INT(11) NOT NULL, label VARCHAR(150) NOT NULL,
        file_ref VARCHAR(30) NOT NULL, original_name VARCHAR(255) NULL, uploaded_by_applicant TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY app (application_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_application_log (id INT(11) NOT NULL AUTO_INCREMENT, application_id INT(11) NOT NULL, user_id INT(11) NULL, kind VARCHAR(12) NOT NULL DEFAULT \'status\',
        from_status VARCHAR(40) NULL, to_status VARCHAR(40) NULL, note TEXT NULL, public TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY app (application_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_students (customer_id INT(11) NOT NULL, status VARCHAR(12) NOT NULL DEFAULT \'Active\', registered_at DATETIME NOT NULL, registered_by INT(11) NULL,
        PRIMARY KEY (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_enrollments (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, application_id INT(11) NOT NULL, course_id INT(11) NOT NULL,
        intake_id INT(11) NULL, branch_id INT(11) NULL, cohort VARCHAR(60) NULL, modules VARCHAR(300) NULL, status VARCHAR(12) NOT NULL DEFAULT \'Registered\',
        registered_at DATETIME NOT NULL, registered_by INT(11) NULL, PRIMARY KEY (id), UNIQUE KEY app (application_id), KEY customer (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_views (course_id INT(11) NOT NULL, day DATE NOT NULL, source VARCHAR(20) NOT NULL, views INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (course_id, day, source)) ENGINE=InnoDB');
    $q('CREATE TABLE IF NOT EXISTS lc_otps (id INT(11) NOT NULL AUTO_INCREMENT, application_id INT(11) NOT NULL, code_hash VARCHAR(255) NOT NULL, expires_at DATETIME NOT NULL,
        attempts INT(11) NOT NULL DEFAULT 0, used_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY app (application_id)) ENGINE=InnoDB');
    $q('CREATE TABLE IF NOT EXISTS lc_attempts (id INT(11) NOT NULL AUTO_INCREMENT, ip VARCHAR(45) NULL, what VARCHAR(20) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY ip (ip, what)) ENGINE=InnoDB');
    $q('CREATE TABLE IF NOT EXISTS lc_audit (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NULL, customer_id INT(11) NULL, action VARCHAR(40) NOT NULL, ref_type VARCHAR(20) NULL,
        ref_id INT(11) NULL, details TEXT NULL, ip VARCHAR(45) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY ref (ref_type, ref_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q("INSERT INTO lc_settings (name, value) VALUES ('schema', '" . LC_SCHEMA . "') ON DUPLICATE KEY UPDATE value = VALUES(value)");
}

function lc_setting(mysqli $conn, string $k): string
{
    static $c = null;
    if ($c === null) {
        $c = [];
        try { foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT name, value FROM lc_settings'), MYSQLI_ASSOC) as $r) { $c[$r['name']] = (string) $r['value']; } } catch (Throwable $e) { }
    }
    return array_key_exists($k, $c) ? trim($c[$k]) : (LC_DEFAULTS[$k] ?? ''); // a saved empty value stays empty
}

function lc_setting_set(mysqli $conn, string $k, string $v): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO lc_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    mysqli_stmt_bind_param($s, 'ss', $k, $v);
    mysqli_stmt_execute($s);
}

function lc_categories(mysqli $conn): array { return array_values(array_filter(array_map('trim', explode("\n", lc_setting($conn, 'categories'))))); }

function lc_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function lc_money($v): string { return 'RWF ' . number_format((float) $v, 0); }
function lc_lines(?string $t): array { return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $t)))); }

/** Who did what (every sensitive action). */
function lc_audit(mysqli $conn, string $action, ?string $refType, ?int $refId, $details = null, ?int $customerId = null): void
{
    try {
        $uid = function_exists('current_user_id') && !empty($_SESSION['user_id']) ? (int) current_user_id() : null;
        $d = $details === null ? null : (is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE));
        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
        $s = mysqli_prepare($conn, 'INSERT INTO lc_audit (user_id, customer_id, action, ref_type, ref_id, details, ip) VALUES (?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iississ', $uid, $customerId, $action, $refType, $refId, $d, $ip);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) { error_log('lc_audit: ' . $e->getMessage()); }
}

/* ------------------------------------------------------------------ numbers and codes */

/** Next number of a sequence (row-locked: two submissions at the same moment never get the same number). */
function lc_next_seq(mysqli $conn, string $prefix): int
{
    $own = false;
    try { $own = mysqli_begin_transaction($conn); } catch (Throwable $e) { $own = false; }
    $p = mysqli_real_escape_string($conn, $prefix);
    mysqli_query($conn, "INSERT IGNORE INTO lc_sequences (prefix, last) VALUES ('$p', 0)");
    $last = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT last FROM lc_sequences WHERE prefix = '$p' FOR UPDATE"))[0];
    mysqli_query($conn, "UPDATE lc_sequences SET last = " . ($last + 1) . " WHERE prefix = '$p'");
    if ($own) { mysqli_commit($conn); }
    return $last + 1;
}

/** Course code from the title: "Web Development Fundamentals" -> WDF-001 (unique, permanent). */
function lc_course_code(mysqli $conn, string $title): string
{
    $words = array_values(array_filter(preg_split('/[^A-Za-z0-9]+/', strtoupper($title)), function ($w) { return $w !== '' && !in_array($w, ['AND', 'OF', 'THE', 'FOR', 'IN', 'TO', 'A'], true); }));
    $pre = '';
    foreach ($words as $w) { $pre .= $w[0]; if (strlen($pre) >= 3) { break; } }
    if (strlen($pre) < 2) { $pre = substr(preg_replace('/[^A-Z]/', '', strtoupper($title)) . 'LC', 0, 3); }
    do {
        $code = $pre . '-' . str_pad((string) lc_next_seq($conn, 'course_' . $pre), 3, '0', STR_PAD_LEFT);
        $exists = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_courses WHERE code = '" . mysqli_real_escape_string($conn, $code) . "'"))[0];
    } while ($exists);
    return $code;
}

/** Application reference from the configurable format ({YYYY} year, {YY}, {NNNN} number per prefix). */
function lc_application_ref(mysqli $conn): string
{
    $fmt = lc_setting($conn, 'ref_format');
    if (strpos($fmt, '{N') === false) { $fmt .= '-{NNNN}'; }
    $prefix = str_replace(['{YYYY}', '{YY}'], [date('Y'), date('y')], $fmt);
    preg_match('/\{(N+)\}/', $prefix, $m);
    $width = strlen($m[1] ?? 'NNNN');
    $base = preg_replace('/\{N+\}/', '#', $prefix);
    do {
        $ref = str_replace('#', str_pad((string) lc_next_seq($conn, 'app_' . $base), $width, '0', STR_PAD_LEFT), $base);
        $exists = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_applications WHERE ref = '" . mysqli_real_escape_string($conn, $ref) . "'"))[0];
    } while ($exists);
    return $ref;
}

/** Last 9 digits of a phone number (07..., +2507..., 2507... all match). */
function lc_phone_key(string $phone): string
{
    $d = preg_replace('/\D/', '', $phone);
    return strlen($d) >= 9 ? substr($d, -9) : $d;
}

/** The Customer ID (= Student ID once registered), exactly as the rest of MY MOTIVE shows it. */
function lc_customer_id_label(mysqli $conn, int $customerId): string
{
    if (is_file(__DIR__ . '/sms_messages.php')) {
        require_once __DIR__ . '/sms_messages.php';
        if (function_exists('sms_customer_code')) { return sms_customer_code($conn, $customerId); }
    }
    return 'RMC' . str_pad((string) $customerId, 6, '0', STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ courses, intakes, branches */

function lc_course(mysqli $conn, int $id): ?array { $r = mysqli_query($conn, 'SELECT * FROM lc_courses WHERE id = ' . (int) $id); return mysqli_fetch_assoc($r) ?: null; }

function lc_course_by_code(mysqli $conn, string $code): ?array
{
    $s = mysqli_prepare($conn, 'SELECT * FROM lc_courses WHERE code = ?');
    $c = strtoupper(trim($code));
    mysqli_stmt_bind_param($s, 's', $c);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

function lc_modules(mysqli $conn, int $courseId): array
{
    return mysqli_fetch_all(mysqli_query($conn, 'SELECT m.*, u.names instructor FROM lc_modules m LEFT JOIN users u ON u.id = m.instructor_user_id WHERE m.course_id = ' . (int) $courseId . ' ORDER BY m.sort, m.id'), MYSQLI_ASSOC);
}

function lc_branch_name(mysqli $conn, ?int $branchId): string
{
    if (!$branchId) { return 'RISE MOTIVE'; }
    if (is_file(__DIR__ . '/branch_helpers.php')) {
        require_once __DIR__ . '/branch_helpers.php';
        try { if (br_enabled($conn)) { $b = br_get($conn, $branchId); if ($b) { return $b['name']; } } } catch (Throwable $e) { }
    }
    return 'Branch #' . $branchId;
}

/** Branches configured as TRAINING locations (Branch Management + Training Centre Enabled). */
function lc_training_branches(mysqli $conn): array
{
    $out = [];
    require_once __DIR__ . '/branch_helpers.php';
    $settings = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_branch_settings'), MYSQLI_ASSOC) as $r) { $settings[(int) $r['branch_id']] = $r; }
    if (br_enabled($conn)) {
        foreach (br_all($conn) as $id => $b) {
            if ($b['status'] === 'Closed') { continue; }
            $out[$id] = ['id' => $id, 'name' => $b['name'], 'district' => $b['district'] ?? '', 'status' => $b['status'], 'settings' => $settings[$id] ?? null,
                         'enabled' => !empty($settings[$id]['training_enabled']) && $b['status'] === 'Active'];
        }
    } else {
        $out[0] = ['id' => 0, 'name' => 'RISE MOTIVE', 'district' => '', 'status' => 'Active', 'settings' => $settings[0] ?? null, 'enabled' => true];
    }
    return $out;
}

/** Intake fees: an intake may override the course's fees. */
function lc_intake_fees(array $course, ?array $intake): array
{
    $pick = function (string $k) use ($course, $intake) { return (float) (($intake[$k] ?? null) !== null ? $intake[$k] : $course[$k]); };
    return ['course' => $pick('course_fee'), 'application' => $pick('application_fee'),
            'registration' => (float) $course['registration_fee'], 'exam' => (float) $course['exam_fee']];
}

/** Applications counted against the capacity of an intake (accepted or registered). */
function lc_intake_taken(mysqli $conn, int $intakeId): int
{
    return (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_applications WHERE intake_id = $intakeId AND status = 'Accepted' AND COALESCE(offer_status, '') <> 'Declined'"))[0];
}

/**
 * Intakes of a course; $openOnly = those someone can apply to TODAY: intake Open, inside the application window,
 * branch is an active training centre, and seats left.
 */
function lc_intakes(mysqli $conn, int $courseId, bool $openOnly = false): array
{
    $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_intakes WHERE course_id = ' . (int) $courseId . " AND status <> 'Cancelled' ORDER BY start_date"), MYSQLI_ASSOC);
    $branches = lc_training_branches($conn);
    $today = date('Y-m-d');
    $out = [];
    foreach ($rows as $r) {
        $bid = (int) $r['branch_id'];
        $r['branch_name'] = $branches[$bid]['name'] ?? lc_branch_name($conn, $bid ?: null);
        $r['branch_ok'] = isset($branches[$bid]) && $branches[$bid]['enabled'];
        $r['taken'] = lc_intake_taken($conn, (int) $r['id']);
        $r['seats_left'] = $r['capacity'] !== null ? max(0, (int) $r['capacity'] - $r['taken']) : null;
        $r['window_open'] = (!$r['apply_open'] || $r['apply_open'] <= $today) && (!$r['apply_close'] || $r['apply_close'] >= $today) && $r['start_date'] >= $today;
        $r['can_apply'] = $r['status'] === 'Open' && $r['window_open'] && $r['branch_ok'] && ($r['seats_left'] === null || $r['seats_left'] > 0);
        if (!$openOnly || $r['can_apply']) { $out[(int) $r['id']] = $r; }
    }
    return $out;
}

/** Can the public apply to this course now? [bool, reason] */
function lc_course_accepting(mysqli $conn, array $course): array
{
    if (!in_array($course['status'], LC_ACCEPTING, true)) {
        return [false, ['Applications Closed' => 'Applications are closed for this course.', 'Fully Booked' => 'This course is fully booked.',
            'Temporarily Unavailable' => 'This course is temporarily unavailable.'][$course['status']] ?? 'This course is not open for applications.'];
    }
    if (!lc_intakes($conn, (int) $course['id'], true)) { return [false, 'There is no intake open for applications at the moment.']; }
    return [true, ''];
}

/** Public label of a course's availability. */
function lc_availability(mysqli $conn, array $course): array
{
    [$ok, $why] = lc_course_accepting($conn, $course);
    if ($ok) {
        $close = array_filter(array_column(lc_intakes($conn, (int) $course['id'], true), 'apply_close'));
        return ['Applications open', 'success', $close ? 'Apply by ' . date('d M Y', strtotime(min($close))) : ''];
    }
    $map = ['Fully Booked' => ['Fully booked', 'danger'], 'Applications Closed' => ['Applications closed', 'secondary'], 'Temporarily Unavailable' => ['Temporarily unavailable', 'warning']];
    $m = $map[$course['status']] ?? ['Coming soon', 'info'];
    return [$m[0], $m[1], $why];
}

/* ------------------------------------------------------------------ public URLs */

/** Base of the public website (setting, else this server's /learn). */
function lc_public_base(mysqli $conn): string
{
    $u = rtrim(lc_setting($conn, 'public_url'), '/');
    if ($u !== '') { return $u; }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return ($https ? 'https' : 'http') . '://' . $host . (defined('BASE_URL') ? BASE_URL : '') . '/learn';
}

/** Permanent shareable link of a course (pretty URL; the .htaccess maps it to course.php). */
function lc_course_url(mysqli $conn, array $course, string $source = ''): string
{
    return lc_public_base($conn) . '/courses/' . rawurlencode(strtolower($course['code'])) . ($source !== '' ? '?src=' . rawurlencode($source) : '');
}

function lc_track_url(mysqli $conn, ?string $ref = null): string { return lc_public_base($conn) . '/track.php' . ($ref ? '?ref=' . rawurlencode($ref) : ''); }

/* ------------------------------------------------------------------ applications (ONE system for public and portal) */

function lc_application(mysqli $conn, int $id): ?array
{
    $r = mysqli_query($conn, 'SELECT a.*, c.title course_title, c.code course_code, i.name intake_name, i.start_date FROM lc_applications a JOIN lc_courses c ON c.id = a.course_id
        LEFT JOIN lc_intakes i ON i.id = a.intake_id WHERE a.id = ' . (int) $id);
    return mysqli_fetch_assoc($r) ?: null;
}

function lc_form_fields(array $course): array { return array_values(array_filter(explode(',', (string) $course['form_fields']))); }

/**
 * Validates and saves an application (public website or Customer Portal). Returns ['id', 'ref', 'duplicate' => bool].
 * $in: posted values; $files: $_FILES['docs'] (one per required document); $customer: signed-in portal customer or null.
 * Throws RuntimeException with a message for the applicant.
 */
function lc_submit_application(mysqli $conn, array $course, array $in, ?array $files, ?array $customer, string $channel, string $source, ?string $campaign, ?string $referrer): array
{
    $fields = lc_form_fields($course);
    $intakes = lc_intakes($conn, (int) $course['id'], true);
    [$ok, $why] = lc_course_accepting($conn, $course);
    if (!$ok) { throw new RuntimeException($why); }
    $intakeId = (int) ($in['intake_id'] ?? 0);
    if (!isset($intakes[$intakeId])) { throw new RuntimeException('Please choose an intake and branch that are open.'); }
    $intake = $intakes[$intakeId];
    $name = $customer ? trim((string) $customer['name']) : trim(preg_replace('/\s+/', ' ', (string) ($in['full_name'] ?? '')));
    $phone = $customer ? (string) $customer['phone'] : trim((string) ($in['phone'] ?? ''));
    $key = lc_phone_key($phone);
    if (mb_strlen($name) < 3) { throw new RuntimeException('Please enter your full name.'); }
    if (strlen($key) !== 9) { throw new RuntimeException('Please enter a valid telephone number (for example 078 123 4567).'); }
    $email = trim((string) ($in['email'] ?? ($customer['email'] ?? '')));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) { throw new RuntimeException('Please check the email address.'); }
    $modes = array_filter(explode(',', (string) ($intake['modes'] ?: $course['modes'])));
    $mode = (string) ($in['mode'] ?? '');
    if ($modes && !in_array($mode, $modes, true)) { $mode = count($modes) === 1 ? reset($modes) : ''; if ($mode === '') { throw new RuntimeException('Please choose the training mode.'); } }
    $dob = null;
    if (in_array('dob', $fields, true)) {
        $dob = (string) ($in['dob'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob) || $dob > date('Y-m-d', strtotime('-10 years')) || $dob < '1930-01-01') { throw new RuntimeException('Please enter your date of birth.'); }
    }
    $gender = in_array('gender', $fields, true) && in_array($in['gender'] ?? '', ['Female', 'Male'], true) ? $in['gender'] : null;
    $mods = [];
    if (in_array('modules', $fields, true)) {
        $valid = array_column(lc_modules($conn, (int) $course['id']), 'id');
        $mods = array_values(array_intersect(array_map('intval', (array) ($in['modules'] ?? [])), array_map('intval', $valid)));
    }
    $answers = [];
    foreach (lc_lines($course['custom_questions']) as $i => $qq) {
        $a = trim((string) ($in['q'][$i] ?? ''));
        if (substr($qq, -1) === '*' && $a === '') { throw new RuntimeException('Please answer: ' . rtrim($qq, '*')); }
        $answers[] = ['q' => rtrim($qq, '*'), 'a' => mb_substr($a, 0, 1000)];
    }
    $t = function ($k, $len) use ($in, $fields) { return in_array(explode('.', $k)[0], $fields, true) ? (mb_substr(trim((string) ($in[explode('.', $k)[1] ?? $k] ?? '')), 0, $len) ?: null) : null; };
    $docs = lc_lines($course['required_documents']);

    // Same person, same course and intake, still active: no second application (double clicks, retries).
    $s = mysqli_prepare($conn, "SELECT id, ref FROM lc_applications WHERE phone_key = ? AND course_id = ? AND COALESCE(intake_id, 0) = ? AND status NOT IN ('Withdrawn', 'Rejected', 'Draft') LIMIT 1");
    $cid = (int) $course['id'];
    mysqli_stmt_bind_param($s, 'sii', $key, $cid, $intakeId);
    mysqli_stmt_execute($s);
    if ($dup = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) { return ['id' => (int) $dup['id'], 'ref' => $dup['ref'], 'duplicate' => true]; }

    // Documents first (a refused file stops the submission before anything is saved).
    require_once __DIR__ . '/evidence_store.php';
    $saved = [];
    foreach ($docs as $i => $label) {
        $f = null;
        if ($files && isset($files['name'][$i])) { $f = ['name' => $files['name'][$i], 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]]; }
        $optional = substr($label, -1) === '?';
        $ref = evidence_save($conn, $f, null);
        if (!$ref && !$optional) { throw new RuntimeException('Please attach: ' . $label . ' (PDF or photo, max 5 MB).'); }
        if ($ref) { $saved[] = [rtrim($label, '?'), $ref, (string) $f['name']]; }
    }
    $ref = lc_application_ref($conn);
    $customerId = $customer ? (int) $customer['id'] : null;
    $bid = (int) $intake['branch_id'] ?: null;
    $modsS = $mods ? implode(',', $mods) : null;
    $acc = in_array('accommodation', $fields, true) && !empty($in['accommodation']) && $intake['accommodation'] ? 1 : 0;
    $answersJ = $answers ? json_encode($answers, JSON_UNESCAPED_UNICODE) : null;
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $vals = [$ref, $cid, $intakeId, $bid, $mode ?: null, $modsS, $customerId, $name, $phone, $key, $email ?: null, $dob, $gender, $t('national_id', 30),
             $t('address.province', 60), $t('address.district', 60), $t('address.sector', 60), $t('address.address', 200), $t('education', 2000), $t('employment', 200), $t('motivation', 2000),
             $acc, $answersJ, $channel, $source, $campaign ? mb_substr($campaign, 0, 100) : null, $referrer ? mb_substr($referrer, 0, 255) : null, $ip];
    $s = mysqli_prepare($conn, "INSERT INTO lc_applications (ref, course_id, intake_id, branch_id, mode, modules, customer_id, full_name, phone, phone_key, email, dob, gender, national_id,
        province, district, sector, address, education, employment, motivation, accommodation, answers, channel, source, campaign, referrer, ip, status, submitted_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Submitted', NOW())");
    mysqli_stmt_bind_param($s, 'siiissi' . str_repeat('s', 14) . 'i' . str_repeat('s', 6), ...$vals);
    mysqli_stmt_execute($s);
    $id = (int) mysqli_insert_id($conn);
    foreach ($saved as [$label, $fref, $orig]) {
        $d = mysqli_prepare($conn, 'INSERT INTO lc_application_docs (application_id, label, file_ref, original_name) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($d, 'isss', $id, $label, $fref, $orig);
        mysqli_stmt_execute($d);
    }
    lc_log($conn, $id, 'status', null, 'Submitted', 'Application submitted (' . (LC_SOURCES[$source] ?? $source) . ', ' . ($channel === 'portal' ? 'Customer Portal' : 'public website') . ').', true);
    lc_audit($conn, 'application_submit', 'application', $id, ['ref' => $ref, 'course' => $course['code'], 'channel' => $channel, 'source' => $source], $customerId);
    $app = lc_application($conn, $id);
    lc_notify_applicant($conn, $app, 'tpl_submitted');
    lc_notify_staff($conn, 'New Learning Centre application ' . $ref, $name . ' applied for ' . $course['title'] . ' (' . $intake['name'] . ', ' . $intake['branch_name'] . ').');
    return ['id' => $id, 'ref' => $ref, 'duplicate' => false];
}

/** History line (status change, note, request). $public = the applicant may see it. */
function lc_log(mysqli $conn, int $appId, string $kind, ?string $from, ?string $to, ?string $note, bool $public): void
{
    $uid = !empty($_SESSION['user_id']) && function_exists('current_user_id') ? (int) current_user_id() : null;
    $p = $public ? 1 : 0;
    $s = mysqli_prepare($conn, 'INSERT INTO lc_application_log (application_id, user_id, kind, from_status, to_status, note, public) VALUES (?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iissssi', $appId, $uid, $kind, $from, $to, $note, $p);
    mysqli_stmt_execute($s);
}

/** Message from a template ({name}, {ref}, {course}, {intake}, {status}, {message}, {track_url}, {student_id}). */
function lc_template(mysqli $conn, string $tpl, array $app, array $extra = []): string
{
    $vars = ['{name}' => strtok((string) $app['full_name'], ' '), '{ref}' => $app['ref'], '{course}' => $app['course_title'] ?? '', '{intake}' => $app['intake_name'] ?? '',
             '{status}' => $app['status'], '{track_url}' => lc_track_url($conn, $app['ref'])] + $extra;
    return strtr(lc_setting($conn, $tpl), $vars);
}

/** SMS (when SMS is set up), email (when given) and the Customer Portal notification (when linked). */
function lc_notify_applicant(mysqli $conn, array $app, string $tpl, array $extra = []): void
{
    $text = lc_template($conn, $tpl, $app, $extra);
    try {
        if (is_file(__DIR__ . '/sms_helpers.php')) {
            require_once __DIR__ . '/sms_helpers.php';
            if (function_exists('sms_send')) { sms_send($conn, $app['phone'], $text, 'learning_' . $tpl, 'lc_application', (int) $app['id'], null); }
        }
    } catch (Throwable $e) { error_log('lc sms: ' . $e->getMessage()); }
    try {
        if (!empty($app['email']) && is_file(__DIR__ . '/mailer.php')) {
            require_once __DIR__ . '/mailer.php';
            if (function_exists('send_email')) { send_email($app['email'], $app['full_name'], 'RM Learning Centre — application ' . $app['ref'], '<p>' . nl2br(lc_e($text)) . '</p><p>RISE MOTIVE Ltd</p>'); }
        }
    } catch (Throwable $e) { error_log('lc email: ' . $e->getMessage()); }
    try {
        if (!empty($app['customer_id'])) {
            $t = 'RM Learning Centre: ' . $app['ref'];
            $s = mysqli_prepare($conn, 'INSERT INTO portal_notifications (customer_id, title, body, link) VALUES (?, ?, ?, ?)');
            $cid = (int) $app['customer_id']; $b = mb_substr($text, 0, 500); $l = 'learning.php?app=' . $app['ref'];
            mysqli_stmt_bind_param($s, 'isss', $cid, $t, $b, $l);
            mysqli_stmt_execute($s);
        }
    } catch (Throwable $e) { /* portal notifications not set up yet */ }
}

function lc_notify_staff(mysqli $conn, string $title, string $message): void
{
    try {
        require_once __DIR__ . '/notification_helper.php';
        require_once __DIR__ . '/access_control.php';
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND LOWER(role) IN ('admin', 'manager')"), MYSQLI_ASSOC) as $u) {
            notifyUser($conn, (int) $u['id'], $title, $message);
        }
    } catch (Throwable $e) { error_log('lc notify staff: ' . $e->getMessage()); }
}

/** Rate limit for public actions (applications, tracking): max $max per $minutes per IP. */
function lc_rate_ok(mysqli $conn, string $what, int $max, int $minutes): bool
{
    $ip = mysqli_real_escape_string($conn, substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45));
    $since = date('Y-m-d H:i:s', time() - 60 * $minutes);
    $n = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_attempts WHERE ip = '$ip' AND what = '" . mysqli_real_escape_string($conn, $what) . "' AND created_at >= '$since'"))[0];
    return $n < $max;
}

function lc_rate_hit(mysqli $conn, string $what): void
{
    $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); $now = date('Y-m-d H:i:s');
    $s = mysqli_prepare($conn, 'INSERT INTO lc_attempts (ip, what, created_at) VALUES (?, ?, ?)');
    mysqli_stmt_bind_param($s, 'sss', $ip, $what, $now);
    mysqli_stmt_execute($s);
}

/** Finds an application for tracking: reference AND (telephone or email used in it). Never by the reference alone. */
function lc_track_find(mysqli $conn, string $ref, string $contact): ?array
{
    $s = mysqli_prepare($conn, 'SELECT id, phone_key, email FROM lc_applications WHERE ref = ?');
    $r = strtoupper(trim($ref));
    mysqli_stmt_bind_param($s, 's', $r);
    mysqli_stmt_execute($s);
    $a = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$a) { return null; }
    $contact = trim($contact);
    $okPhone = strlen(lc_phone_key($contact)) === 9 && hash_equals($a['phone_key'], lc_phone_key($contact));
    $okMail = $a['email'] && filter_var($contact, FILTER_VALIDATE_EMAIL) && hash_equals(strtolower($a['email']), strtolower($contact));
    return ($okPhone || $okMail) ? lc_application($conn, (int) $a['id']) : null;
}

/** What the applicant may see of the history (never internal notes). */
function lc_public_history(mysqli $conn, int $appId): array
{
    return mysqli_fetch_all(mysqli_query($conn, "SELECT kind, to_status, note, created_at FROM lc_application_log WHERE application_id = $appId AND public = 1 ORDER BY id"), MYSQLI_ASSOC);
}

/** Applicant-facing explanation of a status. */
function lc_status_help(string $status, ?string $offer): string
{
    if ($status === 'Accepted') {
        return $offer === 'Accepted' ? 'You accepted your admission offer. RISE MOTIVE will complete your registration and give you your Student ID.'
            : ($offer === 'Declined' ? 'You declined the admission offer.' : 'Congratulations, you are admitted! Please accept or decline the offer below.');
    }
    return ['Submitted' => 'Your application was received and is waiting for review.', 'Under Review' => 'The admissions team is reviewing your application.',
        'Additional Information Required' => 'We need more information or documents from you — see the request below.',
        'Application Fee Pending' => 'Your application fee is pending. Please pay it as instructed by RISE MOTIVE.',
        'Application Fee Paid' => 'Your application fee was received. Paying the fee does not mean admission: your application is still reviewed.',
        'Rejected' => 'We are sorry, your application was not successful this time.', 'Waitlisted' => 'You are on the waiting list. We will contact you if a place becomes available.',
        'Withdrawn' => 'This application was withdrawn.', 'Draft' => 'This application has not been submitted.'][$status] ?? '';
}

/* ------------------------------------------------------------------ customers: linking and registration */

/** Possible existing customers for an application (same phone, or same email). Staff confirms. */
function lc_customer_matches(mysqli $conn, array $app): array
{
    $key = mysqli_real_escape_string($conn, $app['phone_key']);
    $sql = "SELECT id, name, phone, email, created_at FROM customers WHERE RIGHT(REPLACE(REPLACE(REPLACE(phone, ' ', ''), '-', ''), '+', ''), 9) = '$key'";
    if (!empty($app['email'])) { $sql .= " OR LOWER(email) = '" . mysqli_real_escape_string($conn, strtolower($app['email'])) . "'"; }
    return mysqli_fetch_all(mysqli_query($conn, $sql . ' ORDER BY id LIMIT 10'), MYSQLI_ASSOC);
}

/** Links an application to a customer (verified by staff or by the signed-in customer). Never changes the Customer ID. */
function lc_link_customer(mysqli $conn, int $appId, int $customerId, string $how): void
{
    mysqli_query($conn, "UPDATE lc_applications SET customer_id = $customerId, updated_at = NOW() WHERE id = $appId AND (customer_id IS NULL OR customer_id = $customerId)");
    lc_log($conn, $appId, 'note', null, null, 'Linked to customer ' . lc_customer_id_label($conn, $customerId) . ' (' . $how . ').', false);
    lc_audit($conn, 'application_link', 'application', $appId, ['customer' => $customerId, 'how' => $how], $customerId);
}

/** Creates ONE customer record from an application (only when no customer exists for this person). Returns the customer id. */
function lc_create_customer(mysqli $conn, array $app): int
{
    if (lc_customer_matches($conn, $app)) { throw new RuntimeException('A customer with this telephone number or email already exists: link the application to that customer instead.'); }
    $parts = preg_split('/\s+/', trim($app['full_name']));
    $first = $parts[0]; $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : null;
    $s = mysqli_prepare($conn, 'INSERT INTO customers (name, first_name, last_name, phone, email, province, district, sector, address) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'sssssssss', $app['full_name'], $first, $last, $app['phone'], $app['email'], $app['province'], $app['district'], $app['sector'], $app['address']);
    mysqli_stmt_execute($s);
    $id = (int) mysqli_insert_id($conn);
    try { require_once __DIR__ . '/branch_helpers.php'; br_stamp($conn, 'customers', $id, $app['branch_id'] ? (int) $app['branch_id'] : null); } catch (Throwable $e) { }
    lc_audit($conn, 'customer_create', 'customer', $id, ['from_application' => $app['ref']], $id);
    return $id;
}

/**
 * Registers an admitted applicant as a student: the linked customer's ID becomes the Student ID (never a new number),
 * one enrollment for the approved course / branch / intake / cohort.
 */
function lc_register_student(mysqli $conn, array $app, string $cohort): void
{
    if ($app['status'] !== 'Accepted' || $app['offer_status'] !== 'Accepted') { throw new RuntimeException('Only admitted applicants who accepted their offer can be registered.'); }
    if (empty($app['customer_id'])) { throw new RuntimeException('Link the application to the customer first (existing customer, or create the customer record).'); }
    if ($app['registered_at']) { throw new RuntimeException('Already registered.'); }
    $cid = (int) $app['customer_id']; $uid = (int) current_user_id(); $now = date('Y-m-d H:i:s');
    mysqli_begin_transaction($conn);
    try {
        mysqli_query($conn, "INSERT IGNORE INTO lc_students (customer_id, status, registered_at, registered_by) VALUES ($cid, 'Active', '$now', $uid)");
        mysqli_query($conn, "UPDATE lc_students SET status = 'Active' WHERE customer_id = $cid");
        $s = mysqli_prepare($conn, 'INSERT INTO lc_enrollments (customer_id, application_id, course_id, intake_id, branch_id, cohort, modules, registered_at, registered_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $aid = (int) $app['id']; $course = (int) $app['course_id']; $in = $app['intake_id'] ? (int) $app['intake_id'] : null; $b = $app['branch_id'] ? (int) $app['branch_id'] : null;
        $co = $cohort !== '' ? mb_substr($cohort, 0, 60) : null; $mods = $app['modules'];
        mysqli_stmt_bind_param($s, 'iiiiisssi', $cid, $aid, $course, $in, $b, $co, $mods, $now, $uid);
        mysqli_stmt_execute($s);
        mysqli_query($conn, "UPDATE lc_applications SET registered_at = '$now', registered_by = $uid, cohort = " . ($co === null ? 'NULL' : "'" . mysqli_real_escape_string($conn, $co) . "'") . ", updated_at = '$now' WHERE id = $aid");
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
    $sid = lc_customer_id_label($conn, $cid);
    lc_log($conn, (int) $app['id'], 'status', 'Accepted', 'Registered', 'Registered as a student. Student ID: ' . $sid . ' (same as the Customer ID).', true);
    lc_audit($conn, 'student_register', 'application', (int) $app['id'], ['student_id' => $sid, 'cohort' => $cohort], $cid);
    lc_notify_applicant($conn, lc_application($conn, (int) $app['id']), 'tpl_registered', ['{student_id}' => $sid]);
}

/** Is this customer a registered student? (for the Customer Portal) */
function lc_is_student(mysqli $conn, int $customerId): bool
{
    try { return (bool) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_students WHERE customer_id = $customerId AND status = 'Active'"))[0]; } catch (Throwable $e) { return false; }
}

/* ------------------------------------------------------------------ page views (simple, no personal data) */

function lc_count_view(mysqli $conn, int $courseId, string $source): void
{
    try {
        $src = isset(LC_SOURCES[$source]) ? $source : 'website'; $d = date('Y-m-d');
        mysqli_query($conn, "INSERT INTO lc_views (course_id, day, source, views) VALUES ($courseId, '$d', '$src', 1) ON DUPLICATE KEY UPDATE views = views + 1");
    } catch (Throwable $e) { }
}

/** Normalises the ?src= / utm_source parameter to a known source. */
function lc_source_from(array $get, string $default = 'website'): string
{
    $s = strtolower(trim((string) ($get['src'] ?? $get['utm_source'] ?? '')));
    $map = ['wa' => 'whatsapp', 'whatsapp' => 'whatsapp', 'fb' => 'facebook', 'facebook' => 'facebook', 'ig' => 'instagram', 'instagram' => 'instagram', 'li' => 'linkedin',
            'linkedin' => 'linkedin', 'x' => 'x', 'twitter' => 'x', 'qr' => 'qr', 'ref' => 'referral', 'referral' => 'referral', 'email' => 'campaign', 'sms' => 'campaign', 'campaign' => 'campaign', 'portal' => 'portal'];
    if ($s === '') { return $default; }
    return $map[$s] ?? 'other';
}