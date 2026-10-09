<?php
/**
 * NEW FILE (same folder as the Add Capital page): delete a manual Capital Fund entry.
 * Spec section 3. Asks for confirmation, then posts a linked REVERSAL (the original stays in the history).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require_once '../../includes/capital_entry_helpers.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('Only an admin can change Capital Fund entries.'));
    exit;
}
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$entry = $id ? capital_entry_find($conn, (int) $id) : null;
if (!$entry) {
    header('Location: capital_inflow.php?error=' . urlencode('This entry cannot be deleted. It may already have been deleted or corrected.'));
    exit;
}

if (isset($_POST['confirm_delete'])) {
    $reason = trim($_POST['reason'] ?? '');
    if ($reason === '') {
        $error = 'Please give a reason for deleting this entry.';
    } else {
        $result = capital_entry_delete($conn, (int) $id, $userId, $reason);
        if ($result['ok']) {
            header('Location: capital_inflow.php?success=' . urlencode('Entry #' . (int) $id . ' deleted. RWF ' . number_format((float) $entry['amount'], 2) . ' removed from the RM Capital Fund.'));
            exit;
        }
        $error = $result['error'];
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-trash';
$modal_title = 'Delete Capital Entry #' . (int) $id;
$modal_subtitle = 'Remove a Capital Fund entry that should not have been recorded.';
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <?php if (!empty($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <div class="alert alert-warning mb-3" style="border-radius:10px;">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                This removes <strong>RWF <?= number_format((float) $entry['amount'], 2); ?></strong>
                (<?= htmlspecialchars($entry['source_type'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>,
                <?= htmlspecialchars(date('M Y', strtotime($entry['period'] . '-01')), ENT_QUOTES, 'UTF-8'); ?>)
                from the RM Capital Fund. The entry stays in the history, marked as Deleted.
            </div>

            <form method="POST" onsubmit="return confirm('Delete this Capital Fund entry?');">
                <input type="hidden" name="id" value="<?= (int) $id; ?>">
                <label class="form-label small fw-semibold text-muted">Reason</label>
                <textarea name="reason" class="form-control rm-input mb-4" rows="3" style="height:auto;" placeholder="e.g. Entered twice by mistake" required><?= htmlspecialchars($_POST['reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

                <div class="d-flex gap-2 justify-content-end">
                    <a href="capital_inflow.php" class="rm-btn rm-btn-secondary">Never mind</a>
                    <button type="submit" name="confirm_delete" value="1" class="rm-btn rm-btn-danger">
                        <i class="bi bi-trash-fill me-1"></i>Delete Entry
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>