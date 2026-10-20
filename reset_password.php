<?php
/**
 * Public page: choose a new password with the one-hour link from the "forgot password" email.
 */
require_once __DIR__ . '/includes/public_layout.php';
require_once __DIR__ . '/includes/signup.php';
require_once __DIR__ . '/includes/password_reset.php';

platform_session_start('MM_RESET');

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : (is_string($_POST['token'] ?? null) ? $_POST['token'] : '');
$reset = pwreset_lookup($token);
$error = '';
$finished = false;

if ($reset && $_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $ip = platform_client_ip();
    $one = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $two = is_string($_POST['password2'] ?? null) ? $_POST['password2'] : '';
    if (platform_rate_count('reset_attempt', $ip, 900) >= 20) {
        $error = 'Too many attempts. Please wait 15 minutes.';
    } elseif ($one !== $two) {
        platform_rate_hit('reset_attempt', $ip);
        $error = 'The two passwords are not the same.';
    } elseif (($problem = signup_password_problem($one, $reset['email'])) !== null) {
        platform_rate_hit('reset_attempt', $ip);
        $error = $problem;
    } else {
        try {
            pwreset_apply($reset, $one);
            mm_remember_company($reset['company_code']);
            $finished = true;
        } catch (Throwable $e) {
            error_log('[MY MOTIVE reset_password] ' . $e->getMessage());
            $error = 'Something went wrong. Please ask for a new link.';
        }
    }
}

mm_public_start('Choose a new password', [
    'width' => 'slim',
    'shell' => 'narrow',
    'panel' => [
        'headline' => 'Back in a moment.',
        'lead'     => 'Choose a new password, then sign in as usual.',
        'points'   => [['lock', 'Use something you do not use anywhere else'], ['shield', 'We never see your password'], ['key', 'Your business code stays the same']],
    ],
]);

if ($finished) { ?>
    <div class="mm-center">
        <div class="mm-bigicon ok"><?= mm_icon('check', 40); ?></div>
        <h1 class="mm-title">Password changed</h1>
        <p class="mm-sub">You can sign in with your new password now.</p>
        <a class="mm-btn" href="<?= h(BASE_URL); ?>/login.php">Go to sign in <?= mm_icon('arrow', 18); ?></a>
    </div>
<?php } elseif (!$reset) { ?>
    <div class="mm-center">
        <div class="mm-bigicon bad"><?= mm_icon('alert', 40); ?></div>
        <h1 class="mm-title">This link is not valid</h1>
        <p class="mm-sub">It was already used, or it expired (links last one hour). You can ask for a new one.</p>
        <a class="mm-btn" href="<?= h(BASE_URL); ?>/forgot_password.php">Send me a new link</a>
    </div>
<?php } else { ?>
    <h1 class="mm-title">Choose a new password</h1>
    <p class="mm-sub">For <b style="color:var(--mm-ink)"><?= h($reset['email']); ?></b> at <?= h($reset['company_name']); ?>.</p>
<?php if ($error !== '') { ?><div class="mm-alert bad"><?= mm_icon('alert', 18); ?><div><?= h($error); ?></div></div><?php } ?>
    <form method="POST" autocomplete="off">
        <?= platform_csrf_field(); ?>
        <input type="hidden" name="token" value="<?= h($token); ?>">
        <?= mm_password_fields('New password'); ?>
        <button class="mm-btn" type="submit">Change my password <?= mm_icon('arrow', 18); ?></button>
    </form>
<?php }
mm_public_end($reset && !$finished ? mm_password_script() : '');
