<?php
/**
 * NEW FILE (same folder as the Add Capital page): edit a manual Capital Fund entry.
 * Spec section 3. The original is reversed and a corrected entry is recorded, linked to it.
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
    header('Location: capital_inflow.php?error=' . urlencode('This entry cannot be edited. It may already have been deleted or corrected.'));
    exit;
}

$sources = capital_sources();
$source = $entry['source_type'];
$amount = (float) $entry['amount'];
$date = $entry['period'] . '-01';
$note = $entry['description'] === $entry['source_type'] ? '' : (string) $entry['description'];

if (isset($_POST['save'])) {
    $source = $_POST['source_type'] ?? '';
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $date = $_POST['inflow_date'] ?? '';
    $note = trim($_POST['description'] ?? '');

    $error = capital_entry_validate($source, $amount, $date, $note);
    if ($error === null) {
        $result = capital_entry_edit($conn, (int) $id, $source, (float) $amount, $date, $note, $userId);
        if ($result['ok']) {
            header('Location: capital_inflow.php?success=' . urlencode('Entry #' . (int) $id . ' corrected to RWF ' . number_format($amount, 2) . '.'));
            exit;
        }
        $error = $result['error'];
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-pencil-square';
$modal_title = 'Edit Capital Entry #' . (int) $id;
$modal_subtitle = 'Correct a Capital Fund entry that was recorded wrongly.';
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

            <div class="alert alert-info mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
                Currently recorded: <strong>RWF <?= number_format((float) $entry['amount'], 2); ?></strong>
                (<?= htmlspecialchars($entry['source_type'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>).
                Saving keeps the original in the history and records the corrected entry. The RM Capital Fund balance and reports update automatically.
            </div>

            <form method="POST">
                <input type="hidden" name="id" value="<?= (int) $id; ?>">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Source</label>
                    <select name="source_type" class="form-select rm-input" required>
                        <?php foreach ($sources as $s) { ?>
                        <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" <?= $source === $s ? 'selected' : ''; ?>><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Correct Amount (RWF)</label>
                        <input type="number" name="amount" class="form-control rm-input" min="0.01" step="0.01" value="<?= htmlspecialchars($amount !== false && $amount !== null ? (string) $amount : '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Date Received</label>
                        <input type="date" name="inflow_date" class="form-control rm-input" max="<?= date('Y-m-d'); ?>" value="<?= htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description / Reference</label>
                    <textarea name="description" class="form-control rm-input" rows="3" style="height:auto;"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Save Correction
                    </button>
                    <a href="capital_inflow.php" class="rm-btn rm-btn-secondary">
                        <i class="bi bi-x-circle-fill me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>