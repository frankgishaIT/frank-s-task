<?php
/**
 * NEW FILE: modules/assets/reports.php
 * Assets & Depreciation reports. Same layout, PDF and filters as the other reports.
 */
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/asset_helpers.php';        // brings depreciation up to today first
require '../../includes/asset_report_queries.php';
require_role(['Admin', 'Manager']);

asset_run_depreciation_catchup_all($conn);

$types   = asset_report_types();
$lookups = asset_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = $sel !== '' ? asset_report_build($conn, $sel, $_GET) : null;
$period  = (string) ($_GET['period'] ?? 'this_month');
$query   = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'asset_type' => $_GET['asset_type'] ?? '', 'department_id' => $_GET['department_id'] ?? '',
    'status' => $_GET['status'] ?? '', 'group_by' => $_GET['group_by'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Asset Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Assets</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <form method="GET">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Report</label>
                    <select name="report" id="reportSelect" class="form-select rm-input" required>
                        <option value="">Select a report</option>
                        <?php foreach ($types as $key => $label) { ?>
                            <option value="<?= report_e($key); ?>" <?= $sel === $key ? 'selected' : ''; ?>><?= report_e($label); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3 period-box">
                    <label class="form-label small fw-semibold text-muted">Period</label>
                    <select name="period" id="periodSelect" class="form-select rm-input">
                        <?php foreach (report_period_options() as $k => $label) { ?>
                            <option value="<?= report_e($k); ?>" <?= $period === $k ? 'selected' : ''; ?>><?= report_e($label); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-2 custom-dates">
                    <label class="form-label small fw-semibold text-muted">From</label>
                    <input type="date" name="from" class="form-control rm-input" value="<?= report_e($_GET['from'] ?? ''); ?>">
                </div>
                <div class="col-md-2 custom-dates">
                    <label class="form-label small fw-semibold text-muted">To</label>
                    <input type="date" name="to" class="form-control rm-input" value="<?= report_e($_GET['to'] ?? ''); ?>">
                </div>

                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Asset Type</label>
                    <select name="asset_type" class="form-select rm-input">
                        <option value="">All types</option>
                        <?php foreach ($lookups['types'] as $t) { ?>
                            <option value="<?= report_e($t); ?>" <?= ($_GET['asset_type'] ?? '') === $t ? 'selected' : ''; ?>><?= report_e($t); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Department</label>
                    <select name="department_id" class="form-select rm-input">
                        <option value="">All departments</option>
                        <?php foreach ($lookups['departments'] as $d) { ?>
                            <option value="<?= (int) $d['id']; ?>" <?= (string) ($_GET['department_id'] ?? '') === (string) $d['id'] ? 'selected' : ''; ?>><?= report_e($d['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3 status-box">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <?php foreach ($lookups['statuses'] as $k => $label) { ?>
                            <option value="<?= report_e($k); ?>" <?= ($_GET['status'] ?? 'in_use') === $k ? 'selected' : ''; ?>><?= report_e($label); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3 group-box">
                    <label class="form-label small fw-semibold text-muted">Group By</label>
                    <select name="group_by" class="form-select rm-input">
                        <option value="type" <?= ($_GET['group_by'] ?? 'type') === 'type' ? 'selected' : ''; ?>>Asset Type</option>
                        <option value="department" <?= ($_GET['group_by'] ?? '') === 'department' ? 'selected' : ''; ?>>Department</option>
                    </select>
                </div>
            </div>
            <div class="small text-muted mt-2" id="asOfNote">This report shows the situation today; the period does not apply.</div>
            <div class="mt-4">
                <button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-bar-chart-fill me-2"></i>Generate Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($report) { report_screen_result($report, 'asset_report_pdf.php', $query); } ?>

<script>
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
const WITH_PERIOD = ['depreciation', 'acquisitions', 'disposals'];
function syncFilters() {
    const r = reportSelect.value;
    const usesPeriod = WITH_PERIOD.includes(r);
    document.querySelectorAll('.period-box').forEach(function (el) { el.style.display = usesPeriod ? '' : 'none'; });
    document.querySelectorAll('.custom-dates').forEach(function (el) { el.style.display = usesPeriod && periodSelect.value === 'custom' ? '' : 'none'; });
    document.getElementById('asOfNote').style.display = r && !usesPeriod ? '' : 'none';
    document.querySelectorAll('.status-box').forEach(function (el) { el.style.display = r === 'register' ? '' : 'none'; });
    document.querySelectorAll('.group-box').forEach(function (el) { el.style.display = r === 'summary' ? '' : 'none'; });
}
reportSelect.addEventListener('change', syncFilters);
periodSelect.addEventListener('change', syncFilters);
syncFilters();
</script>

<?php include '../../includes/footer.php'; ?>