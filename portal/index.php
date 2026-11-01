<?php
// NEW FILE: portal/index.php — the customer's home page.
require __DIR__ . '/portal_start.php';
$open = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sales WHERE customer_id = $cid AND status IN ('Credit', 'Partially Paid') ORDER BY sale_date"), MYSQLI_ASSOC);
$owed = array_sum(array_map('portal_sale_balance', $open));
$recent = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sales WHERE customer_id = $cid AND status <> 'Cancelled' ORDER BY id DESC LIMIT 5"), MYSQLI_ASSOC);
$requests = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM portal_requests WHERE customer_id = $cid AND status IN ('Open', 'In progress')"))[0];
$docs = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM portal_documents WHERE customer_id = $cid AND unpublished_at IS NULL"))[0];
$notice = portal_setting($conn, 'notice');
portal_header('Home', $me);
portal_alerts();
?>
<h1 class="h4 mb-3">Hello, <?= portal_e($me['first_name'] ?? '' ?: $me['name']); ?></h1>
<?php if (trim($notice) !== '') { ?><div class="alert alert-primary"><i class="bi bi-megaphone me-1"></i><?= nl2br(portal_e($notice)); ?></div><?php } ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">You owe</div><div class="fs-5 fw-bold <?= $owed > 0 ? 'text-danger' : ''; ?>"><?= portal_money($owed); ?></div><div class="small text-muted"><?= count($open); ?> unpaid invoice(s)</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">Loyalty points</div><div class="fs-5 fw-bold"><?= number_format((int) ($me['loyalty_points'] ?? 0)); ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">Open requests</div><div class="fs-5 fw-bold"><?= $requests; ?></div><a href="requests.php" class="small">View</a></div></div></div>
    <div class="col-6 col-md-3"><div class="card"><div class="card-body"><div class="small text-muted">My documents</div><div class="fs-5 fw-bold"><?= $docs; ?></div><a href="documents.php" class="small">View</a></div></div></div>
</div>
<div class="card"><div class="card-body">
    <div class="d-flex justify-content-between mb-2"><h2 class="h6 mb-0">Recent invoices &amp; receipts</h2><a href="history.php" class="small">All history</a></div>
    <div class="table-responsive"><table class="table mb-0 align-middle">
        <tr><th>Number</th><th>Date</th><th class="text-end">Total</th><th class="text-end">Balance</th><th></th></tr>
        <?php if (!$recent) { ?><tr><td colspan="5" class="text-muted">No invoices yet.</td></tr><?php } ?>
        <?php foreach ($recent as $s) { $bal = portal_sale_balance($s); ?>
        <tr><td><?= portal_sale_number((int) $s['id']); ?></td><td><?= date('d M Y', strtotime($s['sale_date'])); ?></td>
            <td class="text-end"><?= portal_money($s['total_amount']); ?></td><td class="text-end <?= $bal > 0 ? 'text-danger' : 'text-success'; ?>"><?= $bal > 0 ? portal_money($bal) : 'Paid'; ?></td>
            <td><a href="invoice.php?id=<?= (int) $s['id']; ?>" class="btn btn-sm btn-outline-primary">Open</a></td></tr>
        <?php } ?>
    </table></div>
</div></div>
<?php portal_footer();