<?php
require_once __DIR__ . '/../includes/platform_web.php';
require_once __DIR__ . '/../includes/signup.php';

$admin = platform_require_admin(true);
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $current = is_string($_POST['current'] ?? null) ? $_POST['current'] : '';
    $one = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $two = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
    if (!password_verify($current, $admin['password_hash'])) {
        $error = 'Your current password is not correct.';
    } elseif ($one !== $two) {
        $error = 'The two new passwords are not the same.';
    } elseif (($problem = signup_password_problem($one, $admin['email'])) !== null) {
        $error = $problem;
    } elseif (password_verify($one, $admin['password_hash'])) {
        $error = 'The new password must be different from the current one.';
    } else {
        $hash = password_hash($one, PASSWORD_DEFAULT);
        $st = mysqli_prepare(platform_conn(), 'UPDATE platform_admins SET password_hash = ?, must_change_password = 0 WHERE id = ?');
        mysqli_stmt_bind_param($st, 'si', $hash, $admin['id']);
        mysqli_stmt_execute($st);
        session_regenerate_id(true);
        platform_audit(null, 'admin:' . $admin['email'], 'admin_password_changed', '');
        platform_flash('Your password was changed.');
        header('Location: ' . BASE_URL . '/platform/index.php');
        exit;
    }
}
platform_page_start('My password', (int) $admin['must_change_password'] === 1 ? null : $admin, 'password');
?>
<div class="row justify-content-center"><div class="col-md-5"><div class="card p-4">
    <h4 class="mb-3">Change my password</h4>
    <?php if ((int) $admin['must_change_password'] === 1) { ?><div class="alert alert-warning">You must choose your own password before you continue.</div><?php } ?>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= h($error); ?></div><?php } ?>
    <form method="POST" autocomplete="off">
        <?= platform_csrf_field(); ?>
        <div class="mb-3"><label class="form-label">Current password</label><input class="form-control" type="password" name="current" required></div>
        <div class="mb-3"><label class="form-label">New password</label><input class="form-control" type="password" name="password" minlength="10" required><div class="form-text">At least 10 characters, with a letter and a digit.</div></div>
        <div class="mb-3"><label class="form-label">Repeat the new password</label><input class="form-control" type="password" name="password2" minlength="10" required></div>
        <button class="btn btn-primary w-100" type="submit">Save</button>
    </form>
</div></div></div>
<?php platform_page_end();
