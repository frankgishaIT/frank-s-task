<?php
/**
 * NEW FILE: includes/portal_layout.php — the customer portal's own page frame (mobile-friendly).
 */
function portal_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function portal_money($v): string { return 'RWF ' . number_format((float) $v, 2); }

function portal_header(string $title, ?array $customer = null): void
{
    $nav = ['index.php' => ['house', 'Home'], 'lookup.php' => ['search', 'Find invoice'], 'history.php' => ['clock-history', 'My history'],
            'documents.php' => ['folder2-open', 'My documents'], 'requests.php' => ['chat-left-text', 'Requests'], 'profile.php' => ['person', 'Profile']];
    // NEW: RM Loans, RM Invest and the customer's notifications.
    if ($customer) { $nav = array_slice($nav, 0, 3, true) + ['loans.php' => ['cash-coin', 'RM Loans'], 'invest.php' => ['graph-up-arrow', 'RM Invest']] + array_slice($nav, 3, null, true); }
    if ($customer) { $nav['notifications.php'] = ['bell', 'Notifications' . (!empty($GLOBALS['PORTAL_UNREAD']) ? ' (' . (int) $GLOBALS['PORTAL_UNREAD'] . ')' : '')]; }
    if ($customer && !empty($GLOBALS['PORTAL_RECORDS_ON'])) { $nav = array_slice($nav, 0, 4, true) + ['records.php' => ['award', 'My records']] + array_slice($nav, 4, null, true); }
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
    .p-nav a { color: #1E2333; text-decoration: none; padding: .5rem .75rem; border-radius: 8px; white-space: nowrap; font-size: .92rem; }
    .p-nav a.active, .p-nav a:hover { background: #E6E8FD; color: #1E2FE0; }
    .card { border: 0; box-shadow: 0 1px 3px rgba(16,24,64,.08); border-radius: 12px; }
    .btn-primary { background: #1E2FE0; border-color: #1E2FE0; }
    .table { font-size: .9rem; }
</style></head><body>
<div class="p-top"><div class="container d-flex justify-content-between align-items-center py-3">
    <a href="index.php" class="fw-bold fs-5"><i class="bi bi-shield-check me-1"></i>RISE MOTIVE · Customer Portal</a>
    <?php if ($customer) { ?><div class="small text-end"><?= portal_e($customer['name']); ?><br><span class="opacity-75"><?= portal_e(portal_customer_code((int) $customer['id'])); ?></span> · <a href="logout.php" class="text-decoration-underline">Sign out</a></div><?php } ?>
</div></div>
<?php if ($customer) { ?>
<div class="bg-white border-bottom"><div class="container p-nav d-flex gap-1 overflow-auto py-2">
    <?php foreach ($nav as $f => [$icon, $label]) { ?><a href="<?= $f; ?>" class="<?= $here === $f ? 'active' : ''; ?>"><i class="bi bi-<?= $icon; ?> me-1"></i><?= $label; ?></a><?php } ?>
</div></div>
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