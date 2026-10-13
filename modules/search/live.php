<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
// NEW: only logged-in users can search (the endpoint had no check of its own).
require_role(['Admin', 'Manager', 'Employee']);

header('Content-Type: application/json');

function esc($v) {
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
}

$q = trim($_GET['q'] ?? '');
$scope = $_GET['scope'] ?? 'all';

// Scopes that have an exact-table renderer wired up (mirrors the real page
// table exactly). Only these support live in-place filtering.
$exactScopes = ['attendance', 'customers', 'departments', 'notifications', 'projects', 'sales', 'transactions', 'employees', 'leave', 'products', 'tasks'];

$role = function_exists('current_user_role') ? current_user_role() : null;
$isManager = in_array($role, ['Admin', 'Manager'], true);
$isAdminRole = $role === 'Admin';
$canManageAttendance = $isManager;
$isAdminSession = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
$userId = function_exists('current_user_id') ? (int) current_user_id() : 0;
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);

// NEW: the same access rules as the pages themselves. A scope this user may not open returns nothing.
$scopeAllowed = [
    'departments' => $isAdminRole,   // Departments page: Admin only
    'employees'   => $isAdminRole,   // Employees page: Admin only
];
if ($q === '' || mb_strlen($q) > 100 || !in_array($scope, $exactScopes, true) || (isset($scopeAllowed[$scope]) && !$scopeAllowed[$scope])) {
    echo json_encode(['empty' => true, 'html' => '']);
    exit;
}

$like = '%' . $q . '%';

// NEW: Customer ID (RMC000025) and receipt number (RM000047) searches.
$searchCustomerId = 0;
$searchSaleId = 0;
if (preg_match('/^RMC0*(\d+)$/i', $q, $m)) {
    $searchCustomerId = (int) $m[1];
} elseif (preg_match('/^RM0*(\d+)$/i', $q, $m)) {
    $searchSaleId = (int) $m[1];
}

