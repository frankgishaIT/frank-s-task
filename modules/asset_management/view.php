<?php
require '../../config/db.php';
require '../../includes/asset_helpers.php';
require_role(['Admin']);

function money($v) { return number_format((float) $v, 2); }

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid asset selected.'); exit; }

if (isset($_POST['update_status'])) {
    $newStatus = $_POST['status'] ?? '';
    if (in_array($newStatus, ['Active', 'Under Maintenance', 'Disposed', 'Lost'], true)) {
        $stmt = mysqli_prepare($conn, 'UPDATE assets SET status = ? WHERE id = ?');
        mysqli_stmt_bind_param($stmt, 'si', $newStatus, $id);
        mysqli_stmt_execute($stmt);
        header('Location: view.php?id=' . $id . '&success=' . urlencode('Status updated to ' . $newStatus . '.'));
        exit;
    }
}

// Bring this asset's value up to date before displaying it.
asset_run_depreciation_catchup($conn, $id);

$assetStatement = mysqli_prepare($conn, 'SELECT assets.*, departments.name AS department_name, users.names AS created_by_name
    FROM assets
    LEFT JOIN departments ON assets.responsible_department_id = departments.id
    LEFT JOIN users ON assets.created_by = users.id
    WHERE assets.id = ?');
mysqli_stmt_bind_param($assetStatement, 'i', $id);
mysqli_stmt_execute($assetStatement);
$asset = mysqli_fetch_assoc(mysqli_stmt_get_result($assetStatement));
if (!$asset) { header('Location: index.php?success=Asset not found.'); exit; }

$historyStatement = mysqli_prepare($conn, 'SELECT * FROM asset_valuation_history WHERE asset_id = ? ORDER BY valuation_date DESC');
mysqli_stmt_bind_param($historyStatement, 'i', $id);
mysqli_stmt_execute($historyStatement);
$history = mysqli_stmt_get_result($historyStatement);

$usefulLifeYears = round($asset['useful_life_days'] / 365, 1);
$dailyAmount = asset_daily_depreciation_amount((float) $asset['acquisition_value'], (float) $asset['residual_value'], (int) $asset['useful_life_days']);
$depreciatedSoFar = round((float) $asset['acquisition_value'] - (float) $asset['current_value'], 2);

$statusBadge = [
    'Active' => ['#0FA968', '#E1F7EE'],
    'Under Maintenance' => ['#E68A1C', '#FDF1DF'],
    'Disposed' => ['#8A90A3', '#F1F3F9'],
    'Lost' => ['#E24B4A', '#FCEAEA'],
];
$badge = $statusBadge[$asset['status']] ?? ['#8A90A3', '#F1F3F9'];

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2><?= htmlspecialchars($asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?>
        <span class="text-muted fs-6 fw-normal"><?= htmlspecialchars($asset['asset_code'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span style="font-size:11px; font-weight:700; color:<?= $badge[0]; ?>; background:<?= $badge[1]; ?>; padding:3px 10px; border-radius:8px; vertical-align:middle;"><?= htmlspecialchars($asset['status'], ENT_QUOTES, 'UTF-8'); ?></span>
    </h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Assets</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Acquisition Value</div>
            <div class="fs-5 fw-bold">RWF <?= money($asset['acquisition_value']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Current Value</div>
            <div class="fs-5 fw-bold" style="color:var(--accent-blue);">RWF <?= money($asset['current_value']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Depreciated So Far</div>
            <div class="fs-5 fw-bold" style="color:var(--accent-red);">RWF <?= money($depreciatedSoFar); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Daily Depreciation</div>
            <div class="fs-5 fw-bold">RWF <?= money($dailyAmount); ?></div>
        </div></div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h5 class="mb-3">Asset Details</h5>
                <div class="row small">
                    <div class="col-6 mb-2"><span class="text-muted">Type:</span> <strong><?= htmlspecialchars($asset['asset_type'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Department:</span> <strong><?= htmlspecialchars($asset['department_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Acquisition Date:</span> <strong><?= date('d M Y', strtotime($asset['acquisition_date'])); ?></strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Residual Value:</span> <strong>RWF <?= money($asset['residual_value']); ?></strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Useful Life:</span> <strong><?= $usefulLifeYears; ?> years (<?= (int) $asset['useful_life_days']; ?> days)</strong></div>
                    <div class="col-6 mb-2"><span class="text-muted">Method:</span> <strong><?= htmlspecialchars($asset['depreciation_method'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                    <div class="col-12"><span class="text-muted">Registered by:</span> <?= htmlspecialchars($asset['created_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?> on <?= date('d M Y', strtotime($asset['created_at'])); ?></div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="mb-3">Update Status</h5>
                <form method="POST" class="d-flex gap-2">
                    <select name="status" class="form-select rm-input">
                        <?php foreach (['Active', 'Under Maintenance', 'Disposed', 'Lost'] as $s) { ?>
                        <option value="<?= $s; ?>" <?= $asset['status'] === $s ? 'selected' : ''; ?>><?= $s; ?></option>
                        <?php } ?>
                    </select>
                    <button type="submit" name="update_status" class="rm-btn rm-btn-primary">Save</button>
                </form>
                <div class="form-text mt-2">Depreciation only runs while status is Active.</div>
            </div>
        </div>

    </div>

    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="mb-3">Value &amp; Depreciation History</h5>
                <div style="max-height:460px; overflow-y:auto;">
                <table>
                    <thead><tr><th>Date</th><th>Daily Depreciation</th><th>Value</th></tr></thead>
                    <tbody>
                        <?php if (mysqli_num_rows($history) === 0) { ?>
                        <tr><td colspan="3" class="text-center text-muted py-3">No history yet.</td></tr>
                        <?php } ?>
                        <?php while ($row = mysqli_fetch_assoc($history)) { ?>
                        <tr>
                            <td><?= date('d M Y', strtotime($row['valuation_date'])); ?></td>
                            <td><?= money($row['daily_depreciation']); ?></td>
                            <td><?= money($row['value']); ?></td>
                        </tr>
                        <?php } ?>
                    </tbody>
                </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>