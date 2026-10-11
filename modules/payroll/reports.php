<?php
/**
 * NEW FILE: modules/payroll/reports.php
 * Payroll reports. Same layout, PDF and filters as the other reports.
 * Admin only, like the Payroll module (salaries are confidential).
 */
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/payroll_report_queries.php';
require_role(['Admin']);

$types   = payroll_report_types();
$lookups = payroll_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = $sel !== '' ? payroll_report_build($conn, $sel, $_GET) : null;
$period  = (string) ($_GET['period'] ?? 'this_year');
$query   = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'employee_id' => $_GET['employee_id'] ?? '', 'department_id' => $_GET['department_id'] ?? '', 'status' => $_GET['status'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Payroll Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Payroll</a>
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
                    <label class="form-label small fw-semibold text-muted">Pay Period</label>
                    <select name="period" id="periodSelect" class="form-select rm-input">
                        <?php foreach (report_period_options(true) as $k => $label) { ?>
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
                    <label class="form-label small fw-semibold text-muted" id="employeeLabel">Employee</label>
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
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <?php foreach ($lookups['statuses'] as $k => $label) { ?>
                            <option value="<?= report_e($k); ?>" <?= ($_GET['status'] ?? 'all') === $k ? 'selected' : ''; ?>><?= report_e($label); ?></option>
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

<?php if ($report) { report_screen_result($report, 'payroll_report_pdf.php', $query); } ?>

<script>
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
function syncFilters() {
    document.getElementById('employeeLabel').textContent = reportSelect.value === 'employee' ? 'Employee (required)' : 'Employee';
    document.querySelectorAll('.custom-dates').forEach(function (el) { el.style.display = periodSelect.value === 'custom' ? '' : 'none'; });
}
reportSelect.addEventListener('change', syncFilters);
periodSelect.addEventListener('change', syncFilters);
syncFilters();
</script>

<?php include '../../includes/footer.php'; ?>