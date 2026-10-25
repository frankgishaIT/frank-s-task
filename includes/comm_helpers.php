<?php
/**
 * NEW FILE: includes/comm_helpers.php
 * Internal Communication (Task 1): announcements, questions & responses, private conversations,
 * shared documents, history, access control and notifications.
 *
 * WHO SEES WHAT (checked on the server for every page, reply and file):
 *   announcement  everyone can read;              only Admins / Managers can publish and post in it
 *   question      the person who asked + Admins / Managers can read and reply
 *   private       ONLY the selected participants (an Admin who is not a participant cannot read it)
 * Files are stored in the database (evidence_files), so no server folder has to be writable.
 */
require_once __DIR__ . '/evidence_store.php';
require_once __DIR__ . '/notification_helper.php';

const COMM_TYPES = ['announcement' => 'Announcements', 'question' => 'Questions', 'private' => 'Private'];
const COMM_MAX_FILES = 5;
const COMM_FILE_TYPES = [
    'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls',
    'text/plain' => 'txt', 'text/csv' => 'csv',
];

function comm_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS comm_conversations (
        id INT(11) NOT NULL AUTO_INCREMENT,
        type ENUM('announcement','question','private') NOT NULL,
        title VARCHAR(200) NOT NULL,
        created_by INT(11) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_message_at DATETIME NULL,
        PRIMARY KEY (id), KEY type_last (type, last_message_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS comm_participants (
        conversation_id INT(11) NOT NULL,
        user_id INT(11) NOT NULL,
        PRIMARY KEY (conversation_id, user_id), KEY user_id (user_id)
    ) ENGINE=InnoDB");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS comm_messages (
        id INT(11) NOT NULL AUTO_INCREMENT,
        conversation_id INT(11) NOT NULL,
        user_id INT(11) NOT NULL,
        body TEXT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY conversation_id (conversation_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS comm_attachments (
        id INT(11) NOT NULL AUTO_INCREMENT,
        message_id INT(11) NOT NULL,
        file_ref VARCHAR(30) NOT NULL,
        original_name VARCHAR(200) NOT NULL,
        size_bytes INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (id), KEY message_id (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS comm_reads (
        conversation_id INT(11) NOT NULL,
        user_id INT(11) NOT NULL,
        last_read_message_id INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (conversation_id, user_id)
    ) ENGINE=InnoDB");
}

function comm_is_management(string $role): bool
{
    return in_array($role, ['Admin', 'Manager'], true);
}

/** SQL condition (on alias c) for the conversations this user may see. */
function comm_visible_sql(int $userId, string $role): string
{
    $mgmt = comm_is_management($role);
    return "(c.type = 'announcement'
        OR (c.type = 'question' AND (" . ($mgmt ? '1 = 1' : 'c.created_by = ' . $userId) . "))
        OR (c.type = 'private' AND EXISTS (SELECT 1 FROM comm_participants p WHERE p.conversation_id = c.id AND p.user_id = " . $userId . ")))";
}

function comm_get(mysqli $conn, int $id): ?array
{
    comm_ensure($conn);
    $s = mysqli_prepare($conn, 'SELECT c.*, u.names AS author FROM comm_conversations c LEFT JOIN users u ON u.id = c.created_by WHERE c.id = ?');
    mysqli_stmt_bind_param($s, 'i', $id);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

function comm_is_participant(mysqli $conn, int $convId, int $userId): bool
{
    $s = mysqli_prepare($conn, 'SELECT 1 FROM comm_participants WHERE conversation_id = ? AND user_id = ?');
    mysqli_stmt_bind_param($s, 'ii', $convId, $userId);
    mysqli_stmt_execute($s);
    return (bool) mysqli_fetch_row(mysqli_stmt_get_result($s));
}

function comm_can_view(mysqli $conn, array $c, int $userId, string $role): bool
{
    if ($c['type'] === 'announcement') { return true; }
    if ($c['type'] === 'question') { return (int) $c['created_by'] === $userId || comm_is_management($role); }
    return comm_is_participant($conn, (int) $c['id'], $userId);
}

function comm_can_post(mysqli $conn, array $c, int $userId, string $role): bool
{
    if ($c['type'] === 'announcement') { return comm_is_management($role); }
    return comm_can_view($conn, $c, $userId, $role);
}

/** Stores the uploaded files of one message ($_FILES['files'] with multiple). Returns [ref, name, size] rows. */
function comm_store_files(mysqli $conn, ?array $files, int $userId): array
{
    $out = [];
    if (!$files || !isset($files['name']) || !is_array($files['name'])) { return $out; }
    $count = 0;
    foreach ($files['name'] as $i => $name) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
        if (++$count > COMM_MAX_FILES) { throw new RuntimeException('You can attach at most ' . COMM_MAX_FILES . ' files to one message.'); }
        $one = ['name' => $name, 'type' => $files['type'][$i], 'tmp_name' => $files['tmp_name'][$i], 'error' => $files['error'][$i], 'size' => $files['size'][$i]];
        $ref = evidence_save($conn, $one, $userId, COMM_FILE_TYPES);
        if ($ref) { $out[] = [$ref, mb_substr(basename((string) $name), 0, 200), (int) $files['size'][$i]]; }
    }
    return $out;
}

/** Adds a message (with its files) to a conversation and notifies the right people. Own transaction. */
function comm_add_message(mysqli $conn, array $c, int $userId, string $body, array $files): int
{
    $body = trim($body);
    if ($body === '' && !$files) { throw new RuntimeException('Write a message or attach a file.'); }
    if (mb_strlen($body) > 5000) { throw new RuntimeException('The message is too long (maximum 5000 characters).'); }
    mysqli_begin_transaction($conn);
    try {
        $s = mysqli_prepare($conn, 'INSERT INTO comm_messages (conversation_id, user_id, body) VALUES (?, ?, ?)');
        $cid = (int) $c['id'];
        mysqli_stmt_bind_param($s, 'iis', $cid, $userId, $body);
        mysqli_stmt_execute($s);
        $mid = (int) mysqli_insert_id($conn);
        $a = mysqli_prepare($conn, 'INSERT INTO comm_attachments (message_id, file_ref, original_name, size_bytes) VALUES (?, ?, ?, ?)');
        foreach ($files as [$ref, $name, $size]) {
            mysqli_stmt_bind_param($a, 'issi', $mid, $ref, $name, $size);
            mysqli_stmt_execute($a);
        }
        $u = mysqli_prepare($conn, 'UPDATE comm_conversations SET last_message_at = NOW() WHERE id = ?');
        mysqli_stmt_bind_param($u, 'i', $cid);
        mysqli_stmt_execute($u);
        comm_mark_read($conn, $cid, $userId, $mid);
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    comm_notify($conn, $c, $userId, $body, $mid);
    return $mid;
}

/** Creates a conversation with its first message. Returns the conversation id. */
function comm_create(mysqli $conn, string $type, string $title, int $userId, string $role, array $recipientIds, string $body, array $files): int
{
    comm_ensure($conn);
    $title = trim($title);
    if (!isset(COMM_TYPES[$type])) { throw new RuntimeException('Choose what you are sending.'); }
    if ($type === 'announcement' && !comm_is_management($role)) { throw new RuntimeException('Only Admins and Managers can publish announcements.'); }
    if ($title === '' || mb_strlen($title) > 200) { throw new RuntimeException('Please write a subject (maximum 200 characters).'); }
    $recipients = [];
    if ($type === 'private') {
        $ids = array_values(array_unique(array_filter(array_map('intval', $recipientIds), function ($i) use ($userId) { return $i > 0 && $i !== $userId; })));
        if (!$ids) { throw new RuntimeException('Choose at least one person to send the private message to.'); }
        $in = implode(',', $ids);
        $res = mysqli_query($conn, "SELECT id FROM users WHERE id IN ($in) AND is_active = 1");
        $recipients = array_map('intval', array_column(mysqli_fetch_all($res, MYSQLI_ASSOC), 'id'));
        if (count($recipients) !== count($ids)) { throw new RuntimeException('One of the selected people is not an active user.'); }
    }
    mysqli_begin_transaction($conn);
    try {
        $s = mysqli_prepare($conn, 'INSERT INTO comm_conversations (type, title, created_by, last_message_at) VALUES (?, ?, ?, NOW())');
        mysqli_stmt_bind_param($s, 'ssi', $type, $title, $userId);
        mysqli_stmt_execute($s);
        $cid = (int) mysqli_insert_id($conn);
        if ($type === 'private') {
            $p = mysqli_prepare($conn, 'INSERT IGNORE INTO comm_participants (conversation_id, user_id) VALUES (?, ?)');
            foreach (array_merge([$userId], $recipients) as $uid) {
                mysqli_stmt_bind_param($p, 'ii', $cid, $uid);
                mysqli_stmt_execute($p);
            }
        }
        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        throw $e;
    }
    comm_add_message($conn, comm_get($conn, $cid), $userId, $body, $files);
    return $cid;
}

function comm_mark_read(mysqli $conn, int $convId, int $userId, ?int $messageId = null): void
{
    if ($messageId === null) {
        $s = mysqli_prepare($conn, 'SELECT COALESCE(MAX(id), 0) FROM comm_messages WHERE conversation_id = ?');
        mysqli_stmt_bind_param($s, 'i', $convId);
        mysqli_stmt_execute($s);
        $messageId = (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0];
    }
    $s = mysqli_prepare($conn, 'INSERT INTO comm_reads (conversation_id, user_id, last_read_message_id) VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE last_read_message_id = GREATEST(last_read_message_id, VALUES(last_read_message_id))');
    mysqli_stmt_bind_param($s, 'iii', $convId, $userId, $messageId);
    mysqli_stmt_execute($s);
}

/** Unread messages (from others) for this user, in conversations they can see. */
function comm_unread_total(mysqli $conn, int $userId, string $role): int
{
    comm_ensure($conn);
    $sql = "SELECT COUNT(*) FROM comm_messages m JOIN comm_conversations c ON c.id = m.conversation_id
        LEFT JOIN comm_reads r ON r.conversation_id = c.id AND r.user_id = $userId
        WHERE m.user_id <> $userId AND m.id > COALESCE(r.last_read_message_id, 0) AND " . comm_visible_sql($userId, $role);
    return (int) mysqli_fetch_row(mysqli_query($conn, $sql))[0];
}

/** Who should be told about a new message in this conversation (never the author). */
function comm_notify(mysqli $conn, array $c, int $authorId, string $body, int $messageId): void
{
    try {
        $cid = (int) $c['id'];
        if ($c['type'] === 'announcement') {
            $sql = "SELECT id FROM users WHERE is_active = 1 AND id <> $authorId";
        } elseif ($c['type'] === 'question') {
            // The person who asked, and management.
            $sql = "SELECT id FROM users WHERE is_active = 1 AND id <> $authorId
                    AND (id = " . (int) $c['created_by'] . " OR LOWER(role) IN ('admin', 'manager'))";
        } else {
            $sql = "SELECT user_id AS id FROM comm_participants WHERE conversation_id = $cid AND user_id <> $authorId";
        }
        $author = mysqli_fetch_row(mysqli_query($conn, 'SELECT names FROM users WHERE id = ' . $authorId))[0] ?? 'Someone';
        $isFirst = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM comm_messages WHERE conversation_id = $cid"))[0] === 1;
        $what = ['announcement' => 'New announcement', 'question' => $isFirst ? 'New question' : 'New response', 'private' => 'New private message'][$c['type']];
        $preview = $body !== '' ? ': ' . mb_substr($body, 0, 120) . (mb_strlen($body) > 120 ? '…' : '') : ' (file attached)';
        // Private messages: the notification does not repeat the content (it may be confidential).
        $message = $c['type'] === 'private'
            ? $author . ' sent you a private message in "' . $c['title'] . '". Open Messages to read it.'
            : $author . ' — "' . $c['title'] . '"' . $preview;
        foreach (mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC) as $r) {
            notifyUser($conn, (int) $r['id'], $what, $message);
        }
    } catch (Throwable $e) {
        error_log('comm notify failed: ' . $e->getMessage());
    }
}

/** Active users that can be chosen as private-message recipients. */
function comm_people(mysqli $conn, int $exceptUserId): array
{
    $s = mysqli_prepare($conn, 'SELECT id, names, role FROM users WHERE is_active = 1 AND id <> ? ORDER BY names');
    mysqli_stmt_bind_param($s, 'i', $exceptUserId);
    mysqli_stmt_execute($s);
    return mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
}