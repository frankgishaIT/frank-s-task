<?php
/**
 * NEW FILE: includes/portal_layout.php — the customer portal's own page frame (mobile-friendly).
 */
function portal_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function portal_money($v): string { return 'RWF ' . number_format((float) $v, 2); }

function portal_header(string $title, ?array $customer = null): void
{
    // CHANGED: one compact menu (grouped), collapsing into a single "Menu" button on phones.
    $groups = [
        ['index.php', 'house', 'Home'],
        ['lookup.php', 'search', 'Find invoice'],
        ['RM Services', 'grid', [['loans.php', 'cash-coin', 'RM Loans'], ['invest.php', 'graph-up-arrow', 'RM Invest'], ['my_investments.php', 'briefcase', 'My investments']]],
        ['My account', 'person-circle', array_values(array_filter([['history.php', 'clock-history', 'My history'], ['documents.php', 'folder2-open', 'My documents'],
            !empty($GLOBALS['PORTAL_RECORDS_ON']) ? ['records.php', 'award', 'My records'] : null, ['requests.php', 'chat-left-text', 'Requests'], ['profile.php', 'person', 'Profile']]))],
    ];
    $here = basename($_SERVER['SCRIPT_NAME'] ?? '');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= portal_e($title); ?> · RISE MOTIVE Customer Portal</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<style>
    body { background: #F4F6FB; color: #1E2333; }
    .p-top { background: #1E2FE0; color: #fff; }
    .p-top a { color: #fff; text-decoration: none; }
    .p-nav .nav-link { color: #1E2333; padding: .5rem .75rem !important; border-radius: 8px; white-space: nowrap; font-size: .92rem; }
    .p-nav .nav-link.active, .p-nav .nav-link:hover, .p-nav .show > .nav-link { background: #E6E8FD; color: #1E2FE0; }
    .p-nav .dropdown-item.active, .p-nav .dropdown-item:active { background: #1E2FE0; }
    .navbar-toggler { border: 1px solid #D8DCE8; font-size: .9rem; }
    .card { border: 0; box-shadow: 0 1px 3px rgba(16,24,64,.08); border-radius: 12px; }
    .btn-primary { background: #1E2FE0; border-color: #1E2FE0; }
    .table { font-size: .9rem; }
</style></head><body>
<div class="p-top"><div class="container d-flex justify-content-between align-items-center py-3">
    <a href="index.php" class="fw-bold fs-5"><i class="bi bi-shield-check me-1"></i>RISE MOTIVE · Customer Portal</a>
    <?php if ($customer) { ?><div class="small text-end"><?= portal_e($customer['name']); ?><br><span class="opacity-75"><?= portal_e(portal_customer_code((int) $customer['id'])); ?></span> · <a href="logout.php" class="text-decoration-underline">Sign out</a></div><?php } ?>
</div></div>
<?php if ($customer) { $unread = (int) ($GLOBALS['PORTAL_UNREAD'] ?? 0); ?>
<nav class="navbar navbar-expand-md bg-white border-bottom py-1"><div class="container">
    <button class="navbar-toggler btn-sm" type="button" data-bs-toggle="collapse" data-bs-target="#pNav" aria-controls="pNav" aria-expanded="false" aria-label="Menu"><i class="bi bi-list"></i> Menu</button>
    <a href="notifications.php" class="d-md-none ms-auto me-2 position-relative text-body fs-5" title="Notifications"><i class="bi bi-bell"></i><?php if ($unread) { ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $unread; ?></span><?php } ?></a>
    <div class="collapse navbar-collapse p-nav" id="pNav"><ul class="navbar-nav me-auto gap-md-1">
        <?php foreach ($groups as $g) { if (is_array($g[2])) { $active = in_array($here, array_column($g[2], 0), true); ?>
        <li class="nav-item dropdown"><a class="nav-link dropdown-toggle <?= $active ? 'active' : ''; ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false"><i class="bi bi-<?= $g[1]; ?> me-1"></i><?= $g[0]; ?></a>
            <ul class="dropdown-menu shadow-sm"><?php foreach ($g[2] as [$f, $icon, $label]) { ?><li><a class="dropdown-item <?= $here === $f ? 'active' : ''; ?>" href="<?= $f; ?>"><i class="bi bi-<?= $icon; ?> me-2"></i><?= $label; ?></a></li><?php } ?></ul></li>
        <?php } else { ?>
        <li class="nav-item"><a class="nav-link <?= $here === $g[0] ? 'active' : ''; ?>" href="<?= $g[0]; ?>"><i class="bi bi-<?= $g[1]; ?> me-1"></i><?= $g[2]; ?></a></li>
        <?php } } ?>
    </ul>
    <a href="notifications.php" class="d-none d-md-inline-block position-relative text-body fs-5 me-2 <?= $here === 'notifications.php' ? 'text-primary' : ''; ?>" title="Notifications"><i class="bi bi-bell"></i><?php if ($unread) { ?><span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:.6rem;"><?= $unread; ?></span><?php } ?></a>
    </div>
</div></nav>
<?php } ?>
<main class="container py-4">
<?php
}

function portal_footer(): void
{
    ?></main>
<footer class="container pb-4 small text-muted">RISE MOTIVE Ltd · Kicukiro, Kigali · www.risemotive.rw · Your information is shown only to you.</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html><?php
}

function portal_alerts(): void
{
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $cls) {
        if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $cls . '">' . portal_e($_GET[$k]) . '</div>'; }
    }
}