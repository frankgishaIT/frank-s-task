<?php
require '../../config/db.php';
require_role(['Manager', 'Admin']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid task selected.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT tasks.*, projects.project_name, users.names AS assignee_name FROM tasks INNER JOIN projects ON tasks.project_id = projects.id LEFT JOIN users ON tasks.assigned_to = users.id WHERE tasks.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$task = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$task) { header('Location: index.php?success=Task not found.'); exit; }

$canAct = $task['status'] === 'Completed';

if ($canAct && isset($_POST['decision'])) {
    $decision = $_POST['decision'];
    $note = trim($_POST['review_note'] ?? '');
    $score = filter_input(INPUT_POST, 'performance_score', FILTER_VALIDATE_INT);
    $reviewerId = current_user_id();

    if (!in_array($decision, ['approve', 'reject'], true)) {
        $error = 'Invalid decision.';
    } elseif ($decision === 'approve' && (!$score || $score < 1 || $score > 100)) {
        $error = 'Please give a performance score between 1 and 100 when approving.';
    } else {
        if ($decision === 'approve') {
            $statement = mysqli_prepare($conn, "UPDATE tasks SET status = 'Approved', reviewed_by = ?, review_note = ?, performance_score = ?, reviewed_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($statement, 'isii', $reviewerId, $note, $score, $id);
        } else {
            $statement = mysqli_prepare($conn, "UPDATE tasks SET status = 'Rejected', reviewed_by = ?, review_note = ?, reviewed_at = NOW() WHERE id = ?");
            mysqli_stmt_bind_param($statement, 'isi', $reviewerId, $note, $id);
        }
        mysqli_stmt_execute($statement);
        header('Location: index.php?success=Task reviewed successfully.');
        exit;
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-clipboard-check-fill';
$modal_title = 'Review Task';
$modal_subtitle = htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8') . ' — ' . htmlspecialchars($task['assignee_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8');
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

            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Completion Note from Employee</label>
                <textarea class="form-control rm-input" rows="4" style="height:auto;" disabled><?= htmlspecialchars($task['completion_note'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>

            <?php if (!$canAct) { ?>
                <div class="alert alert-secondary" style="border-radius:10px;">
                    This task is <?= strtolower($task['status']); ?> and has no pending review.
                </div>
                <a href="index.php" class="btn btn-light rm-btn-light">Back</a>
            <?php } else { ?>
            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Performance Score <span class="text-muted fw-normal">(1–100, required to approve)</span></label>
                    <input type="number" name="performance_score" class="form-control rm-input" min="1" max="100">
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Review Note <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea name="review_note" class="form-control rm-input" rows="3" style="height:auto;"></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-success rm-btn-primary" type="submit" name="decision" value="approve">
                        <i class="bi bi-check-circle-fill me-2"></i>Approve & Score
                    </button>
                    <button class="btn btn-danger rm-btn-primary" type="submit" name="decision" value="reject">
                        <i class="bi bi-x-circle-fill me-2"></i>Reject
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
            <?php } ?>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
