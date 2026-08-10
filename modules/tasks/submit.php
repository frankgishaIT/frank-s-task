<?php
require '../../config/db.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid task selected.'); exit; }

$userId = current_user_id();
$statement = mysqli_prepare($conn, 'SELECT tasks.*, projects.project_name FROM tasks INNER JOIN projects ON tasks.project_id = projects.id WHERE tasks.id = ? AND tasks.assigned_to = ?');
mysqli_stmt_bind_param($statement, 'ii', $id, $userId);
mysqli_stmt_execute($statement);
$task = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$task) { header('Location: index.php?success=Task not found or not assigned to you.'); exit; }
if ($task['status'] !== 'In Progress') { header('Location: index.php?success=This task cannot be submitted from its current status.'); exit; }

if (isset($_POST['save'])) {
    $note = trim($_POST['completion_note'] ?? '');
    if ($note === '') {
        $error = 'Please describe what was completed before submitting.';
    } else {
        $update = mysqli_prepare($conn, "UPDATE tasks SET status = 'Completed', submitted_at = NOW(), completion_note = ? WHERE id = ?");
        mysqli_stmt_bind_param($update, 'si', $note, $id);
        mysqli_stmt_execute($update);
        header('Location: index.php?success=Task submitted for review.');
        exit;
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-send-check-fill';
$modal_title = 'Submit Task';
$modal_subtitle = htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($task['project_name'], ENT_QUOTES, 'UTF-8');
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <form method="POST">
                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">What did you complete?</label>
                    <textarea name="completion_note" class="form-control rm-input" rows="5" style="height:auto;" required></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-primary rm-btn-primary" type="submit" name="save">
                        <i class="bi bi-check-circle-fill me-2"></i>Submit for Review
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
