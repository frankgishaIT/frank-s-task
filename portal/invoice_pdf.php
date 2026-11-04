<?php
/**
 * NEW FILE: portal/invoice_pdf.php — the customer's COPY of an invoice / receipt, as a PDF.
 * Made by the SAME generator as the original (modules/sales/invoice.php), so it has the original layout and details
 * (staff member who served the customer, loyalty points earned, payment method), plus a visible COPY watermark.
 * Only for the signed-in customer's OWN invoices.
 */
ob_start(); // nothing may be sent before the PDF
require __DIR__ . '/portal_start.php';
$sale = portal_own_sale($conn, $cid, (string) ($_GET['id'] ?? ''));
if (!$sale) { ob_end_clean(); http_response_code(404); echo 'Invoice not found in your account.'; exit; }
portal_log($conn, $cid, 'invoice_copy', portal_sale_number((int) $sale['id']) . (!empty($_GET['download']) ? ' (downloaded)' : ''));
$_GET['id'] = (int) $sale['id'];
define('RM_INVOICE_COPY', true);
while (ob_get_level()) { ob_end_clean(); }
require __DIR__ . '/../modules/sales/invoice.php';