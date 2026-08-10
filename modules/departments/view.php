<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require_role(['Admin']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: index.php?success=Invalid department selected.');
    exit;
}

$statement = mysqli_prepare($conn, 'SELECT * FROM departments WHERE id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$department = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$department) {
    header('Location: index.php?success=Department not found.');
    exit;
}

// Inline "Roles" (Positions) management, right on the department page.
if (current_user_role() === 'Admin' && isset($_POST['add_role'])) {
    $title = trim($_POST['title'] ?? '');

    if ($title === '') {
        $roleError = 'role title is required.';
    } else {
        $duplicateCheck = mysqli_prepare($conn, 'SELECT id FROM roles WHERE department_id = ? AND name = ?');
        mysqli_stmt_bind_param($duplicateCheck, 'is', $id, $title);
        mysqli_stmt_execute($duplicateCheck);

        if (mysqli_num_rows(mysqli_stmt_get_result($duplicateCheck)) > 0) {
            $roleError = 'This role already exists in this department.';
        } else {
            $statement = mysqli_prepare($conn, 'INSERT INTO roles (department_id, name) VALUES (?, ?)');
            mysqli_stmt_bind_param($statement, 'is', $id, $title);
            mysqli_stmt_execute($statement);

            notify(
                $conn,
                'Role Added',
                '"' . $title . '" role was added to "' . $department['name'] . '".'
            );

            header('Location: view.php?id=' . $id . '&success=Role added successfully.');
            exit;
        }
    }
}

if (current_user_role() === 'Admin' && isset($_POST['remove_role'])) {
    $roleId = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT);

    if ($roleId) {
        $statement = mysqli_prepare($conn, "UPDATE roles SET is_active = 0 WHERE id = ? AND department_id = ?");

        mysqli_stmt_bind_param($statement, 'ii', $roleId, $id);
        mysqli_stmt_execute($statement);

        header('Location: view.php?id=' . $id . '&success=Role removed successfully.');
        exit;
    }
}
$rolesStatement = mysqli_prepare($conn,
"SELECT roles.*,
(SELECT COUNT(*) FROM users
 WHERE users.role_id = roles.id
 AND users.is_active = 1) AS employee_count
FROM roles
WHERE department_id = ?
AND is_active = 1
ORDER BY name");

mysqli_stmt_bind_param($rolesStatement, 'i', $id);
mysqli_stmt_execute($rolesStatement);
$roles = mysqli_stmt_get_result($rolesStatement);

include '../../includes/header.php';
include '../../includes/sidebar.php';
?>

<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<div class="card shadow mb-4">
    <div class="card-header"><h3>Department Details</h3></div>
    <div class="card-body">
        <table class="table table-bordered mb-4">
            <tr><th width="200">Name</th><td><?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Description</th><td><?= nl2br(htmlspecialchars($department['description'], ENT_QUOTES, 'UTF-8')); ?></td></tr>
            <tr><th>Status</th><td><?= $department['is_active'] ? 'Active' : 'Inactive'; ?></td></tr>
            <tr><th>Created At</th><td><?= date('d M Y', strtotime($department['created_at'])); ?></td></tr>
        </table>
        <a href="index.php" class="rm-btn rm-btn-secondary">
    <i class="bi bi-arrow-left"></i>
    Back
</a>
    </div>
</div>

<div class="card shadow">
    <div class="card-header"><h3 class="mb-0">Roles in <?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8'); ?></h3></div>
    <div class="card-body">
        <?php if (isset($roleError)) { ?>
        <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
            <i class="bi bi-exclamation-circle-fill"></i>
            <?= htmlspecialchars($roleError, ENT_QUOTES, 'UTF-8'); ?>
        </div>
        <?php } ?>

        <table class="table table-bordered mb-4">
            <tr><th>Role</th>
            <th>Employees</th>
            <?php if (current_user_role() === 'Admin') { ?>
            <th>Action</th
            ><?php } ?></tr>
            <?php if (mysqli_num_rows($roles) === 0) { ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No roles defined for this department yet.</td></tr>
            <?php } ?>
            <?php while ($role = mysqli_fetch_assoc($roles)) { ?>
            <tr>
                <td><strong><?= htmlspecialchars($role['name'], ENT_QUOTES, 'UTF-8'); ?></strong></td>
                <td><span class="badge bg-secondary"><?= (int) $role['employee_count']; ?></span></td>
                <?php if (current_user_role() === 'Admin') { ?>
                <td>
                    <form method="POST" onsubmit="return confirm('Remove this role?')">
                        <input type="hidden" name="role_id" value="<?= (int) $role['id']; ?>">
                        <button type="submit" name="remove_role" class="rm-btn rm-btn-danger rm-btn-sm">Remove</button>
                    </form>
                </td>
                <?php } ?>
            </tr>
            <?php } ?>
        </table>

        <?php if (current_user_role() === 'Admin') { ?>
        <hr>
        <h6 class="fw-semibold mb-3">Add a Role</h6>
        <form method="POST" class="row g-2 align-items-end">
           <div class="col-md-9">
    <label class="form-label small fw-semibold text-muted">Role</label>
    <input
        type="text"
        name="title"
        class="form-control rm-input"
        placeholder="Enter role name..."
        required>
</div>

<div class="col-md-3 d-flex align-items-end">
    <button type="submit" name="add_role" class="rm-btn rm-btn-primary w-100">
        <i class="bi bi-plus-circle-fill"></i>
        Add Role
    </button>
</div>
        </form>
        <?php } ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>