<?php
require '../../config/db.php';
require '../../includes/user_photo_helpers.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// NOTE: header.php / sidebar.php are included further down, AFTER the form
// handling, so the top bar (name + photo) already shows the new values on
// the same page load instead of needing a refresh.

$userId = $_SESSION['user_id'];
$success = '';
$error = '';

// Handle profile photo upload / removal
if (isset($_POST['update_photo'])) {
    $photoCheck = validate_user_photo($_FILES['photo'] ?? null);
    $removePhoto = isset($_POST['remove_photo']);

    if (isset($photoCheck['error'])) {
        $error = $photoCheck['error'];
    } elseif (!empty($photoCheck['none']) && !$removePhoto) {
        $error = 'Please choose a photo first.';
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT photo FROM users WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'i', $userId);
        mysqli_stmt_execute($stmt);
        $oldPhoto = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['photo'] ?? null;

        if (save_user_photo($conn, $userId, $photoCheck, $removePhoto, $oldPhoto)) {
            $success = !empty($photoCheck['tmp']) ? 'Profile photo updated.' : 'Profile photo removed.';
        } else {
            $error = 'The photo could not be saved. Please try again.';
        }
    }
}

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
$stmt = mysqli_prepare($conn, 'SELECT names, email, phone, role, photo FROM users WHERE id = ?');
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

$currentPhoto = !empty($user['photo']) ? BASE_URL . '/assets/uploads/users/' . rawurlencode(basename($user['photo'])) : null;

include '../../includes/header.php';
include '../../includes/sidebar.php';
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

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h5 class="mb-3">Profile Photo</h5>
                <form method="POST" enctype="multipart/form-data">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:88px; height:88px; border-radius:50%; overflow:hidden; background:var(--accent-blue-bg); color:var(--accent-blue); display:flex; align-items:center; justify-content:center; font-size:34px; flex-shrink:0;">
                            <?php if ($currentPhoto) { ?>
                                <img id="photoPreview" src="<?= htmlspecialchars($currentPhoto, ENT_QUOTES, 'UTF-8'); ?>" alt="" style="width:100%; height:100%; object-fit:cover;">
                            <?php } else { ?>
                                <img id="photoPreview" src="" alt="" style="width:100%; height:100%; object-fit:cover; display:none;">
                                <i class="bi bi-person-fill" id="photoPlaceholder"></i>
                            <?php } ?>
                        </div>
                        <div class="flex-grow-1">
                            <input type="file" name="photo" id="photoInput" class="form-control" accept="image/jpeg,image/png,image/webp">
                            <div class="form-text">JPG, PNG or WEBP, up to 2 MB.</div>
                            <?php if ($currentPhoto) { ?>
                            <div class="form-check mt-1">
                                <input class="form-check-input" type="checkbox" name="remove_photo" id="removePhoto" value="1">
                                <label class="form-check-label small" for="removePhoto">Remove current photo</label>
                            </div>
                            <?php } ?>
                        </div>
                    </div>
                    <button type="submit" name="update_photo" class="rm-btn rm-btn-primary mt-3">Save Photo</button>
                </form>
            </div>
        </div>

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

<script>
(function () {
    // Live preview of the chosen photo before saving.
    var photoInput = document.getElementById('photoInput');
    var photoPreview = document.getElementById('photoPreview');
    var photoPlaceholder = document.getElementById('photoPlaceholder');
    if (photoInput && photoPreview) {
        photoInput.addEventListener('change', function () {
            var file = photoInput.files && photoInput.files[0];
            if (!file) { return; }
            photoPreview.src = URL.createObjectURL(file);
            photoPreview.style.display = '';
            if (photoPlaceholder) { photoPlaceholder.style.display = 'none'; }
        });
    }
})();
</script>

<?php include '../../includes/footer.php'; ?>