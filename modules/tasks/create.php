<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require_role(['Admin', 'Manager']);
if (isset($_POST['save'])) {
    $projectId = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT); $title = trim($_POST['title'] ?? ''); $description = trim($_POST['description'] ?? ''); $assignedTo = filter_input(INPUT_POST, 'assigned_to', FILTER_VALIDATE_INT) ?: null; $priority = $_POST['priority'] ?? ''; $dueDate = $_POST['due_date'] ?? '';
    if (!$projectId || $title === '' || !in_array($priority, ['Low', 'Medium', 'High'], true)) { $error = 'Please provide valid task details.'; }
    else { $status = 'Pending'; $statement = mysqli_prepare($conn, "INSERT INTO tasks (project_id, title, description, assigned_to, priority, status, due_date) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''))"); mysqli_stmt_bind_param($statement, 'ississs', $projectId, $title, $description, $assignedTo, $priority, $status, $dueDate); if (mysqli_stmt_execute($statement)) { header('Location: index.php?success=Task created successfully.'); exit; } $error = 'Unable to create the task.'; }
}
$projects = mysqli_query($conn, "SELECT id, project_name FROM projects WHERE status != 'Completed' ORDER BY project_name"); $employees = mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'); include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-check2-square';
$modal_title = 'Add Task';
$modal_subtitle = 'Create a new task for a project.';
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
                        <option value="">Select project</option>
                        <?php while ($project = mysqli_fetch_assoc($projects)) { ?>
                            <option value="<?= (int) $project['id']; ?>" <?= isset($projectId) && $projectId === (int) $project['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($project['project_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Task Title</label>
                    <input type="text" name="title" class="form-control rm-input" value="<?= htmlspecialchars($title ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Assign To</label>
                    <select name="assigned_to" class="form-select rm-input">
                        <option value="">Unassigned</option>
                        <?php while ($employee = mysqli_fetch_assoc($employees)) { ?>
                            <option value="<?= (int) $employee['id']; ?>" <?= isset($assignedTo) && $assignedTo === (int) $employee['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($employee['names'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-4">
                        <label class="form-label small fw-semibold text-muted">Priority</label>
                        <select name="priority" class="form-select rm-input">
                            <?php foreach (['Low', 'Medium', 'High'] as $option) { ?>
                                <option value="<?= $option; ?>" <?= ($priority ?? 'Medium') === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-4">
                        <label class="form-label small fw-semibold text-muted">Due Date</label>
                        <input type="date" name="due_date" class="form-control rm-input" value="<?= htmlspecialchars($dueDate ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Save Task
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">
                        <i class="bi bi-x-circle-fill me-2"></i>Cancel
                    </a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
