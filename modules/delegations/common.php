<?php
/**
 * NEW FILE: modules/delegations/common.php — shared by the Delegations pages.
 * Permissions (Roles & Permissions, module delegations):
 *   view (Tasker directory, comparison) · create (delegate a sale, record completion) · approve (approve delegations,
 *   costs, completion) · taskers (Tasker profiles, services, rates, offering settings) · finance (record and verify
 *   payments, reconcile) · settings (weights, profit-sharing basis, follow-up periods).
 */
if (session_status() === PHP_SESSION_NONE) { require_once __DIR__ . '/../../includes/session_boot.php'; rm_session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/access_control.php';
require_once __DIR__ . '/../../includes/dl_helpers.php';
require_once __DIR__ . '/../../includes/dl_workflow.php';
require_role(['Admin', 'Manager', 'Employee']);
dlw_ensure($conn);
try { dl_monitor($conn); } catch (Throwable $e) { error_log('dl_monitor: ' . $e->getMessage()); } // follow-ups, reminders, missed deadlines (every 10 min at most)

function dlc(mysqli $conn, string $a): bool { return access_can('delegations.' . $a, $conn); }
function dl_require(mysqli $conn, string $a): void
{
    if (!dlc($conn, $a)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
}
function dl_csrf_field(): string { if (empty($_SESSION['dl_csrf'])) { $_SESSION['dl_csrf'] = bin2hex(random_bytes(16)); } return '<input type="hidden" name="csrf" value="' . $_SESSION['dl_csrf'] . '">'; }
function dl_csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['dl_csrf']) && hash_equals($_SESSION['dl_csrf'], (string) $_POST['csrf']); }
function dl_go(string $url, string $type, string $msg): void { header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . $type . '=' . urlencode($msg)); exit; }
function dl_users(mysqli $conn): array { return array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users'), MYSQLI_ASSOC), 'names', 'id'); }

function dl_tabs(mysqli $conn, string $active, string $title, string $right = ''): void
{
    $tabs = [['index.php', 'Overview', 'view'], ['list.php', 'Delegations', 'view'], ['taskers.php', 'RM Taskers', 'view'], ['offerings.php', 'Services & Taskers', 'view'], ['compare.php', 'Compare', 'view'],
        ['lost.php', 'Unavailable requests', 'view'], ['settings.php', 'Settings', 'settings']];
    echo '<style>.dl-tabs{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:2px;border-bottom:1px solid #E4E7EF;margin-bottom:14px;scrollbar-width:none}.dl-tabs::-webkit-scrollbar{display:none}
        .dl-tabs a{padding:7px 12px;font-size:13.5px;color:#5B6275;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap}.dl-tabs a.on{color:#1E2FE0;border-bottom-color:#1E2FE0;font-weight:600}
        .dl-tbl td,.dl-tbl th{font-size:13px;vertical-align:middle}.dl-st{font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;white-space:nowrap;background:#F1F2F6;color:#4B5263}
        .dl-ok{background:#E1F7EE;color:#0B7A50}.dl-warn{background:#FFF1E0;color:#A04D00}.dl-bad{background:#FCEAEA;color:#B42318}.dl-blue{background:#E8EDFD;color:#1E2FE0}.dl-gold{background:#FFF4D6;color:#8A5A00}
        .dl-kpi{border:1px solid #EEF1F8;border-radius:12px;padding:10px 12px;background:#fff}.dl-kpi .v{font-size:18px;font-weight:700;color:#1E2333}.dl-kpi .l{font-size:12px;color:#6B7280}</style>';
    echo '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2"><div><h3 class="mb-0">' . dl_e($title) . '</h3><div class="small text-muted">Delegations · serve customers through qualified RM Taskers</div></div>' . $right . '</div>';
    echo '<div class="dl-tabs">';
    foreach ($tabs as [$f, $l, $p]) { if (dlc($conn, $p)) { echo '<a href="' . $f . '" class="' . ($f === $active ? 'on' : '') . '">' . $l . '</a>'; } }
    echo '</div>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . ' py-2 small">' . dl_e($_GET[$k]) . '</div>'; } }
}

function dl_status_chip(string $s): string
{
    $c = ['Active' => 'dl-ok', 'Available' => 'dl-ok', 'Inactive' => '', 'Limited' => 'dl-warn', 'Suspended' => 'dl-bad', 'Unavailable' => 'dl-bad'][$s] ?? '';
    return '<span class="dl-st ' . $c . '">' . dl_e($s) . '</span>';
}