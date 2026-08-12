<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require_role(['Admin']);
if (isset($_POST['save'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        $error = 'Department name is required.';
    } else {
        $statement = mysqli_prepare($conn, 'INSERT INTO departments (name, description) VALUES (?, ?)');
        mysqli_stmt_bind_param($statement, 'ss', $name, $description);

        if (mysqli_stmt_execute($statement)) {
            notify(
    $conn,
    'Department Created',
    '"' . $name . '" department has been created.'
);
            header('Location: index.php?success=Department added successfully.');
            exit;
        }

        $error = 'Unable to add the department. Please try again.';
    }
}

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-building-fill-add';
$modal_title = 'Add Department';
$modal_subtitle = 'Create a new department for your organization.';
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
                    <input type="text" name="name" class="form-control rm-input" value="<?= htmlspecialchars($name ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="rm-btn rm-btn-primary" type="submit" name="save">
                        <i class="bi bi-check-circle-fill me-2"></i>Save Department
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
