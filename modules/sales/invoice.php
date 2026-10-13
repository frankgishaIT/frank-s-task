<?php
require '../../config/db.php';
require '../../vendor/autoload.php'; // adjust path if your vendor folder is elsewhere
require_once '../../includes/credit_charge_helpers.php'; // NEW: overdue charges

use Mpdf\Mpdf;

// NEW: credit invoices must be paid within this many hours (the 72-hour rule).
const INVOICE_PAYMENT_HOURS = 72;
const INVOICE_DAILY_CHARGE_PERCENT = 1;
const INVOICE_HELP_PHONE = '0795 344 768';

// CHANGED: problems are sent as "error" (they were sent as "success"), and messages are urlencoded.
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?error=' . urlencode('Invalid invoice requested.')); exit; }

// NEW: bring the overdue charges up to date before printing.
credit_accrue_now($conn, (int) $id);

$statement = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name, customers.phone AS customer_phone, customers.email AS customer_email, customers.address AS customer_address, customers.province AS customer_province, customers.district AS customer_district, customers.sector AS customer_sector, customers.loyalty_points AS customer_loyalty_points, users.names AS recorded_by_name, users.role AS recorded_by_role, users.phone AS recorded_by_phone, roles.name AS recorded_by_position
    FROM sales
    LEFT JOIN customers ON sales.customer_id = customers.id
    LEFT JOIN users ON sales.recorded_by = users.id
    LEFT JOIN roles ON users.role_id = roles.id
    WHERE sales.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$sale) { header('Location: index.php?error=' . urlencode('Sale not found.')); exit; }

if (current_user_role() === 'Employee' && (int) $sale['recorded_by'] !== (int) current_user_id()) {
    http_response_code(403);
    include '../../includes/access_denied.php';
    exit;
}

$itemsStatement = mysqli_prepare($conn, 'SELECT sale_items.*, products.product_name, products.product_code, products.unit FROM sale_items LEFT JOIN products ON sale_items.product_id = products.id WHERE sale_id = ? ORDER BY sale_items.id');
mysqli_stmt_bind_param($itemsStatement, 'i', $id);
mysqli_stmt_execute($itemsStatement);
$items = mysqli_stmt_get_result($itemsStatement);

$isPending = $sale['status'] === 'Pending Discount Approval';
$isCancelled = $sale['status'] === 'Cancelled';
// CHANGED: any sale that is not fully paid is an invoice (Credit AND Partially Paid), as approved.
// Before, a Partially Paid sale paid partly in cash was printed as a "Receipt" although money was owed.
$isUnpaidInvoice = in_array($sale['status'], ['Credit', 'Partially Paid'], true);
$isPaidReceipt = $sale['status'] === 'Paid';
$showLoyalty = !$isPending && !$isCancelled && $sale['customer_id'];

// Payment methods are shown on every non-cancelled sale.
$showPaymentMethods = !$isCancelled;

function money($v) { return number_format((float) $v, 2); }

$principalDue = max(0, round((float) $sale['total_amount'] - (float) $sale['amount_paid'], 2));
$chargesDue = max(0, round((float) ($sale['charges_accrued'] ?? 0) - (float) ($sale['charges_paid'] ?? 0), 2));
$chargesPaid = round((float) ($sale['charges_paid'] ?? 0), 2);
$balanceDue = round($principalDue + $chargesDue, 2);
// NEW: payment deadline = invoice creation time + 72 hours.
// The deadline stored when the credit invoice was issued (72-hour rule); older sales fall back to created_at + 72h.
$createdTs = strtotime($sale['invoice_issued_at'] ?? $sale['created_at'] ?? $sale['sale_date']);
$deadlineTs = !empty($sale['payment_deadline']) ? strtotime($sale['payment_deadline']) : $createdTs + INVOICE_PAYMENT_HOURS * 3600;
$isOverdue = $isUnpaidInvoice && time() > $deadlineTs;

$customerLocationParts = array_filter([$sale['customer_sector'] ?? '', $sale['customer_district'] ?? '', $sale['customer_province'] ?? '']);
$customerLocation = $customerLocationParts ? implode(', ', $customerLocationParts) : null;

$docTitle = $isPending ? 'Draft Invoice' : ($isUnpaidInvoice ? 'Invoice' : ($isCancelled ? 'Cancelled Sale' : 'Receipt'));
$docNumber = 'No. RM' . str_pad($sale['id'], 6, '0', STR_PAD_LEFT);
$statusLabel = htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8');
$statusColor = $isPending ? '#B8860B' : ($isCancelled ? '#6B7280' : ($isUnpaidInvoice ? '#E24B4A' : '#0FA968'));
$statusBg    = $isPending ? '#FDF3E3' : ($isCancelled ? '#F1F3F9' : ($isUnpaidInvoice ? '#FCE9E9' : '#E1F7EE'));

