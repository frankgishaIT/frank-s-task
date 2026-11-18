<?php
/**
 * NEW FILE: learn/lc_public.php — start and page frame of the PUBLIC RM Learning Centre website (no login).
 * Same database and business rules as MY MOTIVE. Its own session cookie (RMLEARN): it never opens staff or portal sessions.
 * Address: https://<your server>/learn/ or a sub-domain (learn.risemotive.rw) pointed at this folder.
 */
if (!defined('RM_OS_ALLOW_GUEST')) { define('RM_OS_ALLOW_GUEST', true); }
date_default_timezone_set('Africa/Kigali');
if (session_status() === PHP_SESSION_NONE) {
    session_name('RMLEARN');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off']);
    session_start();
}
require __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/lc_helpers.php';
/** @var mysqli $conn */
lc_ensure($conn);
if (lc_setting($conn, 'public_enabled') !== '1') { http_response_code(503); echo 'RM Learning Centre will be back soon.'; exit; }
header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');

/** Customer Portal sign-in (its own address when this website runs on a sub-domain). */
function lcp_portal_url(mysqli $conn): string { $u = lc_setting($conn, 'portal_url'); return $u !== '' ? $u : (defined('BASE_URL') ? BASE_URL : '') . '/portal/login.php'; }

/**
 * FIXED (404 on course pages): the <base> of the public pages is the folder these pages are really served from
 * (…/learn on the main domain, or / on learn.risemotive.rw), never a guess. Relative links then always resolve.
 */
function lcp_base(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $dir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $dir;
}

/** Link to a course page from inside the public website (works with or without short links). */
function lcp_course_href(mysqli $conn, string $code): string
{
    $c = rawurlencode(strtolower($code));
    return lc_setting($conn, 'pretty_urls') === '1' ? 'courses/' . $c : 'course.php?code=' . $c;
}

function lcp_csrf(): string { if (empty($_SESSION['lcp_csrf'])) { $_SESSION['lcp_csrf'] = bin2hex(random_bytes(16)); } return $_SESSION['lcp_csrf']; }
function lcp_csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['lcp_csrf']) && hash_equals($_SESSION['lcp_csrf'], (string) $_POST['csrf']); }

/** Remembers where the visitor came from (first source wins for the visit): ?src=wa / fb / ig / li / qr / ref, utm_source, utm_campaign. */
function lcp_track_source(): void
{
    if (!empty($_GET['src']) || !empty($_GET['utm_source'])) {
        $_SESSION['lcp_source'] = lc_source_from($_GET);
        $_SESSION['lcp_campaign'] = mb_substr((string) ($_GET['utm_campaign'] ?? $_GET['campaign'] ?? ''), 0, 100) ?: null;
    }
    if (empty($_SESSION['lcp_referrer']) && !empty($_SERVER['HTTP_REFERER']) && strpos((string) $_SERVER['HTTP_REFERER'], (string) ($_SERVER['HTTP_HOST'] ?? '')) === false) {
        $_SESSION['lcp_referrer'] = mb_substr((string) $_SERVER['HTTP_REFERER'], 0, 255);
        if (empty($_SESSION['lcp_source'])) {
            $r = strtolower((string) $_SERVER['HTTP_REFERER']);
            foreach (['whatsapp' => 'whatsapp', 'facebook' => 'facebook', 'fb.' => 'facebook', 'instagram' => 'instagram', 'linkedin' => 'linkedin', 't.co' => 'x', 'twitter' => 'x'] as $needle => $src) {
                if (strpos($r, $needle) !== false) { $_SESSION['lcp_source'] = $src; break; }
            }
        }
    }
}

/**
 * CHANGED (Task 7): ICONS — one small set of line icons drawn in the page (no icon font): same size, same stroke, aligned with
 * the text, each with one clear meaning. Decorative icons were removed.
 */
