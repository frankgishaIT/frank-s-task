<?php
/**
 * PDF renderer for every report, built on mPDF (the same library invoice.php uses),
 * so reports carry the same Rise Motive header and footer as invoices and receipts.
 *
 * A report module builds an array in this shape and calls report_render_pdf($report):
 *
 * [
 *   'title'          => 'Sales by Employee',
 *   'filters'        => [ ['Period', 'This Month (...)'], ['Employee', 'Jane'] ],
 *   'summary'        => [ ['Times Sold', '12'], ... ],            // key figures above the table
 *   'columns'        => [ ['key'=>'date','label'=>'Date','w'=>14,'align'=>'L','type'=>'date'], ... ],
 *   'rows'           => [ ['date'=>'2026-10-01', ...], ... ],
 *   'totals'         => ['amount'=>1000, 'profit'=>200],           // optional, keyed by column key
 *   'totals_label'   => 'TOTAL',
 *   'footer_summary' => [ ['Overall Profit', 'RWF 200.00'], ... ], // optional, after the table
 *   'notes'          => ['Amounts are net of discount.'],
 * ]
 *
 * CHANGED: large reports (e.g. All Transactions for a whole year) are rendered in mPDF's fast table
 * mode and very long texts are shortened in the PDF, so they no longer run past the server's time
 * limit (the browser then showed endless loading / "page not found").
 */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/report_helpers.php';

use Mpdf\Mpdf;

const REPORT_PDF_FAST_ROWS = 150;     // above this many rows, use the fast table mode
const REPORT_PDF_MAX_TEXT = 120;      // longer texts are shortened in the PDF (the screen shows them in full)

/** Company details shown in the header of every report. */
function report_company(): array
{
    return [
        'name'    => 'RISE MOTIVE',
        'tin'     => '122923513',
        'website' => 'www.risemotive.rw',
        'address' => 'Kicukiro District, Kigali, Rwanda',
        'logo'    => __DIR__ . '/../assets/images/rise.jpg',
    ];
}

function report_key_figures_html(array $pairs): string
{
    if (!$pairs) {
        return '';
    }
    $html = '<table class="kf" cellspacing="6" width="100%">';
    foreach (array_chunk($pairs, 4) as $chunk) {
        $html .= '<tr>';
        foreach ($chunk as $p) {
            $html .= '<td width="25%"><span class="kl">' . report_e($p[0]) . '</span><br><span class="kv">' . report_e($p[1]) . '</span></td>';
        }
        for ($i = count($chunk); $i < 4; $i++) {
            $html .= '<td width="25%" style="border:none; background:#ffffff;"></td>';
        }
        $html .= '</tr>';
    }
    return $html . '</table>';
}

/** NEW: a cell's text for the PDF, shortened when very long (long unbroken text is very slow to lay out). */
function report_pdf_cell_text(string $text): string
{
    if (mb_strlen($text) > REPORT_PDF_MAX_TEXT) {
        $text = rtrim(mb_substr($text, 0, REPORT_PDF_MAX_TEXT - 1)) . '…';
    }
    // Break very long words (e.g. codes without spaces) so a cell never forces a re-layout of the table.
    return preg_replace('/(\S{40})(?=\S)/u', '$1 ', $text);
}

/**
 * NEW (Task 6A): who generated the report, from the signed-in employee's profile:
 * ['name', 'position', 'phone']. Position = the employee's job title (roles), else their system role.
 */
function report_generated_by(): array
{
    $out = ['name' => (string) ($_SESSION['user_name'] ?? ''), 'position' => '', 'phone' => ''];
    $conn = $GLOBALS['conn'] ?? null;
    $uid = function_exists('current_user_id') ? (int) current_user_id() : 0;
    if ($conn instanceof mysqli && $uid > 0) {
        try {
            $s = mysqli_prepare($conn, 'SELECT u.names, u.role, u.phone, r.name AS position FROM users u LEFT JOIN roles r ON r.id = u.role_id WHERE u.id = ?');
            mysqli_stmt_bind_param($s, 'i', $uid);
            mysqli_stmt_execute($s);
            if ($u = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
                $out = ['name' => (string) $u['names'], 'position' => (string) ($u['position'] ?: ucfirst(strtolower((string) $u['role']))), 'phone' => (string) ($u['phone'] ?? '')];
            }
        } catch (Throwable $e) {
            // users.role_id / roles may not exist on an older database: fall back to the session name.
        }
    }
    return $out;
}

