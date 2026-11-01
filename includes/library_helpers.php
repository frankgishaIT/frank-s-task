<?php
/**
 * NEW FILE: includes/library_helpers.php
 * Task 1: RM DIGITAL LIBRARY. The central document repository of RISE MOTIVE.
 *
 *   Folders      unlimited levels; a folder can be RESTRICTED to chosen employees (its sub-folders inherit it).
 *   Documents    title, description, reference no., issue / expiry dates, status, folder; files kept in the
 *                database (evidence_files), so they are in every database backup and need no writable folder.
 *   Versions     replacing a file adds a version; earlier versions are never overwritten.
 *   Permissions  library.view / create / edit / replace / download / share / approve / archive / delete / manage
 *                (Roles & Permissions); the Library is for RISE MOTIVE only (rm_feature_enabled('digital_library')).
 *   Activity     every view, download, change, share, move, archive, delete and restore is logged.
 */
require_once __DIR__ . '/evidence_store.php';
require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/rm_org.php';
require_once __DIR__ . '/notification_helper.php';

const LIB_MAX_BYTES = 20 * 1024 * 1024;
const LIB_TYPES = [
    'application/pdf' => 'pdf',
    'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
    'text/plain' => 'txt', 'text/csv' => 'csv',
];
const LIB_ACCEPT = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.jpg,.jpeg,.png,.webp,.txt,.csv';
const LIB_STATUSES = ['Active', 'Pending Review', 'Archived'];
const LIB_START_FOLDERS = ['Legal & Company Registration', 'Management & Governance', 'Human Resources', 'Finance & Accounting',
    'Contracts & Agreements', 'Projects & Proposals', 'Operations & Administration', 'Products & Technology',
    'Marketing & Communications', 'Policies & Compliance', 'Training & Development', 'Archive'];

/** Stops the page when the Library is not available to this organization or this employee. */
function lib_guard(mysqli $conn, string $perm = 'library.view'): void
{
    if (!rm_feature_enabled('digital_library') || !access_can($perm, $conn)) {
        http_response_code(403);
        include __DIR__ . '/access_denied.php';
        exit;
    }
}

function lib_can(string $perm): bool { return access_can('library.' . $perm); }

