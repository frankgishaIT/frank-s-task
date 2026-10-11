<?php
/**
 * NEW FILE: <your loans folder>/reports.php
 * Loans reports. Same layout, PDF and filters as the other reports.
 */
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/loan_report_queries.php';
require_role(['Admin', 'Manager']);

$types   = loans_report_types();
$lookups = loans_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = $sel !== '' ? loans_report_build($conn, $sel, $_GET) : null;
$period  = (string) ($_GET['period'] ?? 'all_history');
$query   = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'lender' => $_GET['lender'] ?? '', 'status' => $_GET['status'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Loan Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Loans</a>
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
                    <label class="form-label small fw-semibold text-muted" id="periodLabel">Period</label>
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
                    <label class="form-label small fw-semibold text-muted">Lender</label>
                    <select name="lender" class="form-select rm-input">
                        <option value="">All lenders</option>
                        <?php foreach ($lookups['lenders'] as $l) { ?>
                            <option value="<?= report_e($l); ?>" <?= ($_GET['lender'] ?? '') === $l ? 'selected' : ''; ?>><?= report_e($l); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3 status-box">
                    <label class="form-label small fw-semibold text-muted">Status</label>
                    <select name="status" class="form-select rm-input">
                        <?php foreach ($lookups['statuses'] as $k => $label) { ?>
                            <option value="<?= report_e($k); ?>" <?= ($_GET['status'] ?? 'not_cancelled') === $k ? 'selected' : ''; ?>><?= report_e($label); ?></option>
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

<?php if ($report) { report_screen_result($report, 'loan_report_pdf.php', $query); } ?>

<script>
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
const PERIOD_LABEL = { register: 'Loans started', maturity: 'Maturing', repayments: 'Paid in', installments: 'Due up to the end of' };
function syncFilters() {
    const r = reportSelect.value;
    const usesPeriod = r in PERIOD_LABEL;
    document.querySelectorAll('.period-box').forEach(function (el) { el.style.display = usesPeriod ? '' : 'none'; });
    document.getElementById('periodLabel').textContent = PERIOD_LABEL[r] || 'Period';
    document.querySelectorAll('.custom-dates').forEach(function (el) { el.style.display = usesPeriod && periodSelect.value === 'custom' ? '' : 'none'; });
    document.getElementById('asOfNote').style.display = r && !usesPeriod ? '' : 'none';
    document.querySelectorAll('.status-box').forEach(function (el) { el.style.display = r === 'register' ? '' : 'none'; });
}
reportSelect.addEventListener('change', syncFilters);
periodSelect.addEventListener('change', syncFilters);
syncFilters();
</script>

<?php include '../../includes/footer.php'; ?>