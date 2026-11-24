<?php
/**
 * NEW FILE: modules/market/reports.php — the 12 RM MARKET INSIGHTS REPORTS on screen, with date, category, demand
 * and type filters; View / Print PDF, Download PDF (report_pdf.php) and Excel export.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/market_report_queries.php';
require_once __DIR__ . '/../../includes/report_screen.php';
mi_require($conn, 'reports');
$types = mi_report_types();
$sel = (string) ($_GET['report'] ?? '');
$report = $sel !== '' ? mi_report_build($conn, $sel, $_GET) : null;
if ($report && empty($report['error']) && ($_GET['export'] ?? '') === 'excel') { mi_report_csv($report); }
$period = (string) ($_GET['period'] ?? 'this_month');
$query = http_build_query(array_filter(['report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'category' => $_GET['category'] ?? '', 'level' => $_GET['level'] ?? '', 'kind' => $_GET['kind'] ?? ''], function ($v) { return $v !== ''; }));
$cats = array_values(array_filter(array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT category FROM mi_opportunities WHERE category IS NOT NULL ORDER BY category'), MYSQLI_ASSOC), 'category')));
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'reports.php', 'Market Insights reports');
?>
<form class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 small align-items-end">
    <div class="col-md-4"><label class="form-label fw-semibold">Report</label><select name="report" class="form-select form-select-sm" required><option value="">Select a report</option><?php foreach ($types as $k => $l) { ?><option value="<?= mi_e($k); ?>" <?= $sel === $k ? 'selected' : ''; ?>><?= mi_e($l); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label fw-semibold">Period</label><select name="period" id="miPer" class="form-select form-select-sm"><?php foreach (report_period_options(true) as $k => $l) { ?><option value="<?= mi_e($k); ?>" <?= $period === $k ? 'selected' : ''; ?>><?= mi_e($l); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2 mi-cd"><label class="form-label fw-semibold">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= mi_e($_GET['from'] ?? ''); ?>"></div>
    <div class="col-6 col-md-2 mi-cd"><label class="form-label fw-semibold">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= mi_e($_GET['to'] ?? ''); ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label fw-semibold">Category</label><select name="category" class="form-select form-select-sm"><option value="">All</option><?php foreach ($cats as $c) { ?><option <?= ($_GET['category'] ?? '') === $c ? 'selected' : ''; ?>><?= mi_e($c); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label fw-semibold">Demand</label><select name="level" class="form-select form-select-sm"><option value="">All levels</option><?php foreach (array_keys(MI_DEMAND_LEVELS) as $l) { ?><option <?= ($_GET['level'] ?? '') === $l ? 'selected' : ''; ?>><?= mi_e($l); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label fw-semibold">Type</label><select name="kind" class="form-select form-select-sm"><option value="">Default</option><option value="product" <?= ($_GET['kind'] ?? '') === 'product' ? 'selected' : ''; ?>>Products</option><option value="service" <?= ($_GET['kind'] ?? '') === 'service' ? 'selected' : ''; ?>>Services</option></select></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-bar-chart-fill me-1"></i>Generate</button></div>
</div></form>
<?php if ($report) { if (empty($report['error'])) { ?><div class="text-end mb-2"><a class="btn btn-sm btn-outline-success" href="reports.php?<?= mi_e($query); ?>&amp;export=excel"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Export to Excel</a></div><?php } report_screen_result($report, 'report_pdf.php', $query); ?>
<?php } else { ?><div class="row g-2"><?php foreach ($types as $k => $l) { ?><div class="col-md-6 col-xl-4"><a href="reports.php?report=<?= mi_e($k); ?>&amp;period=all_history" class="card border-0 shadow-sm text-decoration-none h-100"><div class="card-body py-2 small fw-semibold"><?= mi_e($l); ?></div></a></div><?php } ?></div><?php } ?>
<script>(function(){var p=document.getElementById('miPer');function t(){document.querySelectorAll('.mi-cd').forEach(function(e){e.style.display=p.value==='custom'?'':'none';});}p.addEventListener('change',t);t();})();</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>