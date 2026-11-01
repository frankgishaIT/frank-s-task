<?php
// NEW FILE: modules/rm_loans/reports.php — loan portfolio reports; Excel (CSV) export and a printable (PDF) version.
require __DIR__ . '/loans_common.php';
if (!access_can('rm_loans.reports', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
cl_refresh_overdue($conn);
$live = "'Disbursed', 'Active', 'Overdue', 'Defaulted', 'Fully Repaid'";
$n = function ($sql) use ($conn) { return (float) mysqli_fetch_row(mysqli_query($conn, $sql))[0]; };
$k = [
    'Total applications' => $n("SELECT COUNT(*) FROM cl_applications WHERE status <> 'Draft'"),
    'Approved (offers made)' => $n("SELECT COUNT(*) FROM cl_applications WHERE offered_at IS NOT NULL"),
    'Rejected' => $n("SELECT COUNT(*) FROM cl_applications WHERE status = 'Rejected'"),
    'Disbursed loans' => $n("SELECT COUNT(*) FROM cl_applications WHERE status IN ($live)"),
    'Overdue loans' => $n("SELECT COUNT(*) FROM cl_applications WHERE status = 'Overdue'"),
    'Defaulted loans' => $n("SELECT COUNT(*) FROM cl_applications WHERE status = 'Defaulted'"),
];
// Interest first, then principal, within each instalment (the same order payments are applied).
$m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(s.principal - GREATEST(0, s.paid - s.interest - s.fee)), 0) out_principal,
    COALESCE(SUM(s.interest), 0) interest_accrued, COALESCE(SUM(LEAST(s.paid, s.interest)), 0) interest_collected, COALESCE(SUM(s.paid), 0) collected,
    COALESCE(SUM(CASE WHEN s.due_date < CURDATE() THEN s.amount_due ELSE 0 END), 0) due_so_far, COALESCE(SUM(CASE WHEN s.due_date < CURDATE() THEN LEAST(s.paid, s.amount_due) ELSE 0 END), 0) paid_of_due
    FROM cl_schedule s JOIN cl_applications a ON a.id = s.application_id WHERE a.status IN ($live)"));
$perf = (float) $m['due_so_far'] > 0 ? round(100 * (float) $m['paid_of_due'] / (float) $m['due_so_far'], 1) : null;
$loans = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, c.name, (SELECT COALESCE(SUM(amount_due - paid), 0) FROM cl_schedule s WHERE s.application_id = a.id) owed,
    (SELECT MIN(due_date) FROM cl_schedule s WHERE s.application_id = a.id AND s.paid < s.amount_due - 0.004) next_due
    FROM cl_applications a JOIN customers c ON c.id = a.customer_id WHERE a.status <> 'Draft' ORDER BY FIELD(a.status, 'Defaulted', 'Overdue', 'Active', 'Disbursed') DESC, a.id DESC"), MYSQLI_ASSOC);
if (isset($_GET['export'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="rm-loans-portfolio-' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Reference', 'Customer', 'Status', 'Requested', 'Approved', 'Rate %/month', 'Months', 'Outstanding', 'Next due', 'Submitted']);
    foreach ($loans as $l) { fputcsv($o, [cl_ref((int) $l['id'], $l['created_at']), $l['name'], $l['status'], $l['requested_amount'], $l['approved_amount'], $l['monthly_rate'], $l['period_months'], round((float) $l['owed'], 2), $l['next_due'], $l['submitted_at']]); }
    fputcsv($o, []); foreach ($k as $label => $v) { fputcsv($o, [$label, $v]); }
    fputcsv($o, ['Outstanding principal', round((float) $m['out_principal'], 2)]); fputcsv($o, ['Interest accrued', round((float) $m['interest_accrued'], 2)]); fputcsv($o, ['Interest collected', round((float) $m['interest_collected'], 2)]);
    fclose($o); exit;
}
$print = isset($_GET['print']);
if (!$print) { include '../../includes/header.php'; include '../../includes/sidebar.php'; cl_tabs('reports.php'); }
else { echo '<!doctype html><html><head><meta charset="utf-8"><title>RM Loans portfolio</title><link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"></head><body class="p-4"><h4>RM Loans: portfolio report</h4><div class="small text-muted mb-3">Generated ' . date('d M Y, H:i') . ' by ' . $e($_SESSION['user_name'] ?? '') . (cl_testing($conn) ? ' · TESTING MODE (assessment only)' : '') . '</div>'; }
?>
<?php if (!$print) { ?><div class="d-flex gap-2 mb-3"><a href="reports.php?export=1" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-excel"></i> Excel (CSV)</a><a href="reports.php?print=1" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer"></i> PDF / print</a></div><?php } ?>
<div class="row g-3 mb-3">
    <?php foreach ($k as $label => $v) { ?><div class="col-6 col-md-4 col-xl-2"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted"><?= $e($label); ?></div><div class="fs-4 fw-bold"><?= number_format($v); ?></div></div></div></div><?php } ?>
    <?php foreach (['Outstanding principal' => $m['out_principal'], 'Interest accrued' => $m['interest_accrued'], 'Interest collected' => $m['interest_collected'], 'Total collected' => $m['collected']] as $label => $v) { ?>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body"><div class="small text-muted"><?= $e($label); ?></div><div class="fs-5 fw-bold"><?= cl_money($v); ?></div></div></div></div><?php } ?>
    <div class="col-12 small text-muted">Repayment performance: <?= $perf === null ? 'no instalment due yet' : '<strong>' . $perf . '%</strong> of the amounts due so far has been paid'; ?>.</div>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:12.5px;">
    <tr><th class="ps-3">Reference</th><th>Customer</th><th>Status</th><th class="text-end">Requested</th><th class="text-end">Approved</th><th class="text-end">Outstanding</th><th>Next due</th></tr>
    <?php foreach ($loans as $l) { ?><tr><td class="ps-3"><?= $print ? cl_ref((int) $l['id'], $l['created_at']) : '<a href="application.php?id=' . (int) $l['id'] . '">' . cl_ref((int) $l['id'], $l['created_at']) . '</a>'; ?></td><td><?= $e($l['name']); ?></td><td><?= cl_badge($l['status']); ?></td>
        <td class="text-end"><?= cl_money($l['requested_amount']); ?></td><td class="text-end"><?= $l['approved_amount'] !== null ? cl_money($l['approved_amount']) : '—'; ?></td><td class="text-end"><?= (float) $l['owed'] > 0 ? cl_money($l['owed']) : '—'; ?></td>
        <td><?= $l['next_due'] && in_array($l['status'], ['Active', 'Overdue', 'Defaulted', 'Disbursed'], true) ? date('d M Y', strtotime($l['next_due'])) : '—'; ?></td></tr><?php } ?>
</table></div></div></div>
<?php if ($print) { echo '<script>window.print()</script></body></html>'; } else { include '../../includes/footer.php'; } ?>