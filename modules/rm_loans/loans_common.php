<?php
// modules/rm_loans/loans_common.php — shared by the RM Loan Management pages (staff side of RM Customer Loans).
require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/portal_helpers.php';
require_once __DIR__ . '/../../includes/cl_helpers.php';
require_once __DIR__ . '/../../includes/access_control.php';
cl_ensure($conn);
if (!rm_feature_enabled('customer_loans') || !access_can('rm_loans.view', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$me = (int) current_user_id();
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
function cl_tabs(string $active): void {
    $t = ['index.php' => 'Applications & loans'];
    if (access_can('rm_loans.reports')) { $t['reports.php'] = 'Portfolio reports'; }
    $t['audit.php'] = 'Audit log';
    if (access_can('rm_loans.manage')) { $t['settings.php'] = 'Settings'; }
    echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"><h2 class="mb-0">RM Credit Hub</h2>'
       . (cl_testing($GLOBALS['conn']) ? '<span class="badge bg-warning">Testing mode: assessment only, no disbursement</span>' : '<span class="badge bg-danger">LIVE lending</span>') . '</div>';
    echo '<ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">';
    foreach ($t as $f => $l) { echo '<li class="nav-item"><a class="nav-link text-nowrap' . ($active === $f ? ' active' : '') . '" href="' . $f . '">' . $l . '</a></li>'; }
    echo '</ul>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . '">' . htmlspecialchars($_GET[$k], ENT_QUOTES, 'UTF-8') . '</div>'; } }
}
function cl_badge(string $s): string {
    $c = in_array($s, ['Rejected', 'Overdue', 'Defaulted'], true) ? 'bg-danger' : (in_array($s, ['Active', 'Disbursed', 'Fully Repaid', 'Agreement Signed'], true) ? 'bg-success' : (in_array($s, ['Cancelled', 'Draft'], true) ? 'bg-secondary' : 'bg-primary'));
    return '<span class="badge ' . $c . '">' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</span>';
}