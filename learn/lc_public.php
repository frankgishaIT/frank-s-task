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
 * Page header. $meta: title, description, image (absolute URL), url (canonical).
 */
function lcp_header(mysqli $conn, array $meta = []): void
{
    $base = lc_public_base($conn);
    $site = lc_setting($conn, 'title');
    $title = ($meta['title'] ?? '') !== '' ? $meta['title'] . ' · ' . $site : $site . ' — ' . lc_setting($conn, 'tagline');
    $desc = mb_substr($meta['description'] ?? lc_setting($conn, 'intro'), 0, 300);
    $img = $meta['image'] ?? '';
    $url = $meta['url'] ?? $base . '/';
    $logoUrl = 'logo.php'; // served from this folder: works on a sub-domain too
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<base href="<?= lc_e($base); ?>/">
<title><?= lc_e($title); ?></title>
<meta name="description" content="<?= lc_e($desc); ?>">
<link rel="canonical" href="<?= lc_e($url); ?>">
<meta property="og:type" content="website"><meta property="og:site_name" content="<?= lc_e($site); ?>">
<meta property="og:title" content="<?= lc_e($title); ?>"><meta property="og:description" content="<?= lc_e($desc); ?>"><meta property="og:url" content="<?= lc_e($url); ?>">
<?php if ($img) { ?><meta property="og:image" content="<?= lc_e($img); ?>"><meta name="twitter:image" content="<?= lc_e($img); ?>"><?php } ?>
<meta name="twitter:card" content="<?= $img ? 'summary_large_image' : 'summary'; ?>"><meta name="twitter:title" content="<?= lc_e($title); ?>"><meta name="twitter:description" content="<?= lc_e($desc); ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --b: #1E2FE0; --d: #0B1A8C; --ink: #1E2333; --soft: #EEF3FF; --g: #0FA968; }
    body { font-family: 'Nunito Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; color: var(--ink); background: #F6F8FC; }
    a { color: var(--b); } .btn { border-radius: 10px; font-weight: 700; }
    .btn-primary { background: var(--b); border-color: var(--b); } .btn-primary:hover { background: var(--d); border-color: var(--d); }
    .btn-outline-primary { color: var(--b); border-color: var(--b); } .btn-outline-primary:hover { background: var(--b); border-color: var(--b); color: #fff; }
    .lc-nav { background: linear-gradient(100deg, var(--d), var(--b)); }
    .lc-nav a.brand { color: #fff; text-decoration: none; font-weight: 800; letter-spacing: .04em; display: flex; align-items: center; gap: 10px; }
    .lc-nav a.brand img { width: 40px; height: 40px; object-fit: contain; filter: grayscale(1) contrast(4); mix-blend-mode: screen; }
    .lc-nav .nav-link { color: rgba(255,255,255,.9); font-weight: 600; } .lc-nav .nav-link:hover, .lc-nav .nav-link.on { color: #fff; }
    .lc-hero { background: linear-gradient(115deg, var(--d) 0%, var(--b) 60%, #3D5BFF 100%); color: #fff; padding: 48px 0 64px; border-radius: 0 0 36px 36px; }
    .lc-hero h1 { color: #fff; font-weight: 800; font-size: clamp(1.8rem, 4vw, 2.8rem); }
    .card { border: 0; border-radius: 16px; box-shadow: 0 2px 12px rgba(16,24,64,.07); }
    .lc-course-card { transition: transform .15s; } .lc-course-card:hover { transform: translateY(-3px); }
    .lc-course-card .thumb { height: 150px; background: var(--soft) center/cover no-repeat; border-radius: 16px 16px 0 0; display: flex; align-items: center; justify-content: center; color: var(--b); font-size: 2.4rem; }
    .lc-chip { display: inline-block; font-size: .78rem; padding: 2px 10px; border-radius: 20px; background: var(--soft); color: var(--d); font-weight: 600; margin: 0 4px 4px 0; }
    .lc-foot { background: var(--d); color: #fff; padding: 28px 0; margin-top: 40px; } .lc-foot a { color: #fff; }
    .lc-sticky { position: sticky; top: 16px; }
    .lc-course-card h3 { color: var(--ink); } .text-primary { color: var(--b) !important; } .badge.bg-success, .btn-success { background: var(--g) !important; border-color: var(--g); }
    h2.sec { font-weight: 800; color: var(--d); font-size: 1.3rem; }
    @media (max-width: 767px) { .lc-hero { padding: 28px 0 44px; } }
</style></head><body>
<nav class="lc-nav py-2"><div class="container d-flex flex-wrap align-items-center justify-content-between gap-2">
    <a class="brand" href="./"><img src="<?= lc_e($logoUrl); ?>" alt="" onerror="this.style.display='none'"><span><?= lc_e(strtoupper($site)); ?></span></a>
    <div class="d-flex flex-wrap gap-1"><a class="nav-link px-2" href="./">Home</a><a class="nav-link px-2" href="courses.php">Courses</a><a class="nav-link px-2" href="track.php">Track my application</a>
        <a class="nav-link px-2" href="<?= lc_e(lcp_portal_url($conn)); ?>">Customer Portal</a></div>
</div></nav>
<?php
}

function lcp_footer(mysqli $conn): void
{
    $wa = preg_replace('/\D/', '', lc_setting($conn, 'contact_whatsapp'));
    ?>
<footer class="lc-foot"><div class="container"><div class="row g-3 small">
    <div class="col-md-5"><div class="fw-bold mb-1"><?= lc_e(lc_setting($conn, 'title')); ?> · RISE MOTIVE Ltd</div><div class="opacity-75"><?= lc_e(lc_setting($conn, 'tagline')); ?></div>
        <?php if (lc_setting($conn, 'accreditation_note') !== '') { ?><div class="mt-2 opacity-75"><?= lc_e(lc_setting($conn, 'accreditation_note')); ?></div><?php } ?></div>
    <div class="col-md-4"><div class="fw-bold mb-1">Contact &amp; support</div>
        <?php if (lc_setting($conn, 'contact_phone')) { ?><div><i class="bi bi-telephone"></i> <a href="tel:<?= lc_e(preg_replace('/\s/', '', lc_setting($conn, 'contact_phone'))); ?>"><?= lc_e(lc_setting($conn, 'contact_phone')); ?></a></div><?php } ?>
        <?php if ($wa) { ?><div><i class="bi bi-whatsapp"></i> <a href="https://wa.me/<?= lc_e($wa); ?>" target="_blank" rel="noopener">WhatsApp</a></div><?php } ?>
        <?php if (lc_setting($conn, 'contact_email')) { ?><div><i class="bi bi-envelope"></i> <a href="mailto:<?= lc_e(lc_setting($conn, 'contact_email')); ?>"><?= lc_e(lc_setting($conn, 'contact_email')); ?></a></div><?php } ?>
        <div><i class="bi bi-geo-alt"></i> <?= lc_e(lc_setting($conn, 'contact_address')); ?></div></div>
    <div class="col-md-3"><div class="fw-bold mb-1">Quick links</div><div><a href="courses.php">All courses</a></div><div><a href="track.php">Track my application</a></div><div><a href="<?= lc_e(lcp_portal_url($conn)); ?>">Customer Portal</a></div></div>
</div><div class="text-center small opacity-75 mt-3">© <?= date('Y'); ?> RISE MOTIVE Ltd · Your information is used only to process your application.</div></div></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html><?php
}

/** Course card (catalogue, home). */
function lcp_course_card(mysqli $conn, array $c): void
{
    $av = lc_availability($conn, $c);
    $int = lc_intakes($conn, (int) $c['id'], true);
    $next = $int ? reset($int) : null;
    $img = $c['image_ref'] ? 'image.php?c=' . rawurlencode(strtolower($c['code'])) : '';
    ?>
    <div class="col-sm-6 col-lg-4"><a href="courses/<?= lc_e(strtolower($c['code'])); ?>" class="text-decoration-none text-reset"><div class="card h-100 lc-course-card">
        <div class="thumb" style="<?= $img ? "background-image:url('" . lc_e($img) . "')" : ''; ?>"><?= $img ? '' : '<i class="bi bi-mortarboard"></i>'; ?></div>
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-start gap-2"><span class="small text-muted"><?= lc_e($c['category']); ?> · <?= lc_e($c['code']); ?></span><span class="badge bg-<?= $av[1]; ?>"><?= lc_e($av[0]); ?></span></div>
            <h3 class="h6 fw-bold mt-1 mb-1"><?= lc_e($c['title']); ?></h3>
            <div class="small text-muted mb-2"><?= lc_e(mb_strimwidth((string) $c['summary'], 0, 120, '…')); ?></div>
            <div><?php if ($c['duration_value']) { ?><span class="lc-chip"><i class="bi bi-clock"></i> <?= (int) $c['duration_value'] . ' ' . lc_e($c['duration_unit']); ?></span><?php } ?>
                <?php foreach (array_slice(array_filter(explode(',', (string) $c['modes'])), 0, 2) as $m) { ?><span class="lc-chip"><?= lc_e($m); ?></span><?php } ?>
                <?php if ($c['fees_public'] && (float) $c['course_fee'] > 0) { ?><span class="lc-chip"><?= lc_money($c['course_fee']); ?></span><?php } ?></div>
            <?php if ($next) { ?><div class="small mt-1"><i class="bi bi-calendar-event"></i> Next start <?= date('d M Y', strtotime($next['start_date'])); ?><?= $next['apply_close'] ? ' · apply by ' . date('d M', strtotime($next['apply_close'])) : ''; ?></div><?php } ?>
        </div></div></a></div>
    <?php
}