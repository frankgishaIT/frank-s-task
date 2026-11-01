<?php
// modules/portal/portal_common.php — shared by the staff pages of the Customer Portal.
require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/portal_helpers.php';
require_once __DIR__ . '/../../includes/access_control.php';
portal_ensure($conn);
if (!rm_feature_enabled('customer_portal') || !access_can('portal.view', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
function portal_staff_tabs(string $active): void {
    $tabs = ['index.php' => 'Requests', 'accounts.php' => 'Customer accounts', 'documents.php' => 'Published documents',
             'records.php' => 'Records', 'settings.php' => 'Settings & notices', 'activity.php' => 'Activity & lookups'];
    echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"><h2 class="mb-0">Customer Portal</h2>'
       . '<a href="../../portal/login.php" target="_blank" rel="noopener" class="rm-btn rm-btn-light rm-btn-sm"><i class="bi bi-box-arrow-up-right me-1"></i>Open the portal</a></div>';
    echo '<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">';
    foreach ($tabs as $f => $l) { echo '<li class="nav-item"><a class="nav-link text-nowrap' . ($active === $f ? ' active' : '') . '" href="' . $f . '">' . $l . '</a></li>'; }
    echo '</ul>';
}
function portal_staff_alerts(): void {
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . '">' . htmlspecialchars($_GET[$k], ENT_QUOTES, 'UTF-8') . '</div>'; } }
}