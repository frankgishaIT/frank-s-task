<?php
require '../../config/db.php'; $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT); if (!$id) { header('Location: index.php?success=Invalid category selected.'); exit; } $categoryStatement = mysqli_prepare($conn, 'SELECT * FROM categories WHERE id = ?'); mysqli_stmt_bind_param($categoryStatement, 'i', $id); mysqli_stmt_execute($categoryStatement); $category = mysqli_fetch_assoc(mysqli_stmt_get_result($categoryStatement)); if (!$category) { header('Location: index.php?success=Category not found.'); exit; }
if (isset($_POST['update'])) { $categoryName = trim($_POST['category_name'] ?? ''); $description = trim($_POST['description'] ?? ''); if ($categoryName === '') { $error = 'Category name is required.'; } else { $duplicateCheck = mysqli_prepare($conn, 'SELECT id FROM categories WHERE category_name = ? AND id != ?'); mysqli_stmt_bind_param($duplicateCheck, 'si', $categoryName, $id); mysqli_stmt_execute($duplicateCheck); if (mysqli_num_rows(mysqli_stmt_get_result($duplicateCheck)) > 0) { $error = 'A category with this name already exists.'; } else { $statement = mysqli_prepare($conn, 'UPDATE categories SET category_name = ?, description = ? WHERE id = ?'); mysqli_stmt_bind_param($statement, 'ssi', $categoryName, $description, $id); if (mysqli_stmt_execute($statement)) { header('Location: index.php?success=Category updated successfully.'); exit; } $error = 'Unable to update category.'; } } $category['category_name'] = $categoryName; $category['description'] = $description; }
include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-tags-fill';
$modal_title = 'Edit Category';
$modal_subtitle = 'Update this category\'s details.';
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
                    <input type="text" name="category_name" class="form-control rm-input" value="<?= htmlspecialchars($category['category_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Description</label>
                    <textarea name="description" class="form-control rm-input" rows="4" style="height:auto;"><?= htmlspecialchars($category['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="btn btn-primary rm-btn-primary" type="submit" name="update">
                        <i class="bi bi-check-circle-fill me-2"></i>Update Category
                    </button>
                    <a href="index.php" class="btn btn-light rm-btn-light">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>
