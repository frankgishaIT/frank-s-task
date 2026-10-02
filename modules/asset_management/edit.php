<?php
require '../../config/db.php';
require '../../includes/asset_helpers.php';
require_role(['Admin']);

$assetId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$assetId) { header('Location: index.php'); exit; }

$assetStmt = mysqli_prepare($conn, 'SELECT * FROM assets WHERE id = ?');
mysqli_stmt_bind_param($assetStmt, 'i', $assetId);
mysqli_stmt_execute($assetStmt);
$asset = mysqli_fetch_assoc(mysqli_stmt_get_result($assetStmt));
if (!$asset) { header('Location: index.php'); exit; }

// Active departments, plus the asset's current one even if it was deactivated.
$departments = mysqli_query($conn, 'SELECT * FROM departments WHERE is_active = 1 OR id = ' . (int) $asset['responsible_department_id'] . ' ORDER BY name');
$departmentList = [];
while ($d = mysqli_fetch_assoc($departments)) { $departmentList[] = $d; }

$assetTypes = ['Vehicle', 'Equipment', 'Furniture', 'Computer & IT', 'Building', 'Land', 'Machinery', 'Other'];
if (!in_array($asset['asset_type'], $assetTypes, true)) { array_unshift($assetTypes, $asset['asset_type']); }

// Form value helper: posted value after a failed save, otherwise the stored value.
$val = function ($key, $default = '') { return $_POST[$key] ?? $default; };
$storedLifeYears = round(((int) $asset['useful_life_days']) / 365, 4);

