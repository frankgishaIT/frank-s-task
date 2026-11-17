<?php
/**
 * NEW FILE: modules/learning/fees.php — LEARNING CENTRE FEES register (from Sales, nothing recorded twice).
 * Invoiced · discounts and scholarships · collected · outstanding · overdue · reversed (cancelled invoices), by course,
 * with each invoice's instalments. Payments, receipts and cancellations are done in Sales (links on each row).
 */
require __DIR__ . '/common.php';
lcs_require($conn, 'finance');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('fees.php', 'error', 'The page had expired.'); }
    if (($_POST['do'] ?? '') === 'reminders') { $n = lc_fee_reminders($conn); lcs_go('fees.php', 'success', $n ? $n . ' payment reminder(s) sent.' : 'No reminder to send (nothing due soon, or already reminded this week).'); }
}
$fc = (int) ($_GET['course'] ?? 0); $fs = (string) ($_GET['state'] ?? ''); $q = trim((string) ($_GET['q'] ?? ''));
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-01-01'); $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-12-31');
$w = [lcs_branch_sql($conn, 's.branch_id'), "s.sale_date BETWEEN '$from' AND '$to'"];
if ($fc) { $w[] = 'i.course_id = ' . $fc; }
if ($q !== '') { $x = mysqli_real_escape_string($conn, $q); $w[] = "(cu.name LIKE '%$x%' OR cu.phone LIKE '%$x%' OR i.customer_id = " . (int) preg_replace('/\D/', '', $q) . ' OR i.sale_id = ' . (int) preg_replace('/\D/', '', $q) . ')'; }
$rows = mysqli_fetch_all(mysqli_query($conn, str_replace('FROM lc_invoices i', ', cu.name cname, cu.phone cphone, a.ref FROM lc_invoices i', lc_invoice_sql()) . ' JOIN customers cu ON cu.id = i.customer_id LEFT JOIN lc_applications a ON a.id = i.application_id WHERE ' . implode(' AND ', $w) . ' ORDER BY i.id DESC LIMIT 1000'), MYSQLI_ASSOC);
$inv = array_map(function ($r) use ($conn) { return lc_invoice_state($conn, $r); }, $rows);
$inv = array_values(array_filter($inv, function ($i) use ($fs) {
    return $fs === '' || ($fs === 'open' && !$i['cancelled'] && $i['balance'] > 0.009) || ($fs === 'overdue' && $i['overdue'] > 0.009) || ($fs === 'paid' && !$i['cancelled'] && $i['balance'] <= 0.009)
        || ($fs === 'cancelled' && $i['cancelled']) || ($fs === 'pending' && $i['pending']);
}));
$T = ['invoiced' => 0, 'discount' => 0, 'scholar' => 0, 'paid' => 0, 'balance' => 0, 'overdue' => 0, 'reversed' => 0, 'pending' => 0];
$byCourse = [];
foreach ($inv as $i) {
    if ($i['cancelled']) { $T['reversed'] += $i['total']; continue; }
    if ($i['pending']) { $T['pending'] += $i['total']; continue; }
    $T['invoiced'] += $i['total']; $T['paid'] += $i['paid']; $T['balance'] += $i['balance']; $T['overdue'] += $i['overdue'];
    foreach ($i['adjustments'] as $ad) { $T[$ad['type'] === 'Scholarship' ? 'scholar' : 'discount'] += (float) $ad['amount']; }
    $c = $i['course_code']; $byCourse[$c] = ['title' => $i['course_title'], 'invoiced' => ($byCourse[$c]['invoiced'] ?? 0) + $i['total'], 'paid' => ($byCourse[$c]['paid'] ?? 0) + $i['paid'], 'balance' => ($byCourse[$c]['balance'] ?? 0) + $i['balance']];
}
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="learning-centre-fees-' . $from . '-' . $to . '.csv"');
    $o = fopen('php://output', 'w'); fputcsv($o, ['Invoice', 'Date', 'Student / applicant', 'Customer ID', 'Course', 'Kind', 'Plan', 'Invoiced', 'Paid', 'Balance', 'Overdue', 'Status']);
    foreach ($inv as $i) { fputcsv($o, [$i['number'], $i['sale_date'], $i['cname'], lc_customer_id_label($conn, (int) $i['customer_id']), $i['course_code'], $i['kind'], $i['plan'], $i['total'], $i['paid'], $i['balance'], $i['overdue'], $i['status']]); }
    fclose($o); exit;
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'fees.php', 'Fees', '<form method="POST" class="d-inline">' . lcs_csrf_field() . '<input type="hidden" name="do" value="reminders"><button class="btn btn-sm btn-outline-primary" onclick="return confirm(\'Send SMS / portal reminders for instalments due within ' . (int) lc_setting($conn, 'fee_reminder_days') . ' days or overdue?\');"><i class="bi bi-bell"></i> Send payment reminders</button></form>');
$m = function ($v) { return lc_money($v); };
?>
<form class="row g-2 mb-3"><div class="col-md-3"><input name="q" value="<?= lc_e($q); ?>" class="form-control form-control-sm" placeholder="Name, telephone, Customer ID or invoice no."></div>
    <div class="col-md-3"><select name="course" class="form-select form-select-sm"><option value="0">All courses</option><?php foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, code, title FROM lc_courses ORDER BY title'), MYSQLI_ASSOC) as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $fc === (int) $c['id'] ? 'selected' : ''; ?>><?= lc_e($c['code'] . ' · ' . $c['title']); ?></option><?php } ?></select></div>
    <div class="col-md-2"><select name="state" class="form-select form-select-sm"><option value="">All invoices</option><?php foreach (['open' => 'Balance due', 'overdue' => 'Overdue', 'paid' => 'Paid', 'pending' => 'Waiting for approval', 'cancelled' => 'Cancelled'] as $k => $l) { ?><option value="<?= $k; ?>" <?= $fs === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-md-3 d-flex gap-1"><input type="date" name="from" value="<?= $from; ?>" class="form-control form-control-sm"><input type="date" name="to" value="<?= $to; ?>" class="form-control form-control-sm"></div>
    <div class="col-md-1"><button class="btn btn-sm btn-primary w-100">Go</button></div></form>
<div class="row g-2 mb-3">
    <?php foreach (['Invoiced' => $T['invoiced'], 'Collected' => $T['paid'], 'Outstanding' => $T['balance'], 'Overdue' => $T['overdue'], 'Discounts' => $T['discount'], 'Scholarships' => $T['scholar'], 'Reversed (cancelled)' => $T['reversed']] as $l => $v) { ?>
    <div class="col-6 col-md"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl"><?= $l; ?></div><div class="val text-nowrap <?= $l === 'Overdue' && $v > 0 ? 'text-danger' : ''; ?>" style="font-size:.95rem"><?= $m($v); ?></div></div></div></div><?php } ?>
</div>
<?php if ($T['pending'] > 0) { ?><div class="alert alert-warning py-2 small"><?= $m($T['pending']); ?> waiting for discount approval in Sales (not counted above).</div><?php } ?>
<div class="row g-3">
<div class="col-xl-9"><div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3">Invoice</th><th>Student / applicant</th><th>Course</th><th>Plan</th><th class="text-end">Invoiced</th><th class="text-end">Paid</th><th class="text-end">Balance</th><th>Next due</th><th>Status</th><th></th></tr>
    <?php foreach ($inv as $i) { ?><tr class="<?= $i['cancelled'] ? 'text-muted' : ''; ?>"><td class="ps-3"><a href="../sales/invoice.php?id=<?= (int) $i['sale_id']; ?>" class="fw-semibold"><?= lc_e($i['number']); ?></a><div class="small text-muted"><?= lc_e($i['sale_date']); ?> · <?= lc_e($i['kind']); ?></div></td>
        <td><?= lc_e($i['cname']); ?><div class="small text-muted"><?= lc_e(lc_customer_id_label($conn, (int) $i['customer_id'])); ?><?= $i['ref'] ? ' · <a href="application.php?id=' . (int) $i['application_id'] . '">' . lc_e($i['ref']) . '</a>' : ''; ?></div></td>
        <td><?= lc_e($i['course_code']); ?></td><td class="small"><?= $i['plan'] === 'installments' ? count($i['installments']) . ' instalments' : 'Full'; ?></td>
        <td class="text-end"><?= $m($i['total']); ?><?= (float) $i['discount_amount'] > 0 ? '<div class="small text-success">−' . $m($i['discount_amount']) . '</div>' : ''; ?></td><td class="text-end"><?= $m($i['paid']); ?></td>
        <td class="text-end fw-semibold <?= $i['overdue'] > 0 ? 'text-danger' : ''; ?>"><?= $m($i['balance']); ?></td>
        <td class="small"><?= $i['next'] ? date('d M Y', strtotime($i['next']['due_date'])) . ' · ' . $m($i['next']['due']) . ($i['next']['state'] === 'Overdue' ? ' <span class="badge bg-danger">overdue</span>' : '') : '—'; ?></td>
        <td><span class="badge bg-<?= ['Paid' => 'success', 'Partially Paid' => 'info text-dark', 'Credit' => 'warning text-dark', 'Cancelled' => 'secondary', 'Pending Discount Approval' => 'dark'][$i['status']] ?? 'secondary'; ?>"><?= lc_e($i['status']); ?></span></td>
        <td class="text-nowrap small"><?php if (in_array($i['status'], ['Credit', 'Partially Paid'], true)) { ?><a href="../sales/pay_credit.php?id=<?= (int) $i['sale_id']; ?>">Record payment</a><?php } ?></td></tr><?php } ?>
    <?php if (!$inv) { ?><tr><td colspan="10" class="text-center text-muted py-4">No Learning Centre invoice for these filters. Invoices are created from an application (Fees card).</td></tr><?php } ?>
</table></div></div></div>
<div class="small text-muted mt-2"><a href="?<?= lc_e(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">Export CSV</a> · Invoiced = after discounts and scholarships (cancelled invoices excluded). Collected = payments recorded in Sales. Income and receipts are in Sales and Financial Reports — this page only reads them, so nothing is counted twice.</div></div>
<div class="col-xl-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="fw-semibold mb-2">By course</div>
    <?php foreach ($byCourse as $c => $v) { ?><div class="small border-bottom py-1"><strong><?= lc_e($c); ?></strong> <span class="text-muted"><?= lc_e($v['title']); ?></span><div class="d-flex justify-content-between"><span>Invoiced <?= $m($v['invoiced']); ?></span><span class="text-success"><?= $v['invoiced'] > 0 ? round($v['paid'] / $v['invoiced'] * 100) : 0; ?>% paid</span></div><div class="text-muted">Balance <?= $m($v['balance']); ?></div></div><?php } ?>
    <?php if (!$byCourse) { ?><div class="small text-muted">—</div><?php } ?>
    <div class="small text-muted mt-2">Clearance policy: <strong><?= lc_e(['none' => 'fees never block studies', 'certificate' => 'certificate only after fees are cleared', 'documents' => 'certificate and final transcript after fees are cleared'][lc_setting($conn, 'clearance_policy')] ?? lc_setting($conn, 'clearance_policy')); ?></strong> (Settings).</div>
</div></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>