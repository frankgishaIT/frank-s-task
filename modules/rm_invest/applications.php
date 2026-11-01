<?php
// NEW FILE: modules/rm_invest/applications.php — all investment applications and their contributions.
require __DIR__ . '/invest_common.php';
$st = in_array($_GET['status'] ?? '', INV_APP_STATUSES, true) ? $_GET['status'] : '';
$w = $st !== '' ? "a.status = '" . mysqli_real_escape_string($conn, $st) . "'" : (($_GET['all'] ?? '') ? "a.status <> 'Draft'" : "a.status IN ('Submitted', 'Under Review', 'Additional Information Required', 'Payment Pending Verification', 'Awaiting Payment')");
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, c.name, c.phone, p.name AS project, k.status AS pay_status, k.contribution_ref FROM inv_applications a JOIN customers c ON c.id = a.customer_id
    JOIN inv_projects p ON p.id = a.project_id LEFT JOIN inv_contributions k ON k.application_id = a.id WHERE $w ORDER BY a.updated_at DESC, a.id DESC LIMIT 300"), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
inv_tabs('applications.php');
?>
<div class="d-flex flex-wrap gap-1 mb-3"><a href="applications.php" class="btn btn-sm <?= $st === '' && empty($_GET['all']) ? 'btn-primary' : 'btn-outline-primary'; ?>">To act on</a><a href="applications.php?all=1" class="btn btn-sm <?= !empty($_GET['all']) ? 'btn-primary' : 'btn-outline-primary'; ?>">All</a>
    <?php foreach (INV_APP_STATUSES as $s) { if ($s === 'Draft') { continue; } ?><a href="applications.php?status=<?= urlencode($s); ?>" class="btn btn-sm <?= $st === $s ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?= $e($s); ?></a><?php } ?></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Reference</th><th>Investor</th><th>Project</th><th class="text-end">Amount</th><th>Status</th><th>Contribution</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-4">Nothing here.</td></tr><?php } ?>
    <?php foreach ($rows as $a) { ?><tr><td class="ps-3"><?= inv_app_ref($a); ?></td><td><?= $e($a['name']); ?><div class="small text-muted"><?= $e($a['phone']); ?></div></td><td class="small"><?= $e($a['project']); ?></td>
        <td class="text-end"><?= cl_money($a['amount']); ?></td><td><?= inv_badge($a['status']); ?></td><td class="small"><?= $a['contribution_ref'] ? $e($a['contribution_ref'] . ' · ' . $a['pay_status']) : '—'; ?></td>
        <td><a href="application.php?id=<?= (int) $a['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">Open</a></td></tr><?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>