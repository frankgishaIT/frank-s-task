<?php
// modules/rm_invest/invest_common.php — shared by the RM Investment Management pages (staff side of RM Invest).
require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/portal_helpers.php';
require_once __DIR__ . '/../../includes/inv_helpers.php';
require_once __DIR__ . '/../../includes/access_control.php';
inv_ensure($conn);
if (!rm_feature_enabled('customer_invest') || !access_can('rm_invest.view', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$me = (int) current_user_id();
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
function inv_tabs(string $active): void {
    $t = ['index.php' => 'Projects', 'applications.php' => 'Applications & contributions'];
    if (access_can('rm_invest.reports')) { $t['reports.php'] = 'Reports'; }
    $t['audit.php'] = 'Audit log';
    if (access_can('rm_invest.manage')) { $t['settings.php'] = 'Settings'; }
    echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"><h2 class="mb-0">RM Investment Management</h2>'
       . (inv_testing($GLOBALS['conn']) ? '<span class="badge bg-warning">Testing mode: no contribution payments or payouts</span>' : '<span class="badge bg-danger">LIVE: real contributions</span>') . '</div>';
    echo '<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">';
    foreach ($t as $f => $l) { echo '<li class="nav-item"><a class="nav-link text-nowrap' . ($active === $f ? ' active' : '') . '" href="' . $f . '">' . $l . '</a></li>'; }
    echo '</ul>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . '">' . htmlspecialchars($_GET[$k], ENT_QUOTES, 'UTF-8') . '</div>'; } }
}
function inv_badge(string $s): string {
    $c = in_array($s, ['Rejected', 'Cancelled', 'Refunded'], true) ? 'bg-danger' : (in_array($s, ['Published', 'Active', 'Confirmed', 'Active Investor', 'Completed', 'Verified', 'Paid'], true) ? 'bg-success' : (in_array($s, ['Draft', 'Paused'], true) ? 'bg-secondary' : 'bg-primary'));
    return '<span class="badge ' . $c . '">' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</span>';
}