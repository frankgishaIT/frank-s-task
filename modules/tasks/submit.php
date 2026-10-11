<?php
require '../../config/db.php';
// NEW: role check (the page had none of its own). Only the assigned employee can submit, see below.
require_role(['Admin', 'Manager', 'Employee']);

// CHANGED: problems are sent as "error" (they were sent as "success"), and messages are urlencoded.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid task selected.')); exit; }

$userId = (int) current_user_id();
$statement = mysqli_prepare($conn, 'SELECT tasks.*, projects.project_name FROM tasks INNER JOIN projects ON tasks.project_id = projects.id WHERE tasks.id = ? AND tasks.assigned_to = ?');
mysqli_stmt_bind_param($statement, 'ii', $id, $userId);
mysqli_stmt_execute($statement);
$task = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$task) { header('Location: index.php?error=' . urlencode('Task not found or not assigned to you.')); exit; }
if ($task['status'] !== 'In Progress') { header('Location: index.php?error=' . urlencode('This task cannot be submitted from its current status.')); exit; }

$note = '';
if (isset($_POST['save'])) {
    $note = trim($_POST['completion_note'] ?? '');
    if ($note === '') {
        $error = 'Please describe what was completed before submitting.';
    } elseif (mb_strlen($note) > 5000) {
        $error = 'The description is too long (maximum 5000 characters).';
    } else {
        // CHANGED: the update itself checks that the task is still In Progress and still assigned to
        // this employee, so a double click or a reassignment at the same moment cannot change it wrongly.
        // submitted_at is what the reports and the payroll performance bonus use for "on time" / "late".
        $update = mysqli_prepare($conn, "UPDATE tasks SET status = 'Completed', submitted_at = NOW(), completion_note = ?
            WHERE id = ? AND assigned_to = ? AND status = 'In Progress'");
        mysqli_stmt_bind_param($update, 'sii', $note, $id, $userId);
        mysqli_stmt_execute($update);

        if (mysqli_stmt_affected_rows($update) === 1) {
            header('Location: index.php?success=' . urlencode('Task submitted for review.'));
        } else {
            header('Location: index.php?error=' . urlencode('This task cannot be submitted any more (it was already submitted or reassigned).'));
        }
        exit;
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-send-check-fill';
$modal_title = 'Submit Task';
$modal_subtitle = htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($task['project_name'], ENT_QUOTES, 'UTF-8');

// NEW: show the due date, so the employee knows if this submission is on time or late.
$dueText = null; $late = false;
if (!empty($task['due_date'])) {
    $late = strtotime($task['due_date']) < strtotime('today');
    $dueText = date('d M Y', strtotime($task['due_date']));
}
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

            <?php if ($dueText) { ?>
            <div class="alert <?= $late ? 'alert-warning' : 'alert-info'; ?> small mb-3" style="border-radius:10px; border:none;">
                <i class="bi bi-calendar-event me-1"></i>
                Due date: <strong><?= $dueText; ?></strong>.
                <?= $late ? 'This task is past its due date, so it will be recorded as submitted late.' : 'Submitting now counts as on time.'; ?>
            </div>
            <?php } ?>

            <form method="POST">
                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">What did you complete?</label>
                    <!-- CHANGED: the text you typed is kept if there is an error. -->
                    <textarea name="completion_note" class="form-control rm-input" rows="5" maxlength="5000" style="height:auto;" required><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8'); ?></textarea>
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