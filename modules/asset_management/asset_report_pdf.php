<?php
// NEW FILE: modules/assets/asset_report_pdf.php
// Streams an Assets & Depreciation report as a PDF (same as the Sales report PDF).
//   asset_report_pdf.php?report=register              -> opens in the browser (view / print)
//   asset_report_pdf.php?report=register&download=1   -> downloads the file
ob_start();
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/asset_helpers.php';
require '../../includes/asset_report_queries.php';
require '../../includes/report_pdf.php';
require_role(['Admin', 'Manager']);

asset_run_depreciation_catchup_all($conn);

$report = asset_report_build($conn, (string) ($_GET['report'] ?? ''), $_GET);
if (!empty($report['error'])) {
    ob_end_clean();
    http_response_code(400);
    echo htmlspecialchars($report['error'], ENT_QUOTES, 'UTF-8');
    exit;
}

$pdf = report_render_pdf($report);
$filename = 'assets-' . $report['type'] . '-' . date('Ymd') . '.pdf';
ob_end_clean();
$pdf->Output($filename, isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);