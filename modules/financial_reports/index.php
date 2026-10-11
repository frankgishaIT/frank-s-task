<?php
/**
 * NEW MODULE: modules/financial_reports/index.php
 * Financial Reports: Profit & Loss, Balance Sheet, Income Statement, Expense Analysis, Gross Profit,
 * Net Profit, Profit Margin and Financial Position Summary. Calculated automatically from Sales,
 * Stock, Transactions, Purchase Orders, Payroll, Loans, Assets and the RM Funds.
 */
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/asset_helpers.php';             // depreciation is brought up to today first
require '../../includes/financial_report_queries.php';
require_role(['Admin', 'Manager']);

asset_run_depreciation_catchup_all($conn);

$types  = fin_report_types();
$sel    = (string) ($_GET['report'] ?? '');
$report = $sel !== '' ? fin_report_build($conn, $sel, $_GET) : null;
$period = (string) ($_GET['period'] ?? 'this_month');
$asOf   = (string) ($_GET['as_of'] ?? date('Y-m-d'));
$query  = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'as_of' => $asOf,
], function ($v) { return $v !== ''; }));

$descriptions = [
    'pnl'      => 'Revenue, cost of sales, expenses and the resulting profit or loss, compared with the previous period.',
    'balance'  => 'What the business owns (assets), owes (liabilities) and is worth (equity) on a date.',
    'income'   => 'All income earned in the period: sales before and after discounts, and other income.',
    'expenses' => 'Every cost by category, its share of total costs and of revenue, and the change from the previous period.',
    'gross'    => 'Revenue minus the cost of goods and services sold, for every product and service.',
    'net'      => 'Profit left after all expenses and loan interest, month by month.',
    'margin'   => 'Gross, operating and net profit as a percentage of revenue, month by month.',
    'position' => 'A one-page view of cash, debts, net worth, health ratios, this year\'s profit and the RM Funds.',
];

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Financial Reports</h2>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <form method="GET">
            <div class="row g-3 align-items-end">
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
                <div class="col-md-3 asof-box">
                    <label class="form-label small fw-semibold text-muted">As of Date</label>
                    <input type="date" name="as_of" class="form-control rm-input" max="<?= date('Y-m-d'); ?>" value="<?= report_e($asOf); ?>">
                </div>
            </div>
            <div class="small text-muted mt-2" id="reportDescription"></div>
            <div class="mt-4">
                <button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-bar-chart-fill me-2"></i>Generate Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($report) { report_screen_result($report, 'report_pdf.php', $query); } ?>

<script>
const reportSelect = document.getElementById('reportSelect');
const periodSelect = document.getElementById('periodSelect');
const AS_OF = ['balance', 'position'];
const DESCRIPTIONS = <?= json_encode($descriptions, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
function syncFilters() {
    const r = reportSelect.value;
    const asOf = AS_OF.includes(r);
    document.querySelectorAll('.period-box').forEach(function (el) { el.style.display = asOf ? 'none' : ''; });
    document.querySelectorAll('.custom-dates').forEach(function (el) { el.style.display = !asOf && periodSelect.value === 'custom' ? '' : 'none'; });
    document.querySelectorAll('.asof-box').forEach(function (el) { el.style.display = asOf ? '' : 'none'; });
    document.getElementById('reportDescription').textContent = DESCRIPTIONS[r] || '';
}
reportSelect.addEventListener('change', syncFilters);
periodSelect.addEventListener('change', syncFilters);
syncFilters();
</script>

<?php include '../../includes/footer.php'; ?>