if (isset($_POST['save'])) {
    $assetName = trim($_POST['asset_name'] ?? '');
    $assetType = trim($_POST['asset_type'] ?? '');
    $acquisitionDate = $_POST['acquisition_date'] ?? '';
    $acquisitionValue = filter_input(INPUT_POST, 'acquisition_value', FILTER_VALIDATE_FLOAT);
    $residualValue = filter_input(INPUT_POST, 'residual_value', FILTER_VALIDATE_FLOAT);
    $usefulLifeYears = filter_input(INPUT_POST, 'useful_life_years', FILTER_VALIDATE_FLOAT);
    $departmentId = filter_input(INPUT_POST, 'responsible_department_id', FILTER_VALIDATE_INT) ?: null;
    $status = $_POST['status'] ?? 'Active';

    $validDate = DateTime::createFromFormat('Y-m-d', $acquisitionDate);
    $validStatuses = ['Active', 'Under Maintenance', 'Disposed', 'Lost'];

    if ($assetName === '' || $assetType === '') {
        $error = 'Please provide the Asset Name and Asset Type.';
    } elseif (!$validDate || $validDate->format('Y-m-d') !== $acquisitionDate) {
        $error = 'Please provide a valid Acquisition Date.';
    } elseif ($acquisitionValue === false || $acquisitionValue === null || $acquisitionValue <= 0) {
        $error = 'Please provide a valid Acquisition Value.';
    } elseif ($residualValue === false || $residualValue === null || $residualValue < 0) {
        $error = 'Please provide a valid Residual Value (0 or more).';
    } elseif ($residualValue >= $acquisitionValue) {
        $error = 'Residual Value must be less than Acquisition Value.';
    } elseif ($usefulLifeYears === false || $usefulLifeYears === null || $usefulLifeYears <= 0) {
        $error = 'Please provide a valid Useful Life (in years).';
    } elseif (!in_array($status, $validStatuses, true)) {
        $error = 'Invalid status.';
    } else {
        $usefulLifeDays = (int) round($usefulLifeYears * 365);

        mysqli_begin_transaction($conn);
        try {
            // Lock the asset row and read its latest state.
            $lock = mysqli_prepare($conn, 'SELECT * FROM assets WHERE id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $assetId);
            mysqli_stmt_execute($lock);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            mysqli_stmt_close($lock);

            $termsChanged =
                $current['acquisition_date'] !== $acquisitionDate
                || abs((float) $current['acquisition_value'] - $acquisitionValue) > 0.001
                || abs((float) $current['residual_value'] - $residualValue) > 0.001
                || (int) $current['useful_life_days'] !== $usefulLifeDays;

            if (!$termsChanged && $current['status'] === 'Active') {
                // Bring depreciation up to today before the status can freeze it.
                asset_run_depreciation_catchup($conn, $assetId);
            }

            // Where the rebuilt history should stop. Active (before or after the
            // edit) runs up to today; an asset that was already frozen stays
            // frozen at the last date it was valued.
            $endDate = date('Y-m-d');
            if ($termsChanged && $current['status'] !== 'Active' && $status !== 'Active') {
                $lastRow = mysqli_fetch_row(mysqli_query($conn, 'SELECT MAX(valuation_date) FROM asset_valuation_history WHERE asset_id = ' . (int) $assetId));
                $endDate = $lastRow[0] ?: $acquisitionDate;
            }

            $upd = mysqli_prepare($conn, 'UPDATE assets SET
                asset_name = ?, asset_type = ?, acquisition_date = ?, acquisition_value = ?, residual_value = ?,
                useful_life_days = ?, responsible_department_id = ?, status = ?
                WHERE id = ?');
            mysqli_stmt_bind_param($upd, 'sssddiisi',
                $assetName, $assetType, $acquisitionDate, $acquisitionValue, $residualValue,
                $usefulLifeDays, $departmentId, $status, $assetId);
            mysqli_stmt_execute($upd);
            mysqli_stmt_close($upd);

            if ($termsChanged) {
                asset_rebuild_history($conn, $assetId, $acquisitionDate, $acquisitionValue, $residualValue, $usefulLifeDays, $endDate);
            }

            mysqli_commit($conn);
            header('Location: view.php?id=' . $assetId . '&success=' . urlencode('Asset updated' . ($termsChanged ? ' and valuation history recalculated.' : '.')));
            exit;
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            $error = 'Unable to update the asset. Please try again.';
        }
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Edit Asset <span class="text-muted fs-6 fw-normal"><?= htmlspecialchars($asset['asset_code'], ENT_QUOTES, 'UTF-8'); ?></span></h2>
    <a href="view.php?id=<?= (int) $assetId; ?>" class="rm-btn rm-btn-light">Back to Asset</a>
</div>

<?php if (isset($error)) { ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
    <i class="bi bi-exclamation-circle-fill"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php } ?>

<div class="alert alert-warning mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
    <i class="bi bi-info-circle-fill me-1"></i>
    If you change the Acquisition Date, Acquisition Value, Residual Value, or Useful Life, the asset's valuation history is deleted and rebuilt from the corrected figures, and the Current Value is recalculated.
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">Asset Name</label>
                    <input type="text" name="asset_name" class="form-control rm-input" value="<?= htmlspecialchars((string) $val('asset_name', $asset['asset_name']), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">Asset Type</label>
                    <select name="asset_type" class="form-select rm-input" required>
                        <?php foreach ($assetTypes as $t) { ?>
                        <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>" <?= $val('asset_type', $asset['asset_type']) === $t ? 'selected' : ''; ?>><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Acquisition Date</label>
                    <input type="date" name="acquisition_date" class="form-control rm-input" value="<?= htmlspecialchars((string) $val('acquisition_date', $asset['acquisition_date']), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Acquisition Value (RWF)</label>
                    <input type="number" name="acquisition_value" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars((string) $val('acquisition_value', $asset['acquisition_value']), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Residual Value (RWF)</label>
                    <input type="number" name="residual_value" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars((string) $val('residual_value', $asset['residual_value']), ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">Estimated value at the end of its useful life. Use 0 if none.</div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Useful Life (Years)</label>
                    <input type="number" name="useful_life_years" class="form-control rm-input" min="0.1" step="any" value="<?= htmlspecialchars((string) $val('useful_life_years', $storedLifeYears), ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">Converted to days internally (&times; 365) for daily depreciation.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Depreciation Method</label>
                    <input type="text" class="form-control rm-input" value="Straight-Line" disabled>
                    <div class="form-text">Only method currently supported.</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Responsible Department</label>
                    <select name="responsible_department_id" class="form-select rm-input">
                        <option value="">— None —</option>
                        <?php foreach ($departmentList as $d) { ?>
                        <option value="<?= (int) $d['id']; ?>" <?= (int) $val('responsible_department_id', $asset['responsible_department_id']) === (int) $d['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-semibold text-muted">Asset Status</label>
                <select name="status" class="form-select rm-input" required>
                    <?php foreach (['Active', 'Under Maintenance', 'Disposed', 'Lost'] as $s) { ?>
                    <option value="<?= $s; ?>" <?= $val('status', $asset['status']) === $s ? 'selected' : ''; ?>><?= $s; ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Only Active assets keep depreciating. The Asset Code cannot be changed.</div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-end">
                <button type="submit" name="save" class="rm-btn rm-btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Save Changes</button>
                <a href="view.php?id=<?= (int) $assetId; ?>" class="rm-btn rm-btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>