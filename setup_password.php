<?php
/**
 * Public page: the owner of a new company chooses their own password with the one-time link from the email.
 */
require_once __DIR__ . '/includes/public_layout.php';
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

mm_public_start('Choose your password', [
    'width' => 'slim',
    'shell' => 'narrow',
    'panel' => [
        'headline' => 'Welcome aboard.',
        'lead'     => 'Choose a strong password and your company is ready to use.',
        'points'   => [['lock', 'Only you know your password: we cannot see it'], ['key', 'You and your staff sign in with your company code'], ['users', 'Add your team once you are in']],
    ],
]);

if ($finished) { ?>
    <div class="mm-center">
        <div class="mm-bigicon ok"><?= mm_icon('check', 40); ?></div>
        <h1 class="mm-title">Your password is set</h1>
        <p class="mm-sub">You can sign in now. Remember your company code: your staff use it too.</p>
        <div class="mm-summary" style="text-align:left"><div class="row"><span>Company code</span><span style="letter-spacing:.06em;font-size:18px;color:var(--mm-primary-dark)"><?= h($invite['company_code']); ?></span></div></div>
        <a class="mm-btn" href="<?= h(BASE_URL); ?>/login.php">Go to sign in <?= mm_icon('arrow', 18); ?></a>
    </div>
<?php } elseif (!$invite) { ?>
    <div class="mm-center">
        <div class="mm-bigicon bad"><?= mm_icon('alert', 40); ?></div>
        <h1 class="mm-title">This link is not valid</h1>
        <p class="mm-sub">The link was already used, has expired, or was not copied completely. Please ask us to send a new one.</p>
        <a class="mm-btn mm-ghost mm-sm" href="<?= h(BASE_URL); ?>/forgot_password.php">I forgot my password</a>
    </div>
<?php } else { ?>
    <h1 class="mm-title">Choose your password</h1>
    <p class="mm-sub">For <b style="color:var(--mm-ink)"><?= h($invite['company_name']); ?></b> (<?= h($invite['email']); ?>).</p>
<?php if ($error !== '') { ?><div class="mm-alert bad"><?= mm_icon('alert', 18); ?><div><?= h($error); ?></div></div><?php } ?>
    <form method="POST" autocomplete="off">
        <?= platform_csrf_field(); ?>
        <input type="hidden" name="token" value="<?= h($token); ?>">
        <?= mm_password_fields('Your new password'); ?>
        <button class="mm-btn" type="submit">Save my password <?= mm_icon('arrow', 18); ?></button>
    </form>
<?php }
mm_public_end($invite && !$finished ? mm_password_script() : '');
