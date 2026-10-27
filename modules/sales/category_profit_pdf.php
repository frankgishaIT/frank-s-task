<?php
// NEW FILE: modules/sales/category_profit_pdf.php — Sales Profitability by Category as a PDF (view, print, download).
ob_start();
require '../../config/db.php';
require '../../includes/report_helpers.php';
require '../../includes/category_profit_queries.php';
require '../../includes/report_pdf.php';
require_role(['Admin', 'Manager']);

$report = category_profit_build($conn, $_GET);
if (!empty($report['error'])) {
    ob_end_clean();
    http_response_code(400);
    echo htmlspecialchars($report['error'], ENT_QUOTES, 'UTF-8');
    exit;
}
$pdf = report_render_pdf($report);
ob_end_clean();
$pdf->Output('sales-profit-by-category-' . date('Ymd') . '.pdf', isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);