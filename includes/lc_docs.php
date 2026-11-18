<?php
/**
 * NEW FILE: includes/lc_docs.php — RM Learning Centre DOCUMENT TEMPLATES (certificates and transcripts).
 * Administrators design the documents themselves: page size / orientation / margins, fonts, sizes, weights, alignment,
 * line spacing, the position of every element (certificates), the order and spacing of sections (transcripts), logo,
 * signatures, QR code, borders and decorations, and which information appears, with {placeholders} filled from the
 * Learning Centre data. Colours are limited to the approved RISE MOTIVE palette.
 *
 * Versions: "Save draft" keeps work in progress; "Approve and publish" creates an IMMUTABLE version. Every certificate
 * remembers the template version it was issued with, so editing a template later never changes issued documents.
 * Every generated transcript / certificate PDF is recorded (lc_doc_issues) with its reference and template version.
 */
require_once __DIR__ . '/lc_academic.php';
require_once __DIR__ . '/lc_qr.php';

const LC_DOC_SCHEMA = '1';
const LC_DOC_FONTS = ['dejavuserif' => ['Serif (classic)', '\'DejaVu Serif\', Georgia, \'Times New Roman\', serif'], 'dejavusans' => ['Sans (modern)', '\'DejaVu Sans\', Arial, Helvetica, sans-serif'],
                      'dejavusanscondensed' => ['Sans condensed', '\'DejaVu Sans Condensed\', \'Arial Narrow\', Arial, sans-serif'], 'dejavusansmono' => ['Monospace', '\'DejaVu Sans Mono\', Consolas, monospace']];
const LC_DOC_COLORS = ['#0B1A8C' => 'RISE MOTIVE dark blue', '#1E2FE0' => 'RISE MOTIVE blue', '#1E2333' => 'Ink (text)', '#5B6275' => 'Grey', '#0FA968' => 'Green', '#B8860B' => 'Gold', '#EEF3FF' => 'Light blue (table headers)', '#FFFFFF' => 'White', '#000000' => 'Black'];
const LC_DOC_PAGES = ['A4' => [210, 297], 'A5' => [148, 210], 'Letter' => [216, 279]];
const LC_DOC_BLOCKS = ['text' => 'Text', 'logo' => 'Logo', 'signature' => 'Signature', 'qr' => 'QR code (verification)', 'line' => 'Line / divider',
                       'modules_table' => 'Modules table', 'assessments_table' => 'Assessments table', 'summary_table' => 'Results summary'];

/** Placeholders per document kind: name => [description, sample value]. They correspond to real data. */
function lc_doc_fields(string $kind): array
{
    $common = ['institution_name' => ['Company name', 'RISE MOTIVE Ltd'], 'centre_name' => ['Learning Centre name (Settings)', 'RM Learning Centre'],
        'student_name' => ['Student full name', 'Aline Uwase'], 'student_id' => ['Student ID (= Customer ID)', 'RMC000121'], 'course_name' => ['Course title', 'Web Development Fundamentals'],
        'course_code' => ['Course code', 'WDF-001'], 'intake' => ['Intake', 'January 2027'], 'branch_name' => ['Branch', 'RISE MOTIVE – Main Branch'], 'grade' => ['Final grade', 'A'],
        'issue_date' => ['Date the document is issued', date('d F Y')], 'signatory' => ['Signatory (Settings)', 'Director, RM Learning Centre'], 'accreditation' => ['Accreditation statement (only if verified in Settings)', '']];
    if ($kind === 'certificate') {
        return $common + ['certificate_title' => ['Certificate type of the course', 'Certificate of Completion'], 'completion_date' => ['Completion date', '05 March 2027'],
            'certificate_number' => ['Certificate number', 'RMLC-C2027-00001'], 'verification_code' => ['8-character verification code', 'A1B2C3D4'], 'verification_url' => ['Verification page', 'https://learn.risemotive.rw/verify.php'],
            'duration' => ['Course duration', '8 weeks']];
    }
    return $common + ['class_code' => ['Class', 'WDF-001-JAN27-A'], 'final_mark' => ['Final mark', '83.8%'], 'attendance' => ['Attendance', '100%'], 'result' => ['Result', 'Pass'],
        'completion_status' => ['Completion status', 'Completed'], 'transcript_status' => ['FINAL or PROVISIONAL', 'FINAL'], 'pass_mark' => ['Pass mark', '60%'],
        'grading_scale' => ['Grading scale', 'A ≥ 80%, B ≥ 70%, C ≥ 60%, D ≥ 50%, F ≥ 0%'], 'document_reference' => ['Transcript reference', 'RMLC-T2027-00001'], 'certificate_number' => ['Certificate number, if issued', 'RMLC-C2027-00001'],
        'start_date' => ['Start date', '11 January 2027'], 'end_date' => ['End date', '05 March 2027'], 'duration' => ['Course duration', '8 weeks'], 'total_hours' => ['Total contact hours', '60']];
}

