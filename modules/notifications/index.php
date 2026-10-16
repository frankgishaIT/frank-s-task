<?php
require '../../config/db.php';
$pageSearchScope = 'notifications'; // tells the topbar search what module we're in
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const PER_PAGE = 10;

$userId = (int) current_user_id();
$isAdmin = current_user_role() === 'Admin';

// NEW: everyone sees THEIR OWN notifications by default (payslip messages contain salaries).
// An Admin can still switch to "All users" for an overview.
$scope = ($isAdmin && ($_GET['scope'] ?? '') === 'all') ? 'all' : 'mine';
// NEW: All / Unread / Read filter.
$filter = in_array($_GET['filter'] ?? '', ['unread', 'read'], true) ? $_GET['filter'] : 'all';

$where = []; $types = ''; $params = [];
if ($scope === 'mine') { $where[] = 'notifications.user_id = ?'; $types .= 'i'; $params[] = $userId; }
if ($filter === 'unread') { $where[] = 'notifications.is_read = 0'; }
if ($filter === 'read') { $where[] = 'notifications.is_read = 1'; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$count = mysqli_prepare($conn, 'SELECT COUNT(*) AS c FROM notifications' . $whereSql);
if ($types !== '') { mysqli_stmt_bind_param($count, $types, ...$params); }
mysqli_stmt_execute($count);
$totalRows = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($count))['c'];

$currentPage = get_current_page();
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

