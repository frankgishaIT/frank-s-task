<?php
// NEW FILE: modules/rm_invest/index.php — all investment projects with their funding.
require __DIR__ . '/invest_common.php';
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM inv_projects ORDER BY FIELD(status, "Published", "Active", "Funding Closed", "Paused", "Draft", "Completed", "Cancelled"), id DESC'), MYSQLI_ASSOC);
$todo = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM inv_applications WHERE status IN ('Submitted', 'Payment Pending Verification')"))[0];
include '../../includes/header.php'; include '../../includes/sidebar.php';
inv_tabs('index.php');
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <div><?= $todo ? '<a href="applications.php" class="btn btn-sm btn-outline-primary">' . $todo . ' application(s) / payment(s) to handle</a>' : ''; ?></div>
    <?php if (access_can('rm_invest.create')) { ?><a href="project.php" class="rm-btn rm-btn-primary rm-btn-sm">+ New investment opportunity</a><?php } ?>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Project</th><th>Status</th><th class="text-end">Required</th><th style="width:22%;">Raised (verified)</th><th class="text-end">Investors</th><th>Deadline</th><th>Results</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">No investment opportunities yet.</td></tr><?php } ?>
    <?php foreach ($rows as $p) { $f = inv_funding($conn, (int) $p['id']); ?>
    <tr><td class="ps-3"><?= $e($p['name']); ?><div class="small text-muted"><?= inv_project_ref($p); ?> · <?= $e($p['category']); ?></div></td><td><?= inv_badge($p['status']); ?></td>
        <td class="text-end"><?= cl_money($p['capital_required']); ?></td>
        <td><div style="height:8px; background:#EEF1F7; border-radius:4px;"><div style="width:<?= $f['pct']; ?>%; height:8px; background:#0FA968; border-radius:4px;"></div></div><span class="small"><?= cl_money($f['raised']); ?> (<?= $f['pct']; ?>%) · committed <?= cl_money($f['committed']); ?></span></td>
        <td class="text-end"><?= $f['investors']; ?><?= $p['max_investors'] ? ' / ' . (int) $p['max_investors'] : ''; ?></td><td class="small"><?= $p['funding_deadline'] ? date('d M Y', strtotime($p['funding_deadline'])) : '—'; ?></td>
        <td class="small"><?= $e($p['results_status']); ?></td><td><a href="project.php?id=<?= (int) $p['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">Open</a></td></tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>