<?php
require '../../config/db.php';
require_once '../../includes/admin_error_guard.php'; // NEW: shows the Admin the exact reason if this page fails
require '../../includes/asset_helpers.php';
require_once '../../includes/asset_fund_helpers.php'; // NEW: keeps the asset's value in the RM Capital Fund
require_once '../../includes/asset_capital_helpers.php'; // NEW: purchase paid from the RM Capital Fund
asset_capital_ensure_schema($conn); // NEW: adds any missing table / column of this feature automatically
require_role(['Admin']);

$departments = mysqli_query($conn, 'SELECT * FROM departments WHERE is_active = 1 ORDER BY name');
$departmentList = [];
while ($d = mysqli_fetch_assoc($departments)) { $departmentList[] = $d; }

require_once '../../includes/category_helpers.php'; // NEW (Task 3): Admin-managed categories
$assetTypes = categories_active($conn, 'asset_type'); // CHANGED (Task 3): managed by Admins under Categories

// CHANGED: a new asset can only be registered as Active or Under Maintenance.
// Disposed and Lost are outcomes that must go through the asset's disposal / loss action,
// which records the loss and removes the value from the RM Capital Fund (spec section 12).
$createStatuses = ['Active', 'Under Maintenance'];

// NEW (Capital Fund integration): what the fund can pay right now, shown on the form.
$installed = asset_capital_installed($conn);
$reconciled = $installed && asset_capital_reconciled($conn);
$capitalCheck = asset_capital_funding_check($conn, 0);

