<?php
/** NEW FILE: modules/market/report_pdf.php — streams a Market Insights report as a PDF (inline = view / print, &download=1 = download). */
ob_start();
define('MI_NO_ALERTS', true);
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/market_report_queries.php';
require_once __DIR__ . '/../../includes/report_pdf.php';
mi_require($conn, 'reports');
$report = mi_report_build($conn, (string) ($_GET['report'] ?? ''), $_GET);
if (!empty($report['error'])) { ob_end_clean(); http_response_code(400); echo htmlspecialchars($report['error'], ENT_QUOTES, 'UTF-8'); exit; }
$report['totals'] = [];
if (!empty($report['notes'])) { $report['footer_summary'] = []; }
$pdf = report_render_pdf($report);
ob_end_clean();
$pdf->Output('market-' . $report['type'] . '-' . date('Ymd') . '.pdf', isset($_GET['download']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);