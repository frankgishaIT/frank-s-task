<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
if (isset($_POST['save'])) { $categoryName = trim($_POST['category_name'] ?? ''); $description = trim($_POST['description'] ?? ''); if ($categoryName === '') { $error = 'Category name is required.'; } else { $duplicateCheck = mysqli_prepare($conn, 'SELECT id FROM categories WHERE category_name = ?'); mysqli_stmt_bind_param($duplicateCheck, 's', $categoryName); mysqli_stmt_execute($duplicateCheck); if (mysqli_num_rows(mysqli_stmt_get_result($duplicateCheck)) > 0) { $error = 'A category with this name already exists.'; } else { $statement = mysqli_prepare($conn, 'INSERT INTO categories (category_name, description) VALUES (?, ?)'); mysqli_stmt_bind_param($statement, 'ss', $categoryName, $description); if (mysqli_stmt_execute($statement)) { header('Location: index.php?success=Category added successfully.'); exit; } $error = 'Unable to add category.'; } } }
include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-tag-fill';
$modal_title = 'Add Category';
$modal_subtitle = 'Create a new category for products or transactions.';
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
                    <label class="form-label small fw-semibold text-muted">Category Name</label>
                    <input type="text" name="category_name" class="form-control rm-input" value="<?= htmlspecialchars($categoryName ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($description ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-primary rm-btn-primary" type="submit" name="save">
                        <i class="bi bi-check-circle-fill me-2"></i>Save Category
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
