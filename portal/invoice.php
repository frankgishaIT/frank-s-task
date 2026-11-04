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
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3"><a href="history.php" class="small">&larr; My invoices</a>
    <div class="d-flex gap-2">
        <a href="invoice_pdf.php?id=<?= $sid; ?>&download=1" class="btn btn-primary btn-sm"><i class="bi bi-download me-1"></i>Download PDF copy</a>
        <a href="invoice_pdf.php?id=<?= $sid; ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm"><i class="bi bi-printer me-1"></i>Open / print</a>
        <a href="ebm.php?invoice=<?= urlencode(portal_sale_number($sid)); ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-receipt-cutoff me-1"></i>Request EBM receipt</a>
    </div></div>
<h1 class="p-title mb-2"><?= $isReceipt ? 'Receipt' : 'Invoice'; ?> <?= portal_sale_number($sid); ?></h1>
<div class="text-center small text-muted mb-3">A copy of your original RISE MOTIVE <?= $isReceipt ? 'receipt' : 'invoice'; ?>, marked "COPY". <?= $bal > 0.009 ? 'Balance due: <strong class="text-danger">' . portal_money($bal) . '</strong>.' : 'Paid in full.'; ?></div>
<!-- The copy itself (same layout as the original, with a COPY watermark). On phones that cannot show PDFs inside the page, use the buttons above. -->
<div class="card overflow-hidden"><iframe src="invoice_pdf.php?id=<?= $sid; ?>#view=FitH" title="Invoice copy" style="width:100%; height:80vh; border:0;"></iframe></div>
<div class="small text-muted mt-2">This copy is not an RRA/EBM tax invoice. Need the official EBM receipt? <a href="ebm.php?invoice=<?= urlencode(portal_sale_number($sid)); ?>">Request it here</a>.</div>
<?php portal_footer();