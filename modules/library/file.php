<?php
ob_start(); // NEW: nothing (not even a stray line break) may be sent before the file
/**
 * NEW FILE: modules/library/file.php
 * Opens (preview) or downloads one version of a document, after checking access. Logged.
 */
require '../../config/db.php';
require_once '../../includes/library_helpers.php';
lib_ensure($conn);
lib_guard($conn);
$vid = filter_input(INPUT_GET, 'v', FILTER_VALIDATE_INT);
$download = ($_GET['mode'] ?? '') === 'download';
$raw = ($_GET['mode'] ?? '') === 'raw'; // NEW: bytes for the in-browser Word / Excel viewer (view.php)
$v = $vid ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM lib_versions WHERE id = ' . (int) $vid)) : null;
$doc = $v ? lib_document($conn, (int) $v['document_id'], lib_can('delete')) : null;
if (!$doc) { http_response_code(404); echo 'Document not found.'; exit; }
if ($download && !lib_can('download')) { http_response_code(403); echo 'You are not allowed to download documents.'; exit; }
// Only PDF, images and text can be shown inside the page; anything else would be a download.
$ext = strtolower(pathinfo($v['original_name'], PATHINFO_EXTENSION));
if ($raw && in_array($ext, ['docx', 'xlsx', 'xls', 'csv'], true)) {
    lib_log($conn, (int) $doc['id'], (int) $doc['folder_id'], 'viewed', 'Version ' . $v['version_no'] . ': ' . $v['original_name']);
    evidence_output($conn, $v['file_ref'], pathinfo($v['original_name'], PATHINFO_FILENAME), null, true);
}
if (!$download && !in_array($ext, ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'txt'], true)) {
    http_response_code(415);
    echo 'This type of file (' . htmlspecialchars($ext, ENT_QUOTES, 'UTF-8') . ') cannot be previewed in the browser. Use Download.';
    exit;
}
lib_log($conn, (int) $doc['id'], (int) $doc['folder_id'], $download ? 'downloaded' : 'viewed', 'Version ' . $v['version_no'] . ': ' . $v['original_name']);
evidence_output($conn, $v['file_ref'], pathinfo($v['original_name'], PATHINFO_FILENAME), null, $download);