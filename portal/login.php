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
<!-- CHANGED (UX/UI): the sign-in card centered on the page, clean and modern. -->
<div class="d-flex align-items-center justify-content-center" style="min-height:calc(100vh - 260px);">
<div class="card w-100" style="max-width:440px;"><div class="card-body p-4 p-md-5">
    <div class="text-center mb-4">
        <div class="mx-auto mb-3 d-flex align-items-center justify-content-center rounded-circle" style="width:64px; height:64px; background:#EEF3FF;"><i class="bi bi-person-lock" style="font-size:1.9rem; color:#1E2FE0;"></i></div>
        <h1 class="mb-1" style="font-size:1.5rem;">Welcome back</h1>
        <div class="text-muted">Sign in to your RM Customer Portal</div>
    </div>
    <?php portal_alerts(); if (isset($error)) { ?><div class="alert alert-danger small"><?= portal_e($error); ?></div><?php } ?>
    <form method="POST"><?= portal_csrf_field(); ?>
        <label class="form-label fw-semibold" for="idf">Phone number or Customer ID</label>
        <div class="input-group mb-3"><span class="input-group-text bg-white"><i class="bi bi-phone"></i></span>
            <input id="idf" name="identifier" class="form-control form-control-lg" required autocomplete="username" placeholder="e.g. 0788123456" value="<?= portal_e($_POST['identifier'] ?? ''); ?>"></div>
        <div class="d-flex justify-content-between"><label class="form-label fw-semibold" for="pw">Password</label><a href="activate.php?reset=1" class="small fw-semibold">Forgot password?</a></div>
        <div class="input-group mb-4"><span class="input-group-text bg-white"><i class="bi bi-key"></i></span>
            <input id="pw" type="password" name="password" class="form-control form-control-lg" required autocomplete="current-password">
            <button type="button" class="btn btn-outline-secondary" onclick="var f=document.getElementById('pw'); f.type = f.type === 'password' ? 'text' : 'password'; this.firstElementChild.className = f.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';" aria-label="Show password"><i class="bi bi-eye"></i></button></div>
        <button class="btn btn-primary btn-lg w-100">Sign in</button>
    </form>
    <hr class="my-4">
    <div class="text-center">New to the portal? <a href="activate.php" class="fw-semibold">Activate your account</a></div>
    <!-- NEW: for staff who landed on the customer sign-in page. -->
    <div class="text-center small text-muted mt-2">Staff member? <a href="<?= in_array(explode(':', strtolower($_SERVER['HTTP_HOST'] ?? ''))[0], ['localhost', '127.0.0.1'], true) ? '../login.php' : 'https://myspace.risemotive.rw/login.php'; ?>">Sign in to MY MOTIVE</a></div>
</div></div>
</div>
<?php portal_footer();