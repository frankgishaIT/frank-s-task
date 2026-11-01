<?php
// NEW FILE: modules/rm_loans/audit.php — every action on RM customer loans (who, what, when, why).
require __DIR__ . '/loans_common.php';
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.*, u.names, c.name AS customer, a.created_at AS app_created FROM cl_audit l LEFT JOIN users u ON u.id = l.user_id
    LEFT JOIN cl_applications a ON a.id = l.application_id LEFT JOIN customers c ON c.id = COALESCE(l.customer_id, a.customer_id) ORDER BY l.id DESC LIMIT 500'), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
cl_tabs('audit.php');
?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:12.5px;">
    <tr><th class="ps-3">Date &amp; time</th><th>By</th><th>Application</th><th>Action</th><th>Status</th><th>Reason</th></tr>
    <?php foreach ($rows as $l) { ?><tr><td class="ps-3 text-nowrap"><?= date('d M Y, H:i', strtotime($l['created_at'])); ?></td><td><?= $e($l['names'] ?? ($l['customer_id'] ? 'Customer: ' . $l['customer'] : 'System')); ?></td>
        <td><?= $l['application_id'] ? '<a href="application.php?id=' . (int) $l['application_id'] . '">' . cl_ref((int) $l['application_id'], (string) $l['app_created']) . '</a>' : 'Settings'; ?></td>
        <td><?= $e($l['action']); ?></td><td class="small"><?= $l['to_status'] ? $e($l['from_status'] . ' → ' . $l['to_status']) : ''; ?></td><td class="small"><?= $e($l['reason']); ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-3">Nothing yet.</td></tr><?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>