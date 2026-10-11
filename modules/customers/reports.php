<?php
/**
 * NEW FILE: modules/customers/reports.php
 * Customers & Credit reports. Same layout, PDF and filters as the Sales and Transactions reports.
 */
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/customer_report_queries.php';
require_role(['Admin', 'Manager']);

$types   = customer_report_types();
$lookups = customer_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = $sel !== '' ? customer_report_build($conn, $sel, $_GET) : null;
$period  = (string) ($_GET['period'] ?? 'this_month');
$query   = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'customer_id' => $_GET['customer_id'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Customer Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Customers</a>
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

                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted" id="customerLabel">Customer</label>
                    <select name="customer_id" class="form-select rm-input">
                        <option value="">All customers</option>
                        <?php foreach ($lookups['customers'] as $c) { ?>
                            <option value="<?= (int) $c['id']; ?>" <?= (string) ($_GET['customer_id'] ?? '') === (string) $c['id'] ? 'selected' : ''; ?>>
                                <?= report_e(customer_report_code((int) $c['id']) . ' — ' . $c['name'] . ($c['phone'] ? ' (' . $c['phone'] . ')' : '')); ?>
                            </option>
                        <?php } ?>
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

<?php if ($report) { report_screen_result($report, 'customer_report_pdf.php', $query); } ?>

<script>
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
const AS_OF = ['outstanding', 'aging'];   // reports that show today's situation (no period)
function syncFilters() {
    const asOf = AS_OF.includes(reportSelect.value);
    document.querySelectorAll('.period-box').forEach(function (el) { el.style.display = asOf ? 'none' : ''; });
    document.getElementById('asOfNote').style.display = asOf ? '' : 'none';
    document.getElementById('customerLabel').textContent = reportSelect.value === 'statement' ? 'Customer (required)' : 'Customer';
    document.querySelectorAll('.custom-dates').forEach(function (el) {
        el.style.display = !asOf && periodSelect.value === 'custom' ? '' : 'none';
    });
}
reportSelect.addEventListener('change', syncFilters);
periodSelect.addEventListener('change', syncFilters);
syncFilters();
</script>

<?php include '../../includes/footer.php'; ?>