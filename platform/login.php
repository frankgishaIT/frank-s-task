<?php
require_once __DIR__ . '/../includes/platform_web.php';

platform_session_start('MM_PLATFORM');
if (!empty($_SESSION['pa_id']) && platform_admin_by_id((int) $_SESSION['pa_id'])) {
    header('Location: ' . BASE_URL . '/platform/index.php');
    exit;
}

$error = '';
if (($_GET['reason'] ?? '') === 'timeout') {
    $error = 'You were signed out because you were inactive. Please sign in again.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $ip = platform_client_ip();

    if (platform_rate_count('pa_login_ip', $ip, 900) >= 10 || ($email !== '' && platform_rate_count('pa_login_email', $email, 900) >= 5)) {
        $error = 'Too many attempts. Please wait 15 minutes and try again.';
    } else {
        $admin = null;
        if ($email !== '') {
            $st = mysqli_prepare(platform_conn(), 'SELECT * FROM platform_admins WHERE email = ? AND is_active = 1 LIMIT 1');
            mysqli_stmt_bind_param($st, 's', $email);
            mysqli_stmt_execute($st);
            $admin = mysqli_fetch_assoc(mysqli_stmt_get_result($st)) ?: null;
        }
        // an unknown email takes as long as a wrong password
        $hash = $admin ? $admin['password_hash'] : '$2y$10$abcdefghijklmnopqrstuuK7bD5kX0Qm0vC1n3Rk0m3b1d9Yw3y2e';
        if (password_verify($password, $hash) && $admin) {
            session_regenerate_id(true);
            $_SESSION['pa_id'] = (int) $admin['id'];
            $_SESSION['pa_last'] = time();
            $st = mysqli_prepare(platform_conn(), 'UPDATE platform_admins SET last_login_at = NOW() WHERE id = ?');
            mysqli_stmt_bind_param($st, 'i', $admin['id']);
            mysqli_stmt_execute($st);
            platform_audit(null, 'admin:' . $admin['email'], 'admin_login', $ip);
            header('Location: ' . BASE_URL . '/platform/' . ((int) $admin['must_change_password'] === 1 ? 'password.php' : 'index.php'));
            exit;
        }
        platform_rate_hit('pa_login_ip', $ip);
        if ($email !== '') {
            platform_rate_hit('pa_login_email', $email);
        }
        $error = 'Wrong email or password.';
    }
}
platform_page_start('Sign in');
?>
<div class="row justify-content-center"><div class="col-md-5"><div class="card p-4">
    <h4 class="mb-3">Platform sign in</h4>
    <?php if ($error !== '') { ?><div class="alert alert-danger"><?= h($error); ?></div><?php } ?>
    <form method="POST" autocomplete="off">
        <?= platform_csrf_field(); ?>
        <div class="mb-3"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required autofocus></div>
        <div class="mb-3"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required></div>
        <button class="btn btn-primary w-100" type="submit">Sign in</button>
    </form>
</div></div></div>
<?php platform_page_end();