/** Builds the full PDF for a report array and returns the Mpdf object. */
function report_render_pdf(array $report): Mpdf
{
    // NEW: big reports need more time and memory than a normal page.
    @set_time_limit(300);
    @ini_set('memory_limit', '512M');
    // NEW: mPDF refuses HTML longer than pcre.backtrack_limit (1 MB by default) with an error;
    // a year of transactions is longer than that.
    @ini_set('pcre.backtrack_limit', '20000000');

    $co = report_company();
    $logoHtml = is_file($co['logo']) ? '<img src="' . $co['logo'] . '" style="height:42px; width:auto;"><br>' : '';

    $headerHtml = '
    <table width="100%" style="border-bottom:2px solid #1E2333;">
      <tr>
        <td style="width:60%; vertical-align:top;">' . $logoHtml . '
          <span style="font-size:17px; font-weight:bold; color:#000000;">' . report_e($co['name']) . '</span><br>
          <span style="font-size:8px; color:#1E2333;">TIN: ' . report_e($co['tin']) . ' &middot; ' . report_e($co['website']) . ' &middot; ' . report_e($co['address']) . '</span>
        </td>
        <td style="width:40%; text-align:right; vertical-align:bottom;">
          <span style="font-size:15px; font-weight:bold; color:#1E2333;">' . report_e($report['title']) . '</span>
        </td>
      </tr>
    </table>';

    // NEW (Task 6A): the footer names who generated the report.
    $gen = report_generated_by();
    $generatedLine = 'Generated by: <strong>' . report_e($gen['name'] !== '' ? $gen['name'] : 'MY MOTIVE') . '</strong>'
        . ($gen['position'] !== '' ? ' &middot; ' . report_e($gen['position']) : '')
        . ($gen['phone'] !== '' ? ' &middot; Tel. ' . report_e($gen['phone']) : '')
        . ' &middot; ' . date('d M Y, H:i') . ' &middot; MY MOTIVE';

    $footerHtml = '
    <table width="100%" style="border-top:1px solid #E4E8F2;">
      <tr>
        <td style="width:80%; font-size:8px; color:#1E2333;">' . $generatedLine . '</td>
        <td style="width:20%; text-align:right; font-size:8px; color:#1E2333;">Page {PAGENO} of {nbpg}</td>
      </tr>
    </table>';

    $columns = $report['columns'];
    $totalW = 0;
    foreach ($columns as $c) {
        $totalW += $c['w'];
    }

    $body = '<style>
      body { font-family: sans-serif; color:#1E2333; font-size:9px; }
      table.kf td { background:#F2F5F9; border:1px solid #D5DAE5; padding:5px 8px; }
      .kl { font-size:7.5px; color:#6B7280; } .kv { font-size:11px; font-weight:bold; }
      table.data { width:100%; border-collapse:collapse; margin-top:6px; }
      table.data th { background:#1E3A5F; color:#ffffff; font-size:8px; padding:5px 4px; border:1px solid #1E3A5F; text-align:left; }
      table.data td { padding:4px; border:1px solid #D5DAE5; vertical-align:top; }
      table.data tr.total td { background:#E4E9F1; font-weight:bold; }
      .filters td { font-size:9px; padding:1px 0; }
      .note { font-size:8px; color:#6B7280; font-style:italic; margin-top:6px; }
    </style>';

    $body .= '<table class="filters">';
    foreach ($report['filters'] ?? [] as $f) {
        $body .= '<tr><td><strong>' . report_e($f[0]) . ':</strong>&nbsp;</td><td>' . report_e($f[1]) . '</td></tr>';
    }
    $body .= '</table>';
    $body .= report_key_figures_html($report['summary'] ?? []);

    // CHANGED: the header row repeats on every page; column widths are fixed, so mPDF does not
    // have to measure every cell of every row.
    $body .= '<table class="data" repeat_header="1"><thead><tr>';
    foreach ($columns as $c) {
        $align = ($c['align'] ?? 'L') === 'R' ? 'right' : 'left';
        $body .= '<th style="width:' . round(100 * $c['w'] / $totalW, 2) . '%; text-align:' . $align . ';">' . report_e($c['label']) . '</th>';
    }
    $body .= '</tr></thead><tbody>';

    $rows = $report['rows'] ?? [];
    if (!$rows) {
        $body .= '<tr><td colspan="' . count($columns) . '" style="text-align:center; font-style:italic; padding:10px;">No records found for the selected filters.</td></tr>';
    }
    foreach ($rows as $row) {
        $body .= '<tr>';
        foreach ($columns as $c) {
            $align = ($c['align'] ?? 'L') === 'R' ? 'right' : 'left';
            $text = report_pdf_cell_text(report_format_cell($row[$c['key']] ?? '', $c['type'] ?? 'text'));
            $body .= '<td style="text-align:' . $align . ';">' . report_e($text) . '</td>';
        }
        $body .= '</tr>';
    }

    if (!empty($report['totals'])) {
        $body .= '<tr class="total">';
        foreach ($columns as $i => $c) {
            $align = ($c['align'] ?? 'L') === 'R' ? 'right' : 'left';
            if (array_key_exists($c['key'], $report['totals'])) {
                $txt = report_format_cell($report['totals'][$c['key']], $c['type'] ?? 'text');
            } else {
                $txt = $i === 0 ? ($report['totals_label'] ?? 'TOTAL') : '';
            }
            $body .= '<td style="text-align:' . $align . ';">' . report_e($txt) . '</td>';
        }
        $body .= '</tr>';
    }
    $body .= '</tbody></table>';

    if (!empty($report['footer_summary'])) {
        $body .= '<div style="margin-top:8px;">' . report_key_figures_html($report['footer_summary']) . '</div>';
    }
    foreach ($report['notes'] ?? [] as $n) {
        $body .= '<div class="note">' . report_e($n) . '</div>';
    }

    $tmp = sys_get_temp_dir() . '/mpdf';
    if (!is_dir($tmp)) {
        @mkdir($tmp, 0775, true);
    }
    $big = count($rows) > REPORT_PDF_FAST_ROWS;
    $mpdf = new Mpdf([
        'format' => 'A4-L',
        'margin_top' => 36, 'margin_bottom' => 16, 'margin_header' => 8, 'margin_footer' => 6,
        'margin_left' => 12, 'margin_right' => 12,
        'tempDir' => $tmp,
        // NEW: fast mode for big reports (plain cell borders, no per-cell measuring of the whole table).
        'simpleTables' => $big,
        'packTableData' => $big,
        'shrink_tables_to_fit' => 1,
    ]);
    $mpdf->SetTitle($report['title']);
    $mpdf->SetAuthor('Rise Motive Ltd');
    $mpdf->SetHTMLHeader($headerHtml);
    $mpdf->SetHTMLFooter($footerHtml);
    $mpdf->WriteHTML($body);
    return $mpdf;
}