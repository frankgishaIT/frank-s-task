<?php
require '../../config/db.php';
require '../../includes/asset_helpers.php';
require_role(['Admin']);

$departments = mysqli_query($conn, 'SELECT * FROM departments WHERE is_active = 1 ORDER BY name');
$departmentList = [];
while ($d = mysqli_fetch_assoc($departments)) { $departmentList[] = $d; }

$assetTypes = ['Vehicle', 'Equipment', 'Furniture', 'Computer & IT', 'Building', 'Land', 'Machinery', 'Other'];

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
    } elseif ($acquisitionValue === false || $acquisitionValue <= 0) {
        $error = 'Please provide a valid Acquisition Value.';
    } elseif ($residualValue === false || $residualValue < 0) {
        $error = 'Please provide a valid Residual Value (0 or more).';
    } elseif ($residualValue >= $acquisitionValue) {
        $error = 'Residual Value must be less than Acquisition Value.';
    } elseif ($usefulLifeYears === false || $usefulLifeYears <= 0) {
        $error = 'Please provide a valid Useful Life (in years).';
    } elseif (!in_array($status, $validStatuses, true)) {
        $error = 'Invalid status.';
    } else {
        $usefulLifeDays = (int) round($usefulLifeYears * 365);
        $userId = current_user_id();

        mysqli_begin_transaction($conn);
        try {
            $insert = mysqli_prepare($conn, 'INSERT INTO assets
                (asset_code, asset_name, asset_type, acquisition_date, acquisition_value, residual_value,
                 useful_life_days, current_value, responsible_department_id, status, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $placeholderCode = 'PENDING';
            // Column order: asset_code(s) asset_name(s) asset_type(s) acquisition_date(s)
            // acquisition_value(d) residual_value(d) useful_life_days(i) current_value(d)
            // responsible_department_id(i) status(s) created_by(i)
            mysqli_stmt_bind_param($insert, 'ssssddidisi',
                $placeholderCode, $assetName, $assetType, $acquisitionDate, $acquisitionValue, $residualValue,
                $usefulLifeDays, $acquisitionValue, $departmentId, $status, $userId);
            mysqli_stmt_execute($insert);
            $assetId = mysqli_insert_id($conn);

            $assetCode = asset_generate_code($conn, $assetId);
            $updateCode = mysqli_prepare($conn, 'UPDATE assets SET asset_code = ? WHERE id = ?');
            mysqli_stmt_bind_param($updateCode, 'si', $assetCode, $assetId);
            mysqli_stmt_execute($updateCode);

            // Seed day-zero valuation history immediately.
            asset_run_depreciation_catchup($conn, $assetId);

            mysqli_commit($conn);
            header('Location: view.php?id=' . $assetId . '&success=' . urlencode('Asset ' . $assetCode . ' registered.'));
            exit;
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $error = 'Unable to save the asset. Please try again.';
        }
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Register New Asset</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Assets</a>
</div>

<?php if (isset($error)) { ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
    <i class="bi bi-exclamation-circle-fill"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST">
            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">Asset Name</label>
                    <input type="text" name="asset_name" class="form-control rm-input" value="<?= htmlspecialchars($_POST['asset_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">Asset Type</label>
                    <select name="asset_type" class="form-select rm-input" required>
                        <?php foreach ($assetTypes as $t) { ?>
                        <option value="<?= $t; ?>" <?= ($_POST['asset_type'] ?? '') === $t ? 'selected' : ''; ?>><?= $t; ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Acquisition Date</label>
                    <input type="date" name="acquisition_date" class="form-control rm-input" value="<?= htmlspecialchars($_POST['acquisition_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Acquisition Value (RWF)</label>
                    <input type="number" name="acquisition_value" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars($_POST['acquisition_value'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Residual Value (RWF)</label>
                    <input type="number" name="residual_value" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars($_POST['residual_value'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">Estimated value at the end of its useful life. Use 0 if none.</div>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Useful Life (Years)</label>
                    <input type="number" name="useful_life_years" class="form-control rm-input" min="0.1" step="0.1" value="<?= htmlspecialchars($_POST['useful_life_years'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
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
                        <option value="<?= (int) $d['id']; ?>" <?= (int) ($_POST['responsible_department_id'] ?? 0) === (int) $d['id'] ? 'selected' : ''; ?>><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-semibold text-muted">Asset Status</label>
                <select name="status" class="form-select rm-input" required>
                    <?php foreach (['Active', 'Under Maintenance', 'Disposed', 'Lost'] as $s) { ?>
                    <option value="<?= $s; ?>" <?= ($_POST['status'] ?? 'Active') === $s ? 'selected' : ''; ?>><?= $s; ?></option>
                    <?php } ?>
                </select>
                <div class="form-text">Asset Code is generated automatically after saving.</div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-end">
                <button type="submit" name="save" class="rm-btn rm-btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Register Asset</button>
                <a href="index.php" class="rm-btn rm-btn-light">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>