if (isset($_POST['save'])) {
    $assetName = trim($_POST['asset_name'] ?? '');
    $assetType = trim($_POST['asset_type'] ?? '');
    $acquisitionDate = $_POST['acquisition_date'] ?? '';
    $acquisitionValue = filter_input(INPUT_POST, 'acquisition_value', FILTER_VALIDATE_FLOAT);
    $residualValue = filter_input(INPUT_POST, 'residual_value', FILTER_VALIDATE_FLOAT);
    $usefulLifeYears = filter_input(INPUT_POST, 'useful_life_years', FILTER_VALIDATE_FLOAT);
    $departmentId = filter_input(INPUT_POST, 'responsible_department_id', FILTER_VALIDATE_INT) ?: null;
    $status = $_POST['status'] ?? 'Active';
    // NEW: valuation treatment and funding.
    $assetClass = $_POST['asset_class'] ?? 'Tangible';
    $valuationMethod = $_POST['valuation_method'] ?? 'Depreciation';
    $fundingType = $_POST['funding_type'] ?? 'Capital Fund purchase';
    $fundingNote = trim($_POST['funding_note'] ?? '');

    $validDate = DateTime::createFromFormat('Y-m-d', $acquisitionDate);

    if ($assetName === '' || $assetType === '') {
        $error = 'Please provide the Asset Name and Asset Type.';
    } elseif (!in_array($assetType, $assetTypes, true)) {
        // NEW: only the listed asset types are accepted.
        $error = 'Invalid Asset Type.';
    } elseif (!$validDate || $validDate->format('Y-m-d') !== $acquisitionDate) {
        $error = 'Please provide a valid Acquisition Date.';
    } elseif ($acquisitionDate > date('Y-m-d')) {
        // NEW: a future date would add value to the Capital Fund before the asset exists.
        $error = 'The Acquisition Date cannot be in the future.';
    } elseif ($acquisitionValue === false || $acquisitionValue === null || $acquisitionValue <= 0) {
        $error = 'Please provide a valid Acquisition Value.';
    } elseif ($residualValue === false || $residualValue === null || $residualValue < 0) {
        $error = 'Please provide a valid Residual Value (0 or more).';
    } elseif ($residualValue >= $acquisitionValue) {
        $error = 'Residual Value must be less than Acquisition Value.';
    } elseif ($usefulLifeYears === false || $usefulLifeYears === null || $usefulLifeYears <= 0) {
        $error = 'Please provide a valid Useful Life (in years).';
    } elseif (!in_array($status, $createStatuses, true)) {
        $error = 'A new asset can only be registered as Active or Under Maintenance.';
    } elseif (!in_array($assetClass, ASSET_CLASSES, true) || !in_array($valuationMethod, ASSET_VALUATION_METHODS, true)) {
        $error = 'Please choose a valid asset class and valuation method.';
    } elseif ($assetClass === 'Tangible' && $valuationMethod === 'Amortization') {
        $error = 'Amortization is for intangible assets (software, development costs). Use Depreciation for a tangible asset.';
    } elseif ($assetClass !== 'Tangible' && $valuationMethod === 'Depreciation') {
        $error = 'An intangible asset is amortized, not depreciated. Choose Amortization (or No depreciation for an indefinite life).';
    } elseif (!in_array($fundingType, ['Capital Fund purchase', 'Opening balance'], true)) {
        $error = 'Please choose how the asset is funded.';
    } elseif ($fundingType === 'Opening balance' && mb_strlen($fundingNote) < 10) {
        $error = 'An asset already owned (no payment now) needs a note explaining where it comes from (at least 10 characters).';
    } elseif (!$installed) {
        $needsReconciliation = true;
        $error = 'Nothing was saved. This database needs its update first: open Database Migrations and click Run the missing migration(s).';
    } elseif ($fundingType === 'Capital Fund purchase' && !$reconciled) {
        // Section 2: existing records are reconciled BEFORE the purchase rule is used.
        $needsReconciliation = true; // shows the step-by-step box with the button (nothing was saved)
        $error = 'Nothing was saved. Asset purchases are paused until the RM Capital Fund opening reconciliation is posted (see the steps below).';
    } elseif ($fundingType === 'Capital Fund purchase' && !($chk = asset_capital_funding_check($conn, (float) $acquisitionValue))['ok']) {
        // Section 1: blocked, with the available balance, the amount required and the shortfall.
        $error = asset_funding_message($chk);
    } else {
        $acquisitionValue = round($acquisitionValue, 2);
        $residualValue = round($residualValue, 2);
        $usefulLifeDays = (int) round($usefulLifeYears * 365);
        $userId = current_user_id();

        mysqli_begin_transaction($conn);
        try {
            $insert = mysqli_prepare($conn, 'INSERT INTO assets
                (asset_code, asset_name, asset_type, asset_class, valuation_method, acquisition_date, acquisition_value, residual_value,
                 useful_life_days, current_value, responsible_department_id, status, funding_type, funding_note, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $placeholderCode = 'PENDING';
            $noteValue = $fundingNote !== '' ? mb_substr($fundingNote, 0, 255) : null;
            mysqli_stmt_bind_param($insert, 'ssssssddidisssi',
                $placeholderCode, $assetName, $assetType, $assetClass, $valuationMethod, $acquisitionDate, $acquisitionValue, $residualValue,
                $usefulLifeDays, $acquisitionValue, $departmentId, $status, $fundingType, $noteValue, $userId);
            mysqli_stmt_execute($insert);
            $assetId = mysqli_insert_id($conn);

            $assetCode = asset_generate_code($conn, $assetId);
            $updateCode = mysqli_prepare($conn, 'UPDATE assets SET asset_code = ? WHERE id = ?');
            mysqli_stmt_bind_param($updateCode, 'si', $assetCode, $assetId);
            mysqli_stmt_execute($updateCode);

            // NEW (section 1): the purchase is PAID from the RM Capital Fund, once, linked to the asset.
            // The check is repeated here under the fund lock (two purchases at the same moment).
            if ($fundingType === 'Capital Fund purchase') {
                asset_pay_from_capital($conn, (int) $assetId, (float) $acquisitionValue, $acquisitionDate,
                    'Asset purchase ' . $assetCode . ': ' . $assetName, $userId ? (int) $userId : null);
            }

            // Seed day-zero valuation history immediately.
            asset_run_depreciation_catchup($conn, $assetId);

            // NEW (spec section 9): the asset's value is added to the RM Capital Fund.
            // Runs AFTER the depreciation catch-up, so an older asset (acquisition date in the
            // past) is added at its current value, not its original price.
            asset_sync_capital_fund($conn, (int) $assetId, 'ASSET_IN', $userId ? (int) $userId : null,
                'Asset ' . $assetCode . ' registered: ' . $assetName);

            mysqli_commit($conn);
            $msg = 'Asset ' . $assetCode . ' registered.' . ($fundingType === 'Capital Fund purchase'
                ? ' RWF ' . number_format((float) $acquisitionValue, 2) . ' was paid from the RM Capital Fund.' : ' Recorded as an opening balance (no payment).');
            header('Location: view.php?id=' . $assetId . '&success=' . urlencode($msg));
            exit;
        } catch (InsufficientFundException $e) {
            mysqli_rollback($conn);
            $error = asset_funding_message(asset_capital_funding_check($conn, (float) $acquisitionValue));
        } catch (Throwable $e) {
            // CHANGED: Throwable (was Exception), so PHP errors are also rolled back, and the cause is logged.
            mysqli_rollback($conn);
            error_log('asset create failed: ' . $e->getMessage());
            $error = 'Unable to save the asset. Nothing was saved. Please try again.'
                . ' [Technical detail for Admin: ' . $e->getMessage() . ']'; // this page is Admin-only
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

<?php if (!$reconciled) { ?>
<!-- NEW: explains, BEFORE the form is filled in, why purchases are paused and what to do. -->
<div class="card border-0 shadow-sm mb-3 asset-pause-box" style="background:#1E2FE0; color:#FFFFFF;">
    <style>
        .asset-pause-box, .asset-pause-box strong, .asset-pause-box h6, .asset-pause-box li, .asset-pause-box p,
        .asset-pause-box .small, .asset-pause-box a:not(.btn) { color:#FFFFFF !important; }
        .asset-pause-box a:not(.btn) { text-decoration: underline; }
    </style>
    <div class="card-body">
        <h6 class="mb-2"><i class="bi bi-pause-circle-fill me-1"></i>Asset purchases are paused for a one-time check</h6>
        <p class="small mb-2">From now on, every asset bought is <strong>paid from the RM Capital Fund</strong>. Before the first purchase,
            the system must confirm how much money the Capital Fund really has (the <strong>opening reconciliation</strong>).
            This is done <strong>once</strong>, by an Admin, and takes about 2 minutes. Nothing is lost while it is not done.</p>
        <ol class="small mb-2">
            <?php if (!$installed) { ?>
            <li>First open <a href="../tools/migrations.php" target="_blank" rel="noopener"><strong>Database Migrations</strong></a> and click <strong>Run the missing migration(s)</strong>.</li>
            <?php } ?>
            <li>Click <strong>Open the reconciliation</strong> (it opens in a new tab, so this form stays filled in).</li>
            <li>Check the <strong>Opening reconciliation</strong> table, write the supporting note, and click <strong>Post opening reconciliation</strong>.</li>
            <li>Come back to this tab and click <strong>Register Asset</strong> again.</li>
        </ol>
        <a href="../transactions/capital_reconciliation.php" target="_blank" rel="noopener" class="btn btn-light btn-sm fw-semibold" style="color:#1E2FE0;"><i class="bi bi-box-arrow-up-right me-1"></i>Open the reconciliation</a>
        <span class="small ms-2">Asset already owned before MY MOTIVE? Choose <strong>Funding: "Already owned"</strong> below; it is not paused.</span>
    </div>
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
                        <option value="<?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?>" <?= ($_POST['asset_type'] ?? '') === $t ? 'selected' : ''; ?>><?= htmlspecialchars($t, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Acquisition Date</label>
                    <input type="date" name="acquisition_date" class="form-control rm-input" max="<?= date('Y-m-d'); ?>" value="<?= htmlspecialchars($_POST['acquisition_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Acquisition Value (RWF)</label>
                    <input type="number" name="acquisition_value" class="form-control rm-input" min="0.01" step="0.01" value="<?= htmlspecialchars($_POST['acquisition_value'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="form-text">The full amount payable for the asset.</div>
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
                    <label class="form-label small fw-semibold text-muted">Asset Class</label>
                    <select name="asset_class" id="assetClass" class="form-select rm-input">
                        <?php foreach (ASSET_CLASSES as $c) { ?><option value="<?= $c; ?>" <?= ($_POST['asset_class'] ?? 'Tangible') === $c ? 'selected' : ''; ?>><?= $c; ?></option><?php } ?>
                    </select>
                    <div class="form-text">Routine running costs (subscriptions, maintenance, small fixes) are expenses, not assets: record them in Transactions.</div>
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

            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Valuation Method (straight-line)</label>
                    <select name="valuation_method" id="valuationMethod" class="form-select rm-input">
                        <?php foreach (ASSET_VALUATION_METHODS as $v) { ?><option value="<?= $v; ?>" <?= ($_POST['valuation_method'] ?? 'Depreciation') === $v ? 'selected' : ''; ?>><?= $v; ?></option><?php } ?>
                    </select>
                    <div class="form-text">Depreciation: tangible assets. Amortization: finite-life intangibles. No depreciation: e.g. land.</div>
                </div>
                <div class="col-md-8">
                    <label class="form-label small fw-semibold text-muted">Funding</label>
                    <select name="funding_type" id="fundingType" class="form-select rm-input">
                        <option value="Capital Fund purchase" <?= ($_POST['funding_type'] ?? '') !== 'Opening balance' ? 'selected' : ''; ?>>Purchase paid from the RM Capital Fund</option>
                        <option value="Opening balance" <?= ($_POST['funding_type'] ?? '') === 'Opening balance' ? 'selected' : ''; ?>>Already owned before MY MOTIVE (opening balance, no payment)</option>
                    </select>
                    <div class="form-text" id="fundingInfo">
                        <?php if (mm_has_feature('funds')) { // PACKAGES ?>
                        RM Capital Fund money available now: <strong>RWF <?= number_format($capitalCheck['available'], 2); ?></strong>
                        (stock and asset values are not money and are not counted).
                        <?php } ?>
                        <?php if (!$reconciled) { ?><span class="text-danger">Purchases are blocked until the opening reconciliation is posted.</span><?php } ?>
                    </div>
                    <input type="text" name="funding_note" id="fundingNote" class="form-control rm-input mt-2" maxlength="255"
                           placeholder="Where does this asset come from? (e.g. bought in 2024 before MY MOTIVE, invoice no. …)"
                           value="<?= htmlspecialchars($_POST['funding_note'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label small fw-semibold text-muted">Asset Status</label>
                <select name="status" class="form-select rm-input" required>
                    <?php foreach ($createStatuses as $s) { ?>
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

<script>
// NEW: the note is only needed for an asset already owned; suggest the method that fits the class.
(function () {
    var ft = document.getElementById('fundingType'), note = document.getElementById('fundingNote');
    var cls = document.getElementById('assetClass'), method = document.getElementById('valuationMethod');
    function syncFunding() { var opening = ft.value === 'Opening balance'; note.style.display = opening ? '' : 'none'; note.required = opening; }
    function syncClass() {
        if (cls.value === 'Tangible' && method.value === 'Amortization') { method.value = 'Depreciation'; }
        if (cls.value !== 'Tangible' && method.value === 'Depreciation') { method.value = 'Amortization'; }
    }
    ft.addEventListener('change', syncFunding); cls.addEventListener('change', syncClass);
    syncFunding();
})();
</script>
<?php include '../../includes/footer.php'; ?>