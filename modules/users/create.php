<?php
require '../../config/db.php';
require '../../includes/notification_helper.php';
require_role(['Admin']);

// Get all departments
$departments = mysqli_query($conn, "SELECT * FROM departments WHERE is_active = 1");
$roles = mysqli_query($conn, "
    SELECT *
    FROM roles
    WHERE is_active = 1
    ORDER BY department_id, name
");

$roleList = [];

while ($r = mysqli_fetch_assoc($roles)) {
    $roleList[] = $r;
}
if (isset($_POST['save'])) {

    $names = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $rawPassword = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';
    $department_id = filter_input(INPUT_POST, 'department_id', FILTER_VALIDATE_INT);
   $role_id = filter_input(INPUT_POST, 'role_id', FILTER_VALIDATE_INT) ?: null;
    $monthly_salary = filter_input(INPUT_POST, 'monthly_salary', FILTER_VALIDATE_FLOAT);
    $monthly_salary = $monthly_salary === false ? 0 : $monthly_salary;

    if ($names === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $rawPassword === '' || !$department_id || $monthly_salary < 0 || !in_array($role, ['Admin', 'Manager', 'Employee'], true)) {
        $error = "Please provide valid employee details.";
    } else {

    $password = password_hash($rawPassword, PASSWORD_DEFAULT);

   $check = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ?");
mysqli_stmt_bind_param($check, "s", $email);
mysqli_stmt_execute($check);
$checkResult = mysqli_stmt_get_result($check);

    if (mysqli_num_rows($checkResult) > 0) {
        $error = "Employee with this email already exists.";
    } else {
      $stmt = mysqli_prepare($conn, "INSERT INTO users
(names, email, phone, password_hash, role, department_id, role_id, monthly_salary)
VALUES (?, ?, ?, ?, ?, ?, ?, ?)");

mysqli_stmt_bind_param(
    $stmt,
    "sssssiid",
    $names,
    $email,
    $phone,
    $password,
    $role,
    $department_id,
    $role_id,
    $monthly_salary
);
        mysqli_stmt_execute($stmt);
        notify(
    $conn,
    'Employee Added',
    '"' . $names . '" has been added.'
);

        header("Location: index.php?success=Employee added successfully.");
        exit;
    }
    }
}
$modal_icon = 'bi-person-plus-fill';
$modal_title = 'Add Employee';
$modal_subtitle = 'Create a new employee profile and account access.';
include '../../includes/header.php';
include '../../includes/sidebar.php';
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
                    <input type="text" name="name" class="form-control rm-input" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Email address</label>
                        <input type="email" name="email" class="form-control rm-input" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Phone number</label>
                        <input type="text" name="phone" class="form-control rm-input" placeholder="e.g. 078xxxxxxx">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Password</label>
                    <input type="password" name="password" class="form-control rm-input" required>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Role</label>
                        <select name="role" class="form-select rm-input" required>
                            <option value="">Select</option>
                            <option value="Admin">Admin</option>
                            <option value="Manager">Manager</option>
                            <option value="Employee">Employee</option>
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Salary (RWF)</label>
                        <input type="number" name="monthly_salary" class="form-control rm-input" min="0" step="0.01" value="0">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-muted">Department</label>
                    <select name="department_id" id="departmentSelect" class="form-select rm-input" required>
                        <option value="">Select department</option>
                        <?php while ($department = mysqli_fetch_assoc($departments)) { ?>
                            <option value="<?= $department['id']; ?>">
                                <?= htmlspecialchars($department['name'], ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div class="mb-4">
                   <label class="form-label small fw-semibold text-muted">
                                           Department Role
                                        </label>

                         <select name="role_id" id="roleSelect" class="form-select rm-input">

                  <option value="">Select role</option>

            </select>
        </div>
       <script>
const ALL_ROLES = <?= json_encode(array_map(function ($r) {
    return [
        'id' => (int)$r['id'],
        'name' => $r['name'],
        'department_id' => (int)$r['department_id']
    ];
}, $roleList)); ?>;

const departmentSelect = document.getElementById('departmentSelect');
const roleSelect = document.getElementById('roleSelect');

function loadRoles() {

    const departmentId = parseInt(departmentSelect.value || 0);

    roleSelect.innerHTML = '<option value="">Select role</option>';

    ALL_ROLES
        .filter(role => role.department_id === departmentId)
        .forEach(role => {

            const option = document.createElement('option');

            option.value = role.id;
            option.textContent = role.name;

            roleSelect.appendChild(option);
        });
}

departmentSelect.addEventListener('change', loadRoles);
</script>
               <div class="d-flex justify-content-end gap-3 mt-4">

    <button type="submit" name="save" class="rm-btn rm-btn-primary">
    <i class="bi bi-check-circle-fill"></i>
    Save Employee
</button>

<a href="index.php" class="rm-btn rm-btn-secondary">
    Cancel
</a>

</div>
            </form>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->


<?php
include '../../includes/footer.php';
?>