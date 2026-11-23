<?php
/** NEW FILE: modules/market/file.php — serves a market research picture (?id=) or a supplier quotation document (?quote=), never directly from the folder. */
require __DIR__ . '/common.php';
mi_require($conn, 'view');
$f = '';
if (!empty($_GET['quote'])) {
    if (!mi_can_evaluate($conn)) { http_response_code(403); exit; }
    $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT attachment FROM mi_quotes WHERE id = ' . (int) $_GET['quote']));
    $f = $r && $r[0] && preg_match('/^[A-Za-z0-9_.-]+$/', $r[0]) ? __DIR__ . '/../../storage/market/quotes/' . $r[0] : '';
} else {
    $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT image_ref FROM mi_opportunities WHERE id = ' . (int) ($_GET['id'] ?? 0)));
    $f = $r && $r[0] && preg_match('/^[A-Za-z0-9_.-]+$/', $r[0]) ? __DIR__ . '/../../storage/market/' . $r[0] : '';
}
if (!$f || !is_file($f)) { http_response_code(404); exit; }
header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($f) ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($f));
header('Content-Disposition: inline; filename="' . basename($f) . '"');
header('Cache-Control: private, max-age=86400');
readfile($f);