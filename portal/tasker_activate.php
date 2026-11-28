<?php
// NEW FILE: portal/tasker_activate.php — an RM Tasker sets the portal password from the link RISE MOTIVE sent (72 h, single use).
require_once __DIR__ . '/../includes/portal_helpers.php';
require_once __DIR__ . '/../includes/portal_layout.php';
$conn = portal_boot();
require_once __DIR__ . '/../includes/dl_portal.php';
dlw_ensure($conn);
$token = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$acc = null;
if (preg_match('/^[a-f0-9]{40}$/', $token)) {
    $h = hash('sha256', $token);
    $now = dl_now();
    $s = mysqli_prepare($conn, "SELECT * FROM dl_tasker_accounts WHERE token_hash = ? AND token_expires > ? AND status <> 'blocked'");
    mysqli_stmt_bind_param($s, 'ss', $h, $now);
    mysqli_stmt_execute($s);
    $acc = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
}
$t = $acc ? dl_tasker($conn, (int) $acc['party_id']) : null;
$error = null;
if ($t && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $p1 = (string) ($_POST['password'] ?? ''); $p2 = (string) ($_POST['password2'] ?? '');
    if (!portal_csrf_ok()) { $error = 'Your session expired. Please try again.'; }
    elseif (strlen($p1) < 8 || !preg_match('/\d/', $p1) || !preg_match('/[A-Za-z]/', $p1)) { $error = 'Use at least 8 characters with letters and numbers.'; }
    elseif ($p1 !== $p2) { $error = 'The two passwords are different.'; }
    else {
        $hash = password_hash($p1, PASSWORD_DEFAULT); $now = dl_now(); $pid = (int) $t['id'];
        $s = mysqli_prepare($conn, "UPDATE dl_tasker_accounts SET password_hash = ?, status = 'active', token_hash = NULL, token_expires = NULL, activated_at = COALESCE(activated_at, ?) WHERE party_id = ?");
        mysqli_stmt_bind_param($s, 'ssi', $hash, $now, $pid);
        mysqli_stmt_execute($s);
        dl_audit($conn, 'tasker', $pid, 'portal_password_set', $t['code'] . ' set the portal password', null, 'tasker', $pid);
        header('Location: login.php?success=' . urlencode('Password saved. Sign in with your RM Tasker ID ' . $t['code'] . ' (or your phone number).'));
        exit;
    }
}
portal_header('RM Tasker access');
?>
<div class="d-flex align-items-center justify-content-center" style="min-height:calc(100vh - 260px);">
<div class="card w-100" style="max-width:440px;"><div class="card-body p-4">
<?php if (!$t) { ?>
    <h1 style="font-size:1.4rem;">Link not valid</h1><p class="text-muted">This link has expired or was already used. Ask RISE MOTIVE to send you a new access link.</p><a href="login.php" class="btn btn-primary">Go to sign in</a>
<?php } else { ?>
    <h1 style="font-size:1.4rem;">Welcome, <?= portal_e($t['name']); ?></h1>
    <p class="text-muted">Your RM Tasker ID is <b><?= portal_e($t['code']); ?></b>. Choose a password to see your tasks and payments. You will see only your own work.</p>
    <?php if ($error) { ?><div class="alert alert-danger small"><?= portal_e($error); ?></div><?php } ?>
    <form method="post"><?= portal_csrf_field(); ?><input type="hidden" name="t" value="<?= portal_e($token); ?>">
        <label class="form-label fw-semibold">Password</label><input type="password" name="password" class="form-control mb-2" required minlength="8" autocomplete="new-password">
        <label class="form-label fw-semibold">Repeat the password</label><input type="password" name="password2" class="form-control mb-3" required minlength="8" autocomplete="new-password">
        <button class="btn btn-primary w-100">Save my password</button>
    </form>
<?php } ?>
</div></div></div>
<?php portal_footer();