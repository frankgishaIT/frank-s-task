<?php
/**
 * NEW FILE: modules/access/audit.php
 * Task II: who assigned, changed or revoked access, when, and what changed.
 */
require '_common.php';
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT a.*, ua.names AS actor, ut.names AS target, r.name AS role_name FROM acl_audit a
    LEFT JOIN users ua ON ua.id = a.actor_id LEFT JOIN users ut ON ut.id = a.target_user_id LEFT JOIN acl_roles r ON r.id = a.role_id
    ORDER BY a.id DESC LIMIT 500'), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<h2 class="mb-1">Roles &amp; Permissions</h2>
<?php access_tabs('audit.php'); ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-bordered bg-white mb-0 align-middle" style="font-size:12.5px;">
    <tr><th>Date &amp; time</th><th>By</th><th>Employee / role</th><th>Change</th><th>What changed</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted py-4">No changes yet.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?>
    <tr><td class="text-nowrap"><?= date('d M Y, H:i', strtotime($r['created_at'])); ?></td>
        <td><?= $e($r['actor'] ?? 'System'); ?></td>
        <td><?= $e($r['target'] ?? ($r['role_name'] ? 'Role: ' . $r['role_name'] : '—')); ?></td>
        <td><?= $e(str_replace('_', ' ', $r['action'])); ?></td>
        <td><?= $e($r['details']); ?></td></tr>
    <?php } ?>
</table>
</div></div></div>
<?php include '../../includes/footer.php'; ?>