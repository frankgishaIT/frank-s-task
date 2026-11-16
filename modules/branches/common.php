<?php
/**
 * NEW FILE: modules/branches/common.php — shared by the Branch Management pages.
 * Access (checked on the server, not only by hiding buttons):
 *   Head Office  = company-wide access (all branches) AND permission "branches.manage":
 *                  register branches, change status, settings, approve transfers.
 *   HR / managers = permission "branches.assign": employee assignments, transfer requests (for branches they can see).
 *   Everyone with "branches.view": dashboard and profiles of the branches they may see.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/access_control.php';
require_once __DIR__ . '/../../includes/branch_helpers.php';
if (current_user_role() === 'Admin') { require_once __DIR__ . '/../../includes/admin_error_guard.php'; }
br_tables($conn);

function br_head_office(mysqli $conn): bool
{
    return br_enabled($conn) && br_allowed_ids($conn) === null && access_can('branches.manage', $conn);
}

function br_deny(string $why = 'You do not have access to this branch information.'): void
{
    http_response_code(403);
    include __DIR__ . '/../../includes/access_denied.php';
    exit;
}

function br_require_setup(mysqli $conn): void
{
    if (!br_enabled($conn)) {
        header('Location: setup.php');
        exit;
    }
}

function br_csrf(): string { if (empty($_SESSION['br_csrf'])) { $_SESSION['br_csrf'] = bin2hex(random_bytes(16)); } return $_SESSION['br_csrf']; }
function br_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . br_e(br_csrf()) . '">'; }
function br_csrf_ok(): bool { return !empty($_POST['csrf']) && hash_equals($_SESSION['br_csrf'] ?? '', (string) $_POST['csrf']); }

function br_users(mysqli $conn, bool $activeOnly = true): array
{
    $out = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT u.id, u.names, u.role, u.email, u.is_active, ' . (br_has_column($conn, 'users', 'branch_id') ? 'u.branch_id' : 'NULL branch_id') . ', u.department_id, d.name department FROM users u LEFT JOIN departments d ON d.id = u.department_id'
        . ($activeOnly ? ' WHERE u.is_active = 1' : '') . ' ORDER BY u.names'), MYSQLI_ASSOC) as $u) { $out[(int) $u['id']] = $u; }
    return $out;
}

function br_tabs(mysqli $conn, string $active): void
{
    $ho = br_head_office($conn);
    $tabs = [['index.php', 'Dashboard', 'bi-speedometer2', true], ['branches.php', 'Branches', 'bi-diagram-3', true],
        ['assignments.php', 'Employee Assignments', 'bi-people', access_can('branches.assign', $conn)],
        ['transfers.php', 'Transfers', 'bi-arrow-left-right', access_can('branches.assign', $conn) || access_can('branches.approve', $conn)],
        ['audit.php', 'Audit Trail', 'bi-journal-text', $ho], ['settings.php', 'Settings', 'bi-gear', $ho]];
    echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><h2 class="mb-0">Branch Management</h2><ul class="nav nav-pills small">';
    foreach ($tabs as [$f, $l, $i, $show]) {
        if ($show) { echo '<li class="nav-item"><a class="nav-link py-1 px-3' . ($f === $active ? ' active' : '') . '" href="' . $f . '"><i class="bi ' . $i . ' me-1"></i>' . $l . '</a></li>'; }
    }
    echo '</ul></div>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) {
        if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . ' alert-dismissible fade show" style="white-space:pre-line;">' . br_e($_GET[$k]) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'; }
    }
}

/** Redirect with a message. */
function br_go(string $url, string $kind, string $msg): void
{
    header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . $kind . '=' . urlencode($msg));
    exit;
}