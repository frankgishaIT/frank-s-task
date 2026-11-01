<?php
// NEW FILE: portal/history.php — the customer's invoices, payments, purchases and requests, with date filters.
require __DIR__ . '/portal_start.php';
$from = (string) ($_GET['from'] ?? date('Y-01-01')); $to = (string) ($_GET['to'] ?? date('Y-m-d'));
if (!DateTime::createFromFormat('Y-m-d', $from)) { $from = date('Y-01-01'); }
if (!DateTime::createFromFormat('Y-m-d', $to)) { $to = date('Y-m-d'); }
$s = mysqli_prepare($conn, "SELECT * FROM sales WHERE customer_id = ? AND status <> 'Cancelled' AND sale_date BETWEEN ? AND ? ORDER BY sale_date DESC, id DESC");
mysqli_stmt_bind_param($s, 'iss', $cid, $from, $to);
mysqli_stmt_execute($s);
$sales = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
$items = [];
if ($sales) {
    $ids = implode(',', array_map('intval', array_column($sales, 'id')));
    $items = mysqli_fetch_all(mysqli_query($conn, "SELECT COALESCE(p.product_name, si.service_name, 'Item') AS name, SUM(si.quantity) AS qty, SUM(si.line_total) AS total
        FROM sale_items si LEFT JOIN products p ON p.id = si.product_id WHERE si.sale_id IN ($ids) GROUP BY name ORDER BY total DESC LIMIT 20"), MYSQLI_ASSOC);
}
$payments = [];
try {
    $p = mysqli_prepare($conn, "SELECT l.*, s.id AS sale_no FROM credit_ledger l JOIN sales s ON s.id = l.sale_id
        WHERE s.customer_id = ? AND l.entry_type LIKE '%payment%' AND DATE(l.created_at) BETWEEN ? AND ? ORDER BY l.created_at DESC");
    mysqli_stmt_bind_param($p, 'iss', $cid, $from, $to);
    mysqli_stmt_execute($p);
    $payments = mysqli_fetch_all(mysqli_stmt_get_result($p), MYSQLI_ASSOC);
} catch (Throwable $e) { $payments = []; }
$r = mysqli_prepare($conn, 'SELECT * FROM portal_requests WHERE customer_id = ? AND DATE(created_at) BETWEEN ? AND ? ORDER BY id DESC');
mysqli_stmt_bind_param($r, 'iss', $cid, $from, $to);
mysqli_stmt_execute($r);
$reqs = mysqli_fetch_all(mysqli_stmt_get_result($r), MYSQLI_ASSOC);
$totals = ['total' => array_sum(array_column($sales, 'total_amount')), 'paid' => array_sum(array_column($sales, 'amount_paid')), 'balance' => array_sum(array_map('portal_sale_balance', $sales))];
portal_header('My history', $me);
?>
<form class="card mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-5 col-md-3"><label class="form-label small text-muted mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= portal_e($from); ?>"></div>
    <div class="col-5 col-md-3"><label class="form-label small text-muted mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= portal_e($to); ?>"></div>
    <div class="col-2 col-md-2"><button class="btn btn-primary btn-sm w-100">Show</button></div>
</div></form>
<div class="row g-3 mb-3">
    <div class="col-4"><div class="card"><div class="card-body"><div class="small text-muted">Bought</div><div class="fw-bold"><?= portal_money($totals['total']); ?></div></div></div></div>
    <div class="col-4"><div class="card"><div class="card-body"><div class="small text-muted">Paid</div><div class="fw-bold"><?= portal_money($totals['paid']); ?></div></div></div></div>
    <div class="col-4"><div class="card"><div class="card-body"><div class="small text-muted">Outstanding</div><div class="fw-bold <?= $totals['balance'] > 0 ? 'text-danger' : ''; ?>"><?= portal_money($totals['balance']); ?></div></div></div></div>
</div>
<div class="card mb-3"><div class="card-body"><h2 class="h6">Invoices &amp; receipts</h2><div class="table-responsive"><table class="table mb-0 align-middle">
    <tr><th>Number</th><th>Date</th><th>Status</th><th class="text-end">Total</th><th class="text-end">Balance</th><th></th></tr>
    <?php if (!$sales) { ?><tr><td colspan="6" class="text-muted">Nothing in this period.</td></tr><?php } ?>
    <?php foreach ($sales as $s) { $bal = portal_sale_balance($s); ?>
    <tr><td><?= portal_sale_number((int) $s['id']); ?></td><td><?= date('d M Y', strtotime($s['sale_date'])); ?></td><td><?= portal_e($s['status']); ?></td>
        <td class="text-end"><?= portal_money($s['total_amount']); ?></td><td class="text-end <?= $bal > 0 ? 'text-danger' : 'text-success'; ?>"><?= $bal > 0 ? portal_money($bal) : 'Paid'; ?></td>
        <td><a href="invoice.php?id=<?= (int) $s['id']; ?>" class="btn btn-sm btn-outline-primary">Open</a></td></tr>
    <?php } ?></table></div></div></div>
<div class="row g-3">
    <div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="h6">Products &amp; services bought</h2>
        <?php if (!$items) { ?><div class="text-muted small">Nothing in this period.</div><?php } ?>
        <?php foreach ($items as $i) { ?><div class="d-flex justify-content-between small border-bottom py-1"><span><?= portal_e($i['name']); ?> × <?= (int) $i['qty']; ?></span><span><?= portal_money($i['total']); ?></span></div><?php } ?>
    </div></div></div>
    <div class="col-md-6"><div class="card h-100"><div class="card-body"><h2 class="h6">Payments on credit invoices</h2>
        <?php if (!$payments) { ?><div class="text-muted small">No recorded payments in this period.</div><?php } ?>
        <?php foreach ($payments as $p) { ?><div class="d-flex justify-content-between small border-bottom py-1"><span><?= date('d M Y', strtotime($p['created_at'])); ?> · <?= portal_sale_number((int) $p['sale_no']); ?></span><span><?= portal_money(abs((float) $p['amount'])); ?></span></div><?php } ?>
        <h2 class="h6 mt-3">Requests</h2>
        <?php if (!$reqs) { ?><div class="text-muted small">No requests in this period.</div><?php } ?>
        <?php foreach ($reqs as $q) { ?><div class="d-flex justify-content-between small border-bottom py-1"><a href="request.php?id=<?= (int) $q['id']; ?>"><?= portal_request_number((int) $q['id']); ?> · <?= portal_e($q['subject']); ?></a><span><?= portal_e($q['status']); ?></span></div><?php } ?>
    </div></div></div>
</div>
<?php portal_footer();