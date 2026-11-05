<?php
// modules/budget/budget_common.php — shared by the Budget & Fund Control pages.
require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/budget_helpers.php';
require_once __DIR__ . '/../../includes/access_control.php';
bdg_ensure($conn);
if (!access_can('budget.view', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$me = (int) current_user_id();
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$rwf = function ($v) { return 'RWF ' . number_format((float) $v, 0); };
function bdg_tabs(string $active): void {
    $t = ['index.php' => 'Dashboard', 'budgets.php' => 'Budgets', 'commitments.php' => 'Reserved funds', 'funds.php' => 'Funds & transfers'];
    if (access_can('budget.reports')) { $t['reports.php'] = 'Reports'; }
    $t['audit.php'] = 'Audit trail';
    if (access_can('budget.manage')) { $t['settings.php'] = 'Alert settings'; }
    echo '<h2 class="mb-2">Budget &amp; Fund Control</h2><ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">';
    foreach ($t as $f => $l) { echo '<li class="nav-item"><a class="nav-link text-nowrap' . ($active === $f ? ' active' : '') . '" href="' . $f . '">' . $l . '</a></li>'; }
    echo '</ul>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . '">' . htmlspecialchars($_GET[$k], ENT_QUOTES, 'UTF-8') . '</div>'; } }
}
function bdg_lists(mysqli $conn): array {
    return ['departments' => mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC),
            'projects' => mysqli_fetch_all(mysqli_query($conn, 'SELECT id, ' . bdg_project_name_col($conn) . ' AS name FROM projects ORDER BY 2'), MYSQLI_ASSOC),
            'funds' => funds_all($conn),
            'categories' => is_file(__DIR__ . '/../../includes/category_helpers.php') ? (function () use ($conn) { require_once __DIR__ . '/../../includes/category_helpers.php'; return categories_active($conn, 'expense'); })() : fund_expense_categories()];
}