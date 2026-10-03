<?php
// Streams a Sales report as a PDF.
//   report_pdf.php?report=summary&period=this_month            -> opens in the browser (view / print)
//   report_pdf.php?report=summary&period=this_month&download=1 -> downloads the file
ob_start();
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/sales_report_queries.php';
require '../../includes/report_pdf.php';
require_role(['Admin', 'Manager']);

$report = sales_report_build($conn, (string) ($_GET['report'] ?? ''), $_GET);
if (!empty($report['error'])) {
    ob_end_clean();
    http_response_code(400);
    echo htmlspecialchars($report['error'], ENT_QUOTES, 'UTF-8');
    exit;
}

$pdf = report_render_pdf($report);
$filename = 'sales-' . $report['type'] . '-' . date('Ymd') . '.pdf';
ob_end_clean();
$pdf->Output($filename, isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);
