<?php
require '../../config/db.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT); if (!$id) { header('Location: index.php?success=Invalid task selected.'); exit; }
$statement = mysqli_prepare($conn, 'SELECT tasks.*, projects.project_name, users.names AS assignee_name, reviewer.names AS reviewer_name FROM tasks INNER JOIN projects ON tasks.project_id = projects.id LEFT JOIN users ON tasks.assigned_to = users.id LEFT JOIN users reviewer ON tasks.reviewed_by = reviewer.id WHERE tasks.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id); mysqli_stmt_execute($statement); $task = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)); if (!$task) { header('Location: index.php?success=Task not found.'); exit; }
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="card shadow border-0">
    <div class="card-header bg-white"><h3 class="mb-0">Task Details</h3></div>
    <div class="card-body">
        <table class="table table-bordered mb-4">
            <tr><th width="220">Title</th><td><?= htmlspecialchars($task['title'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Description</th><td><?= nl2br(htmlspecialchars($task['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td></tr>
            <tr><th>Project</th><td><?= htmlspecialchars($task['project_name'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Assigned To</th><td><?= htmlspecialchars($task['assignee_name'] ?? 'Unassigned', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Priority</th><td><?= htmlspecialchars($task['priority'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Status</th><td><?= htmlspecialchars($task['status'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Due Date</th><td><?= $task['due_date'] ? date('d M Y', strtotime($task['due_date'])) : '—'; ?></td></tr>
            <tr><th>Accepted At</th><td><?= $task['accepted_at'] ? date('d M Y H:i', strtotime($task['accepted_at'])) : '—'; ?></td></tr>
            <tr><th>Submitted At</th><td><?= $task['submitted_at'] ? date('d M Y H:i', strtotime($task['submitted_at'])) : '—'; ?></td></tr>
            <tr><th>Completion Note</th><td><?= nl2br(htmlspecialchars($task['completion_note'] ?? '—', ENT_QUOTES, 'UTF-8')); ?></td></tr>
            <tr><th>Reviewed By</th><td><?= htmlspecialchars($task['reviewer_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><th>Review Note</th><td><?= nl2br(htmlspecialchars($task['review_note'] ?? '—', ENT_QUOTES, 'UTF-8')); ?></td></tr>
            <tr><th>Performance Score</th><td><?= $task['performance_score'] !== null ? (int) $task['performance_score'] . ' / 100' : '—'; ?></td></tr>
        </table>
        <a href="index.php" class="btn btn-secondary">Back</a>
    </div>
</div>
<?php include '../../includes/footer.php'; ?>
