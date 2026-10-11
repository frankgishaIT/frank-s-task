<?php
require '../../config/db.php';
$pageSearchScope = 'tasks'; // tells the topbar search what module we're in
// NEW: this page had no role check of its own (every other page has one).
require_role(['Admin', 'Manager', 'Employee']);
require '../../includes/pagination.php';
include '../../includes/header.php';
include '../../includes/sidebar.php';

const PER_PAGE = 10;
$role = current_user_role();
$userId = current_user_id();

$whereClause = $role === 'Employee' ? ' WHERE tasks.assigned_to = ' . (int) $userId : '';

$currentPage = get_current_page();
$totalRows = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COUNT(*) AS c FROM tasks' . $whereClause))['c'];
$totalPages = max(1, (int) ceil($totalRows / PER_PAGE));
$currentPage = min($currentPage, $totalPages);
$offset = ($currentPage - 1) * PER_PAGE;

$sql = "SELECT tasks.*, projects.project_name, users.names AS assignee_name
        FROM tasks INNER JOIN projects ON tasks.project_id = projects.id
        LEFT JOIN users ON tasks.assigned_to = users.id" . $whereClause . "
        ORDER BY CASE WHEN tasks.status IN ('Approved', 'Rejected') THEN 1 ELSE 0 END, tasks.due_date ASC, tasks.created_at DESC
        LIMIT " . PER_PAGE . " OFFSET " . $offset;
$tasks = mysqli_query($conn, $sql);

function task_status_badge($status) {
    $map = [
        'Pending' => 'secondary',
        'Accepted' => 'info',
        'In Progress' => 'primary',
        'Completed' => 'warning text-dark',
        'Approved' => 'success',
        'Rejected' => 'danger',
    ];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success alert-dismissible fade show" role="alert"><?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>
<?php if (isset($_GET['error'])) { ?><div class="alert alert-danger alert-dismissible fade show" role="alert"><?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= $role === 'Employee' ? 'My Tasks' : 'Tasks Management'; ?></h2>
    <?php if (in_array($role, ['Admin', 'Manager'], true)) { ?>
    <!-- NEW: Reports, next to Add Task -->
    <div class="d-flex gap-2">
        <a href="reports.php" class="btn btn-outline-primary">Reports</a>
        <a href="create.php" class="btn btn-primary">+ Add Task</a>
    </div>
    <?php } ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <!-- Live-search wires up against this container: it caches this exact
             markup on page load and swaps it out for filtered results as you
             type in the topbar search box, restoring it when the box is
             cleared. Employees only ever see (and search) their own tasks,
             matching this page's own $whereClause restriction. -->
        <div id="pageResultsContainer">
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white mb-0">
                <tr><th>Task</th><th>Project</th><th>Assigned To</th><th>Priority</th><th>Due Date</th><th>Status</th><th>Score</th><th>Action</th></tr>
                <?php if (mysqli_num_rows($tasks) === 0) { ?><tr><td colspan="8" class="text-center text-muted py-4">No tasks found.</td></tr><?php } ?>
                <?php while ($task = mysqli_fetch_assoc($tasks)) {
                    // NEW: an open task (not handed in yet) whose due date has passed is marked Overdue.
                    $isOpen = in_array($task['status'], ['Pending', 'Accepted', 'In Progress'], true);
                    $overdue = $isOpen && $task['due_date'] && strtotime($task['due_date']) < strtotime('today');
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></strong><br><small class="text-muted"><?= htmlspecialchars($task['description'] ?? '', ENT_QUOTES, 'UTF-8'); ?></small></td>
                    <td><?= htmlspecialchars($task['project_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars($task['assignee_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><span class="badge bg-<?= $task['priority'] === 'High' ? 'danger' : ($task['priority'] === 'Medium' ? 'warning text-dark' : 'secondary'); ?>"><?= htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="text-nowrap">
                        <span class="<?= $overdue ? 'text-danger fw-semibold' : ''; ?>"><?= $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—'; ?></span>
                        <?php if ($overdue) { ?><span class="badge bg-danger ms-1">Overdue</span><?php } ?>
                    </td>
                    <td><?= task_status_badge($task['status']); ?></td>
                    <td><?= $task['performance_score'] !== null ? (int) $task['performance_score'] . '/100' : '—'; ?></td>
                    <td class="text-nowrap">
                        <a href="view.php?id=<?= (int) $task['id']; ?>" class="btn btn-info btn-sm">View</a>

                        <?php if ($role === 'Employee' && (int) $task['assigned_to'] === (int) $userId) { ?>
                            <?php if ($task['status'] === 'Pending') { ?>
                                <!-- CHANGED: Accept is a POST button (accept.php no longer accepts a plain link). -->
                                <form method="POST" action="accept.php" class="d-inline">
                                    <input type="hidden" name="id" value="<?= (int) $task['id']; ?>">
                                    <button type="submit" class="btn btn-success btn-sm">Accept</button>
                                </form>
                            <?php } elseif ($task['status'] === 'Accepted') { ?>
                                <!-- CHANGED: Start Work is a POST button (start.php no longer accepts a plain link). -->
                                <form method="POST" action="start.php" class="d-inline">
                                    <input type="hidden" name="id" value="<?= (int) $task['id']; ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">Start Work</button>
                                </form>
                            <?php } elseif ($task['status'] === 'In Progress') { ?>
                                <a href="submit.php?id=<?= (int) $task['id']; ?>" class="btn btn-warning btn-sm">Submit</a>
                            <?php } ?>
                        <?php } ?>

                        <?php if (in_array($role, ['Admin', 'Manager'], true)) { ?>
                            <?php if ($task['status'] === 'Completed') { ?>
                                <a href="review.php?id=<?= (int) $task['id']; ?>" class="btn btn-success btn-sm">Review</a>
                            <?php } ?>
                            <a href="edit.php?id=<?= (int) $task['id']; ?>" class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="delete.php" class="d-inline" onsubmit="return confirm('Delete this task?')">
                                <input type="hidden" name="id" value="<?= (int) $task['id']; ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </table>
        </div>
        </div>
        <div id="pageResultsPagination">
        <?php render_pagination($currentPage, $totalPages); ?>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>