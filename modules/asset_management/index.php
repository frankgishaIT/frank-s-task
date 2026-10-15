<?php
require '../../config/db.php';
require '../../includes/asset_helpers.php';
require_role(['Admin']);

// Catch up every active asset's depreciation before showing the list, so
// values and the total are always current as of "now".
asset_run_depreciation_catchup_all($conn);

$totalCurrentValue = asset_total_current_value($conn);

// NEW (section 4): refunds still due from sellers for assets returned to them.
$refundsDue = [];
try {
    $refundsDue = mysqli_fetch_all(mysqli_query($conn, "SELECT d.id, d.disposal_date, d.proceeds, d.notes, a.asset_code, a.asset_name
        FROM asset_disposals d JOIN assets a ON a.id = d.asset_id WHERE d.refund_status = 'Receivable' ORDER BY d.disposal_date"), MYSQLI_ASSOC);
} catch (Throwable $e) { $refundsDue = []; }

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
<?php if (isset($_GET['error'])) { ?>
<!-- NEW: error messages (e.g. from Remove Asset) were not shown on this page. -->
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['error'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Asset Management</h2>
    <!-- CHANGED: both buttons together on the right (before, Register Asset sat in the middle). -->
    <div class="d-flex gap-2">
        <?php if (in_array(current_user_role(), ['Admin', 'Manager'], true)) { ?>
            <a href="reports.php" class="btn btn-outline-primary">Reports</a>
        <?php } ?>
        <a href="create.php" class="rm-btn rm-btn-primary"><i class="bi bi-plus-circle me-2"></i>Register Asset</a>
    </div>
</div>

<?php if ($refundsDue) { ?>
<!-- NEW: refunds due from sellers -->
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <h6 class="mb-2">Refunds due from sellers</h6>
    <table class="table table-sm mb-0 align-middle" style="font-size:13px;">
        <?php foreach ($refundsDue as $r) { ?>
        <tr><td><?= htmlspecialchars($r['asset_code'] . ' — ' . $r['asset_name'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td>Returned <?= date('d M Y', strtotime($r['disposal_date'])); ?></td>
            <td class="text-end fw-semibold">RWF <?= money($r['proceeds']); ?></td>
            <td class="text-end"><form method="POST" action="refund_receive.php" class="d-inline" onsubmit="return confirm('Has this refund been received? It will be added to the RM Capital Fund.');">
                <input type="hidden" name="disposal_id" value="<?= (int) $r['id']; ?>"><button class="btn btn-success btn-sm">Mark received</button></form></td></tr>
        <?php } ?>
    </table>
</div></div>
<?php } ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Total Current Assets Value</div>
            <div class="fs-4 fw-bold" style="color:var(--accent-blue);">RWF <?= money($totalCurrentValue); ?></div>
            <!-- CHANGED: the total now includes Under Maintenance assets (they are still owned), the same as the RM Capital Fund. -->
            <div class="text-muted" style="font-size:11px;">Sum of current values, Active and Under Maintenance assets</div>
        </div></div>
    </div>
</div>

<!-- CHANGED: same card and table style as the other lists. -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
        <table class="table table-bordered table-hover bg-white mb-0 align-middle">
            <thead>
                <tr>
                    <th>Asset Code</th>
                    <th>Name</th>
                    <th>Type</th>
                    <th>Department</th>
                    <th class="text-end">Acquisition Value</th>
                    <th class="text-end">Current Value</th>
                    <th>Status</th>
                    <th>Funding</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (mysqli_num_rows($assetsResult) === 0) { ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No assets registered yet.</td></tr>
                <?php } ?>
                <?php while ($asset = mysqli_fetch_assoc($assetsResult)) {
                    $badge = $statusBadge[$asset['status']] ?? ['#8A90A3', '#F1F3F9'];
                    $inUse = in_array($asset['status'], ['Active', 'Under Maintenance'], true);
                ?>
                <tr>
                    <td><?= htmlspecialchars($asset['asset_code'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars($asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars($asset['asset_type'], ENT_QUOTES, 'UTF-8'); ?></td>
                    <td><?= htmlspecialchars($asset['department_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                    <td class="text-end">RWF <?= money($asset['acquisition_value']); ?></td>
                    <td class="text-end">RWF <?= money($asset['current_value']); ?></td>
                    <td><span style="font-size:11px; font-weight:700; color:<?= $badge[0]; ?>; background:<?= $badge[1]; ?>; padding:3px 10px; border-radius:8px;"><?= htmlspecialchars($asset['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <!-- NEW (section 3): the funding record of each asset -->
                    <td style="font-size:12px;"><?= ($asset['funding_type'] ?? '') === 'Capital Fund purchase'
                        ? '<span class="badge bg-success">Paid from Capital Fund</span>' . (!empty($asset['funding_transaction_id']) ? '<div class="text-muted">Payment #' . (int) $asset['funding_transaction_id'] . '</div>' : '')
                        : '<span class="badge bg-secondary">Opening balance</span>'; ?></td>
                    <td class="text-nowrap">
                        <a href="view.php?id=<?= (int) $asset['id']; ?>" class="rm-btn rm-btn-light rm-btn-sm">View</a>
                        <a href="valuation.php?id=<?= (int) $asset['id']; ?>" class="rm-btn rm-btn-light rm-btn-sm" title="Valuation method, impairment, revaluation">Valuation</a>
                        <a href="edit.php?id=<?= (int) $asset['id']; ?>" class="rm-btn rm-btn-light rm-btn-sm">Edit</a>
                        <?php if ($inUse) { ?>
                            <!-- NEW: sold, stolen, damaged or thrown away (records the gain or loss and updates the RM Capital Fund). -->
                            <a href="dispose.php?id=<?= (int) $asset['id']; ?>" class="rm-btn rm-btn-danger rm-btn-sm">Remove</a>
                        <?php } ?>
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>