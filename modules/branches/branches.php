<?php
/**
 * NEW FILE: modules/branches/branches.php — the list of branches (only those the user may see).
 */
require __DIR__ . '/common.php';
br_require_setup($conn);
$allowed = br_allowed_ids($conn);
$st = in_array($_GET['status'] ?? '', BR_STATUSES, true) ? $_GET['status'] : '';
$rows = array_filter(br_all($conn), function ($b) use ($allowed, $st) { return ($allowed === null || in_array((int) $b['id'], $allowed, true)) && ($st === '' || $b['status'] === $st); });
$users = br_users($conn, false);
$staff = [];
foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT branch_id, COUNT(*) n FROM users WHERE is_active = 1 GROUP BY branch_id'), MYSQLI_ASSOC) as $r) { $staff[(int) $r['branch_id']] = (int) $r['n']; }

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'branches.php');
?>
<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <div class="btn-group btn-group-sm"><?php foreach (['' => 'All', 'Active' => 'Active', 'Suspended' => 'Suspended', 'Closed' => 'Closed'] as $k => $l) { ?><a href="?status=<?= $k; ?>" class="btn btn-outline-secondary <?= $st === $k ? 'active' : ''; ?>"><?= $l; ?></a><?php } ?></div>
    <?php if (br_head_office($conn)) { ?><a href="create.php" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Register branch</a><?php } ?>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm align-middle mb-0" style="font-size:13.5px;">
    <tr><th class="ps-3">Code</th><th>Branch</th><th>Type</th><th>Location</th><th>Manager</th><th class="text-end">Staff</th><th>Opened</th><th>Status</th><th></th></tr>
    <?php foreach ($rows as $b) { ?>
    <tr>
        <td class="ps-3 fw-semibold"><?= br_e($b['code']); ?></td>
        <td><a href="view.php?id=<?= (int) $b['id']; ?>"><?= br_e($b['name']); ?></a><?= $b['is_main'] ? ' <span class="badge bg-primary">Main · Default</span>' : ''; ?></td>
        <td><?= br_e($b['branch_type']); ?></td>
        <td class="small"><?= br_e(implode(', ', array_filter([$b['sector'], $b['district'], $b['province']]))); ?></td>
        <td><?= br_e($users[(int) $b['manager_user_id']]['names'] ?? '—'); ?></td>
        <td class="text-end"><?= $staff[(int) $b['id']] ?? 0; ?></td>
        <td><?= $b['opening_date'] ? date('d M Y', strtotime($b['opening_date'])) : '—'; ?></td>
        <td><?= br_badge($b['status']); ?></td>
        <td class="text-nowrap"><a href="view.php?id=<?= (int) $b['id']; ?>" class="btn btn-sm btn-outline-primary">Profile</a></td>
    </tr>
    <?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-4">No branches.</td></tr><?php } ?>
</table></div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>