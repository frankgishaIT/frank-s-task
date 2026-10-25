<?php
/**
 * NEW FILE: modules/messages/view.php
 * One conversation: the full history with its documents, and the reply box (when allowed).
 */
require '../../config/db.php';
require_once '../../includes/comm_helpers.php';
require_role(['Admin', 'Manager', 'Employee']);
comm_ensure($conn);

$userId = (int) current_user_id();
$role = current_user_role();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$c = $id ? comm_get($conn, (int) $id) : null;
// Access control: a conversation this user may not see looks exactly like one that does not exist.
if (!$c || !comm_can_view($conn, $c, $userId, $role)) {
    header('Location: index.php?error=' . urlencode('That conversation does not exist or you do not have access to it.'));
    exit;
}
$canPost = comm_can_post($conn, $c, $userId, $role);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canPost) {
        $error = 'You cannot reply here.';
    } else {
        try {
            $files = comm_store_files($conn, $_FILES['files'] ?? null, $userId);
            comm_add_message($conn, $c, $userId, (string) ($_POST['body'] ?? ''), $files);
            header('Location: view.php?id=' . (int) $c['id'] . '#bottom');
            exit;
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        } catch (Throwable $ex) {
            error_log('message reply failed: ' . $ex->getMessage());
            $error = 'Unable to send. Nothing was sent.' . (comm_is_management($role) ? ' [Technical detail for Admin: ' . $ex->getMessage() . ']' : '');
        }
    }
}

$s = mysqli_prepare($conn, 'SELECT m.*, u.names, u.role FROM comm_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.conversation_id = ? ORDER BY m.id');
$cid = (int) $c['id'];
mysqli_stmt_bind_param($s, 'i', $cid);
mysqli_stmt_execute($s);
$messages = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
$files = [];
if ($messages) {
    $ids = implode(',', array_map('intval', array_column($messages, 'id')));
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM comm_attachments WHERE message_id IN ($ids) ORDER BY id"), MYSQLI_ASSOC) as $f) {
        $files[(int) $f['message_id']][] = $f;
    }
}
$participants = [];
if ($c['type'] === 'private') {
    $participants = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT u.names FROM comm_participants p JOIN users u ON u.id = p.user_id WHERE p.conversation_id = $cid ORDER BY u.names"), MYSQLI_ASSOC), 'names');
}
comm_mark_read($conn, $cid, $userId); // opening the conversation marks it read

$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$size = function ($b) { return $b >= 1048576 ? number_format($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB'; };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show"><?= $e($_GET['success']); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<div class="mb-3">
    <a href="index.php?tab=<?= $e($c['type']); ?>" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Back to <?= $e(COMM_TYPES[$c['type']]); ?></a>
    <h2 class="mt-1 mb-1"><?= $e($c['title']); ?></h2>
    <div class="small text-muted">
        <?= ['announcement' => '<i class="bi bi-megaphone-fill"></i> Announcement · visible to everyone', 'question' => '<i class="bi bi-question-circle-fill"></i> Question · visible to ' . $e($c['author']) . ' and management', 'private' => '<i class="bi bi-lock-fill"></i> Private · only ' . $e(implode(', ', $participants))][$c['type']]; ?>
    </div>
</div>

<div class="d-flex flex-column gap-3 mb-4">
    <?php foreach ($messages as $m) { $mine = (int) $m['user_id'] === $userId; ?>
    <div class="p-3" style="background:<?= $mine ? '#EEF3FF' : '#FFFFFF'; ?>; border-radius:12px; border:1px solid #E4E8F2; <?= $mine ? 'margin-left:10%;' : 'margin-right:10%;'; ?>">
        <div class="d-flex justify-content-between mb-1">
            <span class="fw-semibold small"><?= $e($m['names'] ?? 'Former user'); ?><?= $mine ? ' (You)' : ''; ?>
                <?php if (comm_is_management(ucfirst(strtolower((string) $m['role'])))) { ?><span class="badge bg-secondary ms-1"><?= $e(ucfirst(strtolower($m['role']))); ?></span><?php } ?></span>
            <span class="small text-muted"><?= date('d M Y, H:i', strtotime($m['created_at'])); ?></span>
        </div>
        <?php if ($m['body'] !== '') { ?><div style="white-space:pre-wrap;"><?= $e($m['body']); ?></div><?php } ?>
        <?php foreach ($files[(int) $m['id']] ?? [] as $f) { ?>
            <div class="mt-2"><a href="file.php?id=<?= (int) $f['id']; ?>" target="_blank" rel="noopener" class="small text-decoration-none">
                <i class="bi bi-paperclip"></i> <?= $e($f['original_name']); ?> <span class="text-muted">(<?= $size((int) $f['size_bytes']); ?>)</span></a></div>
        <?php } ?>
    </div>
    <?php } ?>
    <span id="bottom"></span>
</div>

<?php if ($canPost) { ?>
<div class="card border-0 shadow-sm"><div class="card-body">
    <?php if (isset($error)) { ?><div class="alert alert-danger small"><?= $e($error); ?></div><?php } ?>
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="id" value="<?= $cid; ?>">
        <label class="form-label small fw-semibold text-muted"><?= $c['type'] === 'question' && comm_is_management($role) && (int) $c['created_by'] !== $userId ? 'Your answer' : 'Reply'; ?></label>
        <textarea name="body" class="form-control rm-input mb-2" rows="3" maxlength="5000" style="height:auto;"></textarea>
        <div class="d-flex gap-2 align-items-center flex-wrap">
            <input type="file" name="files[]" class="form-control rm-input" style="max-width:420px;" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.pptx,.txt,.csv">
            <button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-send-fill me-1"></i>Send</button>
        </div>
    </form>
</div></div>
<?php } elseif ($c['type'] === 'announcement') { ?>
<div class="small text-muted">Announcements are read-only. For a question about it, use <a href="create.php?type=question">Ask a Question</a>.</div>
<?php } ?>
<?php include '../../includes/footer.php'; ?>