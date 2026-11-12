<?php
/**
 * login.php — RM STAFF PORTAL sign-in (RISE MOTIVE staff only).
 * CHANGED: RM Staff Portal branding and welcome text; the same session rules as the rest of the portal
 * (30 minutes of inactivity end the session, with the explanation shown here); clean re-login after expiry.
 */
define('RM_OS_ALLOW_GUEST', true);
require 'config/db.php';

const RM_LOGIN_IDLE_LIMIT = 1800; // same rule as includes/auth.php (30 minutes)
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.gc_maxlifetime', '7200');
    @ini_set('session.use_strict_mode', '1');
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    @session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => $https]);
    session_start();
}
header('Cache-Control: no-store, no-cache, must-revalidate, private');

$expired = isset($_GET['expired']);
// A session that is still open but inactive for more than 30 minutes ends here, with the explanation.
if (!empty($_SESSION['user_id']) && isset($_SESSION['last_activity']) && time() - (int) $_SESSION['last_activity'] > RM_LOGIN_IDLE_LIMIT) {
    $_SESSION = [];
    session_regenerate_id(true);
    $expired = true;
}
if (!empty($_SESSION['user_id'])) {
    header('Location: modules/dashboard/index.php');
    exit;
}

if (isset($_POST['login'])) {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $statement = mysqli_prepare($conn, 'SELECT id, names, email, password_hash, role FROM users WHERE email = ? AND is_active = 1');
    mysqli_stmt_bind_param($statement, 's', $email);
    mysqli_stmt_execute($statement);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['names'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['last_activity'] = time(); // NEW: the 30-minute rule starts now
        header('Location: modules/dashboard/index.php');
        exit;
    }

    $error = 'Invalid email or password.';
    $expired = false;
}
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - RM STAFF PORTAL · RISE MOTIVE</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root{
            --rm-blue:#1E2FE0;
            --rm-deep:#0B1A8C;
            --ink:#1E2333;
            --muted:#6B7287;
            --border-soft:#E4E7F0;
        }
        *, *::before, *::after{ box-sizing:border-box; font-family:'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
        body{ min-height:100vh; margin:0; background:#F4F6FB; display:flex; align-items:center; justify-content:center; padding:24px; }
        .login-shell{ width:100%; max-width:980px; min-height:600px; background:#fff; border-radius:24px; overflow:hidden; box-shadow:0 20px 60px rgba(11,26,140,.10); display:flex; }

        /* Left: RM Staff Portal identity */
        .brand-panel{ flex:1.05; background:linear-gradient(160deg, var(--rm-deep) 0%, var(--rm-blue) 60%, #3D5BFF 100%); color:#fff; padding:52px 48px;
            display:flex; flex-direction:column; justify-content:space-between; position:relative; overflow:hidden; }
        .brand-panel::before{ content:''; position:absolute; width:420px; height:420px; border-radius:50%; background:rgba(255,255,255,.06); top:-140px; right:-140px; }
        .brand-panel::after{ content:''; position:absolute; width:280px; height:280px; border-radius:50%; background:rgba(255,255,255,.05); bottom:-100px; left:-80px; }
        .brand-logo{ display:flex; align-items:center; gap:14px; position:relative; } /* no z-index: the logo blends with the panel's blue */
        .brand-logo img{ width:52px; height:52px; object-fit:contain; filter:grayscale(1) contrast(4); mix-blend-mode:screen; }
        .brand-logo .name{ font-weight:800; font-size:17px; letter-spacing:.06em; line-height:1.15; }
        .brand-logo .name small{ display:block; font-weight:500; font-size:12px; letter-spacing:.02em; opacity:.75; }
        .brand-copy{ position:relative; z-index:1; }
        .staff-badge{ display:inline-flex; align-items:center; gap:6px; background:rgba(255,255,255,.14); border:1px solid rgba(255,255,255,.25); border-radius:999px; padding:5px 12px; font-size:12px; font-weight:600; margin-bottom:18px; }
        .brand-copy h1{ font-size:30px; font-weight:800; line-height:1.25; margin-bottom:14px; }
        .brand-copy p{ font-size:15px; color:rgba(255,255,255,.85); line-height:1.6; max-width:360px; margin:0; }
        .brand-copy .motto{ display:block; margin-top:14px; font-weight:700; color:#fff; letter-spacing:.01em; }
        .brand-foot{ position:relative; z-index:1; font-size:12px; color:rgba(255,255,255,.6); }

        /* Right: staff sign-in */
        .form-panel{ flex:1; padding:52px 56px; display:flex; flex-direction:column; justify-content:center; }
        .form-panel .mobile-brand{ display:none; align-items:center; gap:10px; margin-bottom:22px; }
        .form-panel .mobile-brand img{ width:42px; height:42px; object-fit:contain; border-radius:10px; }
        .form-panel .mobile-brand span{ font-weight:800; color:var(--rm-deep); letter-spacing:.05em; }
        .form-panel h2{ font-size:24px; font-weight:800; color:var(--rm-deep); margin-bottom:6px; }
        .form-panel .subtitle{ font-size:14px; color:var(--muted); margin-bottom:28px; }
        .form-label{ font-size:13px; font-weight:600; color:var(--ink); margin-bottom:6px; }
        .input-group-rm{ position:relative; }
        .input-group-rm > i{ position:absolute; left:16px; top:50%; transform:translateY(-50%); color:var(--muted); font-size:15px; }
        .input-group-rm input{ width:100%; padding:12px 44px 12px 44px; border:1px solid var(--border-soft); border-radius:12px; font-size:14px; background:#FAFBFE; transition:.15s; }
        .input-group-rm input:focus{ outline:none; border-color:var(--rm-blue); background:#fff; box-shadow:0 0 0 3px rgba(30,47,224,.12); }
        .toggle-pw{ position:absolute; right:10px; top:50%; transform:translateY(-50%); border:0; background:transparent; color:var(--muted); padding:6px; }
        .btn-signin{ width:100%; padding:13px; border:none; border-radius:12px; background:var(--rm-blue); color:#fff; font-weight:700; font-size:14px; transition:.15s; }
        .btn-signin:hover, .btn-signin:focus{ background:var(--rm-deep); }
        .alert-rm{ display:flex; align-items:flex-start; gap:10px; border-radius:10px; font-size:13px; padding:12px 14px; margin-bottom:20px; line-height:1.45; }
        .alert-rm.error{ background:#FCEAEA; color:#B42318; }
        .alert-rm.info{ background:#EEF3FF; color:var(--rm-deep); border:1px solid #C9D3FF; }
        .alert-rm i{ font-size:16px; margin-top:1px; }
        .help{ margin-top:22px; font-size:12.5px; color:var(--muted); text-align:center; line-height:1.6; }
        .help a{ color:var(--rm-blue); font-weight:600; text-decoration:none; }
        @media (max-width: 860px){
            .brand-panel{ display:none; }
            .login-shell{ max-width:440px; min-height:auto; }
            .form-panel{ padding:40px 28px; }
            .form-panel .mobile-brand{ display:flex; }
        }
    </style>
</head>
<body>
    <main class="login-shell">
        <section class="brand-panel" aria-label="RM Staff Portal">
            <div class="brand-logo">
                <img src="assets/images/logo.jpg" alt="RISE MOTIVE">
                <div class="name">RM STAFF PORTAL<small>RISE MOTIVE Ltd</small></div>
            </div>
            <div class="brand-copy">
                <div class="staff-badge"><i class="bi bi-shield-lock-fill"></i> RISE MOTIVE staff only</div>
                <h1>Welcome to Your RISE MOTIVE Workspace.</h1>
                <p>Your dedicated workspace as a member of the RISE MOTIVE staff.<span class="motto">Together, We Rise Beyond Limits.</span></p>
            </div>
            <div class="brand-foot">&copy; <?= date('Y'); ?> RISE MOTIVE Ltd · RM Staff Portal · internal workspace for RISE MOTIVE staff</div>
        </section>

        <section class="form-panel">
            <div class="mobile-brand"><img src="assets/images/logo.jpg" alt="RISE MOTIVE"><span>RM STAFF PORTAL</span></div>
            <h2>Staff sign in</h2>
            <p class="subtitle">Use your RISE MOTIVE staff email and password.</p>

            <?php if ($expired && !isset($error)) { ?>
            <div class="alert-rm info" role="status">
                <i class="bi bi-clock-history"></i>
                <span>Your session has expired due to 30 minutes of inactivity. Please log in again to continue.</span>
            </div>
            <?php } ?>
            <?php if (isset($error)) { ?>
            <div class="alert-rm error" role="alert">
                <i class="bi bi-exclamation-circle-fill"></i>
                <span><?= $h($error); ?></span>
            </div>
            <?php } ?>

            <form method="POST" action="login.php" autocomplete="on">
                <div class="mb-3">
                    <label class="form-label" for="email">Staff email address</label>
                    <div class="input-group-rm">
                        <i class="bi bi-envelope"></i>
                        <input type="email" id="email" name="email" placeholder="you@risemotive.rw" value="<?= $h($_POST['email'] ?? ''); ?>" autocomplete="username" required autofocus>
                    </div>
                </div>
                <div class="mb-4">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group-rm">
                        <i class="bi bi-lock"></i>
                        <input type="password" id="password" name="password" placeholder="••••••••" autocomplete="current-password" required>
                        <button type="button" class="toggle-pw" aria-label="Show password" onclick="var p = document.getElementById('password'); var show = p.type === 'password'; p.type = show ? 'text' : 'password'; this.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); this.firstElementChild.className = show ? 'bi bi-eye-slash' : 'bi bi-eye';"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button type="submit" name="login" class="btn-signin">Sign in to RM Staff Portal</button>
            </form>

            <div class="help">For RISE MOTIVE staff only. Forgot your password? Ask an Administrator.<br>
                Are you a customer? <a href="portal/">Go to the RM Customer Portal</a></div>
        </section>
    </main>
</body>
</html>