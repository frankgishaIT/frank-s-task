<?php
/**
 * Public page: a company asks to join MY MOTIVE.
 * Nothing is created here except a pending request. You approve it in /platform/.
 */
require_once __DIR__ . '/includes/platform_web.php';
require_once __DIR__ . '/includes/signup.php';

platform_session_start('MM_SIGNUP');

$errors = [];
$done = null;
$old = [];
$packages = signup_packages();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $ip = platform_client_ip();
    foreach (['company_name', 'owner_name', 'owner_email', 'owner_phone', 'tin', 'package_id', 'billing_period'] as $key) {
        $old[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    }
    if (($_POST['website'] ?? '') !== '') {
        // the hidden "website" field is a trap for robots: pretend it worked, store nothing
        $done = ['reference' => 'SU-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)), 'owner_email' => $old['owner_email'] ?: 'your email'];
    } elseif (platform_rate_count('signup_attempt', $ip, 3600) >= 30 || platform_rate_count('signup_created', $ip, 3600) >= 5) {
        $errors['_'] = 'Too many requests from your network. Please try again in an hour.';
    } else {
        platform_rate_hit('signup_attempt', $ip);
        $clean = signup_clean($_POST, $errors);
        if ($clean) {
            try {
                $done = signup_create($clean, $ip);
                platform_rate_hit('signup_created', $ip);
            } catch (SignupException $e) {
                $errors['_'] = $e->getMessage();
            }
        }
    }
}
$termsUrl = (string) platform_setting('terms_url', '');
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Open a company - MY MOTIVE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>body{background:#F6F7FB}.card{border:0;border-radius:18px;box-shadow:0 12px 40px rgba(30,35,51,.08)}.brand{color:#2E3FC0;font-weight:800}</style>
</head>
<body>
<div class="container py-5" style="max-width:720px">
    <div class="text-center mb-4"><div class="brand fs-3">MY MOTIVE</div><div class="text-muted">Run your team, sales and finances in one place.</div></div>
    <div class="card p-4 p-md-5">
<?php if ($done) { ?>
        <h4 class="mb-3">Request received</h4>
        <p>Thank you. Your reference number is <b><?= h($done['reference']); ?></b>.</p>
        <p>We will review your request and send the result to <b><?= h($done['owner_email']); ?></b>. If it is approved, the email tells you how to pay. Your account is opened as soon as the payment is confirmed.</p>
        <a class="btn btn-outline-primary" href="<?= h(BASE_URL); ?>/login.php">Back to sign in</a>
<?php } else { ?>
        <h4 class="mb-1">Open your company</h4>
        <p class="text-muted mb-4">Fill in the form. After we approve it you pay, and your account opens.</p>
<?php if (!empty($errors['_'])) { ?><div class="alert alert-danger"><?= h($errors['_']); ?></div><?php } ?>
<?php if ($errors && empty($errors['_'])) { ?><div class="alert alert-danger">Please correct the fields marked below.</div><?php } ?>
        <form method="POST" novalidate>
            <?= platform_csrf_field(); ?>
            <div style="position:absolute;left:-5000px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
<?php
$field = function (string $name, string $label, string $type = 'text', string $help = '', bool $required = true) use ($old, $errors) {
    $bad = isset($errors[$name]);
    echo '<div class="mb-3"><label class="form-label fw-semibold">' . h($label) . ($required ? '' : ' <span class="text-muted fw-normal">(optional)</span>') . '</label>'
        . '<input class="form-control' . ($bad ? ' is-invalid' : '') . '" type="' . h($type) . '" name="' . h($name) . '" value="' . h($old[$name] ?? '') . '" maxlength="150">'
        . ($bad ? '<div class="invalid-feedback">' . h($errors[$name]) . '</div>' : ($help ? '<div class="form-text">' . h($help) . '</div>' : ''))
        . '</div>';
};
$field('company_name', 'Company name');
$field('owner_name', 'Your full name');
$field('owner_email', 'Your email address', 'email', 'We send the approval and your sign-in link here.');
$field('owner_phone', 'Your phone number', 'tel', 'For example 0788123456');
$field('tin', 'TIN (tax number)', 'text', '', false);
?>
            <div class="mb-3">
                <label class="form-label fw-semibold">Package</label>
<?php foreach ($packages as $p) {
    $monthly = signup_price($p, 'monthly');
    $yearly = signup_price($p, 'yearly'); ?>
                <div class="form-check border rounded p-3 mb-2 ps-5">
                    <input class="form-check-input" type="radio" name="package_id" id="pkg<?= (int) $p['id']; ?>" value="<?= (int) $p['id']; ?>"<?= ($old['package_id'] ?? '') === (string) $p['id'] ? ' checked' : ''; ?>>
                    <label class="form-check-label w-100" for="pkg<?= (int) $p['id']; ?>"><b><?= h($p['name']); ?></b><br><span class="text-muted"><?= h(signup_money($monthly)); ?> per month, or <?= h(signup_money($yearly)); ?> per year (<?= (int) $p['yearly_discount_percent']; ?>% less)</span></label>
                </div>
<?php } ?>
<?php if (isset($errors['package_id'])) { ?><div class="text-danger small"><?= h($errors['package_id']); ?></div><?php } ?>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Billing</label><br>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="billing_period" id="bm" value="monthly"<?= ($old['billing_period'] ?? 'monthly') !== 'yearly' ? ' checked' : ''; ?>><label class="form-check-label" for="bm">Monthly</label></div>
                <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="billing_period" id="by" value="yearly"<?= ($old['billing_period'] ?? '') === 'yearly' ? ' checked' : ''; ?>><label class="form-check-label" for="by">Yearly (5% less)</label></div>
<?php if (isset($errors['billing_period'])) { ?><div class="text-danger small"><?= h($errors['billing_period']); ?></div><?php } ?>
            </div>
            <div class="form-check mb-4">
                <input class="form-check-input<?= isset($errors['terms']) ? ' is-invalid' : ''; ?>" type="checkbox" name="terms" id="terms" value="1">
                <label class="form-check-label" for="terms">I accept the <?= $termsUrl !== '' ? '<a href="' . h($termsUrl) . '" target="_blank" rel="noopener">terms of service and privacy policy</a>' : 'terms of service and privacy policy'; ?>.</label>
<?php if (isset($errors['terms'])) { ?><div class="text-danger small"><?= h($errors['terms']); ?></div><?php } ?>
            </div>
            <button class="btn btn-primary w-100 py-2" type="submit">Send my request</button>
            <div class="text-center mt-3"><a href="<?= h(BASE_URL); ?>/login.php" class="small">Already have an account? Sign in</a></div>
        </form>
<?php } ?>
    </div>
</div>
</body>
</html>
