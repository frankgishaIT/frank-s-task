<?php
require '../../config/db.php';
require_role(['Admin']);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

if (!$id) {
    header('Location: index.php?success=Invalid employee selected.');
    exit;
}

$userStatement = mysqli_prepare($conn, 'SELECT * FROM users WHERE id = ?');
mysqli_stmt_bind_param($userStatement, 'i', $id);
mysqli_stmt_execute($userStatement);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($userStatement));

if (!$user) {
    header('Location: index.php?success=Employee not found.');
    exit;
}

if (isset($_POST['update'])) {
    $names = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $role = $_POST['role'] ?? '';
    $departmentId = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
    $monthlySalary = filter_input(INPUT_POST, 'monthly_salary', FILTER_VALIDATE_FLOAT);
    $password = $_POST['password'] ?? '';

    if ($names === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$departmentId || $monthlySalary === false || $monthlySalary < 0 || !in_array($role, ['Admin', 'Manager', 'Employee'], true)) {
        $error = 'Please provide valid employee details.';
    } else {
        $emailStatement = mysqli_prepare($conn, 'SELECT id FROM users WHERE email = ? AND id != ?');
        mysqli_stmt_bind_param($emailStatement, 'si', $email, $id);
        mysqli_stmt_execute($emailStatement);
        $existingEmail = mysqli_stmt_get_result($emailStatement);

        if (mysqli_num_rows($existingEmail) > 0) {
            $error = 'Employee with this email already exists.';
       } elseif ($password !== '') {
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    $updateStatement = mysqli_prepare($conn, 'UPDATE users SET names = ?, email = ?, phone = ?, password_hash = ?, role = ?, department_id = ?, monthly_salary = ? WHERE id = ?');
    mysqli_stmt_bind_param($updateStatement, 'ssssssdi', $names, $email, $phone, $passwordHash, $role, $departmentId, $monthlySalary, $id);
            mysqli_stmt_execute($updateStatement);
            header('Location: index.php?success=Employee updated successfully.');
            exit;
        } else {
            $updateStatement = mysqli_prepare($conn, 'UPDATE users SET names = ?, email = ?, phone = ?, role = ?, department_id = ?, monthly_salary = ? WHERE id = ?');
            mysqli_stmt_bind_param($updateStatement, 'ssssidi', $names, $email, $phone, $role, $departmentId, $monthlySalary, $id);
            mysqli_stmt_execute($updateStatement);
            header('Location: index.php?success=Employee updated successfully.');
            exit;
        }
    }

    $user['names'] = $names;
    $user['email'] = $email;
    $user['phone'] = $phone;
    $user['role'] = $role;
    $user['department_id'] = $departmentId;
    $user['monthly_salary'] = $monthlySalary;
}

$departments = mysqli_query($conn, 'SELECT * FROM departments WHERE is_active = 1 ORDER BY name');

include '../../includes/header.php';
include '../../includes/sidebar.php';

$modal_icon = 'bi-person-gear';
$modal_title = 'Edit Employee';
$modal_subtitle = 'Update this employee\'s profile and account access.';
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
                    <label class="form-label small fw-semibold text-muted">Full name</label>
                    <input type="text" name="name" class="form-control rm-input" value="<?= htmlspecialchars($user['names'], ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Email address</label>
                        <input type="email" name="email" class="form-control rm-input" value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Phone number</label>
                        <input type="text" name="phone" class="form-control rm-input" value="<?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="e.g. 078xxxxxxx">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">New password <span class="text-muted fw-normal">(leave blank to keep current)</span></label>
                    <input type="password" name="password" class="form-control rm-input">
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Role</label>
                        <select name="role" class="form-select rm-input" required>
                            <?php foreach (['Admin', 'Manager', 'Employee'] as $role) { ?>
                                <option value="<?= $role; ?>" <?= $user['role'] === $role ? 'selected' : ''; ?>><?= $role; ?></option>
                            <?php } ?>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Salary (RWF)</label>
                        <input type="number" name="monthly_salary" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars((string) $user['monthly_salary'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Department</label>
                    <select name="department_id" id="departmentSelect" class="form-select rm-input" required>
                        <?php while ($department = mysqli_fetch_assoc($departments)) { ?>
                            <option value="<?= (int) $department['id']; ?>" <?= (int) $user['department_id'] === (int) $department['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button class="rm-btn rm-btn-primary" type="submit" name="update">
                    <i class="bi bi-check-circle-fill"></i>Update Employee
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">Cancel</a>
                </div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>