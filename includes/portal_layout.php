<?php
/**
 * includes/portal_layout.php — the Customer Portal's page frame.
 * CHANGED (new design): curved blue header with the logo, the customer's name / ID and the notification bell;
 * a left sidebar menu (slides in from a "Menu" button on phones); a trust line at the bottom.
 */
function portal_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function portal_money($v): string { return 'RWF ' . number_format((float) $v, 2); }

/** The logo: the staff system's logo file. */
function portal_logo_url(): string
{
    $root = dirname(__DIR__);
    foreach (['assets/images/logo.jpg', 'assets/images/logo.png'] as $f) {
        if (is_file($root . '/' . $f)) { return (basename(dirname($_SERVER['SCRIPT_NAME'] ?? '')) === 'portal' ? '../' : '/') . $f; }
    }
    return '';
}

function portal_header(string $title, ?array $customer = null): void
{
    $here = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $unread = (int) ($GLOBALS['PORTAL_UNREAD'] ?? 0);
    $logo = portal_logo_url();
    $menu = [
        ['index.php', 'house-door-fill', 'Home'],
        ['history.php', 'file-earmark-text', 'My Invoices'],
        ['lookup.php', 'search', 'Find Invoice'],
        ['RM Services', 'grid', [['loans.php', 'cash-coin', 'RM Loans'], ['invest.php', 'graph-up-arrow', 'RM Invest']]],
        ['my_investments.php', 'graph-up', 'My Investments'],
        ['documents.php', 'folder2-open', 'My Documents'],
    ];
    if (!empty($GLOBALS['PORTAL_RECORDS_ON'])) { $menu[] = ['records.php', 'award', 'My Records']; }
    $menu[] = ['profile.php', 'person', 'My Profile'];
    $menu[] = ['requests.php', 'headset', 'Support'];
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= portal_e($title); ?> · RISE MOTIVE Customer Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
    :root { --rm-blue: #1E2FE0; --rm-deep: #0B1A8C; --rm-ink: #1E2333; --rm-soft: #EEF3FF; }
    body { background: #F6F8FC; color: var(--rm-ink); font-family: 'Nunito Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
    .p-head { position: relative; background: linear-gradient(100deg, var(--rm-deep) 0%, var(--rm-blue) 55%, #3D5BFF 100%); color: #fff; overflow: hidden; }
    .p-head::after { content: ""; position: absolute; left: -10%; right: -10%; bottom: -40px; height: 64px; background: #F6F8FC; border-radius: 50% 50% 0 0 / 100% 100% 0 0; }
    .p-head .inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 22px 28px 64px; }
    .p-brand { font-weight: 800; letter-spacing: .02em; font-size: 1.25rem; white-space: nowrap; }
    .p-brand .sep { opacity: .55; margin: 0 10px; font-weight: 400; }
    .p-logo { position: absolute; left: 50%; top: 14px; transform: translateX(-50%); z-index: 2; width: 92px; height: 92px; border-radius: 50%; background: #fff; box-shadow: 0 6px 20px rgba(11,26,140,.35); display: flex; align-items: center; justify-content: center; overflow: hidden; }
    .p-logo img { width: 84px; height: 84px; object-fit: contain; }
    .p-user { display: flex; align-items: center; gap: 18px; }
    .p-user .dropdown-toggle { color: #fff; text-decoration: none; display: flex; align-items: center; gap: 10px; }
    .p-user .avatar { width: 42px; height: 42px; border-radius: 50%; background: rgba(255,255,255,.95); color: var(--rm-blue); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    .p-user .who { line-height: 1.2; } .p-user .who small { opacity: .8; }
    .p-bell { color: #fff; font-size: 1.5rem; position: relative; }
    .p-bell .badge { position: absolute; top: -4px; right: -9px; font-size: .62rem; }
    .p-wrap { display: flex; gap: 28px; padding: 18px 28px 0; }
    .p-side { width: 230px; flex-shrink: 0; position: relative; padding-bottom: 120px; }
    .p-side a { display: flex; align-items: center; gap: 14px; padding: 12px 16px; border-radius: 12px; color: var(--rm-ink); text-decoration: none; font-weight: 600; margin-bottom: 4px; }
    .p-side a i { font-size: 1.35rem; color: var(--rm-blue); width: 26px; text-align: center; }
    .p-side a:hover { background: var(--rm-soft); }
    .p-side a.active { background: linear-gradient(90deg, var(--rm-deep), var(--rm-blue)); color: #fff; box-shadow: 0 6px 16px rgba(30,47,224,.3); }
    .p-side a.active i { color: #fff; }
    .p-side .sub a { padding: 8px 16px 8px 56px; font-weight: 500; font-size: .93rem; }
    .p-side .wave { position: absolute; left: -28px; right: 0; bottom: 0; height: 110px; background: linear-gradient(135deg, var(--rm-blue), var(--rm-deep)); border-radius: 0 0 18px 0; clip-path: path('M0 60 C 90 10, 170 10, 260 0 L 260 110 L 0 110 Z'); }
    main.p-main { flex: 1; min-width: 0; padding-bottom: 28px; }
    .p-title { color: var(--rm-deep); font-weight: 800; text-align: center; font-size: 1.9rem; margin: 6px 0 24px; }
    .card { border: 0; box-shadow: 0 2px 10px rgba(16,24,64,.06); border-radius: 14px; }
    .btn-primary { background: var(--rm-blue); border-color: var(--rm-blue); }
    .table { font-size: .93rem; }
    .p-foot { display: flex; align-items: center; gap: 16px; padding: 18px 28px 26px; color: var(--rm-blue); font-size: .95rem; }
    .p-foot::before, .p-foot::after { content: ""; flex: 1; height: 1px; background: linear-gradient(90deg, transparent, #9AA8F5); }
    .p-foot::after { background: linear-gradient(90deg, #9AA8F5, transparent); }
    @media (max-width: 575.98px) { .p-brand { display: none; } }
    @media (max-width: 991.98px) {
        .p-wrap > .p-side { display: none; }
        .p-head .inner { padding: 14px 16px 50px; } .p-brand .long { display: none; }
        .p-logo { width: 66px; height: 66px; top: 10px; } .p-logo img { width: 60px; height: 60px; }
        .p-card-ico { width: 44px !important; height: 44px !important; } .p-card-ico i { font-size: 1.4rem !important; }
        .p-card-val { font-size: 1.25rem !important; } .p-bar { font-size: .95rem; padding: 10px 14px !important; } .p-bar .fs-5 { font-size: 1rem !important; }
        .p-hide-sm { display: none; }
        .table { font-size: .8rem; } .table > :not(caption) > * > * { padding: .45rem .35rem; } .table .ps-4 { padding-left: .6rem !important; }
        .table .btn-sm { padding: .15rem .55rem; font-size: .75rem; }
        .p-user .who { display: none; }
        .p-wrap { padding: 12px 14px 0; } .p-title { font-size: 1.35rem; margin-bottom: 16px; }
        .p-foot { font-size: .8rem; padding: 14px; }
    }
    .offcanvas .p-side { width: auto; padding-bottom: 0; }
</style></head><body>
<header class="p-head"><div class="inner">
    <div class="d-flex align-items-center gap-2">
        <?php if ($customer) { ?><button class="btn btn-sm btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#pMenu" aria-label="Menu"><i class="bi bi-list"></i></button><?php } ?>
        <a href="index.php" class="text-white text-decoration-none p-brand">RISE MOTIVE<span class="long"><span class="sep">|</span>CUSTOMER PORTAL</span></a>
    </div>
    <?php if ($customer) { ?>
    <div class="p-user">
        <div class="dropdown"><a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"><span class="avatar"><i class="bi bi-person-fill"></i></span>
            <span class="who"><strong><?= portal_e($customer['name']); ?></strong><br><small><?= portal_e(portal_customer_code((int) $customer['id'])); ?></small></span></a>
            <ul class="dropdown-menu dropdown-menu-end shadow"><li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My profile</a></li><li><a class="dropdown-item" href="notifications.php"><i class="bi bi-bell me-2"></i>Notifications</a></li>
                <li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li></ul></div>
        <a href="notifications.php" class="p-bell" title="Notifications"><i class="bi bi-bell"></i><?php if ($unread) { ?><span class="badge rounded-pill bg-danger"><?= $unread; ?></span><?php } ?></a>
    </div>
    <?php } ?>
</div>
<a href="index.php" class="p-logo" aria-label="RISE MOTIVE"><?php if ($logo) { ?><img src="<?= portal_e($logo); ?>" alt="RISE MOTIVE"><?php } else { ?><i class="bi bi-shield-check fs-1" style="color:#1E2FE0;"></i><?php } ?></a>
</header>
<?php
    $side = function (string $suffix) use ($menu, $here) {
        echo '<nav class="p-side">';
        foreach ($menu as $m) {
            if (is_array($m[2])) {
                $open = in_array($here, array_column($m[2], 0), true);
                echo '<a href="#rmServices' . $suffix . '" data-bs-toggle="collapse" aria-expanded="' . ($open ? 'true' : 'false') . '"><i class="bi bi-' . $m[1] . '"></i>' . $m[0] . ' <i class="bi bi-chevron-down ms-auto" style="font-size:.85rem;"></i></a>';
                echo '<div class="collapse sub' . ($open ? ' show' : '') . '" id="rmServices' . $suffix . '">';
                foreach ($m[2] as [$f, $icon, $label]) { echo '<a href="' . $f . '" class="' . ($here === $f ? 'active' : '') . '">' . $label . '</a>'; }
                echo '</div>';
            } else {
                echo '<a href="' . $m[0] . '" class="' . ($here === $m[0] ? 'active' : '') . '"><i class="bi bi-' . $m[1] . '"></i>' . $m[2] . '</a>';
            }
        }
        echo '<a href="logout.php"><i class="bi bi-box-arrow-left"></i>Logout</a>';
        if ($suffix === 'D') { echo '<div class="wave"></div>'; }
        echo '</nav>';
    };
    if ($customer) { ?>
<div class="offcanvas offcanvas-start" tabindex="-1" id="pMenu" style="width:270px;"><div class="offcanvas-header"><strong>Menu</strong><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>
    <div class="offcanvas-body"><?php $side('M'); ?></div></div>
<?php } ?>
<div class="p-wrap">
<?php if ($customer) { $side('D'); } ?>
<main class="p-main<?= $customer ? '' : ' mx-auto'; ?>" style="<?= $customer ? '' : 'max-width:1100px;'; ?>">
<?php
}

function portal_footer(): void
{
    ?></main>
</div>
<footer class="p-foot"><i class="bi bi-shield-fill-check fs-4"></i><span>Your information is shown only to you <span class="opacity-50">|</span> Secure &amp; Trusted <span class="opacity-50">|</span> <strong>Rise Beyond Limits.</strong></span></footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html><?php
}

function portal_alerts(): void
{
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $cls) {
        if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $cls . '">' . portal_e($_GET[$k]) . '</div>'; }
    }
}