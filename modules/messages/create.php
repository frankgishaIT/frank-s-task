<?php
/**
 * NEW FILE: modules/messages/create.php
 * New announcement (Admins / Managers), question, or private message, with attached documents.
 */
require '../../config/db.php';
require_once '../../includes/comm_helpers.php';
require_role(['Admin', 'Manager', 'Employee']);
comm_ensure($conn);

$userId = (int) current_user_id();
$role = current_user_role();
$type = $_POST['type'] ?? ($_GET['type'] ?? 'private');
if (!isset(COMM_TYPES[$type]) || ($type === 'announcement' && !comm_is_management($role))) { $type = 'private'; }
$people = comm_people($conn, $userId);
$preselect = array_map('intval', (array) ($_POST['recipients'] ?? (isset($_GET['to']) ? [$_GET['to']] : [])));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $files = comm_store_files($conn, $_FILES['files'] ?? null, $userId);
        $cid = comm_create($conn, $type, (string) ($_POST['title'] ?? ''), $userId, $role, (array) ($_POST['recipients'] ?? []), (string) ($_POST['body'] ?? ''), $files);
        header('Location: view.php?id=' . $cid . '&success=' . urlencode(['announcement' => 'Announcement published.', 'question' => 'Question sent to management.', 'private' => 'Private message sent.'][$type]));
        exit;
    } catch (RuntimeException $ex) {
        $error = $ex->getMessage();
    } catch (Throwable $ex) {
        error_log('message create failed: ' . $ex->getMessage());
        $error = 'Unable to send. Nothing was sent.' . (comm_is_management($role) ? ' [Technical detail for Admin: ' . $ex->getMessage() . ']' : '');
    }
}

$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$modal_icon = ['announcement' => 'bi-megaphone-fill', 'question' => 'bi-question-circle-fill', 'private' => 'bi-lock-fill'][$type];
$modal_title = ['announcement' => 'New Announcement', 'question' => 'Ask a Question', 'private' => 'New Private Message'][$type];
$modal_subtitle = ['announcement' => 'Everyone in MY MOTIVE will see it and be notified.', 'question' => 'Management will answer you here.', 'private' => 'Only the people you choose can see this conversation.'][$type];
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>
        <div class="rm-modal-body">
            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i><?= $e($error); ?>
            </div>
            <?php } ?>
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="type" value="<?= $e($type); ?>">
                <?php if ($type === 'private') { ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">To <span class="text-danger">*</span></label>
                    <input type="text" id="peopleFilter" class="form-control rm-input mb-2" placeholder="Type a name to find people">
                    <div id="peopleList" style="max-height:200px; overflow-y:auto; border:1px solid #E4E8F2; border-radius:10px; padding:6px 10px;">
                        <?php foreach ($people as $p) { ?>
                        <label class="d-block py-1 person"><input type="checkbox" name="recipients[]" value="<?= (int) $p['id']; ?>" class="form-check-input me-2" <?= in_array((int) $p['id'], $preselect, true) ? 'checked' : ''; ?>>
                            <?= $e($p['names']); ?> <span class="text-muted small">(<?= $e(ucfirst(strtolower($p['role']))); ?>)</span></label>
                        <?php } ?>
                    </div>
                </div>
                <?php } ?>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Subject <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="form-control rm-input" maxlength="200" value="<?= $e($_POST['title'] ?? ''); ?>" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted"><?= $type === 'question' ? 'Your question' : 'Message'; ?></label>
                    <textarea name="body" class="form-control rm-input" rows="6" maxlength="5000" style="height:auto;"><?= $e($_POST['body'] ?? ''); ?></textarea>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Attach documents (optional)</label>
                    <input type="file" name="files[]" class="form-control rm-input" multiple accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx,.pptx,.txt,.csv">
                    <div class="form-text">Up to <?= COMM_MAX_FILES; ?> files, 5 MB each: PDF, Word, Excel, PowerPoint, photos, text or CSV.</div>
                </div>
                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-send-fill me-2"></i><?= $type === 'announcement' ? 'Publish' : 'Send'; ?></button>
                    <a href="index.php?tab=<?= $e($type); ?>" class="rm-btn rm-btn-secondary">Cancel</a>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
(function () {
    var f = document.getElementById('peopleFilter');
    if (!f) { return; }
    f.addEventListener('input', function () {
        var q = f.value.trim().toLowerCase();
        document.querySelectorAll('#peopleList .person').forEach(function (l) { l.style.display = l.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none'; });
    });
})();
</script>
<?php include '../../includes/footer.php'; ?>