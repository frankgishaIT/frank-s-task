<?php
// NEW FILE: modules/customers/customer_report_pdf.php
// Streams a Customers & Credit report as a PDF (same as the Sales report PDF).
//   customer_report_pdf.php?report=aging              -> opens in the browser (view / print)
//   customer_report_pdf.php?report=aging&download=1   -> downloads the file
ob_start();
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/customer_report_queries.php';
require '../../includes/report_pdf.php';
require_role(['Admin', 'Manager']);

$report = customer_report_build($conn, (string) ($_GET['report'] ?? ''), $_GET);
if (!empty($report['error'])) {
    ob_end_clean();
    http_response_code(400);
    echo htmlspecialchars($report['error'], ENT_QUOTES, 'UTF-8');
    exit;
}

$pdf = report_render_pdf($report);
$filename = 'customers-' . $report['type'] . '-' . date('Ymd') . '.pdf';
ob_end_clean();
$pdf->Output($filename, isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);