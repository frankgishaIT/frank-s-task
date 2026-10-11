<?php
/**
 * NEW FILE: modules/tasks/reports.php
 * Tasks & Performance reports. Same layout, PDF and filters as the other reports.
 */
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/task_report_queries.php';
require_role(['Admin', 'Manager']);

$types   = task_report_types();
$lookups = task_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = $sel !== '' ? task_report_build($conn, $sel, $_GET) : null;
$period  = (string) ($_GET['period'] ?? 'this_month');
$query   = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'project_id' => $_GET['project_id'] ?? '', 'employee_id' => $_GET['employee_id'] ?? '',
    'priority' => $_GET['priority'] ?? '', 'status' => $_GET['status'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Task Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Tasks</a>
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
                    <label class="form-label small fw-semibold text-muted">Tasks created</label>
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
                    <label class="form-label small fw-semibold text-muted">Project</label>
                    <select name="project_id" class="form-select rm-input">
                        <option value="">All projects</option>
                        <?php foreach ($lookups['projects'] as $p) { ?>
                            <option value="<?= (int) $p['id']; ?>" <?= (string) ($_GET['project_id'] ?? '') === (string) $p['id'] ? 'selected' : ''; ?>><?= report_e($p['project_name']); ?></option>
                        <?php } ?>
                    </select>
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
                <div class="col-md-2">
                    <label class="form-label small fw-semibold text-muted">Priority</label>
                    <select name="priority" class="form-select rm-input">
                        <option value="">All</option>
                        <?php foreach (['High', 'Medium', 'Low'] as $p) { ?>
                            <option value="<?= $p; ?>" <?= ($_GET['priority'] ?? '') === $p ? 'selected' : ''; ?>><?= $p; ?></option>
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
            </div>
            <div class="small text-muted mt-2" id="asOfNote">This report shows the situation today: tasks not done yet that are overdue or due within 7 days.</div>
            <div class="mt-4">
                <button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-bar-chart-fill me-2"></i>Generate Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($report) { report_screen_result($report, 'task_report_pdf.php', $query); } ?>

<script>
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
function syncFilters() {
    const r = reportSelect.value;
    const asOf = r === 'overdue';
    document.querySelectorAll('.period-box').forEach(function (el) { el.style.display = asOf ? 'none' : ''; });
    document.querySelectorAll('.custom-dates').forEach(function (el) { el.style.display = !asOf && periodSelect.value === 'custom' ? '' : 'none'; });
    document.getElementById('asOfNote').style.display = asOf ? '' : 'none';
    document.querySelectorAll('.status-box').forEach(function (el) { el.style.display = r === 'list' || r === '' ? '' : 'none'; });
}
reportSelect.addEventListener('change', syncFilters);
periodSelect.addEventListener('change', syncFilters);
syncFilters();
</script>

<?php include '../../includes/footer.php'; ?>