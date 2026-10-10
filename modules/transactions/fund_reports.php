<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require_once '../../includes/fund_helpers.php';
require_once '../../includes/fund_report_queries.php';

$role = strtolower($_SESSION['user_role'] ?? '');
if (!in_array($role, ['admin', 'manager'], true)) {
    header('Location: index.php?error=' . urlencode('You do not have permission to view Fund reports.'));
    exit;
}

$report = fund_report_build($conn, $_GET);
$tab = $report['tab'];
$fromM = $report['from'];
$toM = $report['to'];
$fundId = $report['fund_id'];
$cols = $report['cols'];
$numeric = $report['numeric'];
$rows = $report['rows'];
$footer = $report['footer'];

/* ---------- CSV export (must run before any HTML) ---------- */

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="fund_' . $tab . '_' . $fromM . '_to_' . $toM . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8 correctly
    fputcsv($out, $cols);
    // CHANGED: text cells starting with = + - @ are prefixed with ' so Excel never runs them as
    // formulas ("CSV injection"). Number columns are left alone (negative amounts stay numbers).
    foreach ($rows as $r) {
        foreach ($r as $i => $cell) {
            if (!in_array($i, $numeric, true) && is_string($cell) && $cell !== '' && strpbrk($cell[0], '=+-@') !== false) {
                $r[$i] = "'" . $cell;
            }
        }
        fputcsv($out, $r);
    }
    if ($footer) { fputcsv($out, $footer); }
    fclose($out);
    exit;
}

include '../../includes/header.php'; include '../../includes/sidebar.php';

$allFunds = funds_all($conn);
$qs = function (array $override = []) use ($tab, $fundId, $fromM, $toM) {
    return http_build_query(array_merge(['tab' => $tab, 'fund_id' => $fundId ?: '', 'from' => $fromM, 'to' => $toM], $override));
};
$fmt = function ($v) { return number_format((float) $v, 2); };
$esc = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$titles = fund_report_titles();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Fund Reports</h2>
    <a href="index.php" class="rm-btn rm-btn-secondary">&larr; Back to Transactions</a>
</div>

<ul class="nav nav-tabs mb-3">
    <?php foreach ($titles as $key => $label) { ?>
    <li class="nav-item"><a class="nav-link <?= $tab === $key ? 'active' : ''; ?>" href="?<?= $qs(['tab' => $key]); ?>"><?= $esc($label); ?></a></li>
    <?php } ?>
</ul>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="<?= $esc($tab); ?>">
            <?php if ($tab !== 'capital') { ?>
            <div class="col-lg-4 col-md-6">
                <label class="form-label small fw-semibold text-muted">Fund</label>
                <select name="fund_id" class="form-select">
                    <option value="">All Funds</option>
                    <?php foreach ($allFunds as $f) { ?>
                    <option value="<?= (int) $f['id']; ?>" <?= $fundId === (int) $f['id'] ? 'selected' : ''; ?>><?= $esc($f['name']); ?></option>
                    <?php } ?>
                </select>
            </div>
            <?php } ?>
            <div class="col-lg-2 col-md-3 col-6">
                <label class="form-label small fw-semibold text-muted">From month</label>
                <input type="month" name="from" class="form-control" value="<?= $esc($fromM); ?>">
            </div>
            <div class="col-lg-2 col-md-3 col-6">
                <label class="form-label small fw-semibold text-muted">To month</label>
                <input type="month" name="to" class="form-control" value="<?= $esc($toM); ?>">
            </div>
            <div class="col-auto">
                <button type="submit" class="rm-btn rm-btn-primary">Apply</button>
            </div>
        </form>
    </div>
</div>

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
    <h5 class="mb-0"><?= $esc($report['title']); ?></h5>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <a href="?<?= $qs(['export' => 'csv']); ?>" class="btn btn-link text-secondary text-decoration-none small"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>
        <a href="fund_report_pdf.php?<?= $qs(); ?>" target="_blank" rel="noopener" class="btn btn-link text-decoration-none fw-semibold"><i class="bi bi-printer me-2"></i>View / Print PDF</a>
        <a href="fund_report_pdf.php?<?= $qs(['download' => 1]); ?>" class="rm-btn rm-btn-primary"><i class="bi bi-download me-2"></i>Download PDF</a>
    </div>