function lc_docs_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    lc_acad_ensure($conn);
    if (lc_setting($conn, 'doc_schema') === LC_DOC_SCHEMA) { return; }
    $q = function (string $sql) use ($conn) { try { mysqli_query($conn, $sql); } catch (Throwable $e) { error_log('lc_docs_ensure: ' . $e->getMessage()); } };
    $q('CREATE TABLE IF NOT EXISTS lc_doc_templates (id INT(11) NOT NULL AUTO_INCREMENT, kind VARCHAR(12) NOT NULL, name VARCHAR(120) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT \'Draft\',
        is_default TINYINT(1) NOT NULL DEFAULT 0, courses VARCHAR(300) NULL, draft MEDIUMTEXT NOT NULL, version INT(11) NOT NULL DEFAULT 0, created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_by INT(11) NULL, updated_at DATETIME NULL, PRIMARY KEY (id), KEY kind (kind, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_doc_versions (template_id INT(11) NOT NULL, version INT(11) NOT NULL, settings MEDIUMTEXT NOT NULL, note VARCHAR(300) NULL, approved_by INT(11) NULL,
        approved_at DATETIME NOT NULL, PRIMARY KEY (template_id, version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_doc_issues (id INT(11) NOT NULL AUTO_INCREMENT, kind VARCHAR(12) NOT NULL, reference VARCHAR(40) NULL, ref_id INT(11) NOT NULL, customer_id INT(11) NULL,
        template_id INT(11) NULL, template_version INT(11) NULL, status VARCHAR(12) NULL, channel VARCHAR(10) NOT NULL DEFAULT \'staff\', issued_by INT(11) NULL,
        issued_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY ref (kind, ref_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    foreach (['template_id INT(11) NULL', 'template_version INT(11) NULL'] as $col) {
        $name = strtok($col, ' ');
        try { $has = mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM lc_certificates LIKE '$name'")); } catch (Throwable $e) { $has = 1; }
        if (!$has) { $q("ALTER TABLE lc_certificates ADD COLUMN $col"); }
    }
    lc_setting_set($conn, 'doc_schema', LC_DOC_SCHEMA);
}

/* ------------------------------------------------------------------ defaults (same look as the built-in documents) */

function lc_doc_default_settings(string $kind): array
{
    $b = function (string $type, array $o) { return $o + ['type' => $type, 'content' => '', 'x' => 0, 'y' => 0, 'w' => 100, 'h' => 0, 'align' => 'center', 'size' => 12, 'weight' => 'normal',
        'italic' => 0, 'line' => 1.3, 'color' => '#1E2333', 'space' => 3, 'visible' => 1, 'image' => '', 'columns' => '']; };
    if ($kind === 'certificate') {
        return ['page' => ['size' => 'A4', 'orientation' => 'L', 'margins' => [12, 12, 12, 12]], 'font' => 'dejavuserif', 'base_size' => 12, 'color' => '#1E2333',
            'border' => ['style' => 'double', 'width' => 1.2, 'color' => '#0B1A8C', 'inset' => 10], 'ornament' => 'corners', 'watermark_logo' => 0,
            'blocks' => [
                $b('logo', ['x' => 133.5, 'y' => 20, 'w' => 30]),
                $b('text', ['content' => '{institution_name} · {centre_name}', 'x' => 20, 'y' => 52, 'w' => 257, 'size' => 11, 'weight' => 'bold', 'color' => '#0B1A8C']),
                $b('text', ['content' => '{certificate_title}', 'x' => 20, 'y' => 64, 'w' => 257, 'size' => 30, 'weight' => 'bold', 'color' => '#0B1A8C']),
                $b('text', ['content' => 'This is to certify that', 'x' => 20, 'y' => 84, 'w' => 257, 'size' => 13, 'italic' => 1]),
                $b('text', ['content' => '{student_name}', 'x' => 20, 'y' => 93, 'w' => 257, 'size' => 26, 'weight' => 'bold']),
                $b('text', ['content' => 'Student ID {student_id}', 'x' => 20, 'y' => 106, 'w' => 257, 'size' => 11, 'color' => '#5B6275']),
                $b('text', ['content' => 'has successfully completed the course', 'x' => 20, 'y' => 115, 'w' => 257, 'size' => 13]),
                $b('text', ['content' => '{course_name}', 'x' => 20, 'y' => 123, 'w' => 257, 'size' => 18, 'weight' => 'bold']),
                $b('text', ['content' => 'with grade {grade} · completed on {completion_date}', 'x' => 20, 'y' => 134, 'w' => 257, 'size' => 12]),
                $b('signature', ['content' => '{signatory}', 'x' => 30, 'y' => 162, 'w' => 70, 'size' => 9, 'align' => 'left']),
                $b('text', ['content' => "Issued {issue_date}\nCertificate No. {certificate_number}\nVerification code {verification_code}", 'x' => 103.5, 'y' => 160, 'w' => 90, 'size' => 8.5, 'color' => '#5B6275']),
                $b('qr', ['x' => 237, 'y' => 152, 'w' => 28]),
                $b('text', ['content' => 'Scan to verify', 'x' => 222, 'y' => 181, 'w' => 58, 'size' => 7.5, 'color' => '#5B6275']),
                $b('text', ['content' => '{accreditation}', 'x' => 20, 'y' => 188, 'w' => 257, 'size' => 7.5, 'color' => '#5B6275']),
            ]];
    }
    return ['page' => ['size' => 'A4', 'orientation' => 'P', 'margins' => [14, 14, 14, 14]], 'font' => 'dejavusans', 'base_size' => 10, 'color' => '#1E2333',
        'border' => ['style' => 'none', 'width' => 0.6, 'color' => '#0B1A8C', 'inset' => 6], 'ornament' => 'none', 'watermark_logo' => 0, 'table_color' => '#EEF3FF',
        'blocks' => [
            $b('logo', ['w' => 22, 'align' => 'left']),
            $b('text', ['content' => '{centre_name}', 'size' => 15, 'weight' => 'bold', 'color' => '#0B1A8C', 'align' => 'left', 'space' => 2]),
            $b('text', ['content' => '{institution_name}', 'size' => 9, 'color' => '#5B6275', 'align' => 'left', 'space' => 0]),
            $b('text', ['content' => 'ACADEMIC TRANSCRIPT — {transcript_status}', 'size' => 13, 'weight' => 'bold', 'align' => 'right', 'space' => 4]),
            $b('line', ['color' => '#0B1A8C', 'space' => 2]),
            $b('text', ['content' => "Student: {student_name}    Student ID: {student_id}\nCourse: {course_name} ({course_code})    Intake: {intake}\nClass: {class_code}    Branch: {branch_name}", 'size' => 10, 'align' => 'left', 'line' => 1.6, 'space' => 4]),
            $b('modules_table', ['columns' => 'code,module,hours,mark,grade', 'space' => 5]),
            $b('assessments_table', ['columns' => 'assessment,type,module,weight,mark', 'space' => 5]),
            $b('summary_table', ['space' => 5]),
            $b('text', ['content' => 'Pass mark {pass_mark} · Grading: {grading_scale}', 'size' => 8.5, 'color' => '#5B6275', 'align' => 'left', 'space' => 3]),
            $b('text', ['content' => 'Reference {document_reference} · issued {issue_date}', 'size' => 8.5, 'color' => '#5B6275', 'align' => 'left', 'space' => 2]),
            $b('signature', ['content' => '{signatory}', 'w' => 70, 'size' => 9, 'align' => 'left', 'space' => 14]),
            $b('text', ['content' => '{accreditation}', 'size' => 8, 'color' => '#5B6275', 'align' => 'left', 'space' => 4]),
        ]];
}

/* ------------------------------------------------------------------ validation of what the designer sends */

/** Cleans a settings array (from the designer form): only known keys, ranges and approved colours. */
function lc_doc_clean(string $kind, array $s): array
{
    $d = lc_doc_default_settings($kind);
    $num = function ($v, $min, $max, $def) { return is_numeric($v) ? max($min, min($max, round((float) $v, 2))) : $def; };
    $col = function ($v, $def) { $v = strtoupper((string) $v); return isset(LC_DOC_COLORS[$v]) ? $v : $def; };
    $o = ['page' => ['size' => isset(LC_DOC_PAGES[$s['page']['size'] ?? '']) ? $s['page']['size'] : $d['page']['size'], 'orientation' => ($s['page']['orientation'] ?? '') === 'L' ? 'L' : (($s['page']['orientation'] ?? '') === 'P' ? 'P' : $d['page']['orientation'])],
          'font' => isset(LC_DOC_FONTS[$s['font'] ?? '']) ? $s['font'] : $d['font'], 'base_size' => $num($s['base_size'] ?? null, 6, 24, $d['base_size']), 'color' => $col($s['color'] ?? '', $d['color']),
          'border' => ['style' => in_array($s['border']['style'] ?? '', ['none', 'solid', 'double', 'dashed'], true) ? $s['border']['style'] : $d['border']['style'],
                       'width' => $num($s['border']['width'] ?? null, 0.2, 5, $d['border']['width']), 'color' => $col($s['border']['color'] ?? '', $d['border']['color']), 'inset' => $num($s['border']['inset'] ?? null, 0, 40, $d['border']['inset'])],
          'ornament' => in_array($s['ornament'] ?? '', ['none', 'corners', 'band'], true) ? $s['ornament'] : 'none', 'watermark_logo' => !empty($s['watermark_logo']) ? 1 : 0,
          'table_color' => $col($s['table_color'] ?? '', '#EEF3FF'), 'blocks' => []];
    $m = (array) ($s['page']['margins'] ?? []);
    $o['page']['margins'] = [0 => $num($m[0] ?? null, 0, 60, 12), 1 => $num($m[1] ?? null, 0, 60, 12), 2 => $num($m[2] ?? null, 0, 60, 12), 3 => $num($m[3] ?? null, 0, 60, 12)];
    foreach (array_slice((array) ($s['blocks'] ?? []), 0, 60) as $bl) {
        $t = (string) ($bl['type'] ?? '');
        if (!isset(LC_DOC_BLOCKS[$t]) || ($kind === 'certificate' && in_array($t, ['modules_table', 'assessments_table', 'summary_table'], true))) { continue; }
        $img = (string) ($bl['image'] ?? '');
        if ($img !== '' && (strlen($img) > 400000 || !preg_match('#^data:image/(jpeg|png);base64,[A-Za-z0-9+/=]+$#', $img))) { $img = ''; }
        $o['blocks'][] = ['type' => $t, 'content' => mb_substr((string) ($bl['content'] ?? ''), 0, 2000), 'x' => $num($bl['x'] ?? 0, -20, 450, 0), 'y' => $num($bl['y'] ?? 0, -20, 450, 0),
            'w' => $num($bl['w'] ?? 100, 5, 450, 100), 'h' => $num($bl['h'] ?? 0, 0, 450, 0), 'align' => in_array($bl['align'] ?? '', ['left', 'center', 'right', 'justify'], true) ? $bl['align'] : 'left',
            'size' => $num($bl['size'] ?? 12, 5, 72, 12), 'weight' => ($bl['weight'] ?? '') === 'bold' ? 'bold' : 'normal', 'italic' => !empty($bl['italic']) ? 1 : 0, 'line' => $num($bl['line'] ?? 1.3, 0.8, 3, 1.3),
            'color' => $col($bl['color'] ?? '', '#1E2333'), 'space' => $num($bl['space'] ?? 3, 0, 80, 3), 'visible' => !empty($bl['visible']) ? 1 : 0, 'image' => $img,
            'columns' => preg_replace('/[^a-z,_]/', '', (string) ($bl['columns'] ?? ''))];
    }
    return $o;
}

/* ------------------------------------------------------------------ data */

function lc_doc_sample(string $kind): array
{
    $o = []; foreach (lc_doc_fields($kind) as $k => [$d, $v]) { $o[$k] = $v; }
    $o['_modules'] = [['code' => 'WDF-M1', 'module' => 'HTML & CSS', 'hours' => '30', 'mark' => '88.0%', 'grade' => 'A'], ['code' => 'WDF-M2', 'module' => 'JavaScript basics', 'hours' => '30', 'mark' => '78.0%', 'grade' => 'B']];
    $o['_assessments'] = [['assessment' => 'Project 1', 'type' => 'Assignment', 'module' => 'HTML & CSS', 'weight' => '30', 'mark' => '88.0%'], ['assessment' => 'Final exam', 'type' => 'Final examination', 'module' => '—', 'weight' => '40', 'mark' => '85.0%']];
    $o['_verify'] = 'https://learn.risemotive.rw/verify.php?c=SAMPLE&t=SAMPLE';
    return $o;
}

function lc_doc_common_data(mysqli $conn, ?array $course): array
{
    $co = ['name' => 'RISE MOTIVE Ltd'];
    try { require_once __DIR__ . '/report_pdf.php'; $co = report_company(); } catch (Throwable $e) { }
    $dur = $course && $course['duration_value'] ? (int) $course['duration_value'] . ' ' . $course['duration_unit'] : '';
    return ['institution_name' => $co['name'] ?? 'RISE MOTIVE Ltd', 'centre_name' => lc_setting($conn, 'title'), 'issue_date' => date('d F Y'), 'signatory' => lc_setting($conn, 'cert_signatory'),
        'accreditation' => lc_setting($conn, 'accreditation_verified') === '1' ? lc_setting($conn, 'accreditation_note') : '', 'duration' => $dur];
}

function lc_doc_cert_data(mysqli $conn, array $cert): array
{
    $course = lc_course($conn, (int) $cert['course_id']);
    $k = lc_class($conn, (int) $cert['class_id']);
    return lc_doc_common_data($conn, $course) + ['student_name' => $cert['student_name'], 'student_id' => lc_customer_id_label($conn, (int) $cert['customer_id']), 'course_name' => $cert['course_title'],
        'course_code' => $course['code'] ?? '', 'intake' => $k['intake_name'] ?? '', 'branch_name' => lc_branch_name($conn, $k && $k['branch_id'] ? (int) $k['branch_id'] : null), 'grade' => (string) $cert['grade'],
        'certificate_title' => ($course['certificate_type'] ?? '') ?: 'Certificate of Completion', 'completion_date' => $cert['completed_on'] ? date('d F Y', strtotime($cert['completed_on'])) : '',
        'issue_date' => date('d F Y', strtotime($cert['issued_at'])), 'certificate_number' => $cert['number'], 'verification_code' => lc_cert_code($cert),
        'verification_url' => lc_public_base($conn) . '/verify.php', '_verify' => lc_cert_verify_url($conn, $cert), '_void' => $cert['status'] !== 'Valid' ? $cert['status'] : ''];
}

/** Transcript data of an enrollment (FINAL when the class results are published, otherwise PROVISIONAL). */
function lc_doc_transcript_data(mysqli $conn, int $enrollmentId): array
{
    $e = mysqli_fetch_assoc(mysqli_query($conn, "SELECT e.*, cu.name, i.name intake_name, i.start_date, i.end_date FROM lc_enrollments e JOIN customers cu ON cu.id = e.customer_id LEFT JOIN lc_intakes i ON i.id = e.intake_id WHERE e.id = $enrollmentId"));
    if (!$e) { throw new RuntimeException('Enrollment not found.'); }
    $course = lc_course($conn, (int) $e['course_id']);
    $class = $e['class_id'] ? lc_class($conn, (int) $e['class_id']) : null;
    $pub = $class ? lc_published_result($conn, (int) $class['id'], (int) $e['customer_id']) : null;
    $calc = $class ? (lc_compute_results($conn, $class)[(int) $e['customer_id']] ?? null) : null;
    $fin = (bool) $pub; $pct = function ($v) { return $v === null || $v === '' ? '—' : number_format((float) $v, 1) . '%'; };
    $mods = $fin ? (json_decode((string) $pub['modules'], true) ?: []) : ($calc['modules_res'] ?? []);
    $mrows = []; $hours = 0;
    foreach (lc_modules($conn, (int) $e['course_id']) as $m) {
        $r = null; foreach ($mods as $x) { if ($x['module'] === $m['name']) { $r = $x; } }
        if (!$r && $m['status'] === 'Retired') { continue; }
        $hours += (float) $m['hours'];
        $mrows[] = ['code' => $m['code'], 'module' => $m['name'], 'hours' => $m['hours'] !== null ? rtrim(rtrim(number_format((float) $m['hours'], 1), '0'), '.') : ($m['duration'] ?: '—'), 'mark' => $r ? $pct($r['pct']) : '—', 'grade' => $r['grade'] ?? '—'];
    }
    $arows = [];
    if ($class && $calc) { foreach (lc_class_assessments($conn, (int) $class['id']) as $aid => $a) { $arows[] = ['assessment' => $a['name'], 'type' => $a['type'], 'module' => $a['module_name'] ?? '—', 'weight' => rtrim(rtrim((string) (float) $a['weight'], '0'), '.'), 'mark' => $pct($calc['per'][$aid] ?? null)]; } }
    $cert = lc_enrollment_certificate($conn, $enrollmentId);
    $dec = $fin ? $pub['decision'] : ($calc['decision'] ?? '—');
    return lc_doc_common_data($conn, $course) + ['student_name' => $e['name'], 'student_id' => lc_customer_id_label($conn, (int) $e['customer_id']), 'course_name' => $course['title'], 'course_code' => $course['code'],
        'intake' => $e['intake_name'] ?? '—', 'class_code' => $class['code'] ?? '—', 'branch_name' => lc_branch_name($conn, $e['branch_id'] ? (int) $e['branch_id'] : null),
        'final_mark' => $pct($fin ? $pub['final_pct'] : ($calc['final'] ?? null)), 'grade' => ($fin ? $pub['grade'] : ($calc['grade'] ?? null)) ?? '—', 'attendance' => $pct($fin ? $pub['attendance_pct'] : ($calc['attendance_pct'] ?? null)),
        'result' => $fin ? $dec : $dec . ' (provisional)', 'completion_status' => $e['status'], 'transcript_status' => $fin ? 'FINAL' : 'PROVISIONAL',
        'pass_mark' => (float) $course['pass_mark'] . '%', 'grading_scale' => implode(', ', array_map(function ($g) { return $g[1] . ' ≥ ' . $g[0] . '%'; }, lc_grade_scale($conn))),
        'certificate_number' => $cert['number'] ?? '—', 'start_date' => $e['start_date'] ? date('d F Y', strtotime($e['start_date'])) : '—', 'end_date' => $e['end_date'] ? date('d F Y', strtotime($e['end_date'])) : '—',
        'total_hours' => $hours ? rtrim(rtrim(number_format($hours, 1), '0'), '.') : '—', 'document_reference' => '—',
        '_modules' => $mrows, '_assessments' => $arows, '_final' => $fin, '_customer' => (int) $e['customer_id'], '_course' => (int) $e['course_id']];
}

/* ------------------------------------------------------------------ rendering (the SAME html for the preview and the PDF) */

function lc_doc_page_size(array $s): array
{
    [$w, $h] = LC_DOC_PAGES[$s['page']['size']] ?? [210, 297];
    return $s['page']['orientation'] === 'L' ? [$h, $w] : [$w, $h];
}

function lc_doc_logo_uri(): string
{
    static $u = null;
    if ($u !== null) { return $u; }
    $f = __DIR__ . '/../assets/images/logo.jpg'; $u = '';
    if (!is_file($f)) { return $u; }
    $cache = sys_get_temp_dir() . '/rm_lc_logo_' . filemtime($f) . '.jpg';
    if (!is_file($cache) && function_exists('imagecreatefromjpeg')) {
        $im = @imagecreatefromjpeg($f);
        if ($im) { $w = imagesx($im); $h = imagesy($im); $nw = min(500, $w); $nh = (int) round($h * $nw / $w); $t = imagecreatetruecolor($nw, $nh); imagefill($t, 0, 0, imagecolorallocate($t, 255, 255, 255)); imagecopyresampled($t, $im, 0, 0, 0, 0, $nw, $nh, $w, $h); imagejpeg($t, $cache, 88); }
    }
    $u = 'data:image/jpeg;base64,' . base64_encode((string) file_get_contents(is_file($cache) ? $cache : $f));
    return $u;
}

/**
 * HTML of a document. $mode 'pdf' (mPDF, absolute positions relative to the page) or 'preview' (browser, same mm units).
 * $data = placeholders (+ _modules, _assessments, _verify, _void). Missing placeholders print nothing (never "{xyz}").
 */
function lc_doc_html(string $kind, array $s, array $data, string $mode = 'pdf'): string
{
    $h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $fill = function (string $t) use ($data, $h) { return nl2br(preg_replace_callback('/ {2,}/', function ($m) { return str_repeat('&nbsp;', strlen($m[0]) - 1) . ' '; }, preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($data, $h) { return isset($data[$m[1]]) && !is_array($data[$m[1]]) ? $h($data[$m[1]]) : ''; }, $h($t)))); };
    [$W, $H] = lc_doc_page_size($s);
    $font = LC_DOC_FONTS[$s['font']][1] ?? 'serif';
    $ff = $mode === 'pdf' ? $s['font'] : $font;
    $positioned = $kind === 'certificate';
    $mg = $s['page']['margins'];
    $out = '';
    $style = function (array $b) use ($positioned, $ff) {
        $st = 'font-size:' . $b['size'] . 'pt;font-weight:' . $b['weight'] . ';' . ($b['italic'] ? 'font-style:italic;' : '') . 'color:' . $b['color'] . ';line-height:' . $b['line'] . ';text-align:' . $b['align'] . ';font-family:' . $ff . ';';
        return $positioned ? 'position:absolute;left:' . $b['x'] . 'mm;top:' . $b['y'] . 'mm;width:' . $b['w'] . 'mm;' . ($b['h'] ? 'height:' . $b['h'] . 'mm;overflow:hidden;' : '') . $st : 'margin-top:' . $b['space'] . 'mm;' . $st;
    };
    $tbl = function (array $cols, array $rows, array $labels) use ($h, $s, $ff) {
        $t = '<table style="width:100%;border-collapse:collapse;font-family:' . $ff . ';font-size:9pt;"><tr>';
        foreach ($cols as $c) { $t .= '<th style="background:' . $s['table_color'] . ';text-align:left;padding:4px 6px;border-bottom:0.5px solid #ccd;">' . $h($labels[$c] ?? $c) . '</th>'; }
        $t .= '</tr>';
        foreach ($rows as $r) { $t .= '<tr>'; foreach ($cols as $c) { $t .= '<td style="padding:4px 6px;border-bottom:0.5px solid #dde;">' . $h($r[$c] ?? '') . '</td>'; } $t .= '</tr>'; }
        return $t . '</table>';
    };
    // decorations
    $deco = '';
    if ($s['border']['style'] !== 'none') {
        $in = $s['border']['inset'];
        $deco .= '<div style="position:absolute;left:' . $in . 'mm;top:' . $in . 'mm;width:' . ($W - 2 * $in) . 'mm;height:' . ($H - 2 * $in) . 'mm;border:' . ($s['border']['style'] === 'double' ? max(1.5, $s['border']['width'] * 2.5) : $s['border']['width']) . 'mm ' . $s['border']['style'] . ' ' . $s['border']['color'] . ';box-sizing:border-box;"></div>';
    }
    if ($s['ornament'] === 'corners') {
        $in = $s['border']['inset'] + 4; $c = $s['border']['color']; $L = 14;
        foreach ([[$in, $in, 'top', 'left'], [$W - $in - $L, $in, 'top', 'right'], [$in, $H - $in - $L, 'bottom', 'left'], [$W - $in - $L, $H - $in - $L, 'bottom', 'right']] as [$x, $y, $v, $hz]) {
            $deco .= '<div style="position:absolute;left:' . $x . 'mm;top:' . $y . 'mm;width:' . $L . 'mm;height:' . $L . 'mm;border-' . $v . ':0.9mm solid ' . $c . ';border-' . $hz . ':0.9mm solid ' . $c . ';"></div>';
        }
    }
    if ($s['ornament'] === 'band') { $deco .= '<div style="position:absolute;left:0;top:0;width:' . $W . 'mm;height:9mm;background:' . $s['border']['color'] . ';"></div><div style="position:absolute;left:0;top:' . ($H - 9) . 'mm;width:' . $W . 'mm;height:9mm;background:' . $s['border']['color'] . ';"></div>'; }
    if ($s['watermark_logo'] && ($lu = lc_doc_logo_uri())) { $ww = min($W, $H) * 0.55; $deco .= '<div style="position:absolute;left:' . (($W - $ww) / 2) . 'mm;top:' . (($H - $ww) / 2) . 'mm;width:' . $ww . 'mm;opacity:0.06;"><img src="' . $lu . '" style="width:' . $ww . 'mm;"></div>'; }
    if (!empty($data['_void'])) { $deco .= '<div style="position:absolute;left:0;top:' . ($H / 2 - 12) . 'mm;width:' . $W . 'mm;text-align:center;font-size:40pt;font-weight:bold;color:#C0392B;opacity:0.35;font-family:' . $ff . ';">' . $h(strtoupper($data['_void'])) . '</div>'; }
    foreach ($s['blocks'] as $bi => $b) {
        if (!$b['visible']) { continue; }
        $st = $style($b);
        $before = strlen($out);
        switch ($b['type']) {
            case 'text':
                $txt = $fill($b['content']);
                if (trim(strip_tags($txt)) === '' && !$positioned) { continue 2; }
                $out .= '<div style="' . $st . '">' . $txt . '</div>'; break;
            case 'logo':
                $src = $b['image'] ?: lc_doc_logo_uri(); if (!$src) { break; }
                $out .= '<div style="' . $st . '"><img src="' . $src . '" style="width:' . $b['w'] . 'mm;"></div>'; break;
            case 'signature':
                $out .= '<div style="' . $st . '">' . ($b['image'] ? '<img src="' . $b['image'] . '" style="height:14mm;"><br>' : '<div style="height:12mm;"></div>')
                    . '<div style="border-top:0.3mm solid #333;width:' . ($positioned ? '100%' : $b['w'] . 'mm') . ';padding-top:1mm;' . ($b['align'] === 'center' && !$positioned ? 'margin:0 auto;' : ($b['align'] === 'right' && !$positioned ? 'margin-left:auto;' : '')) . '">' . $fill($b['content']) . '</div></div>'; break;
            case 'qr':
                $q = lc_qr_data_uri((string) ($data['_verify'] ?? 'https://risemotive.rw'), 5);
                $out .= '<div style="' . $st . '"><img src="' . $q . '" style="width:' . $b['w'] . 'mm;height:' . $b['w'] . 'mm;"></div>'; break;
            case 'line':
                $out .= '<div style="' . $st . 'border-top:0.4mm solid ' . $b['color'] . ';height:0;font-size:1pt;line-height:1;">&nbsp;</div>'; break;
            case 'modules_table':
                if ($positioned || empty($data['_modules'])) { break; }
                $cols = array_values(array_filter(explode(',', $b['columns'] ?: 'code,module,hours,mark,grade')));
                $out .= '<div style="' . $st . '"><div style="font-weight:bold;margin-bottom:1mm;">' . $fill($b['content'] ?: 'Modules') . '</div>' . $tbl($cols, $data['_modules'], ['code' => 'Code', 'module' => 'Module', 'hours' => 'Hours', 'mark' => 'Mark', 'grade' => 'Grade']) . '</div>'; break;
            case 'assessments_table':
                if ($positioned || empty($data['_assessments'])) { break; }
                $cols = array_values(array_filter(explode(',', $b['columns'] ?: 'assessment,type,module,weight,mark')));
                $out .= '<div style="' . $st . '"><div style="font-weight:bold;margin-bottom:1mm;">' . $fill($b['content'] ?: 'Assessments') . '</div>' . $tbl($cols, $data['_assessments'], ['assessment' => 'Assessment', 'type' => 'Type', 'module' => 'Module', 'weight' => 'Weight %', 'mark' => 'Mark']) . '</div>'; break;
            case 'summary_table':
                if ($positioned) { break; }
                $out .= '<div style="' . $st . '">' . $tbl(['a', 'b', 'c', 'd'], [['a' => $data['final_mark'] ?? '—', 'b' => $data['grade'] ?? '—', 'c' => $data['attendance'] ?? '—', 'd' => $data['result'] ?? '—']], ['a' => 'Final mark', 'b' => 'Grade', 'c' => 'Attendance', 'd' => 'Result']) . '</div>'; break;
        }
        // Preview: each element can be selected (and dragged, on certificates) in the designer.
        if ($mode !== 'pdf' && strlen($out) > $before) { $out = substr($out, 0, $before) . preg_replace('/^<div /', '<div data-b="' . $bi . '" ', substr($out, $before), 1); }
    }
    if ($mode === 'pdf') {
        return '<html><body style="font-family:' . $ff . ';font-size:' . $s['base_size'] . 'pt;color:' . $s['color'] . ';">' . $deco . ($positioned ? $out : '<div>' . $out . '</div>') . '</body></html>';
    }
    // Browser preview: a sheet of the real size (mm), the same blocks.
    return '<div class="lc-sheet" style="position:relative;width:' . $W . 'mm;' . ($positioned ? 'height:' . $H . 'mm;' : 'min-height:' . $H . 'mm;') . 'background:#fff;box-shadow:0 2px 14px rgba(0,0,0,.18);overflow:hidden;font-family:' . $font . ';font-size:' . $s['base_size'] . 'pt;color:' . $s['color'] . ';box-sizing:border-box;'
        . ($positioned ? '' : 'padding:' . $mg[0] . 'mm ' . $mg[1] . 'mm ' . $mg[2] . 'mm ' . $mg[3] . 'mm;') . '">' . $deco . $out . '</div>';
}

/** The PDF of a document (page size, orientation and margins of the template). */
function lc_doc_pdf(string $kind, array $s, array $data, string $filename, bool $download = false): void
{
    require_once __DIR__ . '/../vendor/autoload.php';
    [$W, $H] = lc_doc_page_size($s);
    $mg = $s['page']['margins'];
    $pos = $kind === 'certificate';
    $tmp = sys_get_temp_dir() . '/mpdf'; if (!is_dir($tmp)) { @mkdir($tmp, 0775, true); }
    $m = new \Mpdf\Mpdf(['format' => [$W, $H], 'orientation' => 'P', 'margin_top' => $pos ? 0 : $mg[0], 'margin_right' => $pos ? 0 : $mg[1], 'margin_bottom' => $pos ? 0 : $mg[2], 'margin_left' => $pos ? 0 : $mg[3], 'tempDir' => $tmp]);
    $m->SetTitle($filename);
    $m->WriteHTML(lc_doc_html($kind, $s, $data, 'pdf'));
    while (ob_get_level() > 0) { ob_end_clean(); }
    $m->Output($filename, $download ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);
}

/* ------------------------------------------------------------------ templates */

function lc_doc_template(mysqli $conn, int $id): ?array
{
    $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT t.*, u.names updated_by_name FROM lc_doc_templates t LEFT JOIN users u ON u.id = t.updated_by WHERE t.id = $id"));
    if ($t) { $t['settings'] = lc_doc_clean($t['kind'], json_decode((string) $t['draft'], true) ?: []); }
    return $t ?: null;
}

function lc_doc_version_settings(mysqli $conn, int $templateId, int $version): ?array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT v.settings, t.kind FROM lc_doc_versions v JOIN lc_doc_templates t ON t.id = v.template_id WHERE v.template_id = $templateId AND v.version = $version"));
    return $r ? lc_doc_clean($r['kind'], json_decode((string) $r['settings'], true) ?: []) : null;
}

/** The template (approved version) to use for a document: active template for this course, else the active default. */
function lc_doc_pick(mysqli $conn, string $kind, ?int $courseId): ?array
{
    lc_docs_ensure($conn);
    $k = mysqli_real_escape_string($conn, $kind);
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT id, version, courses, is_default FROM lc_doc_templates WHERE kind = '$k' AND status = 'Active' AND version > 0 ORDER BY is_default DESC, id"), MYSQLI_ASSOC);
    $pick = null;
    foreach ($rows as $r) { if ($courseId && in_array((string) $courseId, explode(',', (string) $r['courses']), true)) { $pick = $r; break; } }
    if (!$pick) { foreach ($rows as $r) { if ((int) $r['is_default'] === 1) { $pick = $r; break; } } }
    if (!$pick) { return null; }
    $s = lc_doc_version_settings($conn, (int) $pick['id'], (int) $pick['version']);
    return $s ? ['id' => (int) $pick['id'], 'version' => (int) $pick['version'], 'settings' => $s] : null;
}

/** Records a generated document (kept for the register; certificates also keep their template version). */
function lc_doc_record(mysqli $conn, string $kind, int $refId, ?int $customerId, ?string $reference, ?array $tpl, string $status, string $channel): int
{
    lc_docs_ensure($conn);
    $tid = $tpl['id'] ?? null; $tv = $tpl['version'] ?? null; $uid = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $s = mysqli_prepare($conn, 'INSERT INTO lc_doc_issues (kind, reference, ref_id, customer_id, template_id, template_version, status, channel, issued_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'ssiiiissi', $kind, $reference, $refId, $customerId, $tid, $tv, $status, $channel, $uid);
    mysqli_stmt_execute($s);
    return (int) mysqli_insert_id($conn);
}

/** Final transcript reference (one per enrollment, kept): RMLC-T{YYYY}-{NNNNN}. */
function lc_doc_transcript_ref(mysqli $conn, int $enrollmentId): string
{
    $r = mysqli_fetch_row(mysqli_query($conn, "SELECT reference FROM lc_doc_issues WHERE kind = 'transcript' AND ref_id = $enrollmentId AND status = 'FINAL' AND reference IS NOT NULL ORDER BY id LIMIT 1"));
    if ($r) { return $r[0]; }
    return 'RMLC-T' . date('Y') . '-' . str_pad((string) lc_next_seq($conn, 'transcript_' . date('Y')), 5, '0', STR_PAD_LEFT);
}

/**
 * Generates a certificate PDF: the template version stored on the certificate (never a newer one), else the built-in layout.
 */
function lc_doc_certificate_output(mysqli $conn, array $cert, string $channel, bool $download = false): void
{
    lc_docs_ensure($conn);
    $tpl = null;
    if (!empty($cert['template_id']) && !empty($cert['template_version'])) {
        $s = lc_doc_version_settings($conn, (int) $cert['template_id'], (int) $cert['template_version']);
        if ($s) { $tpl = ['id' => (int) $cert['template_id'], 'version' => (int) $cert['template_version'], 'settings' => $s]; }
    }
    lc_doc_record($conn, 'certificate', (int) $cert['id'], (int) $cert['customer_id'], $cert['number'], $tpl, $cert['status'], $channel);
    if ($tpl) { lc_doc_pdf('certificate', $tpl['settings'], lc_doc_cert_data($conn, $cert), 'certificate-' . $cert['number'] . '.pdf', $download); return; }
    lc_pdf_output(lc_certificate_html($conn, $cert), 'certificate-' . $cert['number'] . '.pdf', true);
}

/** Generates a transcript PDF with the active template (or the built-in layout). FINAL transcripts get a kept reference. */
function lc_doc_transcript_output(mysqli $conn, int $enrollmentId, string $channel, bool $download = false): void
{
    lc_docs_ensure($conn);
    $d = lc_doc_transcript_data($conn, $enrollmentId);
    $tpl = lc_doc_pick($conn, 'transcript', $d['_course']);
    $ref = $d['_final'] ? lc_doc_transcript_ref($conn, $enrollmentId) : null;
    $d['document_reference'] = $ref ?? 'provisional — no reference';
    lc_doc_record($conn, 'transcript', $enrollmentId, $d['_customer'], $ref, $tpl, $d['transcript_status'], $channel);
    $name = 'transcript-' . $d['student_id'] . '.pdf';
    if ($tpl) { lc_doc_pdf('transcript', $tpl['settings'], $d, $name, $download); return; }
    lc_pdf_output(lc_transcript_html($conn, $enrollmentId), $name);
}