function customer_code_txt(int $id): string { return 'RMC' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
function phone_ok_txt($p): bool { return (bool) preg_match('/^07\d{8}$/', preg_replace('/[\s\-]/', '', (string) $p)); }

/* ---------- shared little helpers, ported from each module's index.php ---------- */

function workflowBadgeHtml($status) {
    $map = [
        'Pending Clock-In Approval' => 'secondary',
        'Working' => 'info',
        'Pending Clock-Out Confirmation' => 'secondary',
        'Confirmed' => 'success',
        'Rejected' => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . esc($status ?: 'Not set') . '</span>';
}

function attendanceStatusBadgeHtml($status) {
    $class = $status === 'Present' ? 'success' : ($status === 'Late' ? 'warning text-dark' : 'secondary');
    return '<span class="badge bg-' . $class . '">' . esc($status) . '</span>';
}

/* ---------- exact-table renderers (mirror the real module page) ---------- */

// CHANGED: employees only find their OWN attendance (same as the Attendance page).
function renderAttendanceRows(mysqli $conn, string $like, int $limit, bool $canManage, int $userId): string
{
    $own = $canManage ? '' : ' AND attendance.user_id = ' . (int) $userId;
    $sql = "SELECT attendance.id, attendance.attendance_date, attendance.check_in, attendance.check_out,
                   attendance.status, attendance.workflow_status,
                   users.names AS employee_name, departments.name AS department_name
            FROM attendance
            INNER JOIN users ON attendance.user_id = users.id
            LEFT JOIN departments ON users.department_id = departments.id
            WHERE (users.names LIKE ? OR attendance.status LIKE ? OR attendance.workflow_status LIKE ?)$own
            ORDER BY attendance.attendance_date DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    foreach ($rows as $r) {
        $html .= '<tr>';
        $html .= '<td>' . esc(date('d M Y', strtotime($r['attendance_date']))) . '</td>';
        $html .= '<td>' . esc($r['employee_name']) . '</td>';
        $html .= '<td>' . esc($r['department_name'] ?? '—') . '</td>';
        $html .= '<td>' . ($r['check_in'] ? esc(date('H:i', strtotime($r['check_in']))) : '—') . '</td>';
        $html .= '<td>' . ($r['check_out'] ? esc(date('H:i', strtotime($r['check_out']))) : '—') . '</td>';
        $html .= '<td>' . attendanceStatusBadgeHtml($r['status']) . '</td>';
        $html .= '<td>' . workflowBadgeHtml($r['workflow_status']) . '</td>';
        if ($canManage) {
            $id = (int) $r['id'];
            $html .= '<td class="text-nowrap">';
            $html .= '<a href="../attendance/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning btn-sm">Edit</a> ';
            $html .= '<form method="POST" action="../attendance/delete.php" class="d-inline" onsubmit="return confirm(\'Delete this attendance record?\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn rm-btn-danger btn-sm">Delete</button>';
            $html .= '</form></td>';
        }
        $html .= '</tr>';
    }
    return $html;
}

function attendanceHeaderRow(bool $canManage): string
{
    $h = '<tr><th>Date</th><th>Employee</th><th>Department</th><th>Check In</th><th>Check Out</th><th>Status</th><th>Workflow</th>';
    if ($canManage) $h .= '<th>Action</th>';
    return $h . '</tr>';
}

// CHANGED: same columns as the updated Customers page (ID, location, points, credit, SMS), the same
// balance rule (only Credit and Partially Paid), and search by Customer ID (RMC000025) too.
function renderCustomersRows(mysqli $conn, string $like, int $limit, int $customerId, bool $isManager): string
{
    $sql = "SELECT customers.*,
                   COALESCE(SUM(CASE WHEN sales.status IN ('Credit', 'Partially Paid') THEN sales.total_amount - sales.amount_paid + sales.charges_accrued - sales.charges_paid ELSE 0 END), 0) AS balance
            FROM customers
            LEFT JOIN sales ON sales.customer_id = customers.id
            WHERE customers.is_active = 1 AND (customers.name LIKE ? OR customers.phone LIKE ? OR customers.email LIKE ?
                  OR customers.district LIKE ? OR customers.sector LIKE ? OR customers.id = ?)
            GROUP BY customers.id
            ORDER BY customers.name
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sssssi', $like, $like, $like, $like, $like, $customerId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $threshold = defined('LOYALTY_CREDIT_THRESHOLD') ? (int) LOYALTY_CREDIT_THRESHOLD : 500;
    $html = '';
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $points = (int) $r['loyalty_points'];
        $allowed = $points >= $threshold;
        $balance = (float) $r['balance'];
        $phoneOk = phone_ok_txt($r['phone'] ?? '');
        $location = implode(', ', array_filter([$r['sector'] ?? '', $r['district'] ?? '', $r['province'] ?? '']));

        $html .= '<tr>';
        $html .= '<td class="text-nowrap fw-semibold">' . esc(customer_code_txt($id)) . '</td>';
        $html .= '<td>' . esc($r['name']) . '</td>';
        $html .= '<td class="text-nowrap">' . esc($r['phone'] ?? '—')
            . ($phoneOk ? '' : ' <span class="badge bg-warning text-dark" title="This number cannot receive SMS. It must be 10 digits starting with 07.">Check number</span>') . '</td>';
        $html .= '<td>' . esc($r['email'] ?? '—') . '</td>';
        $html .= '<td>' . ($location !== '' ? esc($location) : '<span class="text-muted">—</span>') . '</td>';
        $html .= $balance > 0.009
            ? '<td><span class="text-danger fw-semibold">RWF ' . number_format($balance, 2) . '</span></td>'
            : '<td><span class="text-muted">RWF 0.00</span></td>';
        $html .= '<td><span class="fw-semibold">' . $points . '</span></td>';
        $html .= '<td><span class="badge bg-' . ($allowed ? 'success' : 'danger') . '">' . ($allowed ? 'Allowed' : 'Not Allowed') . '</span></td>';
        $html .= '<td class="text-nowrap"><a href="../customers/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a>';
        if ($isManager && $phoneOk) {
            $html .= ' <a href="../sms/sms_compose.php?group=customer&id=' . $id . '&template=' . ($balance > 0.009 ? 'credit' : 'custom')
                . '" class="btn btn-outline-primary btn-sm" title="Send an SMS to this customer"><i class="bi bi-chat-dots"></i> SMS</a>';
        }
        $html .= '</td></tr>';
    }
    return $html;
}

function customersHeaderRow(): string
{
    return '<tr><th>Customer ID</th><th>Name</th><th>Phone</th><th>Email</th><th>Location</th><th>Outstanding Balance</th><th>Loyalty Points</th><th>Credit Status</th><th>Action</th></tr>';
}

function renderDepartmentsRows(mysqli $conn, string $like, int $limit, ?string $role): string
{
    $sql = "SELECT id, name, description, is_active FROM departments WHERE name LIKE ? OR description LIKE ? ORDER BY name LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    if (empty($rows)) {
        return '';
    }

    $ids = array_map(fn($r) => (int) $r['id'], $rows);
    $rolesByDept = [];
    $idList = implode(',', $ids);
    $rolesResult = mysqli_query($conn, "SELECT department_id, name FROM roles WHERE is_active = 1 AND department_id IN ($idList) ORDER BY name");
    while ($r = mysqli_fetch_assoc($rolesResult)) {
        $rolesByDept[$r['department_id']][] = $r['name'];
    }

    $isAdmin = $role === 'Admin';

    $html = '';
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $roles = $rolesByDept[$id] ?? [];

        $html .= '<tr>';
        $html .= '<td><strong>' . esc($r['name']) . '</strong><div class="text-muted small">' . esc($r['description']) . '</div></td>';
        $html .= '<td>';
        if (empty($roles)) {
            $html .= '<span class="text-muted small">No roles defined yet</span>';
        } else {
            foreach ($roles as $title) {
                $html .= '<span class="badge bg-light text-dark border me-1 mb-1">' . esc($title) . '</span>';
            }
        }
        $html .= '</td>';
        $html .= '<td>' . ($r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>') . '</td>';
        if ($isAdmin) {
            $html .= '<td class="text-nowrap">';
            $html .= '<a href="../departments/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View / Manage Roles</a> ';
            $html .= '<a href="../departments/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
            $confirmMsg = $r['is_active']
                ? 'Deactivate this department? It will be hidden from the Add Employee dropdown but its data is kept.'
                : 'Reactivate this department? It will show up in the Add Employee dropdown again.';
            $html .= '<form method="POST" action="../departments/toggle_status.php" class="d-inline" onsubmit="return confirm(\'' . esc($confirmMsg) . '\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn ' . ($r['is_active'] ? 'rm-btn-secondary' : 'rm-btn-success') . ' rm-btn-sm">' . ($r['is_active'] ? 'Deactivate' : 'Reactivate') . '</button>';
            $html .= '</form> ';
            $html .= '<form method="POST" action="../departments/delete_permanent.php" class="d-inline" onsubmit="return confirm(\'Permanently delete this department? This cannot be undone. It only works if no employees or roles are assigned to it.\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>';
            $html .= '</form>';
            $html .= '</td>';
        }
        $html .= '</tr>';
    }
    return $html;
}

