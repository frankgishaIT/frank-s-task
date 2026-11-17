<?php
// NEW FILE: learn/image.php — the image of a PUBLISHED course (draft/archived courses never show anything).
require __DIR__ . '/lc_public.php';
require_once __DIR__ . '/../includes/evidence_store.php';
$c = lc_course_by_code($conn, (string) ($_GET['c'] ?? ''));
if (!$c || !in_array($c['status'], LC_PUBLIC_VISIBLE, true) || !$c['image_ref']) { http_response_code(404); exit; }
header('Cache-Control: public, max-age=3600');
evidence_output($conn, $c['image_ref'], strtolower($c['code']));