function lcp_i(string $name, string $cls = ''): string
{
    static $p = [
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'calendar' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M16 3v4M8 3v4M3 10h18"/>',
        'pin' => '<path d="M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11z"/><circle cx="12" cy="10" r="2.5"/>',
        'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>',
        'mail' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
        'chat' => '<path d="M21 12a8.5 8.5 0 0 1-12.6 7.4L3 21l1.6-5.2A8.5 8.5 0 1 1 21 12z"/>',
        'check' => '<path d="m5 12 5 5L20 7"/>',
        'check-circle' => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="m9 9 6 6M15 9l-6 6"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-left' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'share' => '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.4M8.2 13.2l7.6 4.4"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        'cap' => '<path d="m2 9 10-5 10 5-10 5z"/><path d="M6 11v5c0 1.5 2.7 3 6 3s6-1.5 6-3v-5"/>',
        'wallet' => '<rect x="3" y="6" width="18" height="14" rx="2"/><path d="M3 10h18M16 15h2"/>',
        'edit' => '<path d="M4 20h4L19 9l-4-4L4 16z"/>',
        'award' => '<circle cx="12" cy="9" r="6"/><path d="m8.5 14-1.5 7 5-3 5 3-1.5-7"/>',
        'file' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/>',
        'clip' => '<path d="m20 11-8.5 8.5a5 5 0 0 1-7-7L13 4a3.5 3.5 0 0 1 5 5l-8.5 8.5a2 2 0 0 1-3-3L14 7"/>',
        'alert' => '<circle cx="12" cy="12" r="9"/><path d="M12 8v5M12 16h.01"/>',
        'logout' => '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3M10 17l5-5-5-5M15 12H4"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'home' => '<path d="m3 11 9-7 9 7"/><path d="M5 10v10h14V10"/>',
        'briefcase' => '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2v2M3 13h18"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'shield' => '<path d="M12 3 4 6v6c0 5 3.5 8 8 9 4.5-1 8-4 8-9V6z"/><path d="m9 12 2 2 4-4"/>',
        'download' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
        'bed' => '<path d="M3 18V7M3 14h18v4M21 14v-2a3 3 0 0 0-3-3h-7v5"/><circle cx="7" cy="11" r="1.5"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5z"/><path d="m3 13 9 5 9-5"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
    ];
    return '<svg class="lci' . ($cls !== '' ? ' ' . $cls : '') . '" viewBox="0 0 24 24" aria-hidden="true" focusable="false">' . ($p[$name] ?? $p['file']) . '</svg>';
}

/**
 * Page header. $meta: title, description, image (absolute URL), url (canonical).
 * CHANGED (Task 7): one typography system (font, sizes, weights, line spacing for headings, text, buttons and menus),
 * mobile-first layout, a real phone menu, line icons, pictures in fixed proportions (never stretched).
 */
