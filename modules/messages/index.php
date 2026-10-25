<?php
/**
 * NEW FILE: modules/messages/index.php
 * Internal Communication: Announcements, Questions and Private conversations (Task 1).
 */
require '../../config/db.php';
require_once '../../includes/comm_helpers.php';
require_role(['Admin', 'Manager', 'Employee']);
comm_ensure($conn);

$userId = (int) current_user_id();
$role = current_user_role();
$tab = isset(COMM_TYPES[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'announcement';

// Unread per tab, and the conversations of the chosen tab (only those this user may see).
$visible = comm_visible_sql($userId, $role);
$unreadSql = "SELECT COUNT(*) FROM comm_messages m WHERE m.conversation_id = c.id AND m.user_id <> $userId
    AND m.id > COALESCE((SELECT r.last_read_message_id FROM comm_reads r WHERE r.conversation_id = c.id AND r.user_id = $userId), 0)";
$tabUnread = [];
foreach (array_keys(COMM_TYPES) as $t) {
    $tabUnread[$t] = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(($unreadSql)), 0) FROM comm_conversations c WHERE c.type = '$t' AND $visible"))[0];
}
$s = mysqli_prepare($conn, "SELECT c.*, u.names AS author, ($unreadSql) AS unread,
        (SELECT COUNT(*) FROM comm_messages m2 WHERE m2.conversation_id = c.id) AS messages,
        (SELECT GROUP_CONCAT(pu.names ORDER BY pu.names SEPARATOR ', ') FROM comm_participants p JOIN users pu ON pu.id = p.user_id
         WHERE p.conversation_id = c.id AND p.user_id <> ?) AS others
    FROM comm_conversations c LEFT JOIN users u ON u.id = c.created_by
    WHERE c.type = ? AND $visible ORDER BY c.last_message_at DESC, c.id DESC LIMIT 100");
mysqli_stmt_bind_param($s, 'is', $userId, $tab);
mysqli_stmt_execute($s);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show"><?= $e($_GET['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($_GET['error'])) { ?><div class="alert alert-danger alert-dismissible fade show"><?= $e($_GET['error']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Messages</h2>
    <div class="d-flex gap-2">
        <?php if (comm_is_management($role)) { ?><a href="create.php?type=announcement" class="rm-btn rm-btn-secondary"><i class="bi bi-megaphone-fill me-1"></i>New Announcement</a><?php } ?>
        <a href="create.php?type=question" class="rm-btn rm-btn-secondary"><i class="bi bi-question-circle-fill me-1"></i>Ask a Question</a>
        <a href="create.php?type=private" class="rm-btn rm-btn-primary"><i class="bi bi-lock-fill me-1"></i>New Private Message</a>
    </div>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach (COMM_TYPES as $k => $label) { ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : ''; ?>" href="index.php?tab=<?= $k; ?>">
        <?= $e($label); ?><?php if ($tabUnread[$k] > 0) { ?> <span class="badge" style="background:#1E2FE0;"><?= $tabUnread[$k]; ?></span><?php } ?></a></li>
    <?php } ?>
</ul>

<p class="small text-muted">
    <?= ['announcement' => 'Official announcements from management. Everyone can read them.',
         'question' => comm_is_management($role) ? 'Work questions from employees. Any Admin or Manager can answer.' : 'Your work questions. Management answers here.',
         'private' => 'Confidential conversations. Only the people in a conversation can see it.'][$tab]; ?>
</p>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover bg-white mb-0 align-middle">
    <tr><th>Subject</th><th><?= $tab === 'private' ? 'With' : 'From'; ?></th><th class="text-end">Messages</th><th>Last activity</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="4" class="text-center text-muted py-4">Nothing here yet.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { $unread = (int) $r['unread']; ?>
    <tr<?= $unread ? ' style="background:#EEF3FF;"' : ''; ?>>
        <td>
            <a href="view.php?id=<?= (int) $r['id']; ?>" class="text-decoration-none <?= $unread ? 'fw-bold' : ''; ?>"><?= $e($r['title']); ?></a>
            <?php if ($unread) { ?> <span class="badge" style="background:#1E2FE0;"><?= $unread; ?> new</span><?php } ?>
        </td>
        <td><?= $e($tab === 'private' ? ($r['others'] ?: '—') : $r['author']); ?></td>
        <td class="text-end"><?= (int) $r['messages']; ?></td>
        <td class="text-nowrap"><?= $r['last_message_at'] ? date('d M Y, H:i', strtotime($r['last_message_at'])) : ''; ?></td>
    </tr>
    <?php } ?>
</table>
</div></div></div>
<?php include '../../includes/footer.php'; ?>