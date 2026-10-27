<?php
/**
 * NEW FILE: modules/sales/category_profit.php
 * Task 6B: Sales Profitability by Category (Admins and Managers). Screen + PDF (view, print, download).
 */
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/category_profit_queries.php';
require_role(['Admin', 'Manager']);

$mode = in_array($_GET['mode'] ?? '', ['day', 'month', 'year', 'custom'], true) ? $_GET['mode'] : 'month';
$report = isset($_GET['run']) ? category_profit_build($conn, $_GET) : null;
$query = http_build_query(array_filter([
    'mode' => $mode, 'day' => $_GET['day'] ?? '', 'month' => $_GET['month'] ?? '', 'year' => $_GET['year'] ?? '',
    'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
], function ($v) { return $v !== ''; }));
$v = function ($k, $d = '') { return report_e($_GET[$k] ?? $d); };

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Sales Profitability by Category</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Sales</a>
</div>
<div class="card border-0 shadow-sm mb-4"><div class="card-body p-4">
    <form method="GET" class="row g-3 align-items-end">
        <input type="hidden" name="run" value="1">
        <div class="col-md-3">
            <label class="form-label small fw-semibold text-muted">Period</label>
            <select name="mode" id="modeSelect" class="form-select rm-input">
                <?php foreach (['day' => 'A specific day', 'month' => 'A specific month', 'year' => 'A specific year', 'custom' => 'Custom date range'] as $k => $l) { ?>
                <option value="<?= $k; ?>" <?= $mode === $k ? 'selected' : ''; ?>><?= $l; ?></option>
                <?php } ?>
            </select>
        </div>
        <div class="col-md-3 p-day"><label class="form-label small fw-semibold text-muted">Day</label>
            <input type="date" name="day" class="form-control rm-input" value="<?= $v('day', date('Y-m-d')); ?>"></div>
        <div class="col-md-3 p-month"><label class="form-label small fw-semibold text-muted">Month</label>
            <input type="month" name="month" class="form-control rm-input" value="<?= $v('month', date('Y-m')); ?>"></div>
        <div class="col-md-2 p-year"><label class="form-label small fw-semibold text-muted">Year</label>
            <input type="number" name="year" min="2000" max="2100" class="form-control rm-input" value="<?= $v('year', date('Y')); ?>"></div>
        <div class="col-md-2 p-custom"><label class="form-label small fw-semibold text-muted">From</label>
            <input type="date" name="from" class="form-control rm-input" value="<?= $v('from'); ?>"></div>
        <div class="col-md-2 p-custom"><label class="form-label small fw-semibold text-muted">To</label>
            <input type="date" name="to" class="form-control rm-input" value="<?= $v('to'); ?>"></div>
        <div class="col-md-2"><button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-bar-chart-fill me-2"></i>Generate</button></div>
    </form>
</div></div>
<?php if ($report) { report_screen_result($report, 'category_profit_pdf.php', $query); } ?>
<script>
(function () {
    var m = document.getElementById('modeSelect');
    function sync() {
        ['day', 'month', 'year', 'custom'].forEach(function (k) {
            document.querySelectorAll('.p-' + k).forEach(function (el) { el.style.display = m.value === k ? '' : 'none'; });
        });
    }
    m.addEventListener('change', sync); sync();
})();
</script>
<?php include '../../includes/footer.php'; ?>