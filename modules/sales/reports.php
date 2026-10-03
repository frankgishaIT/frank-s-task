<?php
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/sales_report_queries.php';
require_role(['Admin', 'Manager']);

$types   = sales_report_types();
$lookups = sales_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = null;
if ($sel !== '') {
    $report = sales_report_build($conn, $sel, $_GET);
}
$period = (string) ($_GET['period'] ?? 'this_month');
$query  = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'product_id' => $_GET['product_id'] ?? '', 'customer_id' => $_GET['customer_id'] ?? '',
    'employee_id' => $_GET['employee_id'] ?? '', 'payment_method' => $_GET['payment_method'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Sales Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Sales</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <form method="GET" id="reportForm">
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

                <div class="col-md-3 filter-box" data-for="product">
                    <label class="form-label small fw-semibold text-muted">Product / Service</label>
                    <select name="product_id" class="form-select rm-input">
                        <option value="">Select</option>
                        <?php foreach ($lookups['products'] as $p) { ?>
                            <option value="<?= (int) $p['id']; ?>" <?= (string) ($_GET['product_id'] ?? '') === (string) $p['id'] ? 'selected' : ''; ?>>
                                <?= report_e($p['product_name'] . ' (' . $p['product_code'] . ') - ' . $p['item_type']); ?>
                            </option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3 filter-box" data-for="customer">
                    <label class="form-label small fw-semibold text-muted">Customer</label>
                    <select name="customer_id" class="form-select rm-input">
                        <option value="">Select</option>
                        <option value="walkin" <?= ($_GET['customer_id'] ?? '') === 'walkin' ? 'selected' : ''; ?>>Walk-in Customer</option>
                        <?php foreach ($lookups['customers'] as $c) { ?>
                            <option value="<?= (int) $c['id']; ?>" <?= (string) ($_GET['customer_id'] ?? '') === (string) $c['id'] ? 'selected' : ''; ?>><?= report_e($c['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3 filter-box" data-for="employee">
                    <label class="form-label small fw-semibold text-muted">Employee</label>
                    <select name="employee_id" class="form-select rm-input">
                        <option value="">Select</option>
                        <?php foreach ($lookups['employees'] as $e) { ?>
                            <option value="<?= (int) $e['id']; ?>" <?= (string) ($_GET['employee_id'] ?? '') === (string) $e['id'] ? 'selected' : ''; ?>><?= report_e($e['names']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3 filter-box" data-for="payment">
                    <label class="form-label small fw-semibold text-muted">Payment Method</label>
                    <select name="payment_method" class="form-select rm-input">
                        <option value="">Select</option>
                        <?php foreach ($lookups['payments'] as $m) { ?>
                            <option value="<?= report_e($m); ?>" <?= ($_GET['payment_method'] ?? '') === $m ? 'selected' : ''; ?>><?= report_e($m); ?></option>
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
            </div>
            <div class="mt-4">
                <button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-bar-chart-fill me-2"></i>Generate Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($report) { report_screen_result($report, 'report_pdf.php', $query); } ?>

<script>
// Show only the filter that belongs to the chosen report, and the date boxes for Custom Date Range.
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
function syncFilters() {
    document.querySelectorAll('.filter-box').forEach(function (el) {
        el.style.display = el.getAttribute('data-for') === reportSelect.value ? '' : 'none';
    });
    document.querySelectorAll('.custom-dates').forEach(function (el) {
        el.style.display = periodSelect.value === 'custom' ? '' : 'none';
    });
}
reportSelect.addEventListener('change', syncFilters);
periodSelect.addEventListener('change', syncFilters);
syncFilters();
</script>

<?php include '../../includes/footer.php'; ?>
