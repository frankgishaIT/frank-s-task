<?php
/**
 * NEW FILE: includes/lib_access.php — CUSTOMER ACCESS to RM Digital Library (customers, Learning Centre students, instructors).
 *
 *   Every document has an ACCESS SETTING, chosen when it is uploaded or managed (no request-and-approval by customers):
 *     staff      Staff only (default)            — only RISE MOTIVE employees, with the existing folder rules.
 *     customers  All customers                   — every signed-in Customer Portal account.
 *     enrolled   Enrolled students               — customers with a Learning Centre enrollment (any course).
 *     courses    Selected courses                — students enrolled in the chosen courses (optionally one class only).
 *     users      Selected customers              — the customers chosen by name / Customer ID.
 *   Administrators choose which settings are offered, the default, and which enrollment statuses count as "enrolled"
 *   (Library → Customer access settings).
 *   Customers see a document only when it is Active (approved), not archived, not in the Trash, not expired, and their access
 *   setting allows it. "Allow download" can be switched off per document (view only).
 *   Instructors publish materials and assignments for the courses / classes they teach (RM Learning Centre → Course materials).
 *   Every access change, customer view and download is written to the Library activity log.
 */
require_once __DIR__ . '/evidence_store.php';

const LIB_AUDIENCES = ['staff' => 'Staff only', 'customers' => 'All customers', 'enrolled' => 'Enrolled students', 'courses' => 'Selected courses', 'users' => 'Selected customers'];
const LIB_AUD_HELP = ['staff' => 'Only authorised RISE MOTIVE employees.', 'customers' => 'Every customer signed in to the Customer Portal.',
    'enrolled' => 'Every Learning Centre student (enrolled in any course).', 'courses' => 'Only students enrolled in the courses (or classes) you choose.',
    'users' => 'Only the customers / students you choose.'];
const LIB_KINDS = ['material' => 'Learning material', 'assignment' => 'Assignment', 'course_doc' => 'Course document', 'general' => 'General document'];
const LIB_ENROLL_STATUSES = ['Registered', 'Completed', 'Failed', 'Discontinued', 'Withdrawn'];
const LIB_VIEWABLE = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt'];

function lib_access_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $has = function (string $t, string $c) use ($conn): bool { try { return (bool) mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM $t LIKE '$c'")); } catch (Throwable $e) { return true; } };
    foreach (['audience VARCHAR(12) NOT NULL DEFAULT \'staff\'', 'allow_download TINYINT(1) NOT NULL DEFAULT 1', 'kind VARCHAR(12) NULL', 'course_id INT(11) NULL',
              'due_date DATE NULL', 'published_at DATETIME NULL', 'published_by INT(11) NULL'] as $col) {
        $n = strtok($col, ' ');
        if (!$has('lib_documents', $n)) { mysqli_query($conn, "ALTER TABLE lib_documents ADD COLUMN $col"); }
    }
    if (!$has('lib_activity', 'customer_id')) { mysqli_query($conn, 'ALTER TABLE lib_activity ADD COLUMN customer_id INT(11) NULL'); }
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS lib_doc_courses (document_id INT(11) NOT NULL, course_id INT(11) NOT NULL, class_id INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (document_id, course_id, class_id), KEY course (course_id)) ENGINE=InnoDB');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS lib_doc_customers (document_id INT(11) NOT NULL, customer_id INT(11) NOT NULL,
        PRIMARY KEY (document_id, customer_id), KEY customer (customer_id)) ENGINE=InnoDB');
}

/** Customer-access settings (Library → Customer access settings). */
function lib_aud_settings(mysqli $conn): array
{
    $d = ['portal_enabled' => '1', 'audiences' => implode(',', array_keys(LIB_AUDIENCES)), 'default_audience' => 'staff', 'enrolled_statuses' => 'Registered,Completed,Failed',
          'download_default' => '1', 'instructors_publish' => '1'];
    try { foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT name, value FROM lib_settings WHERE name LIKE 'aud_%'"), MYSQLI_ASSOC) as $r) { $d[substr($r['name'], 4)] = (string) $r['value']; } } catch (Throwable $e) { }
    $d['audiences_list'] = array_values(array_intersect(array_keys(LIB_AUDIENCES), explode(',', $d['audiences'])));
    if (!in_array('staff', $d['audiences_list'], true)) { array_unshift($d['audiences_list'], 'staff'); } // Staff only is always offered
    if (!in_array($d['default_audience'], $d['audiences_list'], true)) { $d['default_audience'] = 'staff'; }
    $d['statuses_list'] = array_values(array_intersect(LIB_ENROLL_STATUSES, explode(',', $d['enrolled_statuses']))) ?: ['Registered'];
    return $d;
}

function lib_aud_setting_save(mysqli $conn, string $k, string $v): void
{
    $n = 'aud_' . $k;
    $s = mysqli_prepare($conn, 'DELETE FROM lib_settings WHERE name = ?'); mysqli_stmt_bind_param($s, 's', $n); mysqli_stmt_execute($s);
    $s = mysqli_prepare($conn, 'INSERT INTO lib_settings (name, value) VALUES (?, ?)'); mysqli_stmt_bind_param($s, 'ss', $n, $v); mysqli_stmt_execute($s);
}

/** Learning Centre enrollments of a customer that count for library access: list of [course_id, class_id]. */
function lib_customer_enrollments(mysqli $conn, int $customerId): array
{
    try {
        $st = "'" . implode("','", array_map(function ($x) use ($conn) { return mysqli_real_escape_string($conn, $x); }, lib_aud_settings($conn)['statuses_list'])) . "'";
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT course_id, COALESCE(class_id, 0) class_id FROM lc_enrollments WHERE customer_id = $customerId AND status IN ($st)"), MYSQLI_ASSOC);
    } catch (Throwable $e) { return []; }
    return array_map(function ($r) { return [(int) $r['course_id'], (int) $r['class_id']]; }, $rows);
}

/** SQL condition (on alias d) for the documents a customer may see. Enforced on every list, view and download. */
function lib_customer_where(mysqli $conn, int $customerId): string
{
    $set = lib_aud_settings($conn);
    if ($set['portal_enabled'] !== '1') { return '1 = 0'; }
    $enr = lib_customer_enrollments($conn, $customerId);
    $or = [];
    $on = $set['audiences_list'];
    if (in_array('customers', $on, true)) { $or[] = "d.audience = 'customers'"; }
    if (in_array('enrolled', $on, true) && $enr) { $or[] = "d.audience = 'enrolled'"; }
    if (in_array('courses', $on, true) && $enr) {
        $pairs = array_map(function ($p) { return '(x.course_id = ' . $p[0] . ' AND (x.class_id = 0' . ($p[1] ? ' OR x.class_id = ' . $p[1] : '') . '))'; }, $enr);
        $or[] = "(d.audience = 'courses' AND EXISTS (SELECT 1 FROM lib_doc_courses x WHERE x.document_id = d.id AND (" . implode(' OR ', $pairs) . ')))';
    }
    if (in_array('users', $on, true)) { $or[] = "(d.audience = 'users' AND EXISTS (SELECT 1 FROM lib_doc_customers y WHERE y.document_id = d.id AND y.customer_id = $customerId))"; }
    if (!$or) { return '1 = 0'; }
    return "d.deleted_at IS NULL AND d.status = 'Active' AND d.current_version > 0 AND (d.expiry_date IS NULL OR d.expiry_date >= '" . date('Y-m-d') . "') AND (" . implode(' OR ', $or) . ')';
}

/** Documents a customer may see (newest first), with optional search / course / kind filters. */
function lib_customer_documents(mysqli $conn, int $customerId, string $q = '', int $courseId = 0, string $kind = ''): array
{
    $w = [lib_customer_where($conn, $customerId)]; $types = ''; $p = [];
    if ($q !== '') { $w[] = '(d.title LIKE ? OR d.description LIKE ? OR d.reference_no LIKE ?)'; $like = '%' . $q . '%'; $types .= 'sss'; array_push($p, $like, $like, $like); }
    if ($courseId) { $w[] = '(d.course_id = ' . $courseId . ' OR EXISTS (SELECT 1 FROM lib_doc_courses z WHERE z.document_id = d.id AND z.course_id = ' . $courseId . '))'; }
    if ($kind !== '' && isset(LIB_KINDS[$kind])) { $w[] = 'd.kind = ?'; $types .= 's'; $p[] = $kind; }
    $s = mysqli_prepare($conn, 'SELECT d.*, v.id version_id, v.original_name, v.size_bytes, v.created_at version_at FROM lib_documents d
        LEFT JOIN lib_versions v ON v.document_id = d.id AND v.version_no = d.current_version WHERE ' . implode(' AND ', $w) . ' ORDER BY COALESCE(d.published_at, d.created_at) DESC LIMIT 300');
    if ($types !== '') { mysqli_stmt_bind_param($s, $types, ...$p); }
    mysqli_stmt_execute($s);
    return mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
}

/** One document for a customer (null when they may not see it). */
function lib_customer_document(mysqli $conn, int $customerId, int $docId): ?array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT d.*, v.id version_id, v.file_ref, v.original_name, v.size_bytes FROM lib_documents d
        LEFT JOIN lib_versions v ON v.document_id = d.id AND v.version_no = d.current_version WHERE d.id = ' . $docId . ' AND ' . lib_customer_where($conn, $customerId)));
    return $r ?: null;
}

/** Courses linked to a document: list of [course_id, class_id]. */
function lib_doc_courses(mysqli $conn, int $docId): array
{
    return array_map(function ($r) { return [(int) $r['course_id'], (int) $r['class_id']]; }, mysqli_fetch_all(mysqli_query($conn, "SELECT course_id, class_id FROM lib_doc_courses WHERE document_id = $docId ORDER BY course_id, class_id"), MYSQLI_ASSOC));
}

function lib_doc_customers(mysqli $conn, int $docId): array
{
    return mysqli_fetch_all(mysqli_query($conn, "SELECT c.id, c.name, c.phone FROM lib_doc_customers x JOIN customers c ON c.id = x.customer_id WHERE x.document_id = $docId ORDER BY c.name"), MYSQLI_ASSOC);
}

/** Short description of a document's access, e.g. "Selected courses: WDF-001 (class WDF-001-JAN27-A)". */
function lib_access_label(mysqli $conn, array $doc): string
{
    $a = $doc['audience'] ?? 'staff';
    $l = LIB_AUDIENCES[$a] ?? 'Staff only';
    if ($a === 'courses') {
        $parts = [];
        foreach (lib_doc_courses($conn, (int) $doc['id']) as [$c, $k]) {
            $code = mysqli_fetch_row(mysqli_query($conn, "SELECT code FROM lc_courses WHERE id = $c"))[0] ?? '#' . $c;
            $parts[] = $code . ($k ? ' (class ' . (mysqli_fetch_row(mysqli_query($conn, "SELECT code FROM lc_classes WHERE id = $k"))[0] ?? '#' . $k) . ')' : '');
        }
        $l .= ': ' . ($parts ? implode(', ', $parts) : 'none chosen');
    }
    if ($a === 'users') { $l .= ': ' . count(lib_doc_customers($conn, (int) $doc['id'])) . ' customer(s)'; }
    if ($a !== 'staff' && empty($doc['allow_download'])) { $l .= ' · view only'; }
    return $l;
}

/**
 * Reads the access part of a form ($_POST): audience, aud_course[] / aud_class[] pairs, aud_customers (ids), allow_download, kind, due_date.
 * $limitCourses: for instructors, the [course_id => [class ids]] they may publish to (audience forced to Selected courses).
 */
function lib_access_from_post(mysqli $conn, array $post, ?array $limitCourses = null): array
{
    $set = lib_aud_settings($conn);
    $a = (string) ($post['audience'] ?? $set['default_audience']);
    if ($limitCourses !== null) { $a = 'courses'; }
    if (!in_array($a, $set['audiences_list'], true) && $limitCourses === null) { throw new RuntimeException('This access setting is not available.'); }
    $courses = [];
    foreach ((array) ($post['aud_course'] ?? []) as $i => $c) {
        $c = (int) $c; $k = (int) (($post['aud_class'] ?? [])[$i] ?? 0);
        if (!$c) { continue; }
        if ($limitCourses !== null) {
            if (!isset($limitCourses[$c])) { throw new RuntimeException('You can publish only to the courses you teach.'); }
            if ($k && $limitCourses[$c] !== ['*'] && !in_array($k, $limitCourses[$c], true)) { throw new RuntimeException('You can publish only to the classes you teach.'); }
            if (!$k && $limitCourses[$c] !== ['*']) { throw new RuntimeException('Choose one of your classes (you do not teach every class of this course).'); }
        }
        if ($k && !(int) (mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_classes WHERE id = $k AND course_id = $c"))[0] ?? 0)) { throw new RuntimeException('That class is not a class of the chosen course.'); }
        $courses[$c . '-' . $k] = [$c, $k];
    }
    $customers = array_values(array_unique(array_filter(array_map('intval', preg_split('/[\s,]+/', (string) ($post['aud_customers'] ?? ''))))));
    if ($a === 'courses' && !$courses) { throw new RuntimeException('Choose at least one course.'); }
    if ($a === 'users') {
        if (!$customers) { throw new RuntimeException('Choose at least one customer.'); }
        $found = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM customers WHERE id IN (' . implode(',', $customers) . ')'))[0];
        if ($found !== count($customers)) { throw new RuntimeException('One of the chosen customers does not exist.'); }
    }
    $kind = isset(LIB_KINDS[$post['kind'] ?? '']) ? $post['kind'] : null;
    $due = (string) ($post['due_date'] ?? '');
    $due = $due !== '' && DateTime::createFromFormat('Y-m-d', $due) ? $due : null;
    return ['audience' => $a, 'courses' => array_values($courses), 'customers' => $a === 'users' ? $customers : [], 'allow_download' => !empty($post['allow_download']) ? 1 : 0,
            'kind' => $kind, 'due_date' => $kind === 'assignment' ? $due : null];
}

/** Saves a document's access; logs what changed (Library activity log). Returns the new label. */
function lib_access_save(mysqli $conn, int $docId, array $acc, int $userId): string
{
    $doc = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lib_documents WHERE id = $docId"));
    $before = $doc ? lib_access_label($conn, $doc) : '';
    $courseId = $acc['courses'][0][0] ?? null;
    $pub = $acc['audience'] !== 'staff' && (($doc['audience'] ?? 'staff') === 'staff' || empty($doc['published_at'])) ? date('Y-m-d H:i:s') : ($acc['audience'] === 'staff' ? null : $doc['published_at']);
    $pubBy = $acc['audience'] !== 'staff' ? ($pub === ($doc['published_at'] ?? null) ? (int) $doc['published_by'] : $userId) : null;
    $s = mysqli_prepare($conn, 'UPDATE lib_documents SET audience = ?, allow_download = ?, kind = ?, course_id = ?, due_date = ?, published_at = ?, published_by = ? WHERE id = ?');
    mysqli_stmt_bind_param($s, 'sisissii', $acc['audience'], $acc['allow_download'], $acc['kind'], $courseId, $acc['due_date'], $pub, $pubBy, $docId);
    mysqli_stmt_execute($s);
    mysqli_query($conn, "DELETE FROM lib_doc_courses WHERE document_id = $docId");
    mysqli_query($conn, "DELETE FROM lib_doc_customers WHERE document_id = $docId");
    if ($acc['audience'] === 'courses') { $i = mysqli_prepare($conn, 'INSERT INTO lib_doc_courses (document_id, course_id, class_id) VALUES (?, ?, ?)'); foreach ($acc['courses'] as [$c, $k]) { mysqli_stmt_bind_param($i, 'iii', $docId, $c, $k); mysqli_stmt_execute($i); } }
    if ($acc['audience'] === 'users') { $i = mysqli_prepare($conn, 'INSERT INTO lib_doc_customers (document_id, customer_id) VALUES (?, ?)'); foreach ($acc['customers'] as $c) { mysqli_stmt_bind_param($i, 'ii', $docId, $c); mysqli_stmt_execute($i); } }
    $after = lib_access_label($conn, mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM lib_documents WHERE id = $docId")));
    if ($before !== $after) { lib_access_log($conn, $docId, $doc ? (int) $doc['folder_id'] : null, $userId, null, 'access_changed', ($before !== '' ? $before . ' → ' : '') . $after); }
    return $after;
}

/** Writes a Library activity row for an employee (userId) or a customer (customerId). */
function lib_access_log(mysqli $conn, ?int $docId, ?int $folderId, ?int $userId, ?int $customerId, string $action, string $details = ''): void
{
    $d = mb_substr($details, 0, 500);
    $s = mysqli_prepare($conn, 'INSERT INTO lib_activity (document_id, folder_id, user_id, customer_id, action, details) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iiiiss', $docId, $folderId, $userId, $customerId, $action, $d);
    mysqli_stmt_execute($s);
}

/** The folder for a course's materials: "Learning Centre materials / <COURSE CODE> — <title>" (created when needed). */
function lib_course_folder(mysqli $conn, int $courseId, int $userId): int
{
    $root = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM lib_folders WHERE parent_id IS NULL AND name = 'Learning Centre materials'"))[0] ?? 0);
    if (!$root) {
        $n = 'Learning Centre materials'; $d = 'Course materials and assignments published by instructors (created automatically).';
        $s = mysqli_prepare($conn, 'INSERT INTO lib_folders (name, description, sort_order, created_by) VALUES (?, ?, 90, ?)'); mysqli_stmt_bind_param($s, 'ssi', $n, $d, $userId); mysqli_stmt_execute($s);
        $root = (int) mysqli_insert_id($conn);
    }
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT code, title FROM lc_courses WHERE id = $courseId"));
    if (!$c) { throw new RuntimeException('Course not found.'); }
    $name = mb_substr($c['code'] . ' — ' . $c['title'], 0, 150);
    $s = mysqli_prepare($conn, 'SELECT id FROM lib_folders WHERE parent_id = ? AND name = ?'); mysqli_stmt_bind_param($s, 'is', $root, $name); mysqli_stmt_execute($s);
    $id = (int) (mysqli_fetch_row(mysqli_stmt_get_result($s))[0] ?? 0);
    if ($id) { return $id; }
    $s = mysqli_prepare($conn, 'INSERT INTO lib_folders (parent_id, name, created_by) VALUES (?, ?, ?)'); mysqli_stmt_bind_param($s, 'isi', $root, $name, $userId); mysqli_stmt_execute($s);
    return (int) mysqli_insert_id($conn);
}

/**
 * The access fields of a form (upload, document page, course materials). $doc: current values (or null for a new upload).
 * $courseOptions: [course_id => [label, [class_id => class label]]]; $instructor: only "Selected courses" with the instructor's courses.
 */
function lib_access_fields(mysqli $conn, ?array $doc, array $courseOptions, bool $instructor = false): string
{
    $set = lib_aud_settings($conn);
    $e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $a = $instructor ? 'courses' : ($doc['audience'] ?? ($_POST['audience'] ?? $set['default_audience']));
    $rows = $doc ? lib_doc_courses($conn, (int) $doc['id']) : [];
    if (!$rows && !empty($_POST['aud_course'])) { foreach ((array) $_POST['aud_course'] as $i => $c) { $rows[] = [(int) $c, (int) (($_POST['aud_class'] ?? [])[$i] ?? 0)]; } }
    if (!$rows) { $rows[] = [0, 0]; }
    $custs = $doc ? lib_doc_customers($conn, (int) $doc['id']) : [];
    $dl = $doc ? (int) $doc['allow_download'] : (isset($_POST['audience']) ? (int) !empty($_POST['allow_download']) : (int) $set['download_default']);
    $kind = $doc['kind'] ?? ($_POST['kind'] ?? ($instructor ? 'material' : ''));
    $id = 'aud' . substr(md5((string) mt_rand()), 0, 6);
    $courseSel = function ($cur, $curK) use ($courseOptions, $e) {
        $o = '<option value="">Choose a course</option>';
        foreach ($courseOptions as $cid => [$l]) { $o .= '<option value="' . $cid . '"' . ($cid === $cur ? ' selected' : '') . '>' . $e($l) . '</option>'; }
        $k = '<option value="0">All its students</option>';
        foreach ($courseOptions as $cid => [, $classes]) { foreach ($classes as $kid => $kl) { $k .= '<option value="' . $kid . '" data-c="' . $cid . '"' . ($kid === $curK ? ' selected' : '') . '>Class ' . $e($kl) . '</option>'; } }
        return '<div class="d-flex gap-1 mb-1 aud-row"><select name="aud_course[]" class="form-select form-select-sm">' . $o . '</select><select name="aud_class[]" class="form-select form-select-sm">' . $k . '</select>'
            . '<button type="button" class="btn btn-sm btn-light border aud-del" title="Remove">×</button></div>';
    };
    ob_start(); ?>
<div class="lib-aud border rounded-3 p-2 mb-3" id="<?= $id; ?>" style="background:#FAFBFF;">
    <div class="fw-semibold small mb-1"><i class="bi bi-people me-1"></i>Who can open it</div>
    <?php if ($instructor) { ?><input type="hidden" name="audience" value="courses"><div class="small text-muted mb-1">Students of the course or class you choose see it at once in their Customer Portal (RM Digital Library).</div>
    <?php } else { ?>
    <div class="d-flex flex-wrap gap-1 mb-1"><?php foreach ($set['audiences_list'] as $k) { ?>
        <input type="radio" class="btn-check" name="audience" id="<?= $id . $k; ?>" value="<?= $k; ?>" <?= $a === $k ? 'checked' : ''; ?>><label class="btn btn-sm btn-outline-primary py-0" for="<?= $id . $k; ?>" title="<?= $e(LIB_AUD_HELP[$k]); ?>"><?= $e(LIB_AUDIENCES[$k]); ?></label>
    <?php } ?></div>
    <div class="small text-muted mb-1 aud-help"><?= $e(LIB_AUD_HELP[$a] ?? ''); ?></div><?php } ?>
    <div class="aud-courses" <?= $a === 'courses' ? '' : 'style="display:none"'; ?>><?php foreach ($rows as [$c, $k]) { echo $courseSel($c, $k); } ?>
        <button type="button" class="btn btn-sm btn-link p-0 aud-add">+ another course</button></div>
    <div class="aud-users" <?= $a === 'users' ? '' : 'style="display:none"'; ?>>
        <input type="hidden" name="aud_customers" value="<?= $e(implode(',', array_column($custs, 'id'))); ?>">
        <div class="aud-chips mb-1"><?php foreach ($custs as $c) { ?><span class="badge bg-light text-dark border me-1 mb-1" data-id="<?= (int) $c['id']; ?>"><?= $e($c['name']); ?> · <?= (int) $c['id']; ?> <a href="#" class="text-danger text-decoration-none aud-x">×</a></span><?php } ?></div>
        <input type="text" class="form-control form-control-sm aud-find" placeholder="Type a name, phone or Customer ID…" autocomplete="off"><div class="list-group aud-res small" style="max-height:180px;overflow:auto;"></div></div>
    <div class="aud-more" <?= $a === 'staff' ? 'style="display:none"' : ''; ?>>
        <div class="row g-2 mt-1">
            <div class="col-sm-6"><label class="small text-muted">Type</label><select name="kind" class="form-select form-select-sm"><option value="">—</option><?php foreach (LIB_KINDS as $k => $l) { ?><option value="<?= $k; ?>" <?= $kind === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
            <div class="col-sm-6 aud-due" <?= $kind === 'assignment' ? '' : 'style="display:none"'; ?>><label class="small text-muted">Assignment due date</label><input type="date" name="due_date" class="form-control form-control-sm" value="<?= $e($doc['due_date'] ?? ($_POST['due_date'] ?? '')); ?>"></div>
        </div>
        <label class="small mt-1 d-block"><input type="checkbox" class="form-check-input me-1" name="allow_download" value="1" <?= $dl ? 'checked' : ''; ?>>Customers may download it (otherwise: view in the browser only)</label>
    </div>
</div>
<script>
(function () {
    var w = document.getElementById('<?= $id; ?>'), help = <?= json_encode(LIB_AUD_HELP); ?>;
    function sync() {
        var r = w.querySelector('[name=audience]:checked') || w.querySelector('[name=audience]'), a = r ? r.value : 'staff';
        w.querySelector('.aud-courses').style.display = a === 'courses' ? '' : 'none'; w.querySelector('.aud-users').style.display = a === 'users' ? '' : 'none';
        w.querySelector('.aud-more').style.display = a === 'staff' ? 'none' : ''; var h = w.querySelector('.aud-help'); if (h) h.textContent = help[a] || '';
        w.querySelector('.aud-due').style.display = w.querySelector('[name=kind]').value === 'assignment' ? '' : 'none';
        w.querySelectorAll('.aud-row').forEach(function (row) { var c = row.children[0].value; Array.prototype.forEach.call(row.children[1].options, function (o) { if (o.value !== '0') { o.hidden = o.getAttribute('data-c') !== c; if (o.hidden && o.selected) row.children[1].value = '0'; } }); });
    }
    w.addEventListener('change', sync);
    w.addEventListener('click', function (ev) {
        if (ev.target.classList.contains('aud-add')) { var rows = w.querySelectorAll('.aud-row'), n = rows[rows.length - 1].cloneNode(true); n.children[0].value = ''; n.children[1].value = '0'; ev.target.before(n); sync(); }
        if (ev.target.classList.contains('aud-del')) { if (w.querySelectorAll('.aud-row').length > 1) ev.target.closest('.aud-row').remove(); else { ev.target.closest('.aud-row').children[0].value = ''; } sync(); }
        if (ev.target.classList.contains('aud-x')) { ev.preventDefault(); ev.target.closest('[data-id]').remove(); ids(); }
        var it = ev.target.closest('[data-pick]'); if (it) { ev.preventDefault(); var c = w.querySelector('.aud-chips'); if (!c.querySelector('[data-id="' + it.getAttribute('data-pick') + '"]')) { var s = document.createElement('span'); s.className = 'badge bg-light text-dark border me-1 mb-1'; s.setAttribute('data-id', it.getAttribute('data-pick')); s.textContent = it.getAttribute('data-label') + ' '; var x = document.createElement('a'); x.href = '#'; x.className = 'text-danger text-decoration-none aud-x'; x.textContent = '×'; s.appendChild(x); c.appendChild(s); ids(); } w.querySelector('.aud-res').innerHTML = ''; w.querySelector('.aud-find').value = ''; }
    });
    function ids() { w.querySelector('[name=aud_customers]').value = Array.prototype.map.call(w.querySelectorAll('.aud-chips [data-id]'), function (s) { return s.getAttribute('data-id'); }).join(','); }
    var t = null, f = w.querySelector('.aud-find');
    f.addEventListener('input', function () { clearTimeout(t); var q = f.value.trim(); if (q.length < 2) { w.querySelector('.aud-res').innerHTML = ''; return; }
        t = setTimeout(function () { fetch(<?= json_encode(rtrim(defined('BASE_URL') ? BASE_URL : '', '/') . '/modules/library/customer_lookup.php?q='); ?> + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (list) {
            var o = w.querySelector('.aud-res'); o.innerHTML = '';
            list.forEach(function (c) { var a = document.createElement('a'); a.href = '#'; a.className = 'list-group-item list-group-item-action py-1'; a.setAttribute('data-pick', c.id); a.setAttribute('data-label', c.name + ' · ' + c.id); a.textContent = c.name + ' · ' + c.id + (c.phone ? ' · ' + c.phone : '') + (c.student ? ' · student' : ''); o.appendChild(a); });
            if (!list.length) o.innerHTML = '<div class="text-muted p-1">No customer found.</div>'; }); }, 250); });
    f.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') ev.preventDefault(); });
    sync();
})();
</script>
<?php
    return (string) ob_get_clean();
}

/** Course options for the access form: [course_id => ['CODE — Title', [class_id => class code]]]; $only: limit to these courses/classes. */
function lib_course_options(mysqli $conn, ?array $only = null): array
{
    $o = [];
    try {
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, code, title FROM lc_courses WHERE status <> 'Archived' ORDER BY code"), MYSQLI_ASSOC) as $c) {
            if ($only !== null && !isset($only[(int) $c['id']])) { continue; }
            $o[(int) $c['id']] = [$c['code'] . ' — ' . $c['title'], []];
        }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, course_id, code FROM lc_classes WHERE status <> 'Cancelled' ORDER BY code"), MYSQLI_ASSOC) as $k) {
            $c = (int) $k['course_id'];
            if (!isset($o[$c])) { continue; }
            if ($only !== null && $only[$c] !== ['*'] && !in_array((int) $k['id'], $only[$c], true)) { continue; }
            $o[$c][1][(int) $k['id']] = $k['code'];
        }
    } catch (Throwable $e) { }
    return $o;
}

/** Courses and classes an instructor may publish to: [course_id => [class ids]] (['*'] = every class: academic staff). */
function lib_instructor_scope(mysqli $conn, ?int $instructorId, bool $academic): array
{
    if ($academic) { $o = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM lc_courses WHERE status <> 'Archived'")) as $r) { $o[(int) $r[0]] = ['*']; } return $o; }
    if (!$instructorId || !function_exists('lc_instructor_class_ids')) { return []; }
    $ids = lc_instructor_class_ids($conn, $instructorId);
    if (!$ids) { return []; }
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, course_id FROM lc_classes WHERE id IN (' . implode(',', $ids) . ')')) as $r) { $o[(int) $r[1]][] = (int) $r[0]; }
    return $o;
}

/** Human file size. */
function lib_size(int $b): string { return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB'; }