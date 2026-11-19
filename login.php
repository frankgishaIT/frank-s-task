<?php
/**
 * login.php — RM STAFF PORTAL sign-in (RISE MOTIVE staff only).
 * CHANGED: RM Staff Portal branding and welcome text; the same session rules as the rest of the portal
 * (30 minutes of inactivity end the session, with the explanation shown here); clean re-login after expiry.
 * The photo address carries the file date (?v=…), so a new photo shows at once (no old copy from the browser cache).
 * CHANGED (new design): full-screen split page — Kigali photo on the left (assets/images/login-bg.jpg),
 * sign-in on the right. The sign-in rules above are unchanged.
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
    <title>Staff sign in · RM STAFF PORTAL · RISE MOTIVE</title>
    <meta name="robots" content="noindex">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --blue: #1430E0; --deep: #0A1A6E; --ink: #1E2333; --muted: #5B6275; --field: #F0F3FC; --line: #C9D2EE; }
        *, *::before, *::after { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body { font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif; color: var(--ink); background: #fff; -webkit-font-smoothing: antialiased; }
        .page { display: flex; min-height: 100vh; }

        /* Left: photo of Kigali with the RISE MOTIVE blue */
        .photo { flex: 0 0 54%; position: relative; color: #fff; background: #2A43C9;
            display: flex; flex-direction: column; justify-content: space-between; padding: 52px 64px 52px; overflow: hidden; }
        /* soft shade at the top and bottom so the white text stays readable on any photo */
        .photo::before { content: ''; position: absolute; inset: 0; background: linear-gradient(180deg, rgba(4,10,50,.55) 0%, rgba(4,10,50,0) 26%, rgba(4,10,50,0) 72%, rgba(4,10,50,.6) 100%); pointer-events: none; }
        .photo > * { position: relative; z-index: 2; }
        .photo::before { z-index: 1; }
        /* the photo moves slowly (zoom and drift), like a film */
        .photo > .bg { position: absolute; inset: -3%; z-index: 0; background: url('assets/images/login-bg.jpg?v=<?= (int) @filemtime(__DIR__ . '/assets/images/login-bg.jpg'); ?>') center / cover no-repeat; will-change: transform; animation: rmDrift 26s ease-in-out infinite alternate; }
        @keyframes rmDrift { 0% { transform: scale(1) translate(0, 0); } 50% { transform: scale(1.08) translate(-1.5%, -1%); } 100% { transform: scale(1.14) translate(1.2%, -2%); } }
        /* the blue and gold bands sway gently */
        .deco path { will-change: transform; }
        .deco.top path { transform-origin: 0 0; animation: rmSway 9s ease-in-out infinite; }
        .deco.top path:nth-child(2) { animation-duration: 11s; animation-delay: -3s; }
        .deco.top path:nth-child(3) { animation-duration: 13s; animation-delay: -6s; }
        @keyframes rmSway { 0%, 100% { transform: scale(1, 1); } 50% { transform: scale(1.09, 1.05); } } /* grows from the corner: never leaves a gap */
        .deco.bottom path { animation: rmWave 8s ease-in-out infinite; }
        .deco.bottom path:nth-child(2) { animation-duration: 9s; animation-delay: -2s; }
        .deco.bottom path:nth-child(3) { animation-duration: 10s; animation-delay: -4s; }
        @keyframes rmWave { 0%, 100% { transform: translateY(0) skewY(0deg); } 50% { transform: translateY(9px) skewY(-1deg); } }
        /* gentle entrance */
        .photo .eyebrow { animation: rmIn .8s ease-out both .2s; } .photo .foot { animation: rmIn .8s ease-out both .4s; }
        .box { animation: rmUp .7s ease-out both; }
        @keyframes rmIn { from { opacity: 0; transform: translateX(-14px); } to { opacity: 1; transform: none; } }
        @keyframes rmUp { from { opacity: 0; transform: translateY(14px); } to { opacity: 1; transform: none; } }
        @keyframes rmGrow { from { width: 0; } }
        .photo .eyebrow::before { animation: rmGrow .9s ease-out both .5s; }
        /* people who ask their device for less motion get a still page */
        @media (prefers-reduced-motion: reduce) { .photo > .bg, .deco path, .photo .eyebrow, .photo .foot, .box, .photo .eyebrow::before { animation: none !important; } }
        /* the curved RISE MOTIVE bands in the top-left and bottom-left corners (drawn over any photo) */
        .photo > .deco { position: absolute; z-index: 1; pointer-events: none; }
        .photo > .deco.top { top: 0; left: 0; width: 46%; height: auto; }
        .photo > .deco.bottom { bottom: 0; left: 0; width: 100%; height: auto; }
        .photo .eyebrow { display: flex; align-items: center; gap: 12px; font-size: 14px; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; text-shadow: 0 1px 2px rgba(0,0,0,.15); }
        .photo .eyebrow::before { content: ''; width: 40px; height: 4px; border-radius: 4px; background: #F2B312; }
        .photo .foot { font-size: 14px; font-weight: 500; opacity: .95; }

        /* Right: sign in */
        .side { flex: 1; display: flex; align-items: center; justify-content: center; padding: 48px 32px; }
        .box { width: 100%; max-width: 400px; }
        .logo { width: 92px; height: 92px; object-fit: contain; display: block; margin: 0 0 18px; }
        .kicker { font-size: 13px; font-weight: 700; letter-spacing: .16em; color: var(--blue); text-transform: uppercase; margin-bottom: 4px; }
        h1 { font-size: 36px; line-height: 1.15; font-weight: 800; color: var(--deep); letter-spacing: -.02em; margin: 0 0 22px; }
        .alert { display: flex; gap: 10px; align-items: center; border-radius: 10px; padding: 12px 14px; font-size: 14px; font-weight: 500; margin-bottom: 22px; line-height: 1.4; }
        .alert.info { background: #FEF6D6; border: 1px solid #F1D98A; color: #4A3A00; }
        .alert.error { background: #FDECEC; border: 1px solid #F3B9B9; color: #9B1C1C; }
        .alert svg { width: 20px; height: 20px; flex-shrink: 0; }
        label { display: block; font-size: 14px; font-weight: 600; color: var(--deep); margin: 0 0 8px; }
        .field { position: relative; margin-bottom: 18px; }
        .field > svg { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); width: 19px; height: 19px; color: #3C4560; pointer-events: none; }
        .field input { width: 100%; height: 56px; border: 1px solid var(--line); border-radius: 12px; background: var(--field); padding: 0 50px 0 47px; font: inherit; font-size: 16px; color: var(--ink); transition: border-color .15s, box-shadow .15s, background .15s; }
        .field input::placeholder { color: #6B7287; }
        .field input:focus { outline: none; border-color: var(--blue); background: #fff; box-shadow: 0 0 0 4px rgba(20,48,224,.12); }
        .eye { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); width: 40px; height: 40px; border: 0; background: transparent; border-radius: 8px; color: #3C4560; cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .eye:hover { background: rgba(20,48,224,.08); } .eye svg { width: 21px; height: 21px; }
        svg { fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .btn { width: 100%; height: 56px; margin-top: 4px; border: 0; border-radius: 12px; background: var(--blue); color: #fff; font: inherit; font-size: 17px; font-weight: 700;
            display: flex; align-items: center; justify-content: center; gap: 10px; cursor: pointer; box-shadow: 0 12px 26px -8px rgba(20,48,224,.55); transition: background .15s, transform .05s; }
        .btn:hover, .btn:focus-visible { background: #0E25B8; } .btn:active { transform: translateY(1px); } .btn svg { width: 20px; height: 20px; stroke-width: 2.2; }
        .help { text-align: center; font-size: 14px; color: var(--muted); margin: 22px 0 22px; }
        hr { border: 0; border-top: 1px solid #E3E6EF; margin: 0 0 26px; }
        .cust { display: flex; align-items: center; justify-content: center; gap: 8px; font-size: 14px; font-weight: 700; color: var(--deep); text-decoration: none; }
        .sheet-foot { display: none; }
        .cust:hover { color: var(--blue); } .cust svg { width: 18px; height: 18px; stroke-width: 2.2; color: var(--blue); }

        /* Laptops with a short screen (or a zoomed browser): everything fits without scrolling */
        @media (min-width: 901px) and (max-height: 820px) {
            .side { padding: 24px 32px; }
            .logo { width: 70px; height: 70px; margin-bottom: 10px; }
            h1 { font-size: 30px; margin-bottom: 16px; }
            .alert { margin-bottom: 16px; padding: 10px 12px; }
            label { margin-bottom: 6px; } .field { margin-bottom: 14px; }
            .field input, .btn { height: 50px; }
            .help { margin: 16px 0; } hr { margin-bottom: 16px; }
            .photo { padding: 36px 48px; }
        }
        @media (min-width: 901px) and (max-height: 660px) {
            .logo { width: 56px; height: 56px; } h1 { font-size: 26px; margin-bottom: 12px; }
            .field input, .btn { height: 46px; } .kicker { font-size: 12px; }
        }

        /* Tablets and phones: the photo becomes a band at the top */
        @media (max-width: 900px) {
            /* Phones and tablets: the photo is a moving banner and the sign-in is a white sheet that slides over it */
            .page { flex-direction: column; min-height: 100svh; }
            /* the moving photo fills the whole screen behind a white card rounded on all four corners */
            .photo { position: fixed; inset: 0; height: auto; padding: 22px; justify-content: flex-start; }
            .photo::before { background: linear-gradient(180deg, rgba(4,10,50,.6) 0%, rgba(4,10,50,.05) 30%, rgba(4,10,50,.25) 70%, rgba(4,10,50,.6) 100%); }
            .photo .eyebrow { font-size: 11.5px; letter-spacing: .12em; } .photo .eyebrow::before { width: 26px; height: 3px; }
            .photo .foot { display: none; }
            .photo > .deco.bottom { display: none; } .photo > .deco.top { width: 34%; }
            .side { position: relative; z-index: 3; flex: 1 0 auto; margin: max(150px, 25vh) 14px calc(14px + env(safe-area-inset-bottom)); background: #fff; border-radius: 28px;
                box-shadow: 0 18px 44px rgba(4,10,50,.38); padding: 0 20px 18px; align-items: flex-start; }
            .box { max-width: 460px; margin: 0 auto; }
            .logo { width: 88px; height: 88px; padding: 9px; background: #fff; border-radius: 50%; box-shadow: 0 10px 26px rgba(11,26,140,.20); margin: -44px auto 14px; }
            .kicker { text-align: center; font-size: 12px; }
            h1 { font-size: 28px; text-align: center; margin-bottom: 20px; }
            .field input, .btn { height: 54px; }
            .help { margin: 18px 0; } hr { margin-bottom: 18px; }
            .cust { font-size: 13.5px; }
            /* the form sits in the middle of the white sheet, with the company line at the bottom */
            .side { flex-direction: column; align-items: stretch; justify-content: flex-start; }
            .box { flex: 0 0 auto; width: 100%; margin: auto; padding-top: 58px; animation-name: rmFade; } /* centred between the photo and the bottom line */
            .logo { position: absolute; top: 0; left: 50%; margin: 0; transform: translate(-50%, -50%); }
            @keyframes rmFade { from { opacity: 0; } to { opacity: 1; } }
            .sheet-foot { display: block; text-align: center; font-size: 12px; color: #8A90A2; padding-top: 20px; margin-top: auto; }
        }
        @media (max-width: 380px) { .cust { font-size: 12.5px; gap: 6px; } .side { padding: 0 16px 16px; } }
        @media (max-width: 900px) and (max-height: 700px) {
            .side { margin-top: max(118px, 20vh); }
            .logo { width: 72px; height: 72px; } .box { padding-top: 48px; } h1 { font-size: 25px; margin-bottom: 14px; }
            .field input, .btn { height: 50px; } .field { margin-bottom: 12px; }
        }
    </style>
</head>
<body>
<div class="page">
    <section class="photo" aria-label="RM Staff Portal">
        <div class="bg" aria-hidden="true"></div>
        <svg class="deco top" viewBox="0 0 360 640" aria-hidden="true" focusable="false">
            <path d="M0 0H360C200 70 70 260 0 640Z" fill="#2F55F0" fill-opacity=".38" stroke="none"/>
            <path d="M0 0H255C135 80 45 250 0 470Z" fill="#1A3FE6" fill-opacity=".72" stroke="none"/>
            <path d="M0 0H150C80 60 25 160 0 300Z" fill="#0B2BD8" fill-opacity=".85" stroke="none"/>
        </svg>
        <svg class="deco bottom" viewBox="0 0 780 300" overflow="visible" preserveAspectRatio="xMinYMax meet" aria-hidden="true" focusable="false">
            <path d="M0 8C250 55 520 140 780 250V330H735C495 186 235 112 0 66Z" fill="#FFFFFF" fill-opacity=".16" stroke="none"/>
            <path d="M0 66C235 112 495 186 735 300H700C478 208 222 138 0 92Z" fill="#C99A2E" fill-opacity=".85" stroke="none"/>
            <path d="M0 92C222 138 478 208 700 300L760 330H0Z" fill="#0A1A8C" stroke="none"/>
        </svg>
        <div class="eyebrow">Welcome to RM Staff Portal</div>
        <div class="foot">&copy; <?= date('Y'); ?> RISE MOTIVE Ltd</div>
    </section>

    <main class="side">
        <div class="box">
            <img class="logo" src="assets/images/rise.jpg?v=<?= (int) @filemtime(__DIR__ . '/assets/images/rise.jpg'); ?>" alt="RISE MOTIVE">
            <div class="kicker">RM Staff Portal</div>
            <h1>Staff sign in</h1>

            <?php if ($expired && !isset($error)) { ?>
            <div class="alert info" role="status"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>Session expired. Please sign in again.</span></div>
            <?php } ?>
            <?php if (isset($error)) { ?>
            <div class="alert error" role="alert"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/></svg><span><?= $h($error); ?></span></div>
            <?php } ?>

            <form method="POST" action="login.php" autocomplete="on">
                <label for="email">Email</label>
                <div class="field"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></svg>
                    <input type="email" id="email" name="email" placeholder="name@risemotive.com" value="<?= $h($_POST['email'] ?? ''); ?>" autocomplete="username" required autofocus>
                </div>
                <label for="password">Password</label>
                <div class="field"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
                    <input type="password" id="password" name="password" placeholder="Password" autocomplete="current-password" required>
                    <button type="button" class="eye" id="eye" aria-label="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg></button>
                </div>
                <button type="submit" name="login" class="btn">Sign in <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></button>
            </form>

            <div class="help">Forgot password? Ask an Administrator.</div>
            <hr>
            <a class="cust" href="portal/">Customer? Go to the RM Customer Portal <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg></a>
        </div>
        <div class="sheet-foot">&copy; <?= date('Y'); ?> RISE MOTIVE Ltd &middot; RM Staff Portal</div>
    </main>
</div>
<script>
    (function () {
        var b = document.getElementById('eye'), p = document.getElementById('password');
        var open = b.innerHTML, shut = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3l18 18M10.6 6.1A10 10 0 0 1 12 6c6.4 0 10 6 10 6a17 17 0 0 1-3.2 3.8M6.6 7.6A17 17 0 0 0 2 12s3.6 6 10 6a9.6 9.6 0 0 0 4.2-.9"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/></svg>';
        b.addEventListener('click', function () { var show = p.type === 'password'; p.type = show ? 'text' : 'password'; b.innerHTML = show ? shut : open; b.setAttribute('aria-label', show ? 'Hide password' : 'Show password'); p.focus(); });
    })();
</script>
</body>
</html>