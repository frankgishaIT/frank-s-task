<?php
/** NEW FILE: modules/delegations/payments.php — Tasker Payment Management dashboard: due, paid, outstanding, overdue, unconfirmed, disputed, possible duplicates. */
require __DIR__ . '/common.php';
if (!dlc($conn, 'finance') && !dlc($conn, 'approve')) { dl_require($conn, 'finance'); }
$f = ['tasker' => (int) ($_GET['tasker'] ?? 0), 'service' => (int) ($_GET['service'] ?? 0), 'status' => (string) ($_GET['status'] ?? ''), 'q' => trim((string) ($_GET['q'] ?? '')),
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : '', 'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : ''];
$w = ["(d.completed_at IS NOT NULL OR EXISTS (SELECT 1 FROM dl_payments p WHERE p.delegation_id = d.id))"];
if ($f['tasker']) { $w[] = 'd.party_id = ' . $f['tasker']; }
if ($f['service']) { $w[] = 'd.product_id = ' . $f['service']; }
if ($f['from']) { $w[] = "d.completed_at >= '{$f['from']} 00:00:00'"; }
if ($f['to']) { $w[] = "d.completed_at <= '{$f['to']} 23:59:59'"; }
if ($f['q'] !== '') { $x = dl_id_from_ref($f['q']); $w[] = $x ? 'd.id = ' . $x : '1=0'; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT d.*, p.product_name, bp.business_name, bp.name tname FROM dl_delegations d LEFT JOIN products p ON p.id = d.product_id LEFT JOIN business_parties bp ON bp.id = d.party_id WHERE ' . implode(' AND ', $w) . ' ORDER BY d.verified_at DESC, d.id DESC LIMIT 1000'), MYSQLI_ASSOC);
$k = ['due' => 0, 'paid' => 0, 'out' => 0, 'over' => 0, 'overdue' => 0, 'disputed' => 0];
$byTasker = [];
$list = [];
foreach ($rows as $d) {
    if (in_array($d['status'], DL_ENDED, true) && !mysqli_fetch_row(mysqli_query($conn, 'SELECT 1 FROM dl_payments WHERE delegation_id = ' . (int) $d['id']))) { continue; }
    $d['ref'] = dl_ref((int) $d['id']);
    $pos = dl_pay_position($conn, $d);
    if ($f['status'] === 'overdue' ? !$pos['overdue'] : ($f['status'] !== '' && $pos['status'] !== $f['status'])) { continue; }
    $base = $d['verified_at'] ? ($pos['approved'] ?? $pos['entitled']) : 0;
    $k['due'] += $base; $k['paid'] += $pos['paid']; $k['out'] += $d['verified_at'] ? $pos['outstanding'] : 0; $k['over'] += $pos['overpaid'];
    if ($pos['overdue']) { $k['overdue']++; }
    if ($pos['disputes']) { $k['disputed']++; }
    $tp = (int) $d['party_id'];
    $byTasker[$tp] = $byTasker[$tp] ?? ['unpaid' => 0, 'partial' => 0, 'out' => 0, 'verified' => 0];
    if ($d['verified_at']) { $byTasker[$tp]['verified']++; $byTasker[$tp]['out'] += $pos['outstanding']; }
    if ($d['verified_at'] && $pos['paid'] <= 0 && $pos['outstanding'] > 0.5) { $byTasker[$tp]['unpaid']++; }
    if ($pos['status'] === 'Partially Paid') { $byTasker[$tp]['partial']++; }
    $list[] = ['d' => $d, 'pos' => $pos];
}
$unpaidT = count(array_filter($byTasker, function ($x) { return $x['unpaid'] > 0; }));
$partialT = count(array_filter($byTasker, function ($x) { return $x['partial'] > 0; }));
$clearT = count(array_filter($byTasker, function ($x) { return $x['verified'] > 0 && $x['out'] <= 0.5; }));
$awaitConf = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM dl_payments WHERE status = 'Recorded' AND confirm_status IN ('Awaiting confirmation', 'Expired – follow up')"))[0];
$expired = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM dl_payments WHERE status = 'Recorded' AND confirm_status = 'Expired – follow up'"))[0];
$pendingAppr = mysqli_fetch_all(mysqli_query($conn, "SELECT p.*, d.id did FROM dl_payments p JOIN dl_delegations d ON d.id = p.delegation_id WHERE p.status = 'Pending approval' ORDER BY p.id"), MYSQLI_ASSOC);
$toApprove = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM dl_delegations WHERE verified_at IS NOT NULL AND pay_approved IS NULL AND status NOT IN ('Rejected', 'Cancelled', 'Reassigned')"))[0];
// Possible duplicates: same Tasker, same amount, same day, on different records (references are unique already).
$dups = mysqli_fetch_all(mysqli_query($conn, "SELECT a.id a_id, b.id b_id, a.party_id, a.amount, a.paid_on, a.delegation_id ad, b.delegation_id bd, a.reference ar, b.reference br FROM dl_payments a JOIN dl_payments b ON b.party_id = a.party_id AND b.id > a.id AND ABS(b.amount - a.amount) < 0.5 AND b.paid_on = a.paid_on
    WHERE a.status <> 'Void' AND b.status <> 'Void' ORDER BY a.paid_on DESC LIMIT 50"), MYSQLI_ASSOC);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tasker_payments_' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Delegation', 'RM Tasker', 'Service', 'Completed', 'Entitlement', 'Approved', 'Paid', 'Outstanding', 'Payment status', 'Overdue', 'Due date', 'Last payment', 'Reference', 'Method', 'Confirmed by Tasker', 'Reason not paid']);
    foreach ($list as ['d' => $d, 'pos' => $pos]) {
        fputcsv($o, [$d['ref'], dl_tasker_name(['business_name' => $d['business_name'], 'name' => $d['tname'], 'id' => $d['party_id']]), $d['product_name'], $d['completed_at'], $pos['entitled'], $pos['approved'], $pos['paid'], $pos['outstanding'], $pos['status'], $pos['overdue'] ? 'Yes' : 'No',
            $pos['due_date'], $pos['last']['paid_on'] ?? '', $pos['last']['reference'] ?? '', $pos['last']['method'] ?? '', $pos['confirmed_all'] ? 'Yes' : 'No', $d['nonpay_reason']]);
    }
    exit;
}
$taskers = mysqli_fetch_all(mysqli_query($conn, "SELECT bp.id, COALESCE(NULLIF(bp.business_name, ''), bp.name) n FROM business_parties bp WHERE bp.type = 'Tasker' ORDER BY n"), MYSQLI_ASSOC);
$services = mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT p.id, p.product_name FROM dl_delegations d JOIN products p ON p.id = d.product_id ORDER BY p.product_name'), MYSQLI_ASSOC);
$statuses = ['Pending Payment Approval', 'Awaiting Customer Payment', 'Not Yet Due', 'Unpaid', 'Partially Paid', 'Fully Paid – Awaiting Tasker Confirmation', 'Confirmed', 'Payment Disputed', 'Overpaid', 'Reconciliation Required', 'Awaiting Service Completion', 'Awaiting Quality Verification'];

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'payments.php', 'Tasker payments', '<a class="btn btn-outline-secondary btn-sm" href="?' . dl_e(http_build_query(array_filter($f) + ['export' => 'csv'])) . '"><i class="bi bi-download"></i> Excel (CSV)</a>');
$kp = function ($v, $l, $bad = false, $link = '') { return '<div class="col-6 col-md-4 col-xl"><' . ($link ? 'a href="' . $link . '" class="text-decoration-none d-block' : 'div class="') . ' dl-kpi h-100"><div class="v ' . ($bad ? 'text-danger' : '') . '">' . $v . '</div><div class="l">' . $l . '</div></' . ($link ? 'a' : 'div') . '></div>'; };
?>
<div class="row g-2 mb-2">
    <?= $kp(dl_money($k['due']), 'Total Tasker amounts due'); ?><?= $kp(dl_money($k['paid']), 'Total paid to Taskers'); ?><?= $kp(dl_money($k['out']), 'Total outstanding', $k['out'] > 0); ?>
    <?= $kp($k['over'] > 0 ? dl_money($k['over']) : '0', 'Overpaid (investigate)', $k['over'] > 0, '?status=Overpaid'); ?>
</div>
<div class="row g-2 mb-3">
    <?= $kp($unpaidT, 'Unpaid Taskers', $unpaidT > 0, '?status=Unpaid'); ?><?= $kp($partialT, 'Partially paid Taskers', false, '?status=Partially+Paid'); ?><?= $kp($k['overdue'], 'Overdue payments', $k['overdue'] > 0, '?status=overdue'); ?>
    <?= $kp($awaitConf . ($expired ? ' <span class="small text-danger">(' . $expired . ' expired)</span>' : ''), 'Payments awaiting confirmation', $expired > 0, '?status=' . urlencode('Fully Paid – Awaiting Tasker Confirmation')); ?>
    <?= $kp($k['disputed'], 'Disputed payments', $k['disputed'] > 0, 'disputes.php'); ?><?= $kp($clearT, 'Taskers with no outstanding balance'); ?>
</div>
<?php if ($toApprove || $pendingAppr) { ?><div class="alert alert-warning py-2 small"><?= $toApprove ? '<a href="?status=' . urlencode('Pending Payment Approval') . '"><b>' . $toApprove . '</b> verified delegation(s) waiting for payment approval</a>. ' : ''; ?><?= $pendingAppr ? '<b>' . count($pendingAppr) . '</b> payment(s) need an approver because of a possible duplicate or an amount above what is owed: ' . implode(', ', array_map(function ($p) { return '<a href="pay.php?id=' . (int) $p['did'] . '">' . dl_pay_ref((int) $p['id']) . '</a>'; }, $pendingAppr)) . '.' : ''; ?></div><?php } ?>
<?php if ($dups) { ?><div class="alert alert-danger py-2 small"><b>Possible duplicate payments</b> (same Tasker, same amount, same day): <?= implode('; ', array_map(function ($x) { return dl_pay_ref((int) $x['a_id']) . ' (' . dl_e($x['ar']) . ', <a href="pay.php?id=' . (int) $x['ad'] . '">' . dl_ref((int) $x['ad']) . '</a>) and ' . dl_pay_ref((int) $x['b_id']) . ' (' . dl_e($x['br']) . ', <a href="pay.php?id=' . (int) $x['bd'] . '">' . dl_ref((int) $x['bd']) . '</a>) · ' . dl_money($x['amount']); }, $dups)); ?></div><?php } ?>
<form class="row g-2 mb-2">
    <div class="col-6 col-md-2"><input name="q" value="<?= dl_e($f['q']); ?>" class="form-control form-control-sm" placeholder="DL number"></div>
    <div class="col-6 col-md-2"><select name="tasker" class="form-select form-select-sm"><option value="">Any RM Tasker</option><?php foreach ($taskers as $t) { ?><option value="<?= (int) $t['id']; ?>" <?= $f['tasker'] === (int) $t['id'] ? 'selected' : ''; ?>><?= dl_e($t['n']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="service" class="form-select form-select-sm"><option value="">Any service</option><?php foreach ($services as $s) { ?><option value="<?= (int) $s['id']; ?>" <?= $f['service'] === (int) $s['id'] ? 'selected' : ''; ?>><?= dl_e($s['product_name']); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any payment status</option><option value="overdue" <?= $f['status'] === 'overdue' ? 'selected' : ''; ?>>Overdue</option><?php foreach ($statuses as $s) { ?><option <?= $f['status'] === $s ? 'selected' : ''; ?>><?= dl_e($s); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-1"><input type="date" name="from" value="<?= dl_e($f['from']); ?>" class="form-control form-control-sm" title="Completed from"></div>
    <div class="col-6 col-md-1"><input type="date" name="to" value="<?= dl_e($f['to']); ?>" class="form-control form-control-sm" title="Completed until"></div>
    <div class="col-12 col-md-2 d-flex gap-1"><button class="btn btn-outline-primary btn-sm">Filter</button><a href="payments.php" class="btn btn-link btn-sm">Clear</a></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>Delegation</th><th>RM Tasker</th><th class="text-end">Due</th><th class="text-end">Paid</th><th class="text-end">Outstanding</th><th>Payment status</th><th>Due date</th><th>Last payment</th><th>Confirmed</th></tr></thead><tbody>
<?php foreach ($list as ['d' => $d, 'pos' => $pos]) { ?>
<tr><td><a href="pay.php?id=<?= (int) $d['id']; ?>" class="fw-semibold text-decoration-none"><?= dl_e($d['ref']); ?></a><div class="small text-muted"><?= dl_e($d['product_name']); ?></div></td>
    <td class="small"><a href="tasker.php?id=<?= (int) $d['party_id']; ?>#payments"><?= dl_e(dl_tasker_name(['business_name' => $d['business_name'], 'name' => $d['tname'], 'id' => $d['party_id']])); ?></a></td>
    <td class="text-end small"><?= $d['verified_at'] ? dl_money($pos['approved'] ?? $pos['entitled']) : '<span class="text-muted">' . dl_money($pos['entitled']) . ' exp.</span>'; ?></td><td class="text-end small"><?= dl_money($pos['paid']); ?></td>
    <td class="text-end small fw-semibold <?= $pos['outstanding'] > 0.5 && $d['verified_at'] ? 'text-danger' : ''; ?>"><?= $d['verified_at'] ? dl_money($pos['outstanding']) : '—'; ?></td>
    <td><?= dl_pay_badge($pos); ?><?= $pos['needs_reason'] ? '<div class="small text-danger">reason required</div>' : ($d['nonpay_reason'] && $pos['outstanding'] > 0.5 ? '<div class="small text-muted">' . dl_e($d['nonpay_reason']) . '</div>' : ''); ?></td>
    <td class="small text-nowrap"><?= $pos['due_date'] ? dl_e(date('d M Y', strtotime($pos['due_date']))) : '—'; ?></td>
    <td class="small"><?= $pos['last'] ? dl_e(date('d M', strtotime($pos['last']['paid_on']))) . ' · ' . dl_e($pos['last']['method']) . ' · ' . dl_e($pos['last']['reference']) : '—'; ?></td>
    <td class="small"><?= $pos['paid'] > 0 ? ($pos['confirmed_all'] ? '<span class="text-success">Yes</span>' : '<span class="text-warning">Not yet</span>') : '—'; ?></td></tr>
<?php } ?>
<?php if (!$list) { ?><tr><td colspan="9" class="text-center text-muted py-5">Nothing here.</td></tr><?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>