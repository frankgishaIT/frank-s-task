<?php
require '../../config/db.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

$userId = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle profile info update
if (isset($_POST['update_profile'])) {
    $names = trim($_POST['names'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');

    if ($names === '' || $email === '') {
        $error = 'Name and email are required.';
    } else {
        $stmt = mysqli_prepare($conn, 'UPDATE users SET names = ?, email = ?, phone = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'sssi', $names, $email, $phone, $userId);
        mysqli_stmt_execute($stmt);

        $_SESSION['user_name'] = $names;
        $success = 'Profile updated successfully.';
    }
}

// Handle password change
if (isset($_POST['update_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    $stmt = mysqli_prepare($conn, 'SELECT password_hash FROM users WHERE id = ?');
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$row || !password_verify($currentPassword, $row['password_hash'])) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($newPassword) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($newPassword !== $confirmPassword) {
        $error = 'New password and confirmation do not match.';
    } else {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = mysqli_prepare($conn, 'UPDATE users SET password_hash = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $newHash, $userId);
        mysqli_stmt_execute($stmt);
        $success = 'Password changed successfully.';
    }
}

// Fetch current user data
$stmt = mysqli_prepare($conn, 'SELECT names, email, phone, role FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>My Profile</h2>
</div>

<?php if ($success) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<?php if ($error) { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<div class="row g-4">

    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="mb-3">Profile Information</h5>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Full Name</label>
                        <input type="text" name="names" class="form-control" value="<?= htmlspecialchars($user['names'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Email</label>
                        <input type="email" name="email" class="form-control" value="<?= htmlspecialchars($user['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Phone</label>
                        <input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($user['phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Role</label>
                        <input type="text" class="form-control" value="<?= htmlspecialchars($user['role'], ENT_QUOTES, 'UTF-8'); ?>" disabled>
                    </div>
                    <button type="submit" name="update_profile" class="rm-btn rm-btn-primary">Save Changes</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="mb-3">Change Password</h5>
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label">Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    <button type="submit" name="update_password" class="rm-btn rm-btn-primary">Change Password</button>
                </form>
            </div>
        </div>
    </div>

</div>

<?php include '../../includes/footer.php'; ?>