<?php
/**
 * NEW FILE: modules/customer_first/common.php — shared by the RM CUSTOMER FIRST pages (Task 3).
 * Permissions: customer_first.record (record and handle requests — every employee), customer_first.manage (see every
 * case and the monitoring dashboard — Admin, Manager). An employee always sees the cases they handle or that were
 * escalated to them.
 */
if (session_status() === PHP_SESSION_NONE) { require_once __DIR__ . '/../../includes/session_boot.php'; rm_session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/access_control.php';
require_once __DIR__ . '/../../includes/cf_helpers.php';
require_once __DIR__ . '/../../includes/branch_helpers.php';
require_role(['Admin', 'Manager', 'Employee']);
cf_ensure($conn);
try { cf_overdue_check($conn); } catch (Throwable $e) { error_log('cf_overdue_check: ' . $e->getMessage()); }

function cfc(mysqli $conn, string $a): bool { return access_can('customer_first.' . $a, $conn); }
function cf_require(mysqli $conn, string $a): void { if (!cfc($conn, $a)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; } }
function cf_csrf_field(): string { if (empty($_SESSION['cf_csrf'])) { $_SESSION['cf_csrf'] = bin2hex(random_bytes(16)); } return '<input type="hidden" name="csrf" value="' . $_SESSION['cf_csrf'] . '">'; }
function cf_csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['cf_csrf']) && hash_equals($_SESSION['cf_csrf'], (string) $_POST['csrf']); }
function cf_go(string $url, string $type, string $msg): void { header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . $type . '=' . urlencode($msg)); exit; }

/** Cases this user may see (SQL on alias c). */
function cf_scope_sql(mysqli $conn): string
{
    $u = (int) cf_uid();
    $own = "(c.handled_by = $u OR EXISTS (SELECT 1 FROM cf_escalations e WHERE e.case_id = c.id AND e.to_user = $u))";
    if (!cfc($conn, 'manage')) { return $own; }
    if (!br_enabled($conn)) { return '1=1'; }
    $a = br_allowed_ids($conn);
    return $a === null ? '1=1' : '(' . $own . ' OR COALESCE(c.branch_id, ' . (int) br_main_id($conn) . ') IN (' . implode(',', array_map('intval', $a ?: [0])) . '))';
}

function cf_tabs(mysqli $conn, string $active, string $title, string $right = ''): void
{
    $u = (int) cf_uid();
    $mine = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM cf_escalations WHERE to_user = $u AND status IN ('Open', 'More information')"))[0];
    $tabs = [['new.php', 'New customer request', 'record'], ['cases.php', 'Requests', 'record'], ['cases.php?view=escalated', 'Escalated to me' . ($mine ? ' (' . $mine . ')' : ''), 'record'], ['index.php', 'Monitoring', 'manage']];
    echo '<style>.cf-tabs{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:2px;border-bottom:1px solid #E4E7EF;margin-bottom:14px;scrollbar-width:none}.cf-tabs::-webkit-scrollbar{display:none}.cf-tabs a{padding:7px 12px;font-size:13.5px;color:#5B6275;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap}
        .cf-tabs a.on{color:#1E2FE0;border-bottom-color:#1E2FE0;font-weight:600}.cf-k .lbl{font-size:12px;color:#6B7280}.cf-k .val{font-weight:700;font-size:1.25rem;color:#0B1A8C}.cf-k .sub{font-size:11.5px;color:#8A90A2}.cf-tbl td,.cf-tbl th{font-size:13px;vertical-align:middle}</style>';
    echo '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2"><div><h3 class="mb-0">' . cf_e($title) . '</h3><div class="small text-muted">RM Customer First · don\'t lose a customer without exploring every possible solution</div></div>' . $right . '</div>';
    echo '<div class="cf-tabs">'; foreach ($tabs as [$f, $l, $p]) { if (cfc($conn, $p)) { echo '<a href="' . $f . '" class="' . ($f === $active ? 'on' : '') . '">' . $l . '</a>'; } } echo '</div>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . ' py-2 small">' . cf_e($_GET[$k]) . '</div>'; } }
}

function cf_users(mysqli $conn): array { return mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names, role, department_id FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC); }
function cf_depts(mysqli $conn): array { try { return array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC), 'name', 'id'); } catch (Throwable $e) { return []; } }