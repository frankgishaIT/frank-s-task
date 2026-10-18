<?php
/**
 * Public page: "I forgot my password". Asks for the company code and the email, and (if they match an account)
 * emails a one-hour link. The answer is always the same, so the page cannot be used to find out who has an account.
 */
require_once __DIR__ . '/includes/public_layout.php';
require_once __DIR__ . '/includes/signup.php';
require_once __DIR__ . '/includes/password_reset.php';

platform_session_start('MM_FORGOT');

$sent = false;
$error = '';
$code = is_string($_COOKIE['mm_company'] ?? null) ? $_COOKIE['mm_company'] : '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $ip = platform_client_ip();
    $code = is_string($_POST['company_code'] ?? null) ? trim($_POST['company_code']) : '';
    $email = is_string($_POST['email'] ?? null) ? strtolower(trim($_POST['email'])) : '';
    if ($code === '' || $email === '') {
        $error = 'Please enter your company code and your email address.';
    } elseif (platform_rate_count('pwreset_ip', $ip, 900) >= 10 || platform_rate_count('pwreset_email', $email, 3600) >= 3) {
        $error = 'Too many requests. Please wait a while and try again.';
    } else {
        platform_rate_hit('pwreset_ip', $ip);
        platform_rate_hit('pwreset_email', $email);
        try {
            pwreset_request($code, $email, $ip);
        } catch (Throwable $e) {
            error_log('[MY MOTIVE forgot_password] ' . $e->getMessage());
        }
        $sent = true;     // always the same answer
    }
}

mm_public_start('Forgot your password', [
    'width' => 'slim',
    'shell' => 'narrow',
    'panel' => [
        'headline' => 'Locked out? It happens.',
        'lead'     => 'Tell us your company code and email. If they match an account, we send you a link to choose a new password.',
        'points'   => [['mail', 'The link arrives by email'], ['clock', 'It works once and expires in one hour'], ['shield', 'Your old password keeps working until you choose a new one']],
    ],
]);

if ($sent) { ?>
    <div class="mm-center">
        <div class="mm-bigicon ok"><?= mm_icon('mail', 40); ?></div>
        <h1 class="mm-title">Check your email</h1>
        <p class="mm-sub">If <b style="color:var(--mm-ink)"><?= h($email); ?></b> matches an account at that company, a reset link is on its way. It expires in one hour.</p>
        <div class="mm-howto"><b>Nothing after a few minutes?</b><ol><li>Look in your spam or junk folder.</li><li>Check that the company code and the email are exactly as you use them to sign in.</li></ol></div>
        <a class="mm-link" href="<?= h(BASE_URL); ?>/login.php">Back to sign in</a>
    </div>
<?php } else { ?>
    <h1 class="mm-title">Forgot your password?</h1>
    <p class="mm-sub">No problem. We will email you a link to choose a new one.</p>
<?php if ($error !== '') { ?><div class="mm-alert bad"><?= mm_icon('alert', 18); ?><div><?= h($error); ?></div></div><?php } ?>
    <form method="POST">
        <?= platform_csrf_field(); ?>
        <div class="mm-field"><label class="mm-label" for="company_code">Company code</label>
            <input class="mm-input" id="company_code" name="company_code" value="<?= h($code); ?>" maxlength="30" placeholder="e.g. RM" style="text-transform:uppercase" required<?= $code === '' ? ' autofocus' : ''; ?>></div>
        <div class="mm-field" style="margin-bottom:22px"><label class="mm-label" for="email">Email address</label>
            <input class="mm-input" type="email" id="email" name="email" value="<?= h($email); ?>" maxlength="150" placeholder="you@company.com" required<?= $code !== '' ? ' autofocus' : ''; ?>></div>
        <button class="mm-btn" type="submit"><?= mm_icon('send', 18); ?> Send me the link</button>
        <a class="mm-link" href="<?= h(BASE_URL); ?>/login.php">Back to sign in</a>
    </form>
<?php }
mm_public_end();
