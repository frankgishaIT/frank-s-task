<?php
/**
 * NEW FILE: welcome.php (project root) — "Who are you?" page: staff members go to the MY MOTIVE Staff Portal,
 * customers to the RM Customer Portal. Public page (no sign-in, no database).
 * On the live sites the buttons open the official addresses; on a local computer, the local pages.
 */
$host = strtolower($_SERVER['HTTP_HOST'] ?? '');
$local = in_array(explode(':', $host)[0], ['localhost', '127.0.0.1'], true);
$base = $local ? rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\') : '';
$staffUrl = $local ? $base . '/login.php' : 'https://myspace.risemotive.rw/login.php';
$customerUrl = $local ? $base . '/portal/' : 'https://myspace.risemotive.rw/portal/';
$logo = is_file(__DIR__ . '/assets/images/logo.png') ? 'assets/images/logo.png' : (is_file(__DIR__ . '/assets/images/logo.jpg') ? 'assets/images/logo.jpg' : '');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Welcome · RISE MOTIVE</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --rm-blue: #1E2FE0; --rm-deep: #0B1A8C; --rm-ink: #1E2333; }
    html, body { font-family: 'Nunito Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
    body { background: #F6F8FC; color: var(--rm-ink); min-height: 100vh; display: flex; flex-direction: column; }
    .w-head { position: relative; background: linear-gradient(100deg, var(--rm-deep) 0%, var(--rm-blue) 55%, #3D5BFF 100%); color: #fff; overflow: hidden; text-align: center; padding: 22px 16px 70px; }
    .w-head::after { content: ""; position: absolute; left: -10%; right: -10%; bottom: -40px; height: 64px; background: #F6F8FC; border-radius: 50% 50% 0 0 / 100% 100% 0 0; }
    .w-head img { width: 84px; height: 84px; object-fit: contain; filter: grayscale(1) contrast(4); mix-blend-mode: screen; }
    .w-head h1 { font-weight: 800; letter-spacing: .06em; font-size: 1.5rem; margin: 8px 0 0; }
    main { flex: 1; display: flex; flex-direction: column; align-items: center; padding: 8px 16px 32px; }
    .w-q { color: var(--rm-deep); font-weight: 800; font-size: 1.9rem; text-align: center; margin-bottom: 6px; }
    .w-sub { color: #5A6275; text-align: center; margin-bottom: 26px; }
    .w-choice { display: flex; flex-direction: column; align-items: center; text-align: center; gap: 10px; padding: 30px 24px; border-radius: 18px; background: #fff;
        box-shadow: 0 2px 12px rgba(16,24,64,.07); text-decoration: none; color: var(--rm-ink); border: 2px solid transparent; transition: transform .15s, box-shadow .15s, border-color .15s; height: 100%; }
    .w-choice:hover, .w-choice:focus { transform: translateY(-3px); box-shadow: 0 12px 28px rgba(16,24,64,.14); border-color: var(--rm-blue); color: var(--rm-ink); }
    .w-ico { width: 84px; height: 84px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 2.4rem; color: #fff; }
    .w-choice h2 { font-weight: 800; font-size: 1.35rem; color: var(--rm-deep); margin: 4px 0 0; }
    .w-choice p { color: #5A6275; margin: 0 0 10px; }
    .w-go { margin-top: auto; display: inline-flex; align-items: center; gap: 8px; padding: 10px 22px; border-radius: 12px; color: #fff; font-weight: 700; }
    footer { background: linear-gradient(90deg, var(--rm-deep), var(--rm-blue)); color: #fff; text-align: center; font-weight: 800; padding: 18px 16px; }
    footer .sep { opacity: .55; margin: 0 12px; font-weight: 400; }
    @media (max-width: 575.98px) { .w-q { font-size: 1.4rem; } .w-head h1 { font-size: 1.15rem; } .w-choice { padding: 22px 18px; } footer { font-size: .8rem; } footer .sep { margin: 0 6px; } }
</style></head><body>
<header class="w-head">
    <?php if ($logo) { ?><img src="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8'); ?>" alt="RISE MOTIVE"><?php } ?>
    <h1>RISE MOTIVE</h1>
</header>
<main>
    <div class="w-q">Welcome! Who are you?</div>
    <div class="w-sub">Choose how you would like to sign in.</div>
    <div class="row g-3 g-md-4 w-100" style="max-width:820px;">
        <div class="col-md-6"><a class="w-choice" href="<?= htmlspecialchars($staffUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <span class="w-ico" style="background:linear-gradient(135deg,#0B1A8C,#1E2FE0);"><i class="bi bi-person-badge-fill"></i></span>
            <h2>I am a staff member</h2>
            <p>Sign in to MY MOTIVE, the RISE MOTIVE staff workspace.</p>
            <span class="w-go" style="background:#1E2FE0;">Staff sign in <i class="bi bi-arrow-right"></i></span>
        </a></div>
        <div class="col-md-6"><a class="w-choice" href="<?= htmlspecialchars($customerUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <span class="w-ico" style="background:linear-gradient(135deg,#087A4B,#0FA968);"><i class="bi bi-person-heart"></i></span>
            <h2>I am a customer</h2>
            <p>Open the RM Customer Portal: your invoices, EBM receipts, loyalty rewards, RM Loans and RM Invest.</p>
            <span class="w-go" style="background:#0FA968;">Customer sign in <i class="bi bi-arrow-right"></i></span>
        </a></div>
    </div>
</main>
<footer>Your Information Is Shown Only to You<span class="sep">|</span>Secure &amp; Trusted<span class="sep">|</span>Rise Beyond Limits</footer>
</body></html>