$list = mysqli_prepare($conn, 'SELECT notifications.*, users.names FROM notifications
    LEFT JOIN users ON notifications.user_id = users.id' . $whereSql . '
    ORDER BY notifications.id DESC LIMIT ' . PER_PAGE . ' OFFSET ' . $offset);
if ($types !== '') { mysqli_stmt_bind_param($list, $types, ...$params); }
mysqli_stmt_execute($list);
$result = mysqli_stmt_get_result($list);

// My own counts, for the buttons.
$mine = mysqli_prepare($conn, 'SELECT SUM(is_read = 0) AS unread, SUM(is_read = 1) AS read_count FROM notifications WHERE user_id = ?');
mysqli_stmt_bind_param($mine, 'i', $userId);
mysqli_stmt_execute($mine);
$myCounts = mysqli_fetch_assoc(mysqli_stmt_get_result($mine));
$myUnread = (int) ($myCounts['unread'] ?? 0);
$myRead = (int) ($myCounts['read_count'] ?? 0);

$showUser = $scope === 'all';
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$link = function (array $change) use ($scope, $filter) {
    $q = array_merge(['scope' => $scope, 'filter' => $filter], $change);
    if ($q['scope'] === 'mine') { unset($q['scope']); }
    if ($q['filter'] === 'all') { unset($q['filter']); }
    return 'index.php' . ($q ? '?' . http_build_query($q) : '');
};
?>
<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert"><?= $e($_GET['success']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>
<?php if (isset($_GET['error'])) { ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert"><?= $e($_GET['error']); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="mb-0"><?= $scope === 'all' ? 'Notifications Management' : 'My Notifications'; ?></h2>
    <div class="d-flex gap-2">
        <!-- NEW: Mark all as read (my notifications) -->
        <form method="POST" action="action.php" class="d-inline">
            <input type="hidden" name="action" value="mark_all">
            <input type="hidden" name="back" value="<?= $e($link([])); ?>">
            <button type="submit" class="rm-btn rm-btn-primary rm-btn-sm" <?= $myUnread === 0 ? 'disabled' : ''; ?>>
                <i class="bi bi-check2-all me-1"></i>Mark all as read<?= $myUnread > 0 ? ' (' . $myUnread . ')' : ''; ?>
            </button>
        </form>
        <!-- NEW: Delete all read (my notifications) -->
        <form method="POST" action="action.php" class="d-inline" onsubmit="return confirm('Delete all <?= $myRead; ?> read notification(s)? This cannot be undone.');">
            <input type="hidden" name="action" value="delete_read">
            <input type="hidden" name="back" value="<?= $e($link([])); ?>">
            <button type="submit" class="rm-btn rm-btn-light rm-btn-sm" <?= $myRead === 0 ? 'disabled' : ''; ?>>
                <i class="bi bi-trash me-1"></i>Delete all read
            </button>
        </form>
    </div>
</div>

<!-- NEW: filters (and, for an Admin, My notifications / All users) -->
<div class="d-flex flex-wrap gap-2 mb-3">
    <div class="btn-group btn-group-sm">
        <?php foreach (['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $k => $label) { ?>
            <a href="<?= $e($link(['filter' => $k])); ?>" class="btn <?= $filter === $k ? 'btn-primary' : 'btn-outline-primary'; ?>"><?= $label; ?></a>
        <?php } ?>
    </div>
    <?php if ($isAdmin) { ?>
    <div class="btn-group btn-group-sm">
        <a href="<?= $e($link(['scope' => 'mine'])); ?>" class="btn <?= $scope === 'mine' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">My notifications</a>
        <a href="<?= $e($link(['scope' => 'all'])); ?>" class="btn <?= $scope === 'all' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">All users</a>
    </div>
    <?php } ?>
</div>

<div class="card border-0 shadow-sm">
<div class="card-body p-0">
<div id="pageResultsContainer">
<div class="table-responsive">
<table class="table table-bordered table-hover bg-white mb-0 align-middle">
<tr>
    <?php if ($showUser) { ?><th>User</th><?php } ?>
    <th>Title</th><th>Message</th><th>Status</th><th>Date</th><th>Action</th>
</tr>
<?php if (mysqli_num_rows($result) === 0) { ?>
<tr><td colspan="<?= $showUser ? 6 : 5; ?>" class="text-center text-muted py-4">
    <?= $filter === 'unread' ? 'No unread notifications.' : ($filter === 'read' ? 'No read notifications.' : 'No notifications yet.'); ?></td></tr>
<?php } ?>
<?php while ($row = mysqli_fetch_assoc($result)) {
    $id = (int) $row['id'];
    $isMine = (int) $row['user_id'] === $userId;
    $unread = !(int) $row['is_read'];
?>
<tr<?= $unread ? ' style="background:#F5F8FF;"' : ''; ?>>
    <?php if ($showUser) { ?><td><?= $e($row['names'] ?? '—'); ?></td><?php } ?>
    <td>
        <!-- NEW: the title opens the notification (and marks it read), like the bell menu -->
        <?php if ($isMine) { ?>
            <a href="open.php?id=<?= $id; ?>" class="text-decoration-none <?= $unread ? 'fw-semibold' : ''; ?>"><?= $e($row['title']); ?></a>
        <?php } else { ?>
            <?= $e($row['title']); ?>
        <?php } ?>
    </td>
    <td><?= $e($row['message']); ?></td>
    <td><?= $unread ? '<span class="badge" style="background:#1E2FE0;">Unread</span>' : '<span class="badge bg-success">Read</span>'; ?></td>
    <td class="text-nowrap"><?= date('d M Y H:i', strtotime($row['created_at'])); ?></td>
    <td class="text-nowrap">
        <a href="view.php?id=<?= $id; ?>" class="rm-btn rm-btn-info rm-btn-sm">View</a>
        <?php if ($unread && $isMine) { ?>
            <!-- CHANGED: a button (POST), not a plain link -->
            <form method="POST" action="action.php" class="d-inline">
                <input type="hidden" name="action" value="mark_one"><input type="hidden" name="id" value="<?= $id; ?>">
                <input type="hidden" name="back" value="<?= $e($link(['page' => $currentPage])); ?>">
                <button type="submit" class="rm-btn rm-btn-success rm-btn-sm">Mark Read</button>
            </form>
        <?php } ?>
        <?php if ($isMine || $isAdmin) { ?>
            <form method="POST" action="action.php" class="d-inline" onsubmit="return confirm('Delete this notification?');">
                <input type="hidden" name="action" value="delete_one"><input type="hidden" name="id" value="<?= $id; ?>">
                <input type="hidden" name="back" value="<?= $e($link(['page' => $currentPage])); ?>">
                <button type="submit" class="rm-btn rm-btn-danger rm-btn-sm">Delete</button>
            </form>
        <?php } ?>
    </td>
</tr>
<?php } ?>
</table>
</div>
</div>
<div id="pageResultsPagination">
<?php render_pagination($currentPage, $totalPages, array_filter(['scope' => $scope === 'all' ? 'all' : null, 'filter' => $filter !== 'all' ? $filter : null])); ?>
</div>
</div>
</div>

<script>
setTimeout(function () {
    let alert = document.querySelector('.alert');
    if (alert) { alert.remove(); }
}, 3000);
</script>
<?php include '../../includes/footer.php'; ?>