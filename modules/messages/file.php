<?php
/**
 * NEW FILE: modules/messages/file.php
 * Opens / downloads a document shared in a conversation, ONLY for people who can see that conversation.
 */
require '../../config/db.php';
require_once '../../includes/comm_helpers.php';
require_role(['Admin', 'Manager', 'Employee']);
comm_ensure($conn);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$f = null;
if ($id) {
    $s = mysqli_prepare($conn, 'SELECT a.*, m.conversation_id FROM comm_attachments a JOIN comm_messages m ON m.id = a.message_id WHERE a.id = ?');
    mysqli_stmt_bind_param($s, 'i', $id);
    mysqli_stmt_execute($s);
    $f = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
}
$c = $f ? comm_get($conn, (int) $f['conversation_id']) : null;
if (!$c || !comm_can_view($conn, $c, (int) current_user_id(), current_user_role())) {
    http_response_code(404);
    echo 'File not found.';
    exit;
}
evidence_output($conn, $f['file_ref'], pathinfo($f['original_name'], PATHINFO_FILENAME));