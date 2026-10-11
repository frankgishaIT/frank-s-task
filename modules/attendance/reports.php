<?php
/**
 * NEW FILE: <your attendance folder>/reports.php
 * Attendance reports. Same layout, PDF and filters as the other reports.
 */
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/attendance_report_queries.php';
require_role(['Admin', 'Manager']);

$types   = attendance_report_types();
$lookups = attendance_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = $sel !== '' ? attendance_report_build($conn, $sel, $_GET) : null;
$period  = (string) ($_GET['period'] ?? 'this_month');
$query   = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'employee_id' => $_GET['employee_id'] ?? '', 'department_id' => $_GET['department_id'] ?? '',
    'status' => $_GET['status'] ?? '', 'workflow' => $_GET['workflow'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Attendance Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Attendance</a>
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
                <div class="col-md-3">
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
                    <label class="form-label small fw-semibold text-muted">Employee</label>
                    <select name="employee_id" class="form-select rm-input">
                        <option value="">All employees</option>
                        <?php foreach ($lookups['employees'] as $e) { ?>
                            <option value="<?= (int) $e['id']; ?>" <?= (string) ($_GET['employee_id'] ?? '') === (string) $e['id'] ? 'selected' : ''; ?>><?= report_e($e['names']); ?></option>
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
                <div class="col-md-2 status-box">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <option value="">All</option>
                        <?php foreach ($lookups['statuses'] as $s) { ?>
                            <option value="<?= report_e($s); ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : ''; ?>><?= report_e($s); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">Confirmation</label>
                    <select name="workflow" class="form-select rm-input">
                        <option value="">All</option>
                        <?php foreach ($lookups['workflows'] as $w) { ?>
                            <option value="<?= report_e($w); ?>" <?= ($_GET['workflow'] ?? '') === $w ? 'selected' : ''; ?>><?= report_e($w); ?></option>
                        <?php } ?>
                    </select>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-bar-chart-fill me-2"></i>Generate Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($report) { report_screen_result($report, 'attendance_report_pdf.php', $query); } ?>

<script>
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
function syncFilters() {
    document.querySelectorAll('.status-box').forEach(function (el) { el.style.display = reportSelect.value === 'register' || reportSelect.value === '' ? '' : 'none'; });
    document.querySelectorAll('.custom-dates').forEach(function (el) { el.style.display = periodSelect.value === 'custom' ? '' : 'none'; });
}
reportSelect.addEventListener('change', syncFilters);
periodSelect.addEventListener('change', syncFilters);
syncFilters();
</script>

<?php include '../../includes/footer.php'; ?>