function lcp_header(mysqli $conn, array $meta = []): void
{
    $base = lcp_base();
    $site = lc_setting($conn, 'title');
    $title = ($meta['title'] ?? '') !== '' ? $meta['title'] . ' · ' . $site : $site . ' — ' . lc_setting($conn, 'tagline');
    $desc = mb_substr($meta['description'] ?? lc_setting($conn, 'intro'), 0, 300);
    $img = $meta['image'] ?? '';
    $url = $meta['url'] ?? $base . '/';
    $logoUrl = 'logo.php'; // served from this folder: works on a sub-domain too
    $here = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $nav = [['./', 'Home', ['index.php']], ['courses.php', 'Courses', ['courses.php', 'course.php', 'apply.php']], ['internships.php', 'Internships', ['internships.php']],
            ['track.php', 'Track application', ['track.php']], ['verify.php', 'Verify certificate', ['verify.php']]];
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<base href="<?= lc_e($base); ?>/">
<title><?= lc_e($title); ?></title>
<meta name="description" content="<?= lc_e($desc); ?>">
<meta name="theme-color" content="#0B1A8C">
<link rel="canonical" href="<?= lc_e($url); ?>">
<meta property="og:type" content="website"><meta property="og:site_name" content="<?= lc_e($site); ?>">
<meta property="og:title" content="<?= lc_e($title); ?>"><meta property="og:description" content="<?= lc_e($desc); ?>"><meta property="og:url" content="<?= lc_e($url); ?>">
<?php if ($img) { ?><meta property="og:image" content="<?= lc_e($img); ?>"><meta name="twitter:image" content="<?= lc_e($img); ?>"><?php } ?>
<meta name="twitter:card" content="<?= $img ? 'summary_large_image' : 'summary'; ?>"><meta name="twitter:title" content="<?= lc_e($title); ?>"><meta name="twitter:description" content="<?= lc_e($desc); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
    /* ---- Typography system (mobile first; desktop sizes from 768 px) ---- */
    :root { --b: #1E2FE0; --d: #0B1A8C; --ink: #1E2333; --muted: #5B6275; --line: #E4E8F2; --soft: #EEF3FF; --g: #0FA968; --bg: #F6F8FC;
        --font: 'Nunito Sans', system-ui, -apple-system, 'Segoe UI', Roboto, Arial, sans-serif;
        --fs-xs: .75rem; --fs-sm: .8125rem; --fs-base: .9375rem; --fs-lead: 1rem; --fs-h3: 1rem; --fs-h2: 1.1875rem; --fs-h1: 1.5rem;
        --lh: 1.6; --lh-tight: 1.25; --r: 12px; }
    @media (min-width: 768px) { :root { --fs-base: 1rem; --fs-lead: 1.0625rem; --fs-h3: 1.0625rem; --fs-h2: 1.3125rem; --fs-h1: 2rem; } }
    html { -webkit-text-size-adjust: 100%; }
    body { font-family: var(--font); font-size: var(--fs-base); line-height: var(--lh); color: var(--ink); background: var(--bg); font-weight: 400; overflow-x: hidden; }
    h1, .h1 { font-size: var(--fs-h1); font-weight: 700; line-height: var(--lh-tight); letter-spacing: -.01em; }
    h2, .h2, h2.sec { font-size: var(--fs-h2); font-weight: 700; line-height: var(--lh-tight); color: var(--d); margin-bottom: .6rem; }
    h3, .h3, .h4, .h5, .h6 { font-size: var(--fs-h3); font-weight: 700; line-height: var(--lh-tight); }
    h2.h6 { font-size: var(--fs-sm); letter-spacing: .04em; color: var(--muted); }
    h1.h3, h1.h4, h2.h4 { font-size: var(--fs-h2); color: var(--d); }
    .lead { font-size: var(--fs-lead); font-weight: 400; } .small, small { font-size: var(--fs-sm); } .text-muted { color: var(--muted) !important; }
    .fs-2, .fs-3 { font-size: 1.375rem !important; } .fs-4 { font-size: 1.125rem !important; } .fs-6 { font-size: var(--fs-sm) !important; } .display-6 { font-size: 2.25rem; }
    .fw-bold { font-weight: 700 !important; } a { color: var(--b); text-underline-offset: 2px; }
    /* ---- Icons: one size, one stroke, aligned with the text ---- */
    .lci { width: 1.1em; height: 1.1em; stroke: currentColor; stroke-width: 1.8; fill: none; stroke-linecap: round; stroke-linejoin: round; vertical-align: -.18em; flex-shrink: 0; }
    .lci-lg { width: 2.5rem; height: 2.5rem; stroke-width: 1.5; }
    /* ---- Buttons and forms ---- */
    .btn { font-size: .875rem; font-weight: 600; border-radius: 10px; padding: .5rem 1rem; line-height: 1.3; display: inline-flex; align-items: center; justify-content: center; gap: .4rem; }
    .btn-sm { font-size: var(--fs-sm); padding: .3rem .7rem; border-radius: 8px; } .btn-lg { font-size: .9375rem; padding: .65rem 1.2rem; }
    .btn-primary { background: var(--b); border-color: var(--b); } .btn-primary:hover, .btn-primary:focus { background: var(--d); border-color: var(--d); }
    .btn-outline-primary { color: var(--b); border-color: var(--b); } .btn-outline-primary:hover { background: var(--b); border-color: var(--b); color: #fff; }
    .form-control, .form-select { font-size: var(--fs-base); border-radius: 10px; border-color: #D8DCE8; min-height: 42px; } .form-label { font-size: var(--fs-sm); font-weight: 600; margin-bottom: .25rem; }
    .form-control:focus, .form-select:focus { border-color: var(--b); box-shadow: 0 0 0 .2rem rgba(30,47,224,.12); }
    .badge { font-weight: 600; font-size: var(--fs-xs); padding: .35em .6em; border-radius: 6px; } .badge.bg-success, .btn-success { background: var(--g) !important; border-color: var(--g); }
    .card { border: 0; border-radius: var(--r); box-shadow: 0 1px 3px rgba(16,24,64,.06), 0 4px 14px rgba(16,24,64,.05); } .card-body { padding: 1rem; } @media (min-width: 768px) { .card-body { padding: 1.25rem; } }
    .table { font-size: var(--fs-sm); } .table th { font-weight: 600; color: var(--muted); white-space: nowrap; }
    .text-primary { color: var(--b) !important; }
    /* ---- Navigation: brand + menu button on phones, links on computers ---- */
    .lc-nav { background: var(--d); position: sticky; top: 0; z-index: 1030; }
    .lc-nav .bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 56px; }
    .lc-nav a.brand { color: #fff; text-decoration: none; font-weight: 700; font-size: .9375rem; letter-spacing: .03em; display: flex; align-items: center; gap: 8px; white-space: nowrap; }
    .lc-nav a.brand img { width: 32px; height: 32px; object-fit: contain; filter: grayscale(1) contrast(4); mix-blend-mode: screen; }
    .lc-links { display: none; } .lc-links a { color: rgba(255,255,255,.86); font-weight: 600; font-size: .875rem; text-decoration: none; padding: .45rem .7rem; border-radius: 8px; }
    .lc-links a:hover, .lc-links a.on { color: #fff; background: rgba(255,255,255,.12); }
    .lc-menu-btn { background: transparent; border: 0; color: #fff; padding: 8px; margin-right: -8px; border-radius: 8px; display: inline-flex; } .lc-menu-btn .lci { width: 24px; height: 24px; }
    .lc-drawer { display: none; border-top: 1px solid rgba(255,255,255,.12); padding: 6px 0 12px; } .lc-drawer.open { display: block; }
    .lc-drawer a { display: flex; align-items: center; gap: 10px; color: #fff; text-decoration: none; font-weight: 600; font-size: .9375rem; padding: 11px 4px; border-bottom: 1px solid rgba(255,255,255,.08); }
    .lc-drawer a.on { color: #AFC0FF; } .lc-drawer .btn { margin-top: 10px; width: 100%; }
    @media (min-width: 992px) { .lc-links { display: flex; align-items: center; gap: 2px; } .lc-menu-btn, .lc-drawer { display: none !important; } .lc-nav a.brand { font-size: 1rem; } .lc-nav a.brand img { width: 36px; height: 36px; } }
    /* ---- Page parts ---- */
    .lc-hero { background: linear-gradient(115deg, var(--d) 0%, var(--b) 70%, #3D5BFF 100%); color: #fff; padding: 22px 0 48px; }
    .lc-hero h1 { color: #fff; margin-bottom: .4rem; } .lc-hero .lead { color: rgba(255,255,255,.88); }
    .lc-hero .eyebrow { font-size: var(--fs-xs); font-weight: 700; letter-spacing: .08em; text-transform: uppercase; opacity: .75; margin-bottom: .35rem; }
    @media (min-width: 768px) { .lc-hero { padding: 44px 0 64px; } }
    .lc-pull { margin-top: -24px; position: relative; } @media (min-width: 768px) { .lc-pull { margin-top: -36px; } }
    .lc-clamp-3 { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; } .lc-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    @media (min-width: 768px) { .lc-clamp-3 { -webkit-line-clamp: unset; display: block; } }
    .lc-chip { display: inline-flex; align-items: center; gap: 4px; font-size: var(--fs-xs); padding: 3px 9px; border-radius: 20px; background: var(--soft); color: var(--d); font-weight: 600; margin: 0 4px 4px 0; white-space: nowrap; }
    .lc-ratio { position: relative; overflow: hidden; background: var(--soft); aspect-ratio: 16 / 9; } .lc-ratio > img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
    .lc-ratio.noimg { aspect-ratio: 16 / 5; } .lc-ratio .ph { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; color: var(--b); opacity: .55; }
    .lc-course-card { overflow: hidden; transition: transform .15s, box-shadow .15s; } .lc-course-card:hover { transform: translateY(-2px); box-shadow: 0 8px 24px rgba(16,24,64,.1); }
    .lc-course-card .t { font-size: var(--fs-h3); font-weight: 700; color: var(--ink); line-height: var(--lh-tight); margin: 4px 0; }
    .lc-meta { font-size: var(--fs-sm); color: var(--muted); display: flex; align-items: center; gap: 6px; }
    .lc-list { list-style: none; padding: 0; margin: 0; } .lc-list li { display: flex; gap: 8px; align-items: flex-start; padding: 3px 0; } .lc-list li .lci { color: var(--g); margin-top: .25em; }
    .lc-steps { counter-reset: s; list-style: none; padding: 0; margin: 0; } .lc-steps li { counter-increment: s; display: flex; gap: 10px; padding: 5px 0; } .lc-steps li::before { content: counter(s); flex-shrink: 0; width: 24px; height: 24px; border-radius: 50%; background: var(--soft); color: var(--d); font-weight: 700; font-size: var(--fs-xs); display: flex; align-items: center; justify-content: center; margin-top: 1px; }
    .lc-intake { border: 1px solid var(--line); border-radius: 10px; padding: 10px 12px; display: flex; justify-content: space-between; align-items: center; gap: 10px; }
    .lc-intake + .lc-intake { margin-top: 8px; }
    .lc-sticky { position: sticky; top: 72px; }
    details.lc-sec { border-top: 1px solid var(--line); padding: 2px 0; } details.lc-sec > summary { list-style: none; cursor: pointer; display: flex; justify-content: space-between; align-items: center; padding: 10px 0; }
    details.lc-sec > summary::-webkit-details-marker { display: none; } details.lc-sec > summary h2 { margin: 0; font-size: calc(var(--fs-h3) + .0625rem); } details.lc-sec[open] > summary .lci { transform: rotate(180deg); }
    details.lc-sec > summary .lci { transition: transform .15s; color: var(--muted); } details.lc-sec > div { padding-bottom: 10px; }
    .lc-applybar { position: fixed; left: 0; right: 0; bottom: 0; z-index: 1020; background: #fff; border-top: 1px solid var(--line); box-shadow: 0 -4px 16px rgba(16,24,64,.08); padding: 10px 14px calc(10px + env(safe-area-inset-bottom)); display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .lc-applybar .p { font-size: var(--fs-sm); line-height: 1.3; } .lc-applybar .p strong { display: block; font-size: var(--fs-base); color: var(--d); }
    body.has-applybar { padding-bottom: 76px; } @media (min-width: 992px) { .lc-applybar { display: none; } body.has-applybar { padding-bottom: 0; } }
    .lc-foot { background: var(--d); color: rgba(255,255,255,.85); padding: 28px 0 20px; margin-top: 40px; font-size: var(--fs-sm); } .lc-foot a { color: #fff; text-decoration: none; } .lc-foot a:hover { text-decoration: underline; }
    .lc-foot .h { color: #fff; font-weight: 700; font-size: var(--fs-sm); margin-bottom: 6px; } .lc-foot .row > div > div { padding: 2px 0; }
    img { max-width: 100%; height: auto; }
</style></head><body>
<a href="#main" class="visually-hidden-focusable position-absolute bg-white p-2" style="z-index:2000">Skip to content</a>
<nav class="lc-nav" aria-label="Main"><div class="container">
    <div class="bar">
        <a class="brand" href="./"><img src="<?= lc_e($logoUrl); ?>" alt="" onerror="this.style.display='none'"><span><?= lc_e(strtoupper($site)); ?></span></a>
        <div class="lc-links"><?php foreach ($nav as [$h, $l, $on]) { ?><a href="<?= $h; ?>" class="<?= in_array($here, $on, true) ? 'on' : ''; ?>"><?= $l; ?></a><?php } ?>
            <a href="<?= lc_e(lcp_portal_url($conn)); ?>">Customer Portal</a></div>
        <button class="lc-menu-btn" type="button" aria-expanded="false" aria-controls="lcDrawer" aria-label="Menu" onclick="var d=document.getElementById('lcDrawer'),o=d.classList.toggle('open');this.setAttribute('aria-expanded',o);this.innerHTML=o?<?= lc_e(json_encode(lcp_i('close'))); ?>:<?= lc_e(json_encode(lcp_i('menu'))); ?>;"><?= lcp_i('menu'); ?></button>
    </div>
    <div class="lc-drawer" id="lcDrawer">
        <?php foreach ([['./', 'Home', 'home', ['index.php']], ['courses.php', 'Courses', 'cap', ['courses.php', 'course.php', 'apply.php']], ['internships.php', 'Internships', 'briefcase', ['internships.php']], ['track.php', 'Track my application', 'search', ['track.php']], ['verify.php', 'Verify a certificate', 'shield', ['verify.php']]] as [$h, $l, $ic, $on]) { ?>
        <a href="<?= $h; ?>" class="<?= in_array($here, $on, true) ? 'on' : ''; ?>"><?= lcp_i($ic); ?><?= $l; ?></a><?php } ?>
        <a href="<?= lc_e(lcp_portal_url($conn)); ?>"><?= lcp_i('user'); ?>Customer Portal</a>
        <a href="courses.php?open=1" class="btn btn-light text-primary border-0">Apply for a course</a>
    </div>
</div></nav>
<main id="main">
<?php
}

function lcp_footer(mysqli $conn): void
{
    $wa = preg_replace('/\D/', '', lc_setting($conn, 'contact_whatsapp'));
    ?>
</main>
<footer class="lc-foot"><div class="container"><div class="row g-3">
    <div class="col-12 col-md-5"><div class="h"><?= lc_e(lc_setting($conn, 'title')); ?> · RISE MOTIVE Ltd</div><div><?= lc_e(lc_setting($conn, 'tagline')); ?></div>
        <?php if (lc_setting($conn, 'accreditation_note') !== '') { ?><div class="mt-2 opacity-75"><?= lc_e(lc_setting($conn, 'accreditation_note')); ?></div><?php } ?></div>
    <div class="col-6 col-md-4"><div class="h">Contact</div>
        <?php if (lc_setting($conn, 'contact_phone')) { ?><div><a href="tel:<?= lc_e(preg_replace('/\s/', '', lc_setting($conn, 'contact_phone'))); ?>"><?= lcp_i('phone'); ?> <?= lc_e(lc_setting($conn, 'contact_phone')); ?></a></div><?php } ?>
        <?php if ($wa) { ?><div><a href="https://wa.me/<?= lc_e($wa); ?>" target="_blank" rel="noopener"><?= lcp_i('chat'); ?> WhatsApp</a></div><?php } ?>
        <?php if (lc_setting($conn, 'contact_email')) { ?><div><a href="mailto:<?= lc_e(lc_setting($conn, 'contact_email')); ?>" class="text-break"><?= lcp_i('mail'); ?> <?= lc_e(lc_setting($conn, 'contact_email')); ?></a></div><?php } ?>
        <?php if (lc_setting($conn, 'contact_address')) { ?><div><?= lcp_i('pin'); ?> <?= lc_e(lc_setting($conn, 'contact_address')); ?></div><?php } ?></div>
    <div class="col-6 col-md-3"><div class="h">Quick links</div><div><a href="courses.php">All courses</a></div><div><a href="internships.php">Internships</a></div><div><a href="verify.php">Verify a certificate</a></div><div><a href="track.php">Track my application</a></div><div><a href="<?= lc_e(lcp_portal_url($conn)); ?>">Customer Portal</a></div></div>
</div><div class="text-center opacity-75 mt-3 pt-3" style="border-top:1px solid rgba(255,255,255,.12);font-size:var(--fs-xs);">© <?= date('Y'); ?> RISE MOTIVE Ltd · Your information is used only to process your application.</div></div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" defer></script>
</body></html><?php
}

/** Course card (catalogue, home): picture in 16:9 (never stretched), short text, key facts. */
function lcp_course_card(mysqli $conn, array $c): void
{
    $av = lc_availability($conn, $c);
    $int = lc_intakes($conn, (int) $c['id'], true);
    $next = $int ? reset($int) : null;
    $img = $c['image_ref'] ? 'image.php?c=' . rawurlencode(strtolower($c['code'])) : '';
    ?>
    <div class="col-12 col-sm-6 col-lg-4"><a href="<?= lc_e(lcp_course_href($conn, $c['code'])); ?>" class="text-decoration-none text-reset d-block h-100"><div class="card h-100 lc-course-card">
        <div class="lc-ratio<?= $img ? '' : ' noimg'; ?>"><?= $img ? '<img src="' . lc_e($img) . '" alt="" loading="lazy" decoding="async">' : '<span class="ph">' . lcp_i('cap', 'lci-lg') . '</span>'; ?></div>
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center gap-2"><span class="small text-muted text-truncate"><?= lc_e($c['category']); ?></span><span class="badge bg-<?= $av[1]; ?>"><?= lc_e($av[0]); ?></span></div>
            <div class="t"><?= lc_e($c['title']); ?></div>
            <?php if ($c['summary']) { ?><div class="small text-muted lc-clamp-2 mb-2"><?= lc_e($c['summary']); ?></div><?php } ?>
            <div><?php if ($c['duration_value']) { ?><span class="lc-chip"><?= lcp_i('clock'); ?><?= (int) $c['duration_value'] . ' ' . lc_e($c['duration_unit']); ?></span><?php } ?>
                <?php foreach (array_slice(array_filter(explode(',', (string) $c['modes'])), 0, 2) as $m) { ?><span class="lc-chip"><?= lc_e($m); ?></span><?php } ?>
                <?php if ($c['fees_public'] && (float) $c['course_fee'] > 0) { ?><span class="lc-chip"><?= lc_money($c['course_fee']); ?></span><?php } ?></div>
            <?php if ($next) { ?><div class="lc-meta mt-1"><?= lcp_i('calendar'); ?>Starts <?= date('d M Y', strtotime($next['start_date'])); ?><?= $next['apply_close'] ? ' · apply by ' . date('d M', strtotime($next['apply_close'])) : ''; ?></div><?php } ?>
        </div></div></a></div>
    <?php
}