<?php
require '../../config/db.php'; $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid project selected.'); exit; }
$statement = mysqli_prepare($conn, 'SELECT projects.*, users.names AS creator_name FROM projects LEFT JOIN users ON projects.created_by = users.id WHERE projects.id = ?'); mysqli_stmt_bind_param($statement, 'i', $id); mysqli_stmt_execute($statement); $project = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$project) { header('Location: index.php?success=Project not found.'); exit; }
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="card shadow"><div class="card-header"><h3>Project Details</h3></div><div class="card-body"><table class="table table-bordered mb-4"><tr><th width="200">Project Name</th><td><?= htmlspecialchars($project['project_name'], ENT_QUOTES, 'UTF-8'); ?></td></tr><tr><th>Description</th><td><?= nl2br(htmlspecialchars($project['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td></tr><tr><th>Start Date</th><td><?= $project['start_date'] ? date('d M Y', strtotime($project['start_date'])) : '—'; ?></td></tr><tr><th>End Date</th><td><?= $project['end_date'] ? date('d M Y', strtotime($project['end_date'])) : '—'; ?></td></tr><tr><th>Status</th><td><?= htmlspecialchars($project['status'], ENT_QUOTES, 'UTF-8'); ?></td></tr><tr><th>Created By</th><td><?= htmlspecialchars($project['creator_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td></tr></table><a href="index.php" class="btn btn-secondary">Back</a></div></div>
<?php include '../../includes/footer.php'; ?>
