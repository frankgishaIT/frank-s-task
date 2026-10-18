<?php
require '../../config/db.php';
$pageSearchScope = 'leave'; // tells the topbar search what module we're in
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const PER_PAGE = 10;
$userId = current_user_id();
$role = current_user_role();

$currentPage = get_current_page();
$countStatement = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM leave_requests WHERE user_id = ?');
mysqli_stmt_bind_param($countStatement, 'i', $userId);
mysqli_stmt_execute($countStatement);
$totalRows = mysqli_fetch_assoc(mysqli_stmt_get_result($countStatement))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

// Everyone sees their own requests.
$myStatement = mysqli_prepare($conn, 'SELECT * FROM leave_requests WHERE user_id = ? ORDER BY created_at DESC LIMIT ' . PER_PAGE . ' OFFSET ' . $offset);
mysqli_stmt_bind_param($myStatement, 'i', $userId);
mysqli_stmt_execute($myStatement);
$myLeaves = mysqli_stmt_get_result($myStatement);

// Managers/Admins also see requests waiting on their decision.
$pendingMyApproval = null;
if ($role === 'Manager') {
    $pendingMyApproval = mysqli_query($conn, "SELECT leave_requests.*, users.names AS employee_name FROM leave_requests INNER JOIN users ON leave_requests.user_id = users.id WHERE leave_requests.status = 'Pending' ORDER BY leave_requests.created_at");
} elseif ($role === 'Admin') {
    $pendingMyApproval = mysqli_query($conn, "SELECT leave_requests.*, users.names AS employee_name FROM leave_requests INNER JOIN users ON leave_requests.user_id = users.id WHERE leave_requests.status IN ('Pending', 'Manager Approved') ORDER BY leave_requests.created_at");
}

// Managers/Admins also need a way to get back to ANY request (including
// already-decided ones) so they can keep following its chat thread even
// after it drops out of the approval queue above.
$allRequests = null;
if (in_array($role, ['Manager', 'Admin'], true)) {
    $allRequests = mysqli_query($conn, "SELECT leave_requests.*, users.names AS employee_name FROM leave_requests INNER JOIN users ON leave_requests.user_id = users.id ORDER BY leave_requests.created_at DESC LIMIT 50");
}

function leave_status_badge($status) {
    $map = [
        'Pending' => 'secondary',
        'Manager Approved' => 'info',
        'Approved' => 'success',
        'Rejected' => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * NEW: the dates of a request, readable, with its length.
 *   Short Leave:  20 Oct 2026, 09:00 – 11:00 (2h)
 *   Other types:  20 Oct 2026 → 22 Oct 2026 (3 days)
 */
function leave_dates_html(array $row): string {
    $start = strtotime($row['start_date']);
    $end = strtotime($row['end_date']);
    $st = $row['start_time'] ?? null;
    $et = $row['end_time'] ?? null;
    if ($row['leave_type'] === 'Short Leave' && $st && $et) {
        $minutes = (int) round((strtotime('2000-01-01 ' . $et) - strtotime('2000-01-01 ' . $st)) / 60);
        $length = $minutes >= 60 ? intdiv($minutes, 60) . 'h' . ($minutes % 60 ? ' ' . ($minutes % 60) . 'min' : '') : $minutes . 'min';
        return htmlspecialchars(date('d M Y', $start) . ', ' . substr($st, 0, 5) . ' – ' . substr($et, 0, 5), ENT_QUOTES, 'UTF-8')
            . ' <span class="text-muted small">(' . $length . ')</span>';
    }
    $days = (int) round(($end - $start) / 86400) + 1;
    $text = $start === $end ? date('d M Y', $start) : date('d M Y', $start) . ' → ' . date('d M Y', $end);
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . ' <span class="text-muted small">(' . $days . ' day' . ($days === 1 ? '' : 's') . ')</span>';
}
?>

<?php if (isset($_GET['success'])) { ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>
<?php if (isset($_GET['error'])) { ?>
    <!-- NEW: error messages were not shown on this page. -->
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Leave Requests</h2>
    <a href="create.php" class="rm-btn rm-btn-primary">+ Request Leave</a>
</div>

<?php if ($pendingMyApproval && mysqli_num_rows($pendingMyApproval) > 0) { ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">Awaiting Your Approval</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Dates</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php while ($row = mysqli_fetch_assoc($pendingMyApproval)) { ?>
            <tr>
                <td><?= htmlspecialchars($row['employee_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($row['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="text-nowrap"><?= leave_dates_html($row); ?></td>
                <td><?= leave_status_badge($row['status']); ?></td>
                <td class="text-nowrap">
                    <a href="approve.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-primary rm-btn-sm">Review</a>
                    <a href="view.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-secondary rm-btn-sm">View</a>
                </td>
            </tr>
            <?php } ?>
        </table>
        </div>
    </div>
</div>
<?php } ?>

<?php if ($allRequests && mysqli_num_rows($allRequests) > 0) { ?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white"><h5 class="mb-0">All Leave Requests</h5></div>
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr>
                <th>Employee</th>
                <th>Type</th>
                <th>Dates</th>
                <th>Status</th>
                <th>Submitted</th>
                <th></th>
            </tr>
            <?php while ($row = mysqli_fetch_assoc($allRequests)) { ?>
            <tr>
                <td><?= htmlspecialchars($row['employee_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= htmlspecialchars($row['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="text-nowrap"><?= leave_dates_html($row); ?></td>
                <td><?= leave_status_badge($row['status']); ?></td>
                <td><?= date('d M Y', strtotime($row['created_at'])); ?></td>
                <td><a href="view.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-secondary rm-btn-sm">View</a></td>
            </tr>
            <?php } ?>
        </table>
        </div>
    </div>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white"><h5 class="mb-0">My Leave Requests</h5></div>
    <div class="card-body p-0">
        <!-- Live-search wires up against this container: it caches this exact
             markup on page load and swaps it out for filtered results as you
             type in the topbar search box, restoring it when the box is
             cleared. Only searches YOUR leave requests, matching this page's
             own query — the approval queue above stays untouched. -->
        <div id="pageResultsContainer">
        <table class="table table-bordered table-hover bg-white mb-0">
            <tr>
                <th>Type</th>
                <th>Dates</th>
                <th>Reason</th>
                <th>Status</th>
                <th>Submitted</th>
                <th></th>
            </tr>
            <?php if (mysqli_num_rows($myLeaves) === 0) { ?>
            <tr><td colspan="6" class="text-center text-muted py-4">No leave requests yet.</td></tr>
            <?php } ?>
            <?php while ($row = mysqli_fetch_assoc($myLeaves)) { ?>
            <tr>
                <td><?= htmlspecialchars($row['leave_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                <td class="text-nowrap"><?= leave_dates_html($row); ?></td>
                <td><?= htmlspecialchars($row['reason'] ?? '', ENT_QUOTES, 'UTF-8'); ?></td>
                <td><?= leave_status_badge($row['status']); ?></td>
                <td><?= date('d M Y', strtotime($row['created_at'])); ?></td>
                <td><a href="view.php?id=<?= (int) $row['id']; ?>" class="rm-btn rm-btn-secondary rm-btn-sm">View</a></td>
            </tr>
            <?php } ?>
        </table>
        </div>
    </div>
    <div id="pageResultsPagination">
    <?php render_pagination($currentPage, $totalPages); ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>