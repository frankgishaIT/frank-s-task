<?php
// NEW FILE: <your loans folder>/loan_report_pdf.php
// Streams a Loans report as a PDF (same as the Sales report PDF).
//   loan_report_pdf.php?report=outstanding              -> opens in the browser (view / print)
//   loan_report_pdf.php?report=outstanding&download=1   -> downloads the file
ob_start();
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/loan_report_queries.php';
require '../../includes/report_pdf.php';
require_role(['Admin', 'Manager']);

$report = loans_report_build($conn, (string) ($_GET['report'] ?? ''), $_GET);
if (!empty($report['error'])) {
    ob_end_clean();
    http_response_code(400);
    echo htmlspecialchars($report['error'], ENT_QUOTES, 'UTF-8');
    exit;
}

$pdf = report_render_pdf($report);
$filename = 'loans-' . $report['type'] . '-' . date('Ymd') . '.pdf';
ob_end_clean();
$pdf->Output($filename, isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);