$logoPath = __DIR__ . '/../../assets/images/rise.jpg';

// ---- Repeating header (no URL, ever — this is entirely our own HTML) ----
$headerHtml = '
<table width="100%" style="border-bottom:2px solid #1E2333; padding-bottom:6px;">
  <tr>
    <td style="width:60%; vertical-align:top;">
      <table cellpadding="0" cellspacing="0" width="100%">
       <tr><td><img src="' . $logoPath . '" style="height:70px; width:auto;"></td></tr>
         <tr><td style="padding-top:8px;">
          <span style="font-size:24px; font-weight:900; color:#000000; letter-spacing:0.5px;">RISE MOTIVE</span><br>
           <span style="font-size:10px; font-weight:700; color:#1E2333; margin-top:8px; display:inline-block;">TIN Number:122923513</span><br>
           <span style="font-size:10px; font-weight:700; color:#1E2333; margin-top:8px; display:inline-block;">Website: www.risemotive.rw</span><br>
           <span style="font-size:10px; font-weight:700; color:#1E2333; margin-top:8px; display:inline-block;">Kicukiro District, Kigali, Rwanda</span>
           </td></tr>
      </table>
    </td>
    <td style="width:40%; text-align:right; vertical-align:top;">
      <span style="font-size:14px; font-weight:700; color:#1E2333;">' . htmlspecialchars($docTitle, ENT_QUOTES, 'UTF-8') . '</span><br>
      <span style="font-size:9px; color:#6B7280;">' . htmlspecialchars($docNumber, ENT_QUOTES, 'UTF-8') . '</span><br>
      <span style="font-size:8px; font-weight:700; color:' . $statusColor . '; background:' . $statusBg . '; padding:2px 8px; border-radius:8px;">' . $statusLabel . '</span>
    </td>
  </tr>
</table>';
// ---- Repeating footer: page number only, no URL, no date-stamp strip ----
$footerHtml = '
<table width="100%" style="border-top:1px solid #E4E8F2; padding-top:4px;">
  <tr>
    <td style="width:50%; font-size:8px; color:#1E2333;">Generated by MY MOTIVE &middot; ' . date('d M Y, H:i') . '</td>
    <td style="width:50%; text-align:right; font-size:8px; color:#1E2333;">Page {PAGENO} of {nbpg}</td>
  </tr>
</table>';

