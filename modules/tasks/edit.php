<?php
require '../../config/db.php';
require_role(['Admin', 'Manager']);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid task selected.'); exit; }
$taskStatement = mysqli_prepare($conn, 'SELECT * FROM tasks WHERE id = ?'); mysqli_stmt_bind_param($taskStatement, 'i', $id); mysqli_stmt_execute($taskStatement); $task = mysqli_fetch_assoc(mysqli_stmt_get_result($taskStatement)); if (!$task) { header('Location: index.php?success=Task not found.'); exit; }

if (isset($_POST['update'])) {
    $projectId = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT); $title = trim($_POST['title'] ?? ''); $description = trim($_POST['description'] ?? ''); $assignedTo = filter_input(INPUT_POST, 'assigned_to', FILTER_VALIDATE_INT) ?: null; $priority = $_POST['priority'] ?? ''; $dueDate = $_POST['due_date'] ?? '';
    if (!$projectId || $title === '' || !in_array($priority, ['Low', 'Medium', 'High'], true)) {
        $error = 'Please provide valid task details.';
    } else {
        // Status is not editable here - it only moves through Accept/Submit/Review actions.
        $statement = mysqli_prepare($conn, "UPDATE tasks SET project_id = ?, title = ?, description = ?, assigned_to = ?, priority = ?, due_date = NULLIF(?, '') WHERE id = ?");
        mysqli_stmt_bind_param($statement, 'ississi', $projectId, $title, $description, $assignedTo, $priority, $dueDate, $id);
        mysqli_stmt_execute($statement);
        header('Location: index.php?success=Task updated successfully.'); exit;
    }
    $task = array_merge($task, ['project_id' => $projectId, 'title' => $title, 'description' => $description, 'assigned_to' => $assignedTo, 'priority' => $priority, 'due_date' => $dueDate]);
}
$projects = mysqli_query($conn, 'SELECT id, project_name FROM projects ORDER BY project_name'); $employees = mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'); include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-pencil-square';
$modal_title = 'Edit Task';
$modal_subtitle = 'Update this task\'s details.';
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
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Project</label>
                    <select name="project_id" class="form-select rm-input" required>
                        <?php while ($project = mysqli_fetch_assoc($projects)) { ?>
                            <option value="<?= (int) $project['id']; ?>" <?= (int) $task['project_id'] === (int) $project['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($project['project_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Task Title</label>
                    <input type="text" name="title" class="form-control rm-input" value="<?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($task['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Assign To</label>
                    <select name="assigned_to" class="form-select rm-input">
                        <option value="">Unassigned</option>
                        <?php while ($employee = mysqli_fetch_assoc($employees)) { ?>
                            <option value="<?= (int) $employee['id']; ?>" <?= (int) $task['assigned_to'] === (int) $employee['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($employee['names'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                    <small class="text-muted">Reassigning resets nothing automatically — the current workflow status (<?= htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8'); ?>) stays as-is.</small>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Priority</label>
                        <select name="priority" class="form-select rm-input">
                            <?php foreach (['Low', 'Medium', 'High'] as $option) { ?>
                                <option value="<?= $option; ?>" <?= $task['priority'] === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Due Date</label>
                        <input type="date" name="due_date" class="form-control rm-input" value="<?= htmlspecialchars($task['due_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-primary rm-btn-primary" type="submit" name="update">
                        <i class="bi bi-check-circle-fill me-2"></i>Update Task
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