function departmentsHeaderRow(?string $role): string
{
    $h = '<tr><th>Name</th><th>Roles</th><th>Status</th>';
    if ($role === 'Admin') $h .= '<th>Action</th>';
    return $h . '</tr>';
}

// CHANGED: only the user's OWN notifications (before, searching showed everyone's).
function renderNotificationsRows(mysqli $conn, string $like, int $limit, int $userId): string
{
    $sql = "SELECT notifications.id, notifications.title, notifications.message, notifications.is_read, notifications.created_at, users.names
            FROM notifications
            LEFT JOIN users ON notifications.user_id = users.id
            WHERE notifications.user_id = ? AND (notifications.title LIKE ? OR notifications.message LIKE ?)
            ORDER BY notifications.id DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iss', $userId, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $html .= '<tr>';
        $html .= '<td>' . esc($r['names']) . '</td>';
        $html .= '<td>' . esc($r['title']) . '</td>';
        $html .= '<td>' . esc(mb_strimwidth($r['message'] ?? '', 0, 50, '...')) . '</td>';
        $html .= '<td>' . ($r['is_read'] ? '<span class="badge bg-success">Read</span>' : '<span class="badge bg-warning text-dark">Unread</span>') . '</td>';
        $html .= '<td>' . esc(date('d M Y H:i', strtotime($r['created_at']))) . '</td>';
        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../notifications/view.php?id=' . $id . '" class="rm-btn rm-btn-info btn-sm">View</a> ';
        $html .= '<a href="../notifications/mark_read.php?id=' . $id . '" class="rm-btn rm-btn-success btn-sm">Mark Read</a> ';
        $html .= '<a href="../notifications/delete.php?id=' . $id . '" class="rm-btn rm-btn-danger btn-sm" onclick="return confirm(\'Delete this notification?\')">Delete</a>';
        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function notificationsHeaderRow(): string
{
    return '<tr><th>User</th><th>Title</th><th>Message</th><th>Status</th><th>Date</th><th>Action</th></tr>';
}

function renderProjectsRows(mysqli $conn, string $like, int $limit, bool $isAdmin): string
{
    $sql = "SELECT projects.*, users.names AS creator_name
            FROM projects
            LEFT JOIN users ON projects.created_by = users.id
            WHERE projects.project_name LIKE ? OR projects.description LIKE ?
            ORDER BY projects.id ASC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ss', $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    $rowNumber = 1;
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $statusClass = $r['status'] === 'Completed' ? 'success' : ($r['status'] === 'On Hold' ? 'secondary' : 'primary');
        $html .= '<tr>';
        $html .= '<td>' . $rowNumber++ . '</td>';
        $html .= '<td><strong>' . esc($r['project_name']) . '</strong><br><small class="text-muted">' . esc($r['description'] ?? '') . '</small></td>';
        $html .= '<td>' . ($r['start_date'] ? esc(date('d M Y', strtotime($r['start_date']))) : '—') . '<br>' . ($r['end_date'] ? esc(date('d M Y', strtotime($r['end_date']))) : '—') . '</td>';
        $html .= '<td><span class="badge bg-' . $statusClass . '">' . esc($r['status']) . '</span></td>';
        $html .= '<td>' . esc($r['creator_name'] ?? '—') . '</td>';
        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../projects/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View</a> ';
        if ($isAdmin) {
            $html .= '<a href="../projects/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
            $html .= '<form method="POST" action="../projects/delete.php" class="d-inline" onsubmit="return confirm(\'Delete this project?\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>';
            $html .= '</form>';
        }
        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function projectsHeaderRow(): string
{
    return '<tr><th>No.</th><th>Project</th><th>Dates</th><th>Status</th><th>Created By</th><th>Action</th></tr>';
}

function saleStatusBadgeHtml($status) {
    $map = [
        'Pending Discount Approval' => 'secondary',
        'Paid' => 'success',
        'Partially Paid' => 'warning text-dark',
        'Credit' => 'danger',
        'Cancelled' => 'dark',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . esc($status) . '</span>';
}

// CHANGED: same columns and buttons as the updated Sales page (Balance column, SMS button), and
// search by receipt number (RM000047), Customer ID (RMC000025) and customer phone too.
function renderSalesRows(mysqli $conn, string $like, int $limit, ?string $role, $userId, int $saleId, int $customerId): string
{
    $where = '(customers.name LIKE ? OR customers.phone LIKE ? OR sales.payment_method LIKE ? OR sales.status LIKE ? OR sales.id = ? OR sales.customer_id = ?)';
    if ($role === 'Employee') {
        $where .= ' AND sales.recorded_by = ' . (int) $userId;
    }

    $sql = "SELECT sales.*, customers.name AS customer_name, users.names AS recorded_by_name
            FROM sales
            LEFT JOIN customers ON sales.customer_id = customers.id
            LEFT JOIN users ON sales.recorded_by = users.id
            WHERE $where
            ORDER BY sales.created_at DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ssssii', $like, $like, $like, $like, $saleId, $customerId);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $canManage = in_array($role, ['Admin', 'Manager'], true);

    $html = '';
    foreach ($rows as $sale) {
        $id = (int) $sale['id'];
        $balance = (float) $sale['total_amount'] - (float) $sale['amount_paid'] + (float) $sale['charges_accrued'] - (float) $sale['charges_paid'];
        $owes = in_array($sale['status'], ['Credit', 'Partially Paid'], true);

        $html .= '<tr>';
        $html .= '<td>' . esc(date('d M Y', strtotime($sale['sale_date']))) . '</td>';
        $html .= '<td>' . esc($sale['customer_name'] ?? 'Walk-in') . '</td>';
        $html .= '<td>' . esc($sale['recorded_by_name'] ?? '—') . '</td>';
        $html .= '<td>' . esc($sale['payment_method']) . '</td>';
        $html .= '<td>RWF ' . number_format((float) $sale['total_amount'], 2) . '</td>';
        $html .= '<td>RWF ' . number_format((float) $sale['amount_paid'], 2) . '</td>';
        $html .= $balance > 0
            ? '<td><span class="text-danger fw-semibold">RWF ' . number_format($balance, 2) . '</span></td>'
            : '<td><span class="text-muted">—</span></td>';
        $html .= '<td>' . saleStatusBadgeHtml($sale['status']) . '</td>';

        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../sales/invoice.php?id=' . $id . '" target="_blank" class="btn btn-outline-primary btn-sm">Invoice</a> ';

        if ($owes) {
            $html .= '<a href="../sales/pay_credit.php?id=' . $id . '" class="btn btn-outline-success btn-sm">Pay</a> ';
        }
        if ($owes && $canManage && !empty($sale['customer_id'])) {
            $html .= '<a href="../sms/sms_compose.php?group=customer&id=' . (int) $sale['customer_id'] . '&template=credit" class="btn btn-outline-primary btn-sm" title="Send an SMS reminder to this customer"><i class="bi bi-chat-dots"></i> SMS</a> ';
        }

        if (in_array($sale['status'], ['Credit', 'Partially Paid', 'Paid'], true)) {
            if ($canManage) {
                if (!empty($sale['cancel_requested_by'])) {
                    $html .= '<a href="../sales/review_cancel_request.php?id=' . $id . '" class="btn btn-warning btn-sm">Review Request</a>';
                } else {
                    $html .= '<a href="../sales/cancel.php?id=' . $id . '" class="btn btn-outline-danger btn-sm">Cancel</a>';
                }
            } elseif (!empty($sale['cancel_requested_by'])) {
                $html .= '<span class="badge bg-secondary">Cancel Requested</span>';
            } else {
                $html .= '<a href="../sales/request_cancel.php?id=' . $id . '" class="btn btn-outline-warning btn-sm">Request Cancel</a>';
            }
        }

        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function salesHeaderRow(): string
{
    return '<tr><th>Date</th><th>Customer</th><th>Recorded By</th><th>Payment</th><th>Total</th><th>Paid</th><th>Balance</th><th>Status</th><th>Action</th></tr>';
}

// CHANGED: same columns and buttons as the updated Transactions page: Fund column, Auto and
// Deleted badges, Approve as a POST button, Edit/Delete only on manual, not-deleted transactions.
function renderTransactionsRows(mysqli $conn, string $like, int $limit, bool $isAdmin, $currentUserId): string
{
    $visibilityWhere = $isAdmin ? '1=1' : "(transactions.status = 'approved' OR transactions.recorded_by = " . (int) $currentUserId . ')';
    $searchWhere = '(transactions.category LIKE ? OR transactions.description LIKE ? OR transactions.transaction_type LIKE ? OR transactions.expense_category LIKE ? OR funds.name LIKE ?)';

    $sql = "SELECT transactions.*, users.names AS recorder_name, funds.name AS fund_name, linked_sale.id AS linked_sale_id
            FROM transactions
            LEFT JOIN users ON transactions.recorded_by = users.id
            LEFT JOIN funds ON transactions.fund_id = funds.id
            LEFT JOIN sales linked_sale ON linked_sale.transaction_id = transactions.id
            WHERE $visibilityWhere AND $searchWhere
            ORDER BY transactions.transaction_date DESC, transactions.id DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sssss', $like, $like, $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $statusBadgeClass = [
        'approved' => 'success',
        'pending' => 'warning text-dark',
        'rejected' => 'danger',
        'deleted' => 'dark',
    ];

    $html = '';
    foreach ($rows as $t) {
        $id = (int) $t['id'];
        $badgeClass = $statusBadgeClass[$t['status']] ?? 'secondary';
        $isManual = (int) $t['is_automatic'] === 0 && empty($t['linked_sale_id']);
        $isDeleted = $t['status'] === 'deleted';

        $html .= '<tr' . ($isDeleted ? ' class="text-muted" style="text-decoration:line-through;"' : '') . '>';
        $html .= '<td>' . esc(date('d M Y', strtotime($t['transaction_date']))) . '</td>';
        $html .= '<td>' . esc($t['category']) . '</td>';
        $html .= '<td><span class="badge bg-' . ($t['transaction_type'] === 'Income' ? 'success' : 'danger') . '">' . esc($t['transaction_type']) . '</span></td>';
        $html .= '<td>' . esc($t['fund_name'] ?? '—') . '</td>';
        $html .= '<td>RWF ' . number_format((float) $t['amount'], 2) . '</td>';
        $html .= '<td>' . esc($t['description'] ?? '') . '</td>';
        $html .= '<td>' . esc($t['recorder_name'] ?? '—') . '</td>';
        $html .= '<td><span class="badge bg-' . $badgeClass . '">' . esc(ucfirst($t['status'])) . '</span>';
        if ($t['status'] === 'rejected' && !empty($t['rejection_reason'])) {
            $html .= ' <i class="bi bi-info-circle" title="' . esc($t['rejection_reason']) . '"></i>';
        }
        if (!$isManual) {
            $html .= ' <span class="badge bg-light text-muted border" title="Created automatically. Change it from its own module (e.g. cancel the sale or purchase order).">Auto</span>';
        }
        $html .= '</td>';

        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../transactions/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View</a> ';
        if (!empty($t['linked_sale_id'])) {
            $html .= '<a href="../sales/invoice.php?id=' . (int) $t['linked_sale_id'] . '" class="rm-btn rm-btn-light rm-btn-sm">Sale</a> ';
        }

        if ($isAdmin && $t['status'] === 'pending') {
            $html .= '<form method="POST" action="../transactions/approve.php" class="d-inline" onsubmit="return confirm(\'Approve this transaction?\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn rm-btn-success rm-btn-sm">Approve</button></form> ';
            $html .= '<button type="button" class="rm-btn rm-btn-danger rm-btn-sm" data-bs-toggle="modal" data-bs-target="#rejectModalLive' . $id . '">Reject</button>';

            $html .= '<div class="modal fade" id="rejectModalLive' . $id . '" tabindex="-1"><div class="modal-dialog"><div class="modal-content">';
            $html .= '<form method="POST" action="../transactions/reject.php">';
            $html .= '<div class="modal-header"><h5 class="modal-title">Reject Transaction #' . $id . '</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>';
            $html .= '<div class="modal-body"><input type="hidden" name="id" value="' . $id . '"><label class="form-label small fw-semibold text-muted">Reason (optional)</label><textarea name="reason" class="form-control" rows="3"></textarea></div>';
            $html .= '<div class="modal-footer"><button type="button" class="rm-btn rm-btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="rm-btn rm-btn-danger">Reject</button></div>';
            $html .= '</form></div></div></div>';
        }

        if ($isAdmin && $isManual && !$isDeleted) {
            $html .= '<a href="../transactions/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
            $html .= '<form method="POST" action="../transactions/delete.php" class="d-inline" onsubmit="return confirm(\'Delete this transaction?\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>';
            $html .= '</form>';
        }

        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function transactionsHeaderRow(): string
{
    return '<tr><th>Date</th><th>Category</th><th>Type</th><th>Fund</th><th>Amount</th><th>Description</th><th>Recorded By</th><th>Status</th><th>Action</th></tr>';
}

function renderEmployeesRows(mysqli $conn, string $like, int $limit): string
{
    $sql = "SELECT users.*, departments.name AS department_name
            FROM users
            LEFT JOIN departments ON users.department_id = departments.id
            WHERE users.names LIKE ? OR users.email LIKE ? OR users.role LIKE ?
            ORDER BY users.id ASC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $html .= '<tr>';
        $html .= '<td>' . $id . '</td>';
        $html .= '<td>' . esc($r['names']) . '</td>';
        $html .= '<td>' . esc($r['email']) . '</td>';
        $html .= '<td>' . esc(ucfirst($r['role'])) . '</td>';
        $html .= '<td>' . esc($r['department_name'] ?? '—') . '</td>';
        $html .= '<td>' . ($r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>') . '</td>';
        $html .= '<td>' . esc(date('d M Y', strtotime($r['created_at']))) . '</td>';
        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../users/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View</a> ';
        $html .= '<a href="../users/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
        $confirmMsg = $r['is_active']
            ? 'Deactivate this employee? Their history is kept but they will no longer be able to log in.'
            : 'Reactivate this employee? They will be able to log in again.';
        $html .= '<form method="POST" action="../users/toggle_status.php" class="d-inline" onsubmit="return confirm(\'' . esc($confirmMsg) . '\')">';
        $html .= '<input type="hidden" name="id" value="' . $id . '">';
        $html .= '<button type="submit" class="rm-btn ' . ($r['is_active'] ? 'rm-btn-secondary' : 'rm-btn-success') . ' rm-btn-sm">' . ($r['is_active'] ? 'Deactivate' : 'Reactivate') . '</button>';
        $html .= '</form> ';
        $html .= '<form method="POST" action="../users/delete_permanent.php" class="d-inline" onsubmit="return confirm(\'Permanently delete this employee? This cannot be undone. It only works if they have no attendance, tasks, sales, payroll, or leave history.\')">';
        $html .= '<input type="hidden" name="id" value="' . $id . '">';
        $html .= '<button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>';
        $html .= '</form>';
        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function employeesHeaderRow(): string
{
    return '<tr><th>ID</th><th>Full Name</th><th>Email</th><th>Role</th><th>Department</th><th>Status</th><th>Created At</th><th>Action</th></tr>';
}

function renderLeaveRows(mysqli $conn, string $like, int $limit, $userId): string
{
    $sql = "SELECT * FROM leave_requests
            WHERE user_id = ? AND (leave_type LIKE ? OR reason LIKE ? OR status LIKE ?)
            ORDER BY created_at DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'isss', $userId, $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $statusClass = [
        'Pending' => 'secondary',
        'Manager Approved' => 'info',
        'Approved' => 'success',
        'Rejected' => 'danger',
    ];

    $html = '';
    foreach ($rows as $r) {
        $class = $statusClass[$r['status']] ?? 'secondary';
        $html .= '<tr>';
        $html .= '<td>' . esc($r['leave_type']) . '</td>';
        $html .= '<td>' . esc($r['start_date']) . ' &rarr; ' . esc($r['end_date']) . '</td>';
        $html .= '<td>' . esc($r['reason'] ?? '') . '</td>';
        $html .= '<td><span class="badge bg-' . $class . '">' . esc($r['status']) . '</span></td>';
        $html .= '<td>' . esc(date('d M Y', strtotime($r['created_at']))) . '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function leaveHeaderRow(): string
{
    return '<tr><th>Type</th><th>Dates</th><th>Reason</th><th>Status</th><th>Submitted</th></tr>';
}

function renderProductsRows(mysqli $conn, string $like, int $limit, bool $isAdmin): string
{
    $lowStockThreshold = 5;

    $sql = "SELECT * FROM products
            WHERE product_name LIKE ? OR product_code LIKE ? OR item_type LIKE ?
            ORDER BY item_type, product_name ASC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'sss', $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $html = '';
    $rowNumber = 1;
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $isService = $r['item_type'] === 'Service';

        $html .= '<tr>';
        $html .= '<td>' . $rowNumber++ . '</td>';
        $html .= '<td><span class="badge bg-' . ($isService ? 'info text-dark' : 'primary') . '">' . esc($r['item_type']) . '</span></td>';
        $html .= '<td>' . esc($r['product_name']) . '</td>';
        $html .= '<td>' . esc($r['product_code']) . '</td>';

        if ($isService) {
            $html .= '<td><span class="text-muted">—</span></td>';
        } else {
            $html .= '<td>' . (int) $r['quantity'];
            if ($r['quantity'] <= $lowStockThreshold) {
                $html .= ' <span class="badge bg-warning text-dark ms-1">Low Stock</span>';
            }
            $html .= '</td>';
        }

        $html .= $isService ? '<td><span class="text-muted">—</span></td>' : '<td>' . esc($r['unit'] ?? 'Pieces') . '</td>';
        $html .= $isService ? '<td><span class="text-muted">—</span></td>' : '<td>RWF ' . number_format($r['buying_price'], 2) . '</td>';
        $html .= '<td>RWF ' . number_format($r['selling_price'], 2) . '</td>';
        $html .= '<td>' . ($r['is_active'] ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Inactive</span>') . '</td>';

        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../products/view.php?id=' . $id . '" class="rm-btn rm-btn-info rm-btn-sm">View</a> ';
        if ($isAdmin) {
            $html .= '<a href="../products/edit.php?id=' . $id . '" class="rm-btn rm-btn-warning rm-btn-sm">Edit</a> ';
            if (!$isService) {
                $html .= '<a href="../products/restock.php?id=' . $id . '" class="rm-btn rm-btn-success rm-btn-sm"><i class="bi bi-box-arrow-in-down"></i> Restock</a> ';
            }
            $confirmMsg = $r['is_active'] ? 'Deactivate this?' : 'Reactivate this?';
            $html .= '<a href="../products/delete.php?id=' . $id . '" class="rm-btn ' . ($r['is_active'] ? 'rm-btn-danger' : 'rm-btn-success') . ' rm-btn-sm" onclick="return confirm(\'' . esc($confirmMsg) . '\')">' . ($r['is_active'] ? 'Deactivate' : 'Activate') . '</a>';
        }
        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function productsHeaderRow(): string
{
    return '<tr><th>No.</th><th>Type</th><th>Name</th><th>Code</th><th>Quantity</th><th>Unit</th><th>Buying Price</th><th>Selling Price</th><th>Status</th><th>Action</th></tr>';
}

function taskStatusBadgeHtml($status) {
    $map = [
        'Pending' => 'secondary',
        'Accepted' => 'info',
        'In Progress' => 'primary',
        'Completed' => 'warning text-dark',
        'Approved' => 'success',
        'Rejected' => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . esc($status) . '</span>';
}

// CHANGED: Accept and Start Work are POST buttons (as on the Tasks page; the plain links no longer
// work), overdue tasks are marked, and the project name is searchable too.
function renderTasksRows(mysqli $conn, string $like, int $limit, ?string $role, $userId): string
{
    $where = '(tasks.title LIKE ? OR tasks.description LIKE ? OR tasks.status LIKE ? OR projects.project_name LIKE ?)';
    if ($role === 'Employee') {
        $where .= ' AND tasks.assigned_to = ' . (int) $userId;
    }

    $sql = "SELECT tasks.*, projects.project_name, users.names AS assignee_name
            FROM tasks
            INNER JOIN projects ON tasks.project_id = projects.id
            LEFT JOIN users ON tasks.assigned_to = users.id
            WHERE $where
            ORDER BY CASE WHEN tasks.status IN ('Approved', 'Rejected') THEN 1 ELSE 0 END, tasks.due_date ASC, tasks.created_at DESC
            LIMIT $limit";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'ssss', $like, $like, $like, $like);
    mysqli_stmt_execute($stmt);
    $rows = mysqli_fetch_all(mysqli_stmt_get_result($stmt), MYSQLI_ASSOC);

    $canManage = in_array($role, ['Admin', 'Manager'], true);
    $postButton = function (string $action, int $id, string $class, string $label) {
        return '<form method="POST" action="../tasks/' . $action . '" class="d-inline">'
            . '<input type="hidden" name="id" value="' . $id . '">'
            . '<button type="submit" class="btn ' . $class . ' btn-sm">' . $label . '</button></form> ';
    };

    $html = '';
    foreach ($rows as $task) {
        $id = (int) $task['id'];
        $priorityClass = $task['priority'] === 'High' ? 'danger' : ($task['priority'] === 'Medium' ? 'warning text-dark' : 'secondary');
        $isOpen = in_array($task['status'], ['Pending', 'Accepted', 'In Progress'], true);
        $overdue = $isOpen && $task['due_date'] && strtotime($task['due_date']) < strtotime('today');

        $html .= '<tr>';
        $html .= '<td><strong>' . esc($task['title']) . '</strong><br><small class="text-muted">' . esc($task['description'] ?? '') . '</small></td>';
        $html .= '<td>' . esc($task['project_name']) . '</td>';
        $html .= '<td>' . esc($task['assignee_name'] ?? 'Unassigned') . '</td>';
        $html .= '<td><span class="badge bg-' . $priorityClass . '">' . esc($task['priority']) . '</span></td>';
        $html .= '<td class="text-nowrap"><span class="' . ($overdue ? 'text-danger fw-semibold' : '') . '">'
            . ($task['due_date'] ? esc(date('d M Y', strtotime($task['due_date']))) : '—') . '</span>'
            . ($overdue ? ' <span class="badge bg-danger ms-1">Overdue</span>' : '') . '</td>';
        $html .= '<td>' . taskStatusBadgeHtml($task['status']) . '</td>';
        $html .= '<td>' . ($task['performance_score'] !== null ? (int) $task['performance_score'] . '/100' : '—') . '</td>';

        $html .= '<td class="text-nowrap">';
        $html .= '<a href="../tasks/view.php?id=' . $id . '" class="btn btn-info btn-sm">View</a> ';

        if ($role === 'Employee' && (int) $task['assigned_to'] === (int) $userId) {
            if ($task['status'] === 'Pending') {
                $html .= $postButton('accept.php', $id, 'btn-success', 'Accept');
            } elseif ($task['status'] === 'Accepted') {
                $html .= $postButton('start.php', $id, 'btn-primary', 'Start Work');
            } elseif ($task['status'] === 'In Progress') {
                $html .= '<a href="../tasks/submit.php?id=' . $id . '" class="btn btn-warning btn-sm">Submit</a> ';
            }
        }

        if ($canManage) {
            if ($task['status'] === 'Completed') {
                $html .= '<a href="../tasks/review.php?id=' . $id . '" class="btn btn-success btn-sm">Review</a> ';
            }
            $html .= '<a href="../tasks/edit.php?id=' . $id . '" class="btn btn-warning btn-sm">Edit</a> ';
            $html .= '<form method="POST" action="../tasks/delete.php" class="d-inline" onsubmit="return confirm(\'Delete this task?\')">';
            $html .= '<input type="hidden" name="id" value="' . $id . '">';
            $html .= '<button type="submit" class="btn btn-danger btn-sm">Delete</button>';
            $html .= '</form>';
        }

        $html .= '</td>';
        $html .= '</tr>';
    }
    return $html;
}

function tasksHeaderRow(): string
{
    return '<tr><th>Task</th><th>Project</th><th>Assigned To</th><th>Priority</th><th>Due Date</th><th>Status</th><th>Score</th><th>Action</th></tr>';
}

/* ================= Build the replacement HTML for #pageResultsContainer =================
   Header + matching rows, wrapped exactly like the original markup around
   that module's table. */
$rowsHtml = '';
$html = '';
$tableOpen = '<table class="table table-bordered table-hover bg-white mb-0">';

try {
    switch ($scope) {
        case 'attendance':
            $rowsHtml = renderAttendanceRows($conn, $like, 50, $canManageAttendance, $userId);
            if ($rowsHtml !== '') { $html = $tableOpen . attendanceHeaderRow($canManageAttendance) . $rowsHtml . '</table>'; }
            break;
        case 'customers':
            $rowsHtml = renderCustomersRows($conn, $like, 50, $searchCustomerId, $isManager);
            if ($rowsHtml !== '') {
                // The Customers page wraps its table in .table-responsive inside #pageResultsContainer.
                $html = '<div class="table-responsive">' . $tableOpen . customersHeaderRow() . $rowsHtml . '</table></div>';
            }
            break;
        case 'departments':
            $rowsHtml = renderDepartmentsRows($conn, $like, 50, $role);
            if ($rowsHtml !== '') { $html = $tableOpen . departmentsHeaderRow($role) . $rowsHtml . '</table>'; }
            break;
        case 'notifications':
            $rowsHtml = renderNotificationsRows($conn, $like, 50, $userId);
            if ($rowsHtml !== '') { $html = '<table class="table table-bordered table-hover">' . notificationsHeaderRow() . $rowsHtml . '</table>'; }
            break;
        case 'projects':
            $rowsHtml = renderProjectsRows($conn, $like, 50, $isAdminSession);
            if ($rowsHtml !== '') { $html = $tableOpen . projectsHeaderRow() . $rowsHtml . '</table>'; }
            break;
        case 'sales':
            $rowsHtml = renderSalesRows($conn, $like, 50, $role, $userId, $searchSaleId, $searchCustomerId);
            if ($rowsHtml !== '') { $html = '<div class="table-responsive">' . $tableOpen . salesHeaderRow() . $rowsHtml . '</table></div>'; }
            break;
        case 'transactions':
            $rowsHtml = renderTransactionsRows($conn, $like, 50, $isAdminSession, $currentUserId);
            if ($rowsHtml !== '') { $html = '<div class="table-responsive">' . $tableOpen . transactionsHeaderRow() . $rowsHtml . '</table></div>'; }
            break;
        case 'employees':
            $rowsHtml = renderEmployeesRows($conn, $like, 50);
            if ($rowsHtml !== '') { $html = $tableOpen . employeesHeaderRow() . $rowsHtml . '</table>'; }
            break;
        case 'leave':
            $rowsHtml = renderLeaveRows($conn, $like, 50, $userId);
            if ($rowsHtml !== '') { $html = $tableOpen . leaveHeaderRow() . $rowsHtml . '</table>'; }
            break;
        case 'products':
            $rowsHtml = renderProductsRows($conn, $like, 50, $isAdminSession);
            if ($rowsHtml !== '') {
                // Note: this page's table uses different classes (no bg-white/mb-0) than the others.
                $html = '<table class="table table-bordered table-hover">' . productsHeaderRow() . $rowsHtml . '</table>';
            }
            break;
        case 'tasks':
            $rowsHtml = renderTasksRows($conn, $like, 50, $role, $userId);
            if ($rowsHtml !== '') { $html = '<div class="table-responsive">' . $tableOpen . tasksHeaderRow() . $rowsHtml . '</table></div>'; }
            break;
    }
} catch (Throwable $e) {
    // NEW: a failed search shows "Search failed" in the page instead of a broken response.
    error_log('live search failed (' . $scope . '): ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['empty' => true, 'html' => '', 'error' => 'Search failed']);
    exit;
}

echo json_encode(['empty' => $rowsHtml === '', 'html' => $html]);