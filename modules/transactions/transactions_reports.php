<?php
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/transaction_report_queries.php';
require_role(['Admin', 'Manager']);

$types   = transaction_report_types();
$lookups = transaction_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = $sel !== '' ? transaction_report_build($conn, $sel, $_GET) : null;
$period  = (string) ($_GET['period'] ?? 'this_month');
$status  = (string) ($_GET['status'] ?? $lookups['default_status']);
$query   = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'type' => $_GET['type'] ?? '', 'employee_id' => $_GET['employee_id'] ?? '',
    'status' => $status, 'category' => $_GET['category'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Transactions Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Transactions</a>
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

                <div class="col-md-3" id="typeBox">
                    <label class="form-label small fw-semibold text-muted">Transaction Type</label>
                    <select name="type" class="form-select rm-input">
                        <option value="">All</option>
                        <?php foreach (['Income', 'Expense'] as $t) { ?>
                            <option value="<?= $t; ?>" <?= ($_GET['type'] ?? '') === $t ? 'selected' : ''; ?>><?= $t; ?></option>
                        <?php } ?>
                    </select>
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
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <option value="all" <?= $status === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <?php foreach ($lookups['statuses'] as $s) { ?>
                            <option value="<?= report_e($s); ?>" <?= $status === $s ? 'selected' : ''; ?>><?= report_e(ucfirst($s)); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Category</label>
                    <select name="category" class="form-select rm-input">
                        <option value="">All categories</option>
                        <?php foreach ($lookups['categories'] as $c) { ?>
                            <option value="<?= report_e($c); ?>" <?= ($_GET['category'] ?? '') === $c ? 'selected' : ''; ?>><?= report_e($c); ?></option>
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

<?php if ($report) { report_screen_result($report, 'transactions_report_pdf.php', $query); } ?>

<script>
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
function syncFilters() {
    document.getElementById('typeBox').style.display = reportSelect.value === 'all' || reportSelect.value === '' ? '' : 'none';
    document.getElementById('employeeLabel').textContent = reportSelect.value === 'employee' ? 'Employee (required)' : 'Employee';
    document.querySelectorAll('.custom-dates').forEach(function (el) {
        el.style.display = periodSelect.value === 'custom' ? '' : 'none';
    });
}
reportSelect.addEventListener('change', syncFilters);
periodSelect.addEventListener('change', syncFilters);
syncFilters();
</script>

<?php include '../../includes/footer.php'; ?>
