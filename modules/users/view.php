<?php
require '../../config/db.php';
require_role(['Admin']);
include '../../includes/header.php';
include '../../includes/sidebar.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: index.php?success=Invalid employee selected.');
    exit;
}

$statement = mysqli_prepare($conn, "
    SELECT users.*, departments.name AS department_name
    FROM users
    LEFT JOIN departments ON users.department_id = departments.id
    WHERE users.id = ?
");
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$user) {
    header('Location: index.php?success=Employee not found.');
    exit;
}
?>

<div class="card shadow">

    <div class="card-header">
        <h3>Employee Details</h3>
    </div>

    <div class="card-body">

        <table class="table table-bordered">

            <tr>
                <th width="200">Full Name</th>
                <td><?= htmlspecialchars($user['names'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>

            <tr>
                <th>Email</th>
                <td><?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>

            <tr>
                <th>Role</th>
                <td><?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>

            <tr>
                <th>Department</th>
                <td><?= htmlspecialchars($user['department_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>

            <tr>
                <th>Status</th>
                <td>
                    <?= htmlspecialchars($user['is_active'] ? "Active" : "Inactive", ENT_QUOTES, 'UTF-8'); ?>
                </td>
            </tr>

            <tr>
                <th>Created At</th>
                <td><?= htmlspecialchars(date('d M Y', strtotime($user['created_at'])), ENT_QUOTES, 'UTF-8'); ?></td>
            </tr>

        </table>

        <a href="index.php" class="rm-btn rm-btn-secondary">Back</a>

    </div>

</div>

<?php
include '../../includes/footer.php';
?>