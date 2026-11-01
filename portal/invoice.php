<?php
// NEW FILE: portal/invoice.php — one invoice / receipt of the signed-in customer (printable).
require __DIR__ . '/portal_start.php';
$sale = portal_own_sale($conn, $cid, (string) ($_GET['id'] ?? ''));
if (!$sale) { header('Location: lookup.php'); exit; }
$sid = (int) $sale['id'];
portal_log($conn, $cid, 'invoice_viewed', portal_sale_number($sid));
$items = mysqli_fetch_all(mysqli_query($conn, "SELECT si.*, p.product_name, p.unit FROM sale_items si LEFT JOIN products p ON p.id = si.product_id WHERE si.sale_id = $sid ORDER BY si.id"), MYSQLI_ASSOC);
$bal = portal_sale_balance($sale);
$charges = round((float) ($sale['charges_accrued'] ?? 0) - (float) ($sale['charges_paid'] ?? 0), 2);
$isReceipt = $bal <= 0.009;
portal_header(($isReceipt ? 'Receipt ' : 'Invoice ') . portal_sale_number($sid), $me);
?>
<style>@media print { .p-top, .p-nav, footer, .no-print { display: none !important; } body { background: #fff; } .card { box-shadow: none; } }</style>
<div class="d-flex justify-content-between align-items-center mb-3 no-print"><a href="history.php" class="small">&larr; My history</a>
    <button onclick="window.print()" class="btn btn-sm btn-outline-primary"><i class="bi bi-printer"></i> Print / Save as PDF</button></div>
<div class="card"><div class="card-body p-4">
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
        <div><div class="fw-bold fs-5">RISE MOTIVE</div><div class="small text-muted">TIN 122923513 · Kicukiro, Kigali · www.risemotive.rw</div></div>
        <div class="text-end"><div class="fs-5 fw-bold"><?= $isReceipt ? 'Receipt' : 'Invoice'; ?></div><div><?= portal_sale_number($sid); ?></div>
            <div class="small text-muted"><?= date('d M Y', strtotime($sale['sale_date'])); ?></div></div>
    </div>
    <div class="small mb-3"><strong>Customer:</strong> <?= portal_e($me['name']); ?> (<?= portal_customer_code($cid); ?>) · <strong>Payment:</strong> <?= portal_e($sale['payment_method']); ?></div>
    <div class="table-responsive"><table class="table align-middle">
        <tr><th>Item</th><th class="text-end">Qty</th><th class="text-end">Unit price</th><th class="text-end">Total</th></tr>
        <?php foreach ($items as $i) { ?>
        <tr><td><?= portal_e($i['product_name'] ?: ($i['service_name'] ?? 'Item')); ?><?= !empty($i['pack_label']) && (int) ($i['pack_size'] ?? 1) > 1 ? ' <span class="text-muted small">(' . portal_e($i['pack_label']) . ')</span>' : ''; ?></td>
            <td class="text-end"><?= (int) $i['quantity']; ?></td><td class="text-end"><?= number_format((float) $i['unit_price'], 2); ?></td><td class="text-end"><?= number_format((float) $i['line_total'], 2); ?></td></tr>
        <?php } ?>
    </table></div>
    <div class="row justify-content-end"><div class="col-md-5"><table class="table table-sm mb-0">
        <tr><td>Subtotal</td><td class="text-end"><?= portal_money($sale['subtotal']); ?></td></tr>
        <?php if ((float) $sale['discount_amount'] > 0) { ?><tr><td>Discount</td><td class="text-end">− <?= portal_money($sale['discount_amount']); ?></td></tr><?php } ?>
        <tr class="fw-bold"><td>Total</td><td class="text-end"><?= portal_money($sale['total_amount']); ?></td></tr>
        <tr><td>Paid</td><td class="text-end">− <?= portal_money($sale['amount_paid']); ?></td></tr>
        <?php if ($charges > 0.009) { ?><tr><td>Overdue charges</td><td class="text-end">+ <?= portal_money($charges); ?></td></tr><?php } ?>
        <tr class="fw-bold <?= $isReceipt ? 'text-success' : 'text-danger'; ?>"><td>Balance</td><td class="text-end"><?= $isReceipt ? 'Paid in full' : portal_money($bal); ?></td></tr>
    </table></div></div>
    <p class="small text-muted mt-3 mb-0">This is a copy of your RISE MOTIVE <?= $isReceipt ? 'receipt' : 'invoice'; ?>. It is not an RRA/EBM tax invoice. Questions? Send a request from the portal.</p>
</div></div>
<?php portal_footer();