function lib_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS lib_folders (
        id INT(11) NOT NULL AUTO_INCREMENT, parent_id INT(11) NULL, name VARCHAR(150) NOT NULL, description VARCHAR(255) NULL,
        is_restricted TINYINT(1) NOT NULL DEFAULT 0, sort_order INT(11) NOT NULL DEFAULT 0,
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY parent_id (parent_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS lib_folder_access (
        folder_id INT(11) NOT NULL, user_id INT(11) NOT NULL, PRIMARY KEY (folder_id, user_id)) ENGINE=InnoDB');
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS lib_documents (
        id INT(11) NOT NULL AUTO_INCREMENT, folder_id INT(11) NOT NULL, title VARCHAR(200) NOT NULL, description TEXT NULL,
        reference_no VARCHAR(80) NULL, issue_date DATE NULL, expiry_date DATE NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Active', current_version INT(11) NOT NULL DEFAULT 1,
        uploaded_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        reviewed_by INT(11) NULL, reviewed_at DATETIME NULL, archived_at DATETIME NULL,
        deleted_at DATETIME NULL, deleted_by INT(11) NULL, reminded_30 TINYINT(1) NOT NULL DEFAULT 0, reminded_0 TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id), KEY folder_id (folder_id), KEY reference_no (reference_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS lib_versions (
        id INT(11) NOT NULL AUTO_INCREMENT, document_id INT(11) NOT NULL, version_no INT(11) NOT NULL,
        file_ref VARCHAR(30) NOT NULL, original_name VARCHAR(255) NOT NULL, size_bytes INT(11) NOT NULL DEFAULT 0,
        note VARCHAR(255) NULL, uploaded_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY doc_version (document_id, version_no)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS lib_shares (
        document_id INT(11) NOT NULL, user_id INT(11) NOT NULL, shared_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (document_id, user_id)) ENGINE=InnoDB');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS lib_activity (
        id INT(11) NOT NULL AUTO_INCREMENT, document_id INT(11) NULL, folder_id INT(11) NULL, user_id INT(11) NULL,
        action VARCHAR(30) NOT NULL, details VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY document_id (document_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS lib_settings (name VARCHAR(40) NOT NULL, value VARCHAR(255) NULL, PRIMARY KEY (name)) ENGINE=InnoDB');

    // Documents uploaded before references were automatic get theirs now.
    mysqli_query($conn, "UPDATE lib_documents SET reference_no = CONCAT('RM-DOC-', YEAR(created_at), '-', LPAD(id, 5, '0'))
        WHERE reference_no IS NULL OR reference_no = ''");

    if ((int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COUNT(*) FROM lib_folders'))[0] === 0) {
        $s = mysqli_prepare($conn, 'INSERT INTO lib_folders (name, sort_order) VALUES (?, ?)');
        foreach (LIB_START_FOLDERS as $i => $name) { $o = $i + 1; mysqli_stmt_bind_param($s, 'si', $name, $o); mysqli_stmt_execute($s); }
    }
}

/** NEW: the automatic reference number of a document, e.g. RM-DOC-2026-00042 (year of upload + running number). */
function lib_reference(int $docId, string $createdAt): string
{
    return 'RM-DOC-' . date('Y', strtotime($createdAt ?: 'now')) . '-' . str_pad((string) $docId, 5, '0', STR_PAD_LEFT);
}

/** Gives a document its automatic reference number (only if it has none). */
function lib_assign_reference(mysqli $conn, int $docId): string
{
    $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT reference_no, created_at FROM lib_documents WHERE id = ' . $docId));
    if (!$row) { return ''; }
    if (trim((string) $row['reference_no']) !== '') { return $row['reference_no']; }
    $ref = lib_reference($docId, (string) $row['created_at']);
    $s = mysqli_prepare($conn, 'UPDATE lib_documents SET reference_no = ? WHERE id = ?');
    mysqli_stmt_bind_param($s, 'si', $ref, $docId);
    mysqli_stmt_execute($s);
    return $ref;
}

function lib_log(mysqli $conn, ?int $docId, ?int $folderId, string $action, string $details = ''): void
{
    $uid = (int) current_user_id();
    $s = mysqli_prepare($conn, 'INSERT INTO lib_activity (document_id, folder_id, user_id, action, details) VALUES (?, ?, ?, ?, ?)');
    $d = mb_substr($details, 0, 500);
    mysqli_stmt_bind_param($s, 'iiiss', $docId, $folderId, $uid, $action, $d);
    mysqli_stmt_execute($s);
}

/* ------------------------------------------------------------------ folders and folder access */

/** All folders: id => row (with 'children' ids). Cached per request. */
function lib_folders(mysqli $conn): array
{
    static $all = null;
    if ($all !== null) { return $all; }
    $all = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lib_folders ORDER BY sort_order, name'), MYSQLI_ASSOC) as $f) { $f['children'] = []; $all[(int) $f['id']] = $f; }
    foreach ($all as $id => $f) { if ($f['parent_id'] && isset($all[(int) $f['parent_id']])) { $all[(int) $f['parent_id']]['children'][] = $id; } }
    return $all;
}

function lib_folder_path(mysqli $conn, int $id): string
{
    $all = lib_folders($conn); $parts = []; $guard = 0;
    while ($id && isset($all[$id]) && $guard++ < 50) { array_unshift($parts, $all[$id]['name']); $id = (int) $all[$id]['parent_id']; }
    return implode(' / ', $parts);
}

/** May the signed-in employee open this folder? A restricted folder (or one inside it) is for its listed people only. */
function lib_folder_allowed(mysqli $conn, int $folderId): bool
{
    static $cache = [];
    if (isset($cache[$folderId])) { return $cache[$folderId]; }
    if (lib_can('manage')) { return $cache[$folderId] = true; } // folder managers see every folder
    $all = lib_folders($conn); $uid = (int) current_user_id(); $id = $folderId; $guard = 0;
    while ($id && isset($all[$id]) && $guard++ < 50) {
        if ((int) $all[$id]['is_restricted'] === 1) {
            $s = mysqli_prepare($conn, 'SELECT 1 FROM lib_folder_access WHERE folder_id = ? AND user_id = ?');
            mysqli_stmt_bind_param($s, 'ii', $id, $uid);
            mysqli_stmt_execute($s);
            if (!mysqli_fetch_row(mysqli_stmt_get_result($s))) { return $cache[$folderId] = false; }
        }
        $id = (int) $all[$id]['parent_id'];
    }
    return $cache[$folderId] = isset($all[$folderId]);
}

function lib_allowed_folder_ids(mysqli $conn): array
{
    return array_values(array_filter(array_keys(lib_folders($conn)), function ($id) use ($conn) { return lib_folder_allowed($conn, $id); }));
}

/** A folder and everything below it. */
function lib_descendants(mysqli $conn, int $id): array
{
    $all = lib_folders($conn); $out = [$id]; $stack = [$id];
    while ($stack) { $c = array_pop($stack); foreach ($all[$c]['children'] ?? [] as $k) { $out[] = $k; $stack[] = $k; } }
    return $out;
}

/* ------------------------------------------------------------------ documents */

/** A document the signed-in employee may see (its folder allowed, or shared with them), or null. */
function lib_document(mysqli $conn, int $id, bool $includeDeleted = false): ?array
{
    $s = mysqli_prepare($conn, 'SELECT d.*, u.names AS uploader FROM lib_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.id = ?');
    mysqli_stmt_bind_param($s, 'i', $id);
    mysqli_stmt_execute($s);
    $d = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$d || ($d['deleted_at'] && !$includeDeleted)) { return null; }
    if (lib_folder_allowed($conn, (int) $d['folder_id'])) { return $d; }
    $uid = (int) current_user_id();
    $sh = mysqli_prepare($conn, 'SELECT 1 FROM lib_shares WHERE document_id = ? AND user_id = ?');
    mysqli_stmt_bind_param($sh, 'ii', $id, $uid);
    mysqli_stmt_execute($sh);
    return mysqli_fetch_row(mysqli_stmt_get_result($sh)) ? $d : null;
}

/** Stores one uploaded file as a new version of a document. Returns the version number. */
function lib_add_version(mysqli $conn, int $docId, array $file, ?string $note): int
{
    $ref = evidence_save($conn, $file, (int) current_user_id(), LIB_TYPES, LIB_MAX_BYTES);
    if (!$ref) { throw new RuntimeException('Choose a file.'); }
    $next = (int) mysqli_fetch_row(mysqli_query($conn, 'SELECT COALESCE(MAX(version_no), 0) + 1 FROM lib_versions WHERE document_id = ' . $docId))[0];
    $name = mb_substr(basename((string) $file['name']), 0, 255);
    $size = (int) $file['size'];
    $uid = (int) current_user_id();
    $s = mysqli_prepare($conn, 'INSERT INTO lib_versions (document_id, version_no, file_ref, original_name, size_bytes, note, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iissisi', $docId, $next, $ref, $name, $size, $note, $uid);
    mysqli_stmt_execute($s);
    mysqli_query($conn, 'UPDATE lib_documents SET current_version = ' . $next . ', updated_at = NOW() WHERE id = ' . $docId);
    return $next;
}

/** Splits $_FILES['files'] (multiple) into single-file arrays. */
function lib_files(?array $files): array
{
    $out = [];
    if (!$files || !isset($files['name'])) { return $out; }
    if (!is_array($files['name'])) { return ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files]; }
    foreach ($files['name'] as $i => $n) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
        $out[] = ['name' => $n, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
    }
    return $out;
}

/* ------------------------------------------------------------------ expiry reminders (once a day, on opening the Library) */

function lib_expiry_reminders(mysqli $conn): void
{
    $today = date('Y-m-d');
    $last = mysqli_fetch_row(mysqli_query($conn, "SELECT value FROM lib_settings WHERE name = 'last_expiry_check'"))[0] ?? '';
    if ($last === $today) { return; }
    mysqli_query($conn, "INSERT INTO lib_settings (name, value) VALUES ('last_expiry_check', '$today') ON DUPLICATE KEY UPDATE value = '$today'");
    $managers = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND LOWER(role) = 'admin'"), MYSQLI_ASSOC), 'id');
    foreach ([['reminded_30', "expiry_date > CURDATE() AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)", 'expires on'],
              ['reminded_0', "expiry_date <= CURDATE()", 'EXPIRED on']] as [$flag, $cond, $word]) {
        $docs = mysqli_fetch_all(mysqli_query($conn, "SELECT id, title, reference_no, expiry_date, uploaded_by FROM lib_documents
            WHERE deleted_at IS NULL AND status <> 'Archived' AND expiry_date IS NOT NULL AND $flag = 0 AND $cond"), MYSQLI_ASSOC);
        foreach ($docs as $d) {
            $who = array_unique(array_filter(array_merge($managers, [(int) $d['uploaded_by']])));
            foreach ($who as $uid) {
                try { notifyUser($conn, (int) $uid, 'Document ' . ($flag === 'reminded_0' ? 'expired' : 'expiring soon'),
                    '"' . $d['title'] . '"' . ($d['reference_no'] ? ' (' . $d['reference_no'] . ')' : '') . ' ' . $word . ' ' . date('d M Y', strtotime($d['expiry_date'])) . '. Open RM Digital Library to renew or archive it.'); }
                catch (Throwable $e) { error_log('library reminder: ' . $e->getMessage()); }
            }
            mysqli_query($conn, "UPDATE lib_documents SET $flag = 1 WHERE id = " . (int) $d['id']);
        }
    }
}