<?php
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/stock_report_queries.php';
require_role(['Admin', 'Manager']);

$types  = stock_report_types();
$sel    = (string) ($_GET['report'] ?? '');
$report = $sel !== '' ? stock_report_build($conn, $sel, $_GET) : null;
$query  = http_build_query(array_filter([
    'report' => $sel, 'status' => $_GET['status'] ?? '', 'level' => $_GET['level'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Stock &amp; Inventory Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Offerings</a>
</div>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-4">
        <form method="GET">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Report</label>
                    <select name="report" class="form-select rm-input" required>
                        <option value="">Select a report</option>
                        <?php foreach ($types as $key => $label) { ?>
                            <option value="<?= report_e($key); ?>" <?= $sel === $key ? 'selected' : ''; ?>><?= report_e($label); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Products</label>
                    <select name="status" class="form-select rm-input">
                        <?php foreach (['active' => 'Active only', 'inactive' => 'Inactive only', 'all' => 'Active and inactive'] as $k => $l) { ?>
                            <option value="<?= $k; ?>" <?= ($_GET['status'] ?? 'active') === $k ? 'selected' : ''; ?>><?= $l; ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3" id="levelBox">
                    <label class="form-label small fw-semibold text-muted">Stock Level</label>
                    <select name="level" class="form-select rm-input">
                        <?php foreach (['all' => 'All', 'in_stock' => 'In stock only', 'out_of_stock' => 'Out of stock only'] as $k => $l) { ?>
                            <option value="<?= $k; ?>" <?= ($_GET['level'] ?? 'all') === $k ? 'selected' : ''; ?>><?= $l; ?></option>
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

<script>
const rs = document.querySelector('[name=report]'), lb = document.getElementById('levelBox');
function syncLevel() { lb.style.display = rs.value === 'low_stock' ? 'none' : ''; }
rs.addEventListener('change', syncLevel); syncLevel();
</script>

<?php if ($report) { report_screen_result($report, 'report_pdf.php', $query); } ?>

<?php include '../../includes/footer.php'; ?>
