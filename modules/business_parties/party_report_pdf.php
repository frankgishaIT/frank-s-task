<?php
// NEW FILE: modules/business_parties/party_report_pdf.php
// Streams a Receivables / Payables report as a PDF (view, print or download).
ob_start();
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/party_report_queries.php';
require '../../includes/report_pdf.php';
require_role(['Admin', 'Manager']);

$report = party_report_build($conn, (string) ($_GET['report'] ?? ''), $_GET);
if (!empty($report['error'])) {
    ob_end_clean();
    http_response_code(400);
    echo htmlspecialchars($report['error'], ENT_QUOTES, 'UTF-8');
    exit;
}
$pdf = report_render_pdf($report);
$filename = 'business-parties-' . $report['type'] . '-' . date('Ymd') . '.pdf';
ob_end_clean();
$pdf->Output($filename, isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);