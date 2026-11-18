<?php
ob_start();
/**
 * NEW FILE: portal/library_file.php — the file of an RM Digital Library document, ONLY when the customer's access allows it
 * (checked again here). View (PDF, image, text) or download (only when downloads are allowed). Logged.
 */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/lib_access.php';
if (empty($GLOBALS['PORTAL_LIBRARY_ON'])) { http_response_code(404); echo 'Not found.'; exit; }
lib_access_ensure($conn);
$d = lib_customer_document($conn, $cid, (int) ($_GET['id'] ?? 0));
if (!$d || !$d['file_ref']) { http_response_code(404); echo 'Document not found.'; exit; }
$download = ($_GET['mode'] ?? '') === 'download';
$ext = strtolower(pathinfo((string) $d['original_name'], PATHINFO_EXTENSION));
if ($download && !$d['allow_download']) { http_response_code(403); echo 'This document can be viewed but not downloaded.'; exit; }
if (!$download && !in_array($ext, LIB_VIEWABLE, true)) { http_response_code(415); echo 'This type of file cannot be shown in the browser.'; exit; }
lib_access_log($conn, (int) $d['id'], (int) $d['folder_id'], null, $cid, $download ? 'portal_downloaded' : 'portal_viewed', 'Version ' . (int) $d['current_version'] . ': ' . $d['original_name']);
portal_log($conn, $cid, $download ? 'library_download' : 'library_view', $d['title']);
evidence_output($conn, $d['file_ref'], pathinfo((string) $d['original_name'], PATHINFO_FILENAME), null, $download);