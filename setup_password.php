<?php
/**
 * Public page: the owner of a new company sets their own password with the one-time link from the email.
 */
require_once __DIR__ . '/includes/platform_web.php';
require_once __DIR__ . '/includes/signup.php';

platform_session_start('MM_SETUP');

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : (is_string($_POST['token'] ?? null) ? $_POST['token'] : '');
$invite = invite_lookup($token);
$error = '';
$finished = false;

if ($invite && $_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $ip = platform_client_ip();
    $one = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $two = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
    if (platform_rate_count('setup_attempt', $ip, 900) >= 20) {
        $error = 'Too many attempts. Please wait 15 minutes.';
    } elseif ($one !== $two) {
        platform_rate_hit('setup_attempt', $ip);
        $error = 'The two passwords are not the same.';
    } elseif (($problem = signup_password_problem($one, $invite['email'])) !== null) {
        platform_rate_hit('setup_attempt', $ip);
        $error = $problem;
    } else {
        try {
            invite_set_password($invite, $one);
            mm_remember_company($invite['company_code']);
            $finished = true;
        } catch (SignupException $e) {
            $error = $e->getMessage();
        } catch (Throwable $e) {
            error_log('[MY MOTIVE setup_password] ' . $e->getMessage());
            $error = 'Something went wrong. Please try the link again, or contact support.';
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Set your password - MY MOTIVE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#F6F7FB}.card{border:0;border-radius:18px;box-shadow:0 12px 40px rgba(30,35,51,.08)}.brand{color:#2E3FC0;font-weight:800}</style>
</head>
<body>
<div class="container py-5" style="max-width:520px">
    <div class="text-center mb-4 brand fs-3">MY MOTIVE</div>
    <div class="card p-4 p-md-5">
<?php if ($finished) { ?>
        <h4>Your password is set</h4>
        <p>You can sign in now. Your company code is <b><?= h($invite['company_code']); ?></b>. Your staff use the same code.</p>
        <a class="btn btn-primary w-100" href="<?= h(BASE_URL); ?>/login.php">Go to sign in</a>
<?php } elseif (!$invite) { ?>
        <h4>This link is not valid</h4>
        <p class="text-muted">The link was already used, has expired, or is not complete. Please ask for a new one.</p>
<?php } else { ?>
        <h4 class="mb-1">Set your password</h4>
        <p class="text-muted">For <b><?= h($invite['company_name']); ?></b> (<?= h($invite['email']); ?>).</p>
<?php if ($error !== '') { ?><div class="alert alert-danger"><?= h($error); ?></div><?php } ?>
        <form method="POST" autocomplete="off">
            <?= platform_csrf_field(); ?>
            <input type="hidden" name="token" value="<?= h($token); ?>">
            <div class="mb-3"><label class="form-label fw-semibold">New password</label><input class="form-control" type="password" name="password" minlength="10" required autofocus><div class="form-text">At least 10 characters, with a letter and a digit.</div></div>
            <div class="mb-4"><label class="form-label fw-semibold">Repeat the password</label><input class="form-control" type="password" name="password2" minlength="10" required></div>
            <button class="btn btn-primary w-100 py-2" type="submit">Save my password</button>
        </form>
<?php } ?>
    </div>
</div>
</body>
</html>