// ---- Main body content ----
ob_start();
?>
<style>
  body { font-family: sans-serif; color:#1E2333; font-size:11px; }
  table.info-grid { width:100%; border:1px solid #E4E8F2; border-radius:6px; font-size:10px; margin-bottom:14px; }
  table.info-grid td { padding:5px 10px; }
  table.info-grid .label { color:#6B7280; width:90px; display:inline-block; margin-right:8px; }
  table.items { width:100%; border-collapse:collapse; font-size:10px; margin-bottom:10px; }
  table.items thead td { font-size:9px; text-transform:uppercase; color:#000000; border-bottom:1px solid #E4E8F2; padding:4px 0; font-weight:800; }
  table.items tbody td { padding:5px 0; border-bottom:1px solid #E4E8F2; }
  table.items td.amount { text-align:right; }
  table.totals { width:45%; margin-left:55%; font-size:10px; page-break-inside: avoid; }
  table.totals td { padding:3px 0; }
  table.totals td.val { text-align:right; }
  table.totals tr.grand td { border-top:2px solid #1E2333; font-size:13px; font-weight:800; color:#1E2FE0; padding-top:6px; }
  table.totals tr.due td { font-size:12px; font-weight:800; color:#E24B4A; padding-top:4px; }
  .footnote { margin-top:12px; font-size:9px; color:#1E2333; }
  .loyalty-block { margin-bottom:14px; padding:8px 10px; background:#F0F4FF; border:1px solid #D8E0FA; border-radius:6px; font-size:10px; }
  .loyalty-block .label { color:#6B7280; width:120px; display:inline-block; }
  .loyalty-block table { width:100%; }
  .loyalty-block td { padding:0; }
 .loyalty-block td.right { text-align:right; }
  .info-box { margin-top:16px; padding:10px 12px; border:1px solid #E4E8F2; border-radius:6px; font-size:10px; }
  .info-box table.cols { width:100%; border-collapse:collapse; }
  .info-box table.cols td { vertical-align:top; padding:0; }
  .info-box table.cols td.col-left { width:60%; padding-right:14px; }
  .info-box table.cols td.col-right { width:40%; border-left:1px solid #E4E8F2; padding-left:14px; }
  .pm-heading, .sig-heading { font-size:11px; font-weight:800; margin-bottom:8px; }
  .pm-option { font-weight:700; margin:8px 0 3px; }
  .pm-detail, .sig-detail { margin-left:14px; color:#1E2333; }
  .pm-detail .pm-label, .sig-detail .pm-label { color:#1E2333; }
  .sig-sub { color:#1E2333; font-weight:600; margin-bottom:6px; }
  .deadline { margin-bottom:14px; padding:7px 10px; border-radius:6px; font-size:10px; }
  .terms { margin-top:14px; padding:9px 12px; border:1px solid #E4E8F2; border-radius:6px; font-size:8.5px; page-break-inside: avoid; }
  .terms .t-heading { font-size:9.5px; font-weight:800; margin-bottom:5px; }
  .terms ol { margin:0; padding-left:16px; }
  .terms li { margin-bottom:2px; }
</style>

<table class="info-grid">
  <tr>
    <td width="50%"><span class="label"><strong>Customer:&nbsp;</strong></span><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer', ENT_QUOTES, 'UTF-8'); ?></td>
    <td width="50%"><span class="label"><strong>Date:&nbsp;</strong></span><?= date('d M Y', strtotime($sale['sale_date'])); ?></td>
  </tr>
  <tr>
    <td><span class="label"><strong>Phone:&nbsp;</strong></span><?= htmlspecialchars($sale['customer_phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
    <td><span class="label"><strong>Payment Method:&nbsp;</strong></span><?= htmlspecialchars($sale['payment_method'], ENT_QUOTES, 'UTF-8'); ?></td>
  </tr>
  <tr>
    <td><span class="label"><strong>Location:&nbsp;</strong></span><?= $customerLocation ? htmlspecialchars($customerLocation, ENT_QUOTES, 'UTF-8') : '—'; ?></td>
    <td><span class="label"><strong>Served By:&nbsp;</strong></span><?= htmlspecialchars($sale['recorded_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
  </tr>
  <tr>
    <td><span class="label"><strong>Amount Paid:&nbsp;</strong></span>RWF <?= money($sale['amount_paid']); ?></td>
    <td><?php if ($sale['customer_id']) { ?><span class="label"><strong>Customer ID:&nbsp;</strong></span>RMC<?= str_pad((string) $sale['customer_id'], 6, '0', STR_PAD_LEFT); ?><?php } ?></td>
  </tr>
</table>

<?php if ($isUnpaidInvoice) { ?>
<!-- NEW: payment deadline (72 hours after the invoice was created) -->
<div class="deadline" style="background:<?= $isOverdue ? '#FCE9E9' : '#FFF8E6'; ?>; border:1px solid <?= $isOverdue ? '#F3C1C1' : '#F5DFA6'; ?>;">
  <strong>Payment due by: <?= date('d M Y, H:i', $deadlineTs); ?></strong>
  (<?= INVOICE_PAYMENT_HOURS; ?> hours after this invoice was issued on <?= date('d M Y, H:i', $createdTs); ?>).
  <?php if ($isOverdue) { ?>
    <strong style="color:#E24B4A;">This invoice is overdue.</strong>
  <?php } else { ?>
    Pay in full by then to avoid overdue charges.
  <?php } ?>
</div>
<?php } ?>

<?php if ($showLoyalty) { ?>
<div class="loyalty-block">
  <table>
    <tr>
      <td>
        <?php $pStatus = $sale['points_status'] ?? null; ?>
        <?php if ($pStatus === 'Pending') { ?>
          <!-- NEW (72-hour rule) -->
          <span class="label"><strong>Points Pending:&nbsp;</strong></span><strong><?= (int) $sale['points_pending']; ?></strong>
          <span style="color:#6B7280;">&mdash; earned only if paid in full by <?= date('d M Y, H:i', $deadlineTs); ?></span>
        <?php } elseif ($pStatus === 'Not awarded') { ?>
          <span class="label"><strong>Points Earned:&nbsp;</strong></span><strong>0</strong>
          <span style="color:#6B7280;">&mdash; <?= htmlspecialchars((string) $sale['points_reason'], ENT_QUOTES, 'UTF-8'); ?></span>
        <?php } else { ?>
          <span class="label"><strong>Points Earned:&nbsp;</strong></span><strong><?= (int) ($sale['loyalty_points_earned'] ?? 0); ?></strong>
        <?php } ?>
      </td>
      <td class="right"><span class="label"><strong>Total Loyalty Points:&nbsp;</strong></span><strong><?= (int) ($sale['customer_loyalty_points'] ?? 0); ?></strong></td>
    </tr>
  </table>
</div>
<?php } ?>

<table class="items">
  <thead>
    <tr>
      <td>Product</td>
      <td>Type</td>
      <td class="amount">Qty</td>
      <td class="amount">Unit Price</td>
      <td class="amount">Total</td>
    </tr>
  </thead>
  <tbody>
    <?php while ($item = mysqli_fetch_assoc($items)) {
        $packSize = max(1, (int) ($item['pack_size'] ?? 1));
        $showPacking = $item['item_type'] === 'Product' && $packSize > 1;
    ?>
    <tr>
      <td>
        <?= htmlspecialchars($item['product_name'] ?? $item['service_name'] ?? 'Item', ENT_QUOTES, 'UTF-8'); ?>
        <?php if ($item['item_type'] === 'Product') { ?>
          <span style="font-size:9px; color:#6B7280;">(<?= htmlspecialchars($item['product_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?> — <?= htmlspecialchars($item['unit'] ?? 'Pieces', ENT_QUOTES, 'UTF-8'); ?>)</span>
        <?php } ?>
      </td>
      <td><?= htmlspecialchars($item['item_type'] === 'Product' ? 'Product' : 'Service', ENT_QUOTES, 'UTF-8'); ?></td>
      <td class="amount">
        <?= (int) $item['quantity']; ?> <?= $showPacking ? htmlspecialchars($item['pack_label'], ENT_QUOTES, 'UTF-8') : ''; ?>
        <?php if ($showPacking) { ?>
          <br><span style="font-size:9px; color:#6B7280;">(<?= $packSize; ?> each = <?= (int) $item['quantity'] * $packSize; ?> <?= htmlspecialchars($item['unit'] ?? '', ENT_QUOTES, 'UTF-8'); ?>)</span>
        <?php } ?>
      </td>
      <td class="amount"><?= money($item['unit_price']); ?></td>
      <td class="amount"><?= money($item['line_total']); ?></td>
    </tr>
    <?php } ?>
  </tbody>
</table>

<table class="totals">
  <tr><td>Subtotal</td><td class="val">RWF <?= money($sale['subtotal']); ?></td></tr>
  <tr><td>Discount</td><td class="val">&minus; RWF <?= money($sale['discount_amount']); ?></td></tr>
  <tr class="grand"><td>Total</td><td class="val">RWF <?= money($sale['total_amount']); ?></td></tr>
  <?php if ($isUnpaidInvoice) { ?>
  <!-- NEW: what has been paid and what is still due on this invoice -->
  <tr><td>Amount Paid</td><td class="val">&minus; RWF <?= money($sale['amount_paid']); ?></td></tr>
  <?php if ($chargesDue > 0.009 || $chargesPaid > 0.009) { ?>
  <!-- NEW: overdue charges (1% per day, compounded, after the 72-hour deadline) -->
  <tr><td>Overdue Charges (<?= (int) ($sale['charge_days'] ?? 0); ?> day<?= (int) ($sale['charge_days'] ?? 0) === 1 ? '' : 's'; ?>)</td><td class="val">+ RWF <?= money($chargesDue + $chargesPaid); ?></td></tr>
  <?php if ($chargesPaid > 0.009) { ?><tr><td>Charges Paid</td><td class="val">&minus; RWF <?= money($chargesPaid); ?></td></tr><?php } ?>
  <?php } ?>
  <tr class="due"><td>Balance Due</td><td class="val">RWF <?= money($balanceDue); ?></td></tr>
  <?php } ?>
</table>

<?php if ($showPaymentMethods) { ?>
<div class="info-box">
<table class="cols">
<tr>
<td class="col-left">
  <div class="pm-heading"><strong>ACCEPTED PAYMENT METHODS</strong></div>

  <div class="pm-option">Bank Transfer &ndash; EQUITY BANK</div>
  <div class="pm-detail"><span class="pm-label">Account No:</span> <strong>4020201146022</strong></div>

  <div class="pm-option">MTN-MoMo Code</div>
  <div class="pm-detail"><span class="pm-label">MoMo Code:</span> <strong>070600</strong></div>
  <div class="pm-detail"><span class="pm-label">Account Names:</span> <strong>RISE MOTIVE Ltd</strong></div>
</td>
<td class="col-right">
<?php if (!$isPending) { ?>
  <div class="sig-heading"><strong>For RISE MOTIVE</strong></div>
  <div class="sig-sub"><?= $isPaidReceipt ? 'Receipt' : 'Invoice'; ?> Issued and Approved by:</div>
  <div class="sig-detail"><span class="pm-label">Name:</span> <strong><?= htmlspecialchars($sale['recorded_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
  <div class="sig-detail"><span class="pm-label">Position:</span> <strong><?= htmlspecialchars($sale['recorded_by_position'] ?? ucfirst($sale['recorded_by_role'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
  <div class="sig-detail"><span class="pm-label">Contact:</span> <strong><?= htmlspecialchars($sale['recorded_by_phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></strong></div>
<?php } ?>
</td>
</tr>
</table>
</div>
<?php } ?>

<?php if ($isUnpaidInvoice || $isPending) { ?>
<!-- NEW: Terms & Conditions, on every invoice that is not paid (and on draft invoices) -->
<div class="terms">
  <div class="t-heading">TERMS &amp; CONDITIONS</div>
  <div style="margin-bottom:3px;">By Accepting This Invoice, The Customer Agrees That:</div>
  <ol>
    <li>This is not an official RRA/EBM tax invoice. Request an EBM Receipt after payment via <?= INVOICE_HELP_PHONE; ?>.</li>
    <li>Credit invoices must be paid within <?= INVOICE_PAYMENT_HOURS; ?> hours. Thereafter, a <?= INVOICE_DAILY_CHARGE_PERCENT; ?>% daily compound charge applies.</li>
    <li>Rise Motive Ltd is not responsible for delays caused by incomplete information or late customer responses.</li>
    <li>Additional work or changes after confirmation may incur extra charges.</li>
    <li>Payments are non-refundable once service has started or products have been delivered.</li>
    <li>This system-generated RM Invoice is valid without a handwritten signature.</li>
  </ol>
</div>
<?php } elseif ($isPaidReceipt) { ?>
<!-- NEW: Tax & Receipt Notice, on every paid receipt -->
<div class="terms">
  <div class="t-heading">TAX &amp; RECEIPT NOTICE</div>
  <ol>
    <li>This Receipt is not an official RRA/EBM tax receipt. For an official EBM Receipt, contact <?= INVOICE_HELP_PHONE; ?>.</li>
    <li>This system-generated RM Receipt is valid without a handwritten signature.</li>
  </ol>
</div>
<?php } ?>

<div class="footnote">
  <?php if ($isPending) { ?>
    This is a draft invoice. This sale is awaiting manager approval — stock and finance have not been updated yet.
  <?php } elseif ($isCancelled) { ?>
    This sale was cancelled and was not applied to inventory or finance records.
  <?php } else { ?>
    Rise Beyond Limits!
  <?php } ?>
</div>
<?php
$bodyHtml = ob_get_clean();

// ---- Build the PDF ----
$tmp = sys_get_temp_dir() . '/mpdf';
if (!is_dir($tmp)) { @mkdir($tmp, 0775, true); }
$mpdf = new Mpdf([
    'format' => 'A4',
    'margin_top' => 55,
    'margin_bottom' => 18,
    'margin_header' => 10,
    'margin_footer' => 6,
    'margin_left' => 15,
    'margin_right' => 15,
    'tempDir' => $tmp,
]);

// ---- Real, page-covering watermark ----
if ($isPending) {
    $mpdf->SetWatermarkText('PENDING APPROVAL');
    $mpdf->showWatermarkText = true;
    $mpdf->watermark_font = 'DejaVuSansCondensed';
    $mpdf->watermarkTextAlpha = 0.15;
} elseif ($isCancelled) {
    $mpdf->SetWatermarkText('CANCELLED');
    $mpdf->showWatermarkText = true;
    $mpdf->watermark_font = 'DejaVuSansCondensed';
    $mpdf->watermarkTextAlpha = 0.15;
}

$mpdf->SetTitle($docTitle . ' ' . $docNumber);
$mpdf->SetHTMLHeader($headerHtml);
$mpdf->SetHTMLFooter($footerHtml);
$mpdf->WriteHTML($bodyHtml);

$filename = ($isPending ? 'Draft-Invoice' : ($isCancelled ? 'Cancelled-Sale' : ($isUnpaidInvoice ? 'Invoice' : 'Receipt')))
    . '-RM' . str_pad($sale['id'], 6, '0', STR_PAD_LEFT) . '.pdf';

$mpdf->Output($filename, \Mpdf\Output\Destination::INLINE);