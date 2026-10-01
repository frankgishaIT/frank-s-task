<?php
require '../../config/db.php';
require '../../includes/asset_helpers.php';
require_role(['Admin']);

// Catch up every active asset's depreciation before showing the list, so
// values and the total are always current as of "now".
asset_run_depreciation_catchup_all($conn);

$totalCurrentValue = asset_total_current_value($conn);

$assetsResult = mysqli_query($conn, 'SELECT assets.*, departments.name AS department_name
    FROM assets LEFT JOIN departments ON assets.responsible_department_id = departments.id
    ORDER BY FIELD(assets.status, "Active","Under Maintenance","Disposed","Lost"), created_at DESC');

function money($v) { return number_format((float) $v, 2); }

$statusBadge = [
    'Active' => ['#0FA968', '#E1F7EE'],
    'Under Maintenance' => ['#E68A1C', '#FDF1DF'],
    'Disposed' => ['#8A90A3', '#F1F3F9'],
    'Lost' => ['#E24B4A', '#FCEAEA'],
];

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Asset Management</h2>
    <a href="create.php" class="rm-btn rm-btn-primary"><i class="bi bi-plus-circle me-2"></i>Register Asset</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Total Current Assets Value</div>
            <div class="fs-4 fw-bold" style="color:var(--accent-blue);">RWF <?= money($totalCurrentValue); ?></div>
            <div class="text-muted" style="font-size:11px;">Sum of current values, Active assets only</div>
        </div></div>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>Asset Code</th>
            <th>Name</th>
            <th>Type</th>
            <th>Department</th>
            <th>Acquisition Value</th>
            <th>Current Value</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (mysqli_num_rows($assetsResult) === 0) { ?>
        <tr><td colspan="8" class="text-center text-muted py-4">No assets registered yet.</td></tr>
        <?php } ?>
        <?php while ($asset = mysqli_fetch_assoc($assetsResult)) {
            $badge = $statusBadge[$asset['status']] ?? ['#8A90A3', '#F1F3F9'];
        ?>
        <tr>
            <td><?= htmlspecialchars($asset['asset_code'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?= htmlspecialchars($asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?= htmlspecialchars($asset['asset_type'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?= htmlspecialchars($asset['department_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
            <td>RWF <?= money($asset['acquisition_value']); ?></td>
            <td>RWF <?= money($asset['current_value']); ?></td>
            <td><span style="font-size:11px; font-weight:700; color:<?= $badge[0]; ?>; background:<?= $badge[1]; ?>; padding:3px 10px; border-radius:8px;"><?= htmlspecialchars($asset['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
            <td><a href="view.php?id=<?= (int) $asset['id']; ?>" class="rm-btn rm-btn-light rm-btn-sm">View</a></td>
        </tr>
        <?php } ?>
    </tbody>
</table>

<?php include '../../includes/footer.php'; ?>