</div>
<p class="text-muted small mb-3">
    <?= $esc($report['fund_label']); ?> &middot;
    <?= date('M Y', strtotime($fromM . '-01')); ?> to <?= date('M Y', strtotime($toM . '-01')); ?>
    <?php if ($tab === 'summary') { ?>
    <!-- CHANGED: the formula includes stock and asset changes (RM Capital Fund only). -->
    &middot; Closing Balance = Opening Balance + Contributions &minus; Amount Used + Stock Change + Asset Change. "Available Now" is today's balance after expenses waiting for approval.
    <?php } elseif ($tab === 'capital') { ?>
    &middot; Changes in the value of stock and assets held in the RM Capital Fund. See also <a href="capital_check.php">Capital Fund Check</a>.
    <?php } ?>
</p>

<?php if ($tab !== 'summary' && $report['fund_totals']) { ?>
<div class="row row-cols-1 row-cols-md-3 row-cols-xl-5 g-2 mb-3">
    <?php foreach ($report['fund_totals'] as $name => $amt) { ?>
    <div class="col"><div class="card"><div class="card-body py-2">
        <div class="text-muted" style="font-size:11px;"><?= $esc($name); ?></div>
        <div class="fw-semibold">RWF <?= $fmt($amt); ?></div>
    </div></div></div>
    <?php } ?>
</div>
<?php } ?>

<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered table-hover bg-white mb-0">
                <tr>
                    <?php foreach ($cols as $i => $c) { ?>
                    <th class="<?= in_array($i, $numeric, true) ? 'text-end' : ''; ?>"><?= $esc($c); ?></th>
                    <?php } ?>
                </tr>
                <?php if (!$rows) { ?>
                <tr><td colspan="<?= count($cols); ?>" class="text-center text-muted py-4">No records for this period.</td></tr>
                <?php } foreach ($rows as $r) { ?>
                <tr>
                    <?php foreach ($r as $i => $cell) { ?>
                    <td class="<?= in_array($i, $numeric, true) ? 'text-end' : ''; ?>"><?= in_array($i, $numeric, true) ? $fmt($cell) : $esc($cell); ?></td>
                    <?php } ?>
                </tr>
                <?php } ?>
                <?php if ($footer && $rows) { ?>
                <tr class="fw-bold table-light">
                    <?php foreach ($footer as $i => $cell) { ?>
                    <td class="<?= in_array($i, $numeric, true) ? 'text-end' : ''; ?>"><?= in_array($i, $numeric, true) ? $fmt($cell) : $esc($cell); ?></td>
                    <?php } ?>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>
</div>

<?php if (!empty($report['pivot'])) { $p = $report['pivot']; ?>
<h5 class="mb-3"><?= $esc($p['title']); ?></h5>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-bordered bg-white mb-0">
                <tr>
                    <?php foreach ($p['cols'] as $i => $c) { ?>
                    <th class="<?= in_array($i, $p['numeric'], true) ? 'text-end' : ''; ?>"><?= $esc($c); ?></th>
                    <?php } ?>
                </tr>
                <?php if (!$p['rows']) { ?>
                <tr><td colspan="<?= count($p['cols']); ?>" class="text-center text-muted py-4">No profit has been allocated in this period.</td></tr>
                <?php } foreach ($p['rows'] as $r) { ?>
                <tr>
                    <?php foreach ($r as $i => $cell) { ?>
                    <td class="<?= in_array($i, $p['numeric'], true) ? 'text-end' : ''; ?>"><?= in_array($i, $p['numeric'], true) ? $fmt($cell) : $esc($cell); ?></td>
                    <?php } ?>
                </tr>
                <?php } ?>
            </table>
        </div>
    </div>
</div>
<?php } ?>
<?php include '../../includes/footer.php'; ?>