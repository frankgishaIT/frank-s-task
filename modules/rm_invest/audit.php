<?php
// NEW FILE: modules/rm_invest/audit.php — every action on RM Invest.
require __DIR__ . '/invest_common.php';
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.*, u.names, p.name AS project, c.name AS customer FROM inv_audit l LEFT JOIN users u ON u.id = l.user_id LEFT JOIN inv_projects p ON p.id = l.project_id LEFT JOIN customers c ON c.id = l.customer_id ORDER BY l.id DESC LIMIT 500'), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
inv_tabs('audit.php');
?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:12.5px;">
    <tr><th class="ps-3">Date &amp; time</th><th>By</th><th>Project</th><th>Action</th><th>Reason</th></tr>
    <?php foreach ($rows as $l) { ?><tr><td class="ps-3 text-nowrap"><?= date('d M Y, H:i', strtotime($l['created_at'])); ?></td><td><?= $e($l['names'] ?? ($l['customer'] ? 'Investor: ' . $l['customer'] : 'System')); ?></td>
        <td><?= $l['project_id'] ? '<a href="project.php?id=' . (int) $l['project_id'] . '">' . $e($l['project']) . '</a>' : 'Settings'; ?></td><td><?= $e($l['action']); ?></td><td class="small"><?= $e($l['reason']); ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted py-3">Nothing yet.</td></tr><?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>