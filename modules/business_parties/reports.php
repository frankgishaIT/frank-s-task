<?php
/**
 * NEW FILE: modules/business_parties/reports.php
 * Receivables and Payables reports. Same layout, filters and PDF as the other reports.
 */
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/report_screen.php';
require '../../includes/party_report_queries.php';
require_role(['Admin', 'Manager']);

$types   = party_report_types();
$lookups = party_report_lookups($conn);
$sel     = (string) ($_GET['report'] ?? '');
$report  = $sel !== '' ? party_report_build($conn, $sel, $_GET) : null;
$period  = (string) ($_GET['period'] ?? 'this_month');
$query   = http_build_query(array_filter([
    'report' => $sel, 'period' => $period, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '',
    'party_id' => $_GET['party_id'] ?? '', 'status' => $_GET['status'] ?? '',
], function ($v) { return $v !== ''; }));

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Receivables &amp; Payables</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Business Parties</a>
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
                <div class="col-md-4">
                    <label class="form-label small fw-semibold text-muted">Business Party</label>
                    <select name="party_id" class="form-select rm-input" data-searchable>
                        <option value="">All business parties</option>
                        <?php foreach ($lookups['parties'] as $p) { ?>
                            <option value="<?= (int) $p['id']; ?>" <?= (string) ($_GET['party_id'] ?? '') === (string) $p['id'] ? 'selected' : ''; ?>><?= report_e($p['label'] . ' (' . $p['type'] . ')'); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small fw-semibold text-muted">Payment Status</label>
                    <select name="status" id="statusSelect" class="form-select rm-input">
                        <option value="">All statuses</option>
                    </select>
                </div>
            </div>
            <div class="mt-4">
                <button type="submit" class="rm-btn rm-btn-primary"><i class="bi bi-bar-chart-fill me-2"></i>Generate Report</button>
            </div>
        </form>
    </div>
</div>

<?php if ($report) { report_screen_result($report, 'party_report_pdf.php', $query); } ?>

<script>
(function () {
    var reportSelect = document.getElementById('reportSelect'), periodSelect = document.getElementById('periodSelect');
    var statusSelect = document.getElementById('statusSelect');
    var chosen = <?= json_encode((string) ($_GET['status'] ?? ''), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    function sync() {
        var pay = reportSelect.value.indexOf('payables') === 0;
        var opts = reportSelect.value === '' ? [] : (pay ? ['Paid', 'Pending approval'] : ['Received', 'Expected']);
        statusSelect.innerHTML = '<option value="">All statuses</option>';
        opts.forEach(function (o) { var el = document.createElement('option'); el.value = o; el.textContent = o; if (o === chosen) { el.selected = true; } statusSelect.appendChild(el); });
        document.querySelectorAll('.custom-dates').forEach(function (el) { el.style.display = periodSelect.value === 'custom' ? '' : 'none'; });
    }
    reportSelect.addEventListener('change', function () { chosen = ''; sync(); });
    periodSelect.addEventListener('change', sync);
    sync();
})();
</script>
<?php include '../../includes/footer.php'; ?>