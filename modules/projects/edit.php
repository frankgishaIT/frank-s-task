<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?success=' . urlencode('You do not have permission to edit projects.'));
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid project selected.'); exit; }
$projectStatement = mysqli_prepare($conn, 'SELECT * FROM projects WHERE id = ?'); mysqli_stmt_bind_param($projectStatement, 'i', $id); mysqli_stmt_execute($projectStatement); $project = mysqli_fetch_assoc(mysqli_stmt_get_result($projectStatement));
if (!$project) { header('Location: index.php?success=Project not found.'); exit; }
if (isset($_POST['update'])) {
    $projectName = trim($_POST['project_name'] ?? ''); $description = trim($_POST['description'] ?? ''); $startDate = $_POST['start_date'] ?? ''; $endDate = $_POST['end_date'] ?? ''; $status = $_POST['status'] ?? ''; $createdBy = filter_input(INPUT_POST, 'created_by', FILTER_VALIDATE_INT) ?: null;
    if ($projectName === '' || !in_array($status, ['Planning', 'In Progress', 'Completed', 'On Hold'], true) || ($startDate !== '' && $endDate !== '' && $startDate > $endDate)) { $error = 'Enter a project name, valid status, and valid date range.'; }
    else { $statement = mysqli_prepare($conn, "UPDATE projects SET project_name = ?, description = ?, start_date = NULLIF(?, ''), end_date = NULLIF(?, ''), status = ?, created_by = ? WHERE id = ?"); mysqli_stmt_bind_param($statement, 'sssssii', $projectName, $description, $startDate, $endDate, $status, $createdBy, $id); mysqli_stmt_execute($statement); header('Location: index.php?success=Project updated successfully.'); exit; }
    $project = ['project_name' => $projectName, 'description' => $description, 'start_date' => $startDate, 'end_date' => $endDate, 'status' => $status, 'created_by' => $createdBy];
}
$employees = mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'); include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-kanban';
$modal_title = 'Edit Project';
$modal_subtitle = 'Update this project\'s details.';
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
                    <input type="text" name="project_name" class="form-control rm-input" value="<?= htmlspecialchars($project['project_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($project['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Start Date</label>
                        <input type="date" name="start_date" class="form-control rm-input" value="<?= htmlspecialchars($project['start_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">End Date</label>
                        <input type="date" name="end_date" class="form-control rm-input" value="<?= htmlspecialchars($project['end_date'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <?php foreach (['Planning', 'In Progress', 'Completed', 'On Hold'] as $option) { ?>
                            <option value="<?= $option; ?>" <?= $project['status'] === $option ? 'selected' : ''; ?>><?= $option; ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Created By</label>
                    <select name="created_by" class="form-select rm-input">
                        <option value="">Not specified</option>
                        <?php while ($employee = mysqli_fetch_assoc($employees)) { ?>
                            <option value="<?= (int) $employee['id']; ?>" <?= (int) $project['created_by'] === (int) $employee['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($employee['names'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="rm-btn rm-btn-primary" type="submit" name="update">
                        <i class="bi bi-check-circle-fill me-2"></i>Update Project
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>