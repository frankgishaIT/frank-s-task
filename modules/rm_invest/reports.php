<?php
// NEW FILE: modules/rm_invest/reports.php — RM Invest management report; Excel (CSV) and printable PDF.
require __DIR__ . '/invest_common.php';
if (!access_can('rm_invest.reports', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$projects = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM inv_projects WHERE status <> 'Draft' ORDER BY id DESC"), MYSQLI_ASSOC);
$rows = []; $tot = ['required' => 0, 'raised' => 0, 'investors' => 0, 'revenue' => 0, 'expenses' => 0, 'fees' => 0, 'profit' => 0, 'distributed' => 0, 'outstanding' => 0];
foreach ($projects as $p) {
    $f = inv_funding($conn, (int) $p['id']); $r = $p['results_status'] === 'Approved' ? inv_calculate($conn, $p) : null;
    $d = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT COALESCE(SUM(CASE WHEN status = "Paid" THEN total_payout END), 0) paid, COALESCE(SUM(CASE WHEN status <> "Paid" THEN total_payout END), 0) due FROM inv_distributions WHERE project_id = ' . (int) $p['id']));
    $row = ['ref' => inv_project_ref($p), 'name' => $p['name'], 'status' => $p['status'], 'required' => (float) $p['capital_required'], 'raised' => $f['raised'], 'investors' => $f['investors'],
            'revenue' => $r ? $r['revenue'] : null, 'expenses' => $r ? $r['direct'] + $r['other'] : null, 'profit' => $r ? $r['profit'] : null, 'fee' => $r ? $r['fee'] : null, 'paid' => (float) $d['paid'], 'due' => (float) $d['due']];
    $rows[] = $row;
    $tot['required'] += $row['required']; $tot['raised'] += $row['raised']; $tot['investors'] += $row['investors']; $tot['revenue'] += (float) $row['revenue']; $tot['expenses'] += (float) $row['expenses'];
    $tot['fees'] += (float) $row['fee']; $tot['profit'] += (float) $row['profit']; $tot['distributed'] += $row['paid']; $tot['outstanding'] += $row['due'];
}
$count = function ($s) use ($projects) { return count(array_filter($projects, function ($p) use ($s) { return in_array($p['status'], (array) $s, true); })); };
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="rm-invest-report-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Reference', 'Project', 'Status', 'Capital required', 'Capital raised', 'Investors', 'Revenue', 'Expenses', 'Realized profit/loss', 'Management fee', 'Paid out', 'Outstanding payouts']);
    foreach ($rows as $r) { fputcsv($o, [$r['ref'], $r['name'], $r['status'], $r['required'], $r['raised'], $r['investors'], $r['revenue'], $r['expenses'], $r['profit'], $r['fee'], $r['paid'], $r['due']]); }
    fclose($o); exit;
}
$print = isset($_GET['print']);
if (!$print) { include '../../includes/header.php'; include '../../includes/sidebar.php'; inv_tabs('reports.php'); }
else { echo '<!doctype html><html><head><meta charset="utf-8"><title>RM Invest report</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4"><h4>RM Invest: management report</h4><div class="small text-muted mb-3">Generated ' . date('d M Y, H:i') . (inv_testing($conn) ? ' · TESTING MODE' : '') . '</div>'; }
?>
<?php if (!$print) { ?><div class="d-flex gap-2 mb-3"><a href="reports.php?export=1" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel (CSV)</a><a href="reports.php?print=1" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer"></i> PDF / print</a></div><?php } ?>
<div class="row g-3 mb-3">
    <?php foreach (['Published opportunities' => $count(['Published', 'Paused', 'Funding Closed', 'Active', 'Completed']), 'Active projects' => $count('Active'), 'Completed projects' => $count('Completed'), 'Investors' => $tot['investors']] as $l => $v) { ?>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted"><?= $l; ?></div><div class="fs-4 fw-bold"><?= number_format($v); ?></div></div></div></div><?php } ?>
    <?php foreach (['Capital requested' => $tot['required'], 'Capital raised (verified)' => $tot['raised'], 'Project revenue' => $tot['revenue'], 'Project expenses' => $tot['expenses'], 'Management fees' => $tot['fees'], 'Realized profit / loss' => $tot['profit'], 'Distributed to investors' => $tot['distributed'], 'Outstanding payouts' => $tot['outstanding']] as $l => $v) { ?>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted"><?= $l; ?></div><div class="fw-bold"><?= cl_money($v); ?></div></div></div></div><?php } ?>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:12.5px;">
    <tr><th class="ps-3">Project</th><th>Status</th><th class="text-end">Required</th><th class="text-end">Raised</th><th class="text-end">Investors</th><th class="text-end">Revenue</th><th class="text-end">Expenses</th><th class="text-end">Profit / loss</th><th class="text-end">Fee</th><th class="text-end">Paid out</th><th class="text-end">To pay</th></tr>
    <?php foreach ($rows as $r) { ?><tr><td class="ps-3"><?= $e($r['name']); ?><div class="small text-muted"><?= $r['ref']; ?></div></td><td><?= inv_badge($r['status']); ?></td><td class="text-end"><?= number_format($r['required']); ?></td><td class="text-end"><?= number_format($r['raised']); ?></td><td class="text-end"><?= $r['investors']; ?></td>
        <?php foreach (['revenue', 'expenses', 'profit', 'fee'] as $kk) { ?><td class="text-end"><?= $r[$kk] === null ? '—' : number_format($r[$kk]); ?></td><?php } ?><td class="text-end"><?= number_format($r['paid']); ?></td><td class="text-end"><?= number_format($r['due']); ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="11" class="text-center text-muted py-3">No published projects yet.</td></tr><?php } ?>
</table></div></div></div>
<?php if ($print) { echo '<script>window.print()</script></body></html>'; } else { include '../../includes/footer.php'; } ?>