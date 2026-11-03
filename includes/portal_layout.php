<?php
/**
 * includes/portal_layout.php — the RM Customer Portal page frame.
 * CHANGED (UX/UI task): RISE MOTIVE logo at the top left, "RM CUSTOMER PORTAL" centered, the customer's name / ID and
 * notifications at the right; left menu with "RM Mutual Growth"; the Services & Products Pricing pop-up on every page;
 * a centered bold footer on the brand blue. One font family (Nunito Sans) with one size scale everywhere.
 */
function portal_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function portal_money($v): string { return 'RWF ' . number_format((float) $v, 2); }

/** The official logo (the staff system's logo file). */
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
        ['#prices', 'tags', 'Services & Products Pricing'],
        ['RM Mutual Growth', 'people', [['loans.php', 'cash-coin', 'RM Loans'], ['invest.php', 'graph-up-arrow', 'RM Invest'], ['rewards.php', 'gift', 'Request Loyalty Reward']]],
        ['my_investments.php', 'graph-up', 'My Investments'],
        ['documents.php', 'folder2-open', 'My Documents'],
    ];
    if (!empty($GLOBALS['PORTAL_RECORDS_ON'])) { $menu[] = ['records.php', 'award', 'My Records']; }
    $menu[] = ['profile.php', 'person', 'My Profile'];
    $menu[] = ['requests.php', 'headset', 'Support'];
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= portal_e($title); ?> · RM Customer Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Nunito+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
    /* One font family and one size scale for the whole portal. */
    :root { --rm-blue: #1E2FE0; --rm-deep: #0B1A8C; --rm-ink: #1E2333; --rm-soft: #EEF3FF; --rm-green: #0FA968;
            --fs-page: 1.75rem; --fs-section: 1.15rem; --fs-body: .95rem; --fs-small: .82rem; }
    html, body, button, input, select, textarea, .btn, .form-control, .form-select, .table, .dropdown-menu, .modal { font-family: 'Nunito Sans', system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; }
    body { background: #F6F8FC; color: var(--rm-ink); font-size: var(--fs-body); }
    h1, .h4, .h5 { font-size: var(--fs-page); font-weight: 800; color: var(--rm-deep); }
    h2, .h6 { font-size: var(--fs-section); font-weight: 700; color: var(--rm-deep); }
    .small, small { font-size: var(--fs-small); }
    .btn { border-radius: 10px; font-weight: 600; }
    .btn-primary { background: var(--rm-blue); border-color: var(--rm-blue); } .btn-primary:hover { background: var(--rm-deep); border-color: var(--rm-deep); }
    .btn-outline-primary { color: var(--rm-blue); border-color: var(--rm-blue); } .btn-outline-primary:hover { background: var(--rm-blue); border-color: var(--rm-blue); }
    .btn-check:checked + .btn-outline-primary, .btn-outline-primary.active { background: var(--rm-blue); border-color: var(--rm-blue); color: #fff; }
    .form-control, .form-select { border-radius: 10px; border-color: #D8DCE8; } .form-control:focus, .form-select:focus { border-color: var(--rm-blue); box-shadow: 0 0 0 .2rem rgba(30,47,224,.12); }
    .card { border: 0; box-shadow: 0 2px 10px rgba(16,24,64,.06); border-radius: 14px; }
    a { color: var(--rm-blue); }
    /* Header: logo left, title centered, account right. */
    .p-head { position: relative; background: linear-gradient(100deg, var(--rm-deep) 0%, var(--rm-blue) 55%, #3D5BFF 100%); color: #fff; overflow: hidden; }
    .p-head::after { content: ""; position: absolute; left: -10%; right: -10%; bottom: -40px; height: 64px; background: #F6F8FC; border-radius: 50% 50% 0 0 / 100% 100% 0 0; }
    .p-head .inner { position: relative; display: grid; grid-template-columns: 1fr auto 1fr; align-items: center; gap: 12px; padding: 16px 28px 44px; }
    .p-logo { display: inline-flex; align-items: center; gap: 10px; text-decoration: none; }
    /* CHANGED: the logo sits directly on the blue header (no white circle or shadow around it). */
    .p-logo .disc { width: 78px; height: 78px; display: flex; align-items: center; justify-content: center; background: transparent; }
    .p-logo img { width: 78px; height: 78px; object-fit: contain;
        /* Only the white drawing of the logo shows: its own blue square becomes black, and "screen" makes black
           transparent, so the header's blue shows through (one blue, no square). */
        filter: grayscale(1) contrast(4); mix-blend-mode: screen; }
    .p-title-head { color: #fff; font-weight: 800; letter-spacing: .06em; font-size: 1.45rem; text-align: center; margin: 0; white-space: nowrap; }
    .p-user { justify-self: end; display: flex; align-items: center; gap: 18px; }
    .p-user .dropdown-toggle { color: #fff; text-decoration: none; display: flex; align-items: center; gap: 10px; }
    .p-user .avatar { width: 42px; height: 42px; border-radius: 50%; background: rgba(255,255,255,.95); color: var(--rm-blue); display: flex; align-items: center; justify-content: center; font-size: 1.5rem; }
    .p-user .who { line-height: 1.2; font-size: var(--fs-body); } .p-user .who small { opacity: .8; }
    .p-bell { color: #fff; font-size: 1.5rem; position: relative; } .p-bell .badge { position: absolute; top: -4px; right: -9px; font-size: .62rem; }
    /* Left menu */
    .p-wrap { display: flex; gap: 28px; padding: 14px 28px 0; }
    .p-side { width: 240px; flex-shrink: 0; position: relative; padding-bottom: 120px; }
    .p-side a { display: flex; align-items: center; gap: 14px; padding: 11px 16px; border-radius: 12px; color: var(--rm-ink); text-decoration: none; font-weight: 600; margin-bottom: 4px; font-size: var(--fs-body); }
    .p-side a i { font-size: 1.3rem; color: var(--rm-blue); width: 26px; text-align: center; }
    .p-side a:hover { background: var(--rm-soft); }
    .p-side a.active { background: linear-gradient(90deg, var(--rm-deep), var(--rm-blue)); color: #fff; box-shadow: 0 6px 16px rgba(30,47,224,.3); } .p-side a.active i { color: #fff; }
    .p-side .sub a { padding: 8px 16px 8px 56px; font-weight: 500; }
    .p-side .wave { position: absolute; left: -28px; right: 0; bottom: 0; height: 110px; background: linear-gradient(135deg, var(--rm-blue), var(--rm-deep)); border-radius: 0 0 18px 0; clip-path: path('M0 60 C 90 10, 170 10, 270 0 L 270 110 L 0 110 Z'); }
    main.p-main { flex: 1; min-width: 0; padding-bottom: 28px; }
    .p-title { color: var(--rm-deep); font-weight: 800; text-align: center; font-size: var(--fs-page); margin: 4px 0 22px; }
    /* Footer: centered, bold, white on the brand blue. */
    .p-foot { margin-top: 18px; background: linear-gradient(90deg, var(--rm-deep), var(--rm-blue)); color: #fff; text-align: center; font-weight: 800; padding: 18px 16px; font-size: 1rem; letter-spacing: .01em; }
    .p-foot .sep { opacity: .55; margin: 0 12px; font-weight: 400; }
    /* Pricing pop-up */
    .price-row { display: flex; justify-content: space-between; align-items: center; gap: 12px; padding: 12px 4px; border-bottom: 1px solid #EEF1F7; }
    .price-row .nm { font-weight: 700; } .price-row .pr { font-weight: 800; color: var(--rm-deep); white-space: nowrap; text-align: right; }
    @media (max-width: 991.98px) {
        .p-wrap > .p-side { display: none; }
        .p-head .inner { padding: 12px 14px 40px; grid-template-columns: auto 1fr auto; }
        .p-logo .disc { width: 54px; height: 54px; } .p-logo img { width: 54px; height: 54px; }
        .p-title-head { font-size: .9rem; letter-spacing: .02em; white-space: normal; line-height: 1.15; } .p-user .who { display: none; } .p-user { gap: 10px; }
        .p-user .avatar { width: 34px; height: 34px; font-size: 1.15rem; } .p-user .dropdown-toggle::after { display: none; } .p-bell { font-size: 1.3rem; }
        .p-tile-val { font-size: 1.2rem !important; } .p-tile-lbl { font-size: .82rem !important; }
        .p-wrap { padding: 10px 14px 0; } .p-title { font-size: 1.3rem; margin-bottom: 16px; }
        .p-foot { font-size: .8rem; } .p-foot .sep { margin: 0 6px; }
        .p-card-ico { width: 44px !important; height: 44px !important; } .p-card-ico i { font-size: 1.4rem !important; }
        .p-card-val { font-size: 1.2rem !important; } .p-bar { padding: 10px 14px !important; } .p-bar .fs-5 { font-size: 1rem !important; }
        .p-hide-sm { display: none; }
        .table { font-size: .8rem; } .table > :not(caption) > * > * { padding: .45rem .35rem; } .table .ps-4 { padding-left: .6rem !important; } .table .btn-sm { padding: .15rem .55rem; font-size: .75rem; }
    }
    .offcanvas .p-side { width: auto; padding-bottom: 0; }
</style></head><body>
<header class="p-head"><div class="inner">
    <div class="d-flex align-items-center gap-2">
        <?php if ($customer) { ?><button class="btn btn-sm btn-light d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#pMenu" aria-label="Menu"><i class="bi bi-list"></i></button><?php } ?>
        <a href="index.php" class="p-logo" aria-label="RISE MOTIVE"><span class="disc"><?php if ($logo) { ?><img src="<?= portal_e($logo); ?>" alt="RISE MOTIVE"><?php } else { ?><i class="bi bi-shield-check fs-2 text-white"></i><?php } ?></span></a>
    </div>
    <p class="p-title-head">RM CUSTOMER PORTAL</p>
    <?php if ($customer) { ?>
    <div class="p-user">
        <div class="dropdown"><a href="#" class="dropdown-toggle" data-bs-toggle="dropdown" data-bs-popper-config='{"strategy":"fixed"}' aria-expanded="false"><span class="avatar"><i class="bi bi-person-fill"></i></span>
            <span class="who"><strong><?= portal_e($customer['name']); ?></strong><br><small><?= portal_e(portal_customer_code((int) $customer['id'])); ?></small></span></a>
            <ul class="dropdown-menu dropdown-menu-end shadow"><li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>My profile</a></li><li><a class="dropdown-item" href="notifications.php"><i class="bi bi-bell me-2"></i>Notifications</a></li>
                <li><hr class="dropdown-divider"></li><li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li></ul></div>
        <a href="notifications.php" class="p-bell" title="Notifications"><i class="bi bi-bell"></i><?php if ($unread) { ?><span class="badge rounded-pill bg-danger"><?= $unread; ?></span><?php } ?></a>
    </div>
    <?php } else { ?><div></div><?php } ?>
</div></header>
<?php
    $side = function (string $suffix) use ($menu, $here) {
        echo '<nav class="p-side">';
        foreach ($menu as $m) {
            if (is_array($m[2])) {
                $open = in_array($here, array_column($m[2], 0), true);
                echo '<a href="#mg' . $suffix . '" data-bs-toggle="collapse" aria-expanded="' . ($open ? 'true' : 'false') . '"><i class="bi bi-' . $m[1] . '"></i>' . $m[0] . ' <i class="bi bi-chevron-down ms-auto" style="font-size:.85rem;"></i></a>';
                echo '<div class="collapse sub' . ($open ? ' show' : '') . '" id="mg' . $suffix . '">';
                foreach ($m[2] as [$f, $icon, $label]) { echo '<a href="' . $f . '" class="' . ($here === $f ? 'active' : '') . '">' . $label . '</a>'; }
                echo '</div>';
            } elseif ($m[0] === '#prices') {
                echo '<a href="#" data-bs-toggle="modal" data-bs-target="#pricesModal"><i class="bi bi-' . $m[1] . '"></i>' . $m[2] . '</a>';
            } else {
                echo '<a href="' . $m[0] . '" class="' . ($here === $m[0] ? 'active' : '') . '"><i class="bi bi-' . $m[1] . '"></i>' . $m[2] . '</a>';
            }
        }
        echo '<a href="logout.php"><i class="bi bi-box-arrow-left"></i>Logout</a>';
        if ($suffix === 'D') { echo '<div class="wave"></div>'; }
        echo '</nav>';
    };
    if ($customer) { ?>
<div class="offcanvas offcanvas-start" tabindex="-1" id="pMenu" style="width:280px;"><div class="offcanvas-header"><strong>Menu</strong><button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Close"></button></div>
    <div class="offcanvas-body"><?php $side('M'); ?></div></div>
<?php } ?>
<div class="p-wrap">
<?php if ($customer) { $side('D'); } ?>
<main class="p-main<?= $customer ? '' : ' mx-auto'; ?>" style="<?= $customer ? '' : 'max-width:1100px; width:100%;'; ?>">
<?php
}

function portal_footer(): void
{
    $signedIn = !empty($_SESSION['portal_customer_id']);
    ?></main>
</div>
<footer class="p-foot">Your Information Is Shown Only to You<span class="sep">|</span>Secure &amp; Trusted<span class="sep">|</span>Rise Beyond Limits</footer>
<?php if ($signedIn) { ?>
<!-- Services & Products Pricing: live from MY MOTIVE's registered services and products. -->
<div class="modal fade" id="pricesModal" tabindex="-1" aria-labelledby="pricesTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable modal-fullscreen-sm-down"><div class="modal-content" style="border-radius:16px;">
    <div class="modal-header text-white" style="background:linear-gradient(90deg,#0B1A8C,#1E2FE0);"><h2 class="modal-title text-white mb-0" id="pricesTitle"><i class="bi bi-tags me-2"></i>Services &amp; Products Pricing</h2><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div class="btn-group w-100 mb-3" role="group" aria-label="Category">
            <input type="radio" class="btn-check" name="pType" id="pServices" value="Service" checked><label class="btn btn-outline-primary" for="pServices"><i class="bi bi-tools me-1"></i>Services</label>
            <input type="radio" class="btn-check" name="pType" id="pProducts" value="Product"><label class="btn btn-outline-primary" for="pProducts"><i class="bi bi-box-seam me-1"></i>Products</label>
        </div>
        <div class="row g-2 mb-2"><div class="col-sm-7"><input type="search" id="pSearch" class="form-control" placeholder="Search by name or keyword…" aria-label="Search"></div>
            <div class="col-sm-5"><select id="pCat" class="form-select" aria-label="Category filter"><option value="">All categories</option></select></div></div>
        <div id="pList"><div class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>Loading the latest prices…</div></div>
        <div class="small text-muted mt-3" id="pNote"></div>
    </div>
</div></div></div>
<?php } ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if ($signedIn) { ?>
<script>
(function () {
    var data = null, modal = document.getElementById('pricesModal');
    function money(v) { return 'RWF ' + Number(v).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 }); }
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
    function type() { return document.querySelector('input[name=pType]:checked').value; }
    function cats() {
        var sel = document.getElementById('pCat'), cur = sel.value, set = {};
        data.items.forEach(function (i) { if (i.type === type() && i.category) { set[i.category] = 1; } });
        sel.innerHTML = '<option value="">All categories</option>' + Object.keys(set).sort().map(function (c) { return '<option' + (c === cur ? ' selected' : '') + '>' + esc(c) + '</option>'; }).join('');
    }
    function render() {
        var q = document.getElementById('pSearch').value.trim().toLowerCase(), cat = document.getElementById('pCat').value;
        var rows = data.items.filter(function (i) { return i.type === type() && (!cat || i.category === cat) && (!q || (i.name + ' ' + (i.category || '') + ' ' + (i.description || '')).toLowerCase().indexOf(q) !== -1); });
        document.getElementById('pList').innerHTML = rows.length ? rows.map(function (i) {
            return '<div class="price-row"><div><div class="nm">' + esc(i.name) + '</div><div class="small text-muted">' + esc(i.category || '') + (i.description ? ' · ' + esc(i.description) : '') + '</div></div>'
                + '<div class="pr">' + money(i.price) + '<div class="small text-muted fw-normal">per ' + esc(i.unit || 'unit') + '</div>'
                + (i.packs || []).map(function (p) { return '<div class="small fw-semibold">' + money(p.price) + ' <span class="text-muted fw-normal">per ' + esc(p.unit) + ' (' + p.qty + ' ' + esc(i.unit) + ')</span></div>'; }).join('') + '</div></div>';
        }).join('') : '<div class="text-center text-muted py-4">No ' + (type() === 'Service' ? 'service' : 'product') + ' matches your search.</div>';
    }
    function load() {
        fetch('prices.php', { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            data = d; cats(); render();
            document.getElementById('pNote').textContent = 'Prices as registered in MY MOTIVE, updated ' + d.updated + '. Prices may change; the price on your invoice applies.';
        }).catch(function () { document.getElementById('pList').innerHTML = '<div class="alert alert-danger">The prices could not be loaded. Please try again.</div>'; });
    }
    modal.addEventListener('show.bs.modal', function () { load(); }); // always the latest prices when opened
    document.querySelectorAll('input[name=pType]').forEach(function (r) { r.addEventListener('change', function () { if (data) { cats(); render(); } }); });
    document.getElementById('pSearch').addEventListener('input', function () { if (data) { render(); } });
    document.getElementById('pCat').addEventListener('change', function () { if (data) { render(); } });
})();
</script>
<?php } ?>
</body></html><?php
}

function portal_alerts(): void
{
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $cls) {
        if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $cls . '">' . portal_e($_GET[$k]) . '</div>'; }
    }
}