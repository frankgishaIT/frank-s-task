<?php
// NEW FILE: portal/login.php — customer sign-in (phone or Customer ID + password).
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/portal_layout.php';
$conn = portal_boot();
if (!portal_is_open($conn)) { http_response_code(503); echo 'The customer portal is not available at the moment.'; exit; }
if (portal_customer($conn)) { header('Location: index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $idf = trim((string) ($_POST['identifier'] ?? ''));
    if (!portal_csrf_ok()) { $error = 'Your session expired. Please try again.'; }
    elseif (portal_login_locked($conn, $idf)) { $error = 'Too many failed attempts. Please wait 15 minutes, or reset your password.'; }
    else {
        $c = portal_find_customer($conn, $idf);
        $a = $c ? portal_account($conn, (int) $c['id']) : null;
        if ($c && $a && $a['status'] === 'active' && $a['password_hash'] && password_verify((string) ($_POST['password'] ?? ''), $a['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['portal_customer_id'] = (int) $c['id'];
            mysqli_query($conn, 'UPDATE portal_accounts SET last_login_at = NOW() WHERE customer_id = ' . (int) $c['id']);
            portal_log($conn, (int) $c['id'], 'login', '');
            header('Location: index.php'); exit;
        }
        portal_log($conn, $c ? (int) $c['id'] : null, 'login_failed', mb_strtolower($idf));
        $error = $a && $a['status'] === 'blocked' ? 'This account is not allowed to use the portal. Please contact RISE MOTIVE.'
            : 'Wrong phone / Customer ID or password. New here? Activate your account first.';
    }
}
portal_header('Sign in');
?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-5"><div class="card"><div class="card-body p-4">
    <h1 class="h4 mb-3">Sign in</h1>
    <?php portal_alerts(); if (isset($error)) { ?><div class="alert alert-danger"><?= portal_e($error); ?></div><?php } ?>
    <form method="POST"><?= portal_csrf_field(); ?>
        <label class="form-label small text-muted">Phone number or Customer ID</label>
        <input name="identifier" class="form-control mb-3" required autocomplete="username" placeholder="07XXXXXXXX or RMC000025" value="<?= portal_e($_POST['identifier'] ?? ''); ?>">
        <label class="form-label small text-muted">Password</label>
        <input type="password" name="password" class="form-control mb-3" required autocomplete="current-password">
        <button class="btn btn-primary w-100">Sign in</button>
    </form>
    <div class="d-flex justify-content-between small mt-3"><a href="activate.php">First time? Activate your account</a><a href="activate.php?reset=1">Forgot password?</a></div>
</div></div></div></div>
<?php portal_footer();