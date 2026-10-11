<?php
// NEW MODULE: modules/financial_reports/report_pdf.php
// Streams a Financial Report as a PDF with the Rise Motive header and footer.
//   report_pdf.php?report=pnl&period=this_month              -> opens in the browser (view / print)
//   report_pdf.php?report=balance&as_of=2026-10-31&download=1 -> downloads the file
ob_start();
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/asset_helpers.php';
require '../../includes/financial_report_queries.php';
require '../../includes/report_pdf.php';
require_role(['Admin', 'Manager']);

asset_run_depreciation_catchup_all($conn);

$report = fin_report_build($conn, (string) ($_GET['report'] ?? ''), $_GET);
if (!empty($report['error'])) {
    ob_end_clean();
    http_response_code(400);
    echo htmlspecialchars($report['error'], ENT_QUOTES, 'UTF-8');
    exit;
}

$pdf = report_render_pdf($report);
$filename = 'financial-' . $report['type'] . '-' . date('Ymd') . '.pdf';
ob_end_clean();
$pdf->Output($filename, isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);