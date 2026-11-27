<?php
/** NEW FILE: modules/staff_cards/media.php — serves a carousel image (?ref=db:N), only when a card uses it. */
require __DIR__ . '/common.php';
$ref = (string) ($_GET['ref'] ?? '');
if (!preg_match('/^db:\d+$/', $ref)) { http_response_code(404); exit; }
$s = mysqli_prepare($conn, 'SELECT category FROM sdc_cards WHERE image_ref = ? LIMIT 1');
mysqli_stmt_bind_param($s, 's', $ref);
mysqli_stmt_execute($s);
if (!mysqli_fetch_row(mysqli_stmt_get_result($s))) { http_response_code(404); exit; }
header('Cache-Control: private, max-age=86400');
evidence_output($conn, $ref, 'card_image');