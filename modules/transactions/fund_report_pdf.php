<?php
// Streams a Fund report as a PDF (inline = view/print, &download=1 = download).
// Same pattern as the Transactions report PDF, and it uses the same
// report_render_pdf() so it carries the same Rise Motive header and footer.
// Save next to fund_reports.php in modules/transactions/.
ob_start();
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/fund_helpers.php';
require '../../includes/fund_report_queries.php';
require '../../includes/report_pdf.php';
require_role(['Admin', 'Manager']);

try {
    $fundReport = fund_report_build($conn, $_GET);
    $pdf = report_render_pdf(fund_report_pdf_shape($fundReport));
} catch (Throwable $e) {
    error_log('Fund report PDF failed: ' . $e->getMessage());
    ob_end_clean();
    http_response_code(500);
    echo 'Unable to create the PDF report.';
    exit;
}

$filename = 'fund-' . $fundReport['tab'] . '-' . $fundReport['from'] . '-to-' . $fundReport['to'] . '.pdf';
ob_end_clean();
$pdf->Output($filename, isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);