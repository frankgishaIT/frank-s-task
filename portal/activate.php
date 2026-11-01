<?php
/**
 * NEW FILE: portal/activate.php — activate an account, or reset a forgotten password:
 * 1) phone or Customer ID  ->  2) the 6-digit SMS code  ->  3) a new password.
 */
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/portal_layout.php';
$conn = portal_boot();
if (!portal_is_open($conn)) { http_response_code(503); echo 'The customer portal is not available at the moment.'; exit; }
$reset = isset($_GET['reset']) || ($_POST['purpose'] ?? '') === 'reset';
$purpose = $reset ? 'reset' : 'activate';
$step = $_SESSION['portal_pending'] ?? null; // ['customer_id', 'purpose']
if ($step && $step['purpose'] !== $purpose) { $step = null; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!portal_csrf_ok()) { throw new RuntimeException('Your session expired. Please try again.'); }
        if (isset($_POST['identifier'])) {               // step 1
            $c = portal_find_customer($conn, (string) $_POST['identifier']);
            $a = $c ? portal_account($conn, (int) $c['id']) : null;
            // The same answer whether the customer exists or not (nothing is revealed about who is a customer).
            $generic = 'If this phone number or Customer ID belongs to a RISE MOTIVE customer, a code has been sent by SMS.';
            if ($c && (!$a || $a['status'] !== 'blocked')) {
                if (!$reset && $a && $a['password_hash']) { throw new RuntimeException('This account is already active. Sign in, or use "Forgot password".'); }
                if (!$reset && portal_setting($conn, 'self_activation', '1') !== '1') { throw new RuntimeException('Please contact RISE MOTIVE to get access to the portal.'); }
                portal_send_otp($conn, $c, $purpose);
                $_SESSION['portal_pending'] = ['customer_id' => (int) $c['id'], 'purpose' => $purpose];
            } else {
                $_SESSION['portal_pending'] = ['customer_id' => 0, 'purpose' => $purpose];
            }
            $info = $generic;
        } elseif (isset($_POST['code'])) {               // steps 2 + 3
            $cid = (int) ($step['customer_id'] ?? 0);
            $pw = (string) ($_POST['password'] ?? '');
            if (strlen($pw) < 8 || !preg_match('/\d/', $pw) || !preg_match('/[A-Za-z]/', $pw)) { throw new RuntimeException('The password needs at least 8 characters, with letters and numbers.'); }
            if ($pw !== (string) ($_POST['password2'] ?? '')) { throw new RuntimeException('The two passwords are not the same.'); }
            if ($cid <= 0 || !portal_check_otp($conn, $cid, $purpose, (string) $_POST['code'])) { throw new RuntimeException('The code is wrong or has expired. Ask for a new code.'); }
            $hash = password_hash($pw, PASSWORD_DEFAULT);
            $s = mysqli_prepare($conn, "INSERT INTO portal_accounts (customer_id, password_hash, status, phone_verified_at) VALUES (?, ?, 'active', NOW())
                ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), phone_verified_at = NOW()");
            mysqli_stmt_bind_param($s, 'is', $cid, $hash);
            mysqli_stmt_execute($s);
            portal_log($conn, $cid, $reset ? 'password_reset' : 'activated', 'Phone verified by SMS code');
            unset($_SESSION['portal_pending']);
            session_regenerate_id(true);
            $_SESSION['portal_customer_id'] = $cid;
            header('Location: index.php?success=' . urlencode($reset ? 'Your password was changed.' : 'Welcome! Your account is active.')); exit;
        }
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
    $step = $_SESSION['portal_pending'] ?? null;
}
portal_header($reset ? 'Reset password' : 'Activate account');
?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5"><div class="card"><div class="card-body p-4">
    <h1 class="h4 mb-3"><?= $reset ? 'Reset your password' : 'Activate your account'; ?></h1>
    <?php if (isset($error)) { ?><div class="alert alert-danger"><?= portal_e($error); ?></div><?php } ?>
    <?php if (isset($info)) { ?><div class="alert alert-info"><?= portal_e($info); ?></div><?php } ?>
    <?php if (!$step) { ?>
    <form method="POST"><?= portal_csrf_field(); ?><input type="hidden" name="purpose" value="<?= $purpose; ?>">
        <label class="form-label small text-muted">The phone number you gave RISE MOTIVE, or your Customer ID</label>
        <input name="identifier" class="form-control mb-3" required placeholder="07XXXXXXXX or RMC000025">
        <button class="btn btn-primary w-100">Send me a code by SMS</button>
    </form>
    <?php } else { ?>
    <form method="POST"><?= portal_csrf_field(); ?><input type="hidden" name="purpose" value="<?= $purpose; ?>">
        <label class="form-label small text-muted">6-digit code from the SMS</label>
        <input name="code" class="form-control mb-3" required inputmode="numeric" pattern="\d{6}" maxlength="6" autocomplete="one-time-code">
        <label class="form-label small text-muted">New password (at least 8 characters, letters and numbers)</label>
        <input type="password" name="password" class="form-control mb-2" required minlength="8" autocomplete="new-password">
        <input type="password" name="password2" class="form-control mb-3" required minlength="8" placeholder="Repeat the password" autocomplete="new-password">
        <button class="btn btn-primary w-100"><?= $reset ? 'Change password' : 'Activate'; ?></button>
    </form>
    <form method="POST" class="mt-2"><?= portal_csrf_field(); ?><input type="hidden" name="purpose" value="<?= $purpose; ?>"><input type="hidden" name="identifier" value="<?= portal_e(portal_customer_code((int) $step['customer_id'])); ?>">
        <button class="btn btn-link btn-sm p-0">Send a new code</button></form>
    <?php } ?>
    <div class="small mt-3"><a href="login.php">Back to sign in</a></div>
</div></div></div></div>
<?php portal_footer();