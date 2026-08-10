<?php
require '../../config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: index.php?success=Invalid department selected.');
    exit;
}

$departmentStatement = mysqli_prepare($conn, 'SELECT * FROM departments WHERE id = ?');
mysqli_stmt_bind_param($departmentStatement, 'i', $id);
mysqli_stmt_execute($departmentStatement);
$department = mysqli_fetch_assoc(mysqli_stmt_get_result($departmentStatement));

if (!$department) {
    header('Location: index.php?success=Department not found.');
    exit;
}

if (isset($_POST['update'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $error = 'Department name is required.';
    } else {
        $updateStatement = mysqli_prepare($conn, 'UPDATE departments SET name = ?, description = ? WHERE id = ?');
        mysqli_stmt_bind_param($updateStatement, 'ssi', $name, $description, $id);
        mysqli_stmt_execute($updateStatement);

        header('Location: index.php?success=Department updated successfully.');
        exit;
    }

    $department['name'] = $name;
    $department['description'] = $description;
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-building-gear';
$modal_title = 'Edit Department';
$modal_subtitle = 'Update this department\'s details.';
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
                    <label class="form-label small fw-semibold text-muted">Department Name</label>
                    <input type="text" name="name" class="form-control rm-input" value="<?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($department['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="rm-btn rm-btn-primary" type="submit" name="update">
                        <i class="bi bi-check-circle-fill me-2"></i>Update Department
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
