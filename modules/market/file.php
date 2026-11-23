<?php
/** NEW FILE: modules/market/file.php — serves the picture of a market research record (stored outside the web folder rules). */
require __DIR__ . '/common.php';
mi_require($conn, 'view');
$id = (int) ($_GET['id'] ?? 0);
$r = mysqli_fetch_row(mysqli_query($conn, "SELECT image_ref FROM mi_opportunities WHERE id = $id"));
$f = $r && $r[0] && preg_match('/^[A-Za-z0-9_.-]+$/', $r[0]) ? __DIR__ . '/../../storage/market/' . $r[0] : '';
if (!$f || !is_file($f)) { http_response_code(404); exit; }
header('Content-Type: ' . ((new finfo(FILEINFO_MIME_TYPE))->file($f) ?: 'image/jpeg'));
header('Content-Length: ' . filesize($f));
header('Cache-Control: private, max-age=86400');
readfile($f);