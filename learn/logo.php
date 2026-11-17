<?php
// NEW FILE: learn/logo.php — the RISE MOTIVE logo for the public Learning Centre pages (works when this folder is a sub-domain).
$f = __DIR__ . '/../assets/images/logo.jpg';
if (!is_file($f)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg'); header('Cache-Control: public, max-age=86400');
readfile($f);