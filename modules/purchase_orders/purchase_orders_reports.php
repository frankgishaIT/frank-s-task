<?php
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/purchase_report_queries.php';
require_role(['Admin', 'Manager']);

$types   = purchase_report_types();
$lookups = purchase_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = $sel !== '' ? purchase_report_build($conn, $sel, $_GET) : null;
$period  = (string) ($_GET['period'] ?? 'this_month');
$status  = (string) ($_GET['status'] ?? 'default');
$query   = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'supplier' => $_GET['supplier'] ?? '', 'status' => $_GET['status'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Purchase Order Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Purchase Orders</a>
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

                <div class="col-md-4 filter-box" data-for="summary ordered cancelled supplier">
                    <label class="form-label small fw-semibold text-muted">Supplier</label>
                    <select name="supplier" class="form-select rm-input">
                        <option value="">All suppliers</option>
                        <?php foreach ($lookups['suppliers'] as $sp) { ?>
                            <option value="<?= (int) $sp['id']; ?>" <?= (string) ($_GET['supplier'] ?? '') === (string) $sp['id'] ? 'selected' : ''; ?>><?= report_e($sp['label']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3 filter-box" data-for="summary supplier">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <option value="">Default</option>
                        <option value="all" <?= $status === 'all' ? 'selected' : ''; ?>>All statuses</option>
                        <option value="active" <?= $status === 'active' ? 'selected' : ''; ?>>Ordered and Received</option>
                        <?php foreach ($lookups['statuses'] as $s) { ?>
                            <option value="<?= report_e($s); ?>" <?= $status === $s ? 'selected' : ''; ?>><?= report_e($s); ?></option>
                        <?php } ?>
                    </select>
                    <div class="form-text">Default: all for Summary, Ordered and Received for Purchase by Supplier.</div>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-bar-chart-fill me-2"></i>Generate Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($report) { report_screen_result($report, 'purchase_orders_report_pdf.php', $query); } ?>

<script>
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
function syncFilters() {
    document.querySelectorAll('.filter-box').forEach(function (el) {
        el.style.display = el.getAttribute('data-for').split(' ').indexOf(reportSelect.value) !== -1 ? '' : 'none';
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
