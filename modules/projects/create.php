<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
if (isset($_POST['save'])) {
    $projectName = trim($_POST['project_name'] ?? ''); $description = trim($_POST['description'] ?? '');
    $startDate = $_POST['start_date'] ?? ''; $endDate = $_POST['end_date'] ?? ''; $status = $_POST['status'] ?? '';
    $createdBy = filter_input(INPUT_POST, 'created_by', FILTER_VALIDATE_INT) ?: null;
    $validStatuses = ['Planning', 'In Progress', 'Completed', 'On Hold'];
    if ($projectName === '' || !in_array($status, $validStatuses, true) || ($startDate !== '' && $endDate !== '' && $startDate > $endDate)) {
        $error = 'Enter a project name, valid status, and valid date range.';
    } else {
        $statement = mysqli_prepare($conn, "INSERT INTO projects (project_name, description, start_date, end_date, status, created_by) VALUES (?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?)");
        mysqli_stmt_bind_param($statement, 'sssssi', $projectName, $description, $startDate, $endDate, $status, $createdBy);
        if (mysqli_stmt_execute($statement)) { header('Location: index.php?success=Project created successfully.'); exit; }
        $error = 'Unable to create the project.';
    }
}
$employees = mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names');
include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-kanban-fill';
$modal_title = 'Add Project';
$modal_subtitle = 'Set up a new project for your team.';
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
                    <label class="form-label small fw-semibold text-muted">Project Name</label>
                    <input type="text" name="project_name" class="form-control rm-input" value="<?= htmlspecialchars($projectName ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Start Date</label>
                        <input type="date" name="start_date" class="form-control rm-input" value="<?= htmlspecialchars($startDate ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">End Date</label>
                        <input type="date" name="end_date" class="form-control rm-input" value="<?= htmlspecialchars($endDate ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <?php foreach (['Planning', 'In Progress', 'Completed', 'On Hold'] as $option) { ?>
                            <option value="<?= $option; ?>" <?= ($status ?? 'Planning') === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Created By</label>
                    <select name="created_by" class="form-select rm-input">
                        <option value="">Not specified</option>
                        <?php while ($employee = mysqli_fetch_assoc($employees)) { ?>
                            <option value="<?= (int) $employee['id']; ?>" <?= isset($createdBy) && $createdBy === (int) $employee['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($employee['names'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Save Project
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
