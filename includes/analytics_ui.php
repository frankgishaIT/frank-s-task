<?php
/**
 * NEW FILE: includes/analytics_ui.php
 * Shared pieces of the analytics pages (Customer Analytics, Employees Dashboard):
 *   - a chart card with a "JPG" button (saves that chart as a picture),
 *   - the "Download PDF" button: the browser sends the drawn charts as pictures, the server puts them in a PDF
 *     with the same header as every MY MOTIVE report (mPDF),
 *   - charts with a white background (so JPG and PDF are not transparent/black),
 *   - clicking a bar / slice opens the records behind it (when the chart has links).
 */
require_once __DIR__ . '/report_helpers.php';

function an_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }

/** A chart card. $height in px. */
function an_chart_card(string $id, string $title, string $subtitle = '', int $height = 260, string $extraClass = ''): void
{
    echo '<div class="card border-0 shadow-sm h-100 ' . an_e($extraClass) . '"><div class="card-body">'
        . '<div class="d-flex justify-content-between align-items-start gap-2 mb-1"><div><h6 class="mb-0">' . an_e($title) . '</h6>'
        . ($subtitle !== '' ? '<div class="small text-muted">' . $subtitle . '</div>' : '') . '</div>'
        . '<button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Save this chart as a JPG picture" onclick="rmChartJpg(\'' . an_e($id) . '\', ' . an_e(json_encode($title)) . ')"><i class="bi bi-image"></i> JPG</button></div>'
        . '<div style="position:relative; height:' . $height . 'px;"><canvas id="' . an_e($id) . '" data-an-title="' . an_e($title) . '"></canvas></div>'
        . '</div></div>';
}

/** The "Download PDF" form: the charts are added by rmAnalyticsPdf() just before sending. */
function an_pdf_button(string $label = 'Download PDF'): void
{
    $qs = $_GET; unset($qs['export'], $qs['drill']);
    echo '<form method="POST" action="?' . an_e(http_build_query($qs + ['export' => 'pdf'])) . '" target="_blank" id="anPdfForm" class="d-inline">'
        . '<button type="submit" class="btn btn-sm btn-outline-primary" onclick="return rmAnalyticsPdf(this.form);"><i class="bi bi-file-earmark-pdf"></i> ' . an_e($label) . '</button></form>';
}

/** NEW: "Preview PDF" (opens in a new tab) and "Download PDF" buttons; both send the drawn charts. */
function an_pdf_buttons(): void
{
    $qs = $_GET; unset($qs['export'], $qs['drill'], $qs['dl']);
    foreach ([['Preview PDF', 'bi-eye', []], ['Download PDF', 'bi-download', ['dl' => 1]]] as [$l, $i, $x]) {
        echo '<form method="POST" action="?' . an_e(http_build_query($qs + ['export' => 'pdf'] + $x)) . '" target="_blank" class="d-inline">'
            . '<button type="submit" class="btn btn-sm btn-outline-primary" onclick="return rmAnalyticsPdf(this.form);"><i class="bi ' . $i . '"></i> ' . an_e($l) . '</button></form> ';
    }
}

/** Chart pictures sent by the browser for the PDF: [['title', 'data' => data URI]] (JPEG/PNG only, size-limited). */
function an_posted_charts(): array
{
    $out = [];
    foreach ((array) ($_POST['charts'] ?? []) as $c) {
        $data = (string) ($c['data'] ?? '');
        if (strlen($data) > 3000000 || !preg_match('#^data:image/(jpeg|png);base64,[A-Za-z0-9+/=]+$#', $data)) { continue; }
        $out[] = ['title' => mb_substr((string) ($c['title'] ?? ''), 0, 120), 'data' => $data];
        if (count($out) >= 20) { break; }
    }
    return $out;
}

/**
 * The PDF: report header (company, title, filters), summary boxes, the charts (two per row) and tables.
 * $tables: [['title', 'columns' => [label...], 'rows' => [[cell...]]]]
 */
function an_render_pdf(string $title, array $filters, array $summary, array $charts, array $tables, string $fileName): void
{
    require_once __DIR__ . '/report_pdf.php';
    $co = report_company();
    $tmp = sys_get_temp_dir() . '/mpdf';
    if (!is_dir($tmp)) { @mkdir($tmp, 0775, true); }
    $mpdf = new \Mpdf\Mpdf(['format' => 'A4-L', 'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 12, 'tempDir' => $tmp]);
    $mpdf->SetTitle($title);
    $mpdf->SetAuthor('Rise Motive Ltd');
    $mpdf->SetFooter('MY MOTIVE · ' . $title . ' · generated ' . date('d M Y H:i') . '||Page {PAGENO} of {nbpg}');
    $h = '<style>body{font-family:sans-serif;font-size:9pt;color:#1E2333;} h1{font-size:16pt;margin:0;color:#1E2FE0;} .muted{color:#6B7280;}
        .box{border:1px solid #E3E7F1;border-radius:6px;padding:6px 8px;} table.t{border-collapse:collapse;width:100%;margin-top:4px;} table.t th{background:#F2F5F9;text-align:left;padding:4px;border-bottom:1px solid #DDE2EE;font-size:8pt;}
        table.t td{padding:3px 4px;border-bottom:1px solid #EEF1F8;font-size:8pt;} .ct{font-weight:bold;font-size:10pt;margin:6px 0 2px;}</style>';
    $logo = is_file($co['logo']) ? '<img src="' . $co['logo'] . '" style="height:36px;"><br>' : '';
    $h .= '<table width="100%"><tr><td>' . $logo . '<h1>' . an_e($title) . '</h1><div class="muted">' . an_e($co['name'] . ' · TIN ' . $co['tin'] . ' · ' . $co['address']) . '</div></td><td style="text-align:right;vertical-align:top;" class="muted">';
    foreach ($filters as [$k, $v]) { $h .= '<div><b>' . an_e($k) . ':</b> ' . an_e($v) . '</div>'; }
    $h .= '</td></tr></table>';
    if ($summary) {
        $h .= '<table width="100%" style="margin-top:8px;"><tr>';
        foreach ($summary as $i => [$k, $v]) {
            if ($i && $i % 5 === 0) { $h .= '</tr><tr>'; }
            $h .= '<td style="width:20%;padding:3px;"><div class="box"><div class="muted">' . an_e($k) . '</div><div style="font-size:12pt;font-weight:bold;">' . an_e($v) . '</div></div></td>';
        }
        $h .= '</tr></table>';
    }
    if ($charts) {
        $h .= '<table width="100%" style="margin-top:6px;">';
        foreach (array_chunk($charts, 2) as $pair) {
            $h .= '<tr>';
            foreach ($pair as $c) { $h .= '<td style="width:50%;padding:4px;vertical-align:top;"><div class="ct">' . an_e($c['title']) . '</div><img src="' . $c['data'] . '" style="width:100%;"></td>'; }
            if (count($pair) === 1) { $h .= '<td></td>'; }
            $h .= '</tr>';
        }
        $h .= '</table>';
    } else {
        $h .= '<p class="muted">No chart pictures were received (the page was not fully loaded). The tables below contain the same figures.</p>';
    }
    foreach ($tables as $t) {
        if (!$t['rows']) { continue; }
        $h .= '<div class="ct" style="margin-top:10px;">' . an_e($t['title']) . '</div><table class="t"><tr>';
        foreach ($t['columns'] as $c) { $h .= '<th>' . an_e($c) . '</th>'; }
        $h .= '</tr>';
        foreach ($t['rows'] as $r) { $h .= '<tr>'; foreach ($r as $cell) { $h .= '<td>' . an_e($cell) . '</td>'; } $h .= '</tr>'; }
        $h .= '</table>';
    }
    $mpdf->WriteHTML($h);
    while (ob_get_level() > 0) { ob_end_clean(); }
    // CHANGED: ?dl=1 downloads the file; otherwise the PDF opens in the browser (preview).
    $mpdf->Output($fileName, !empty($_GET['dl']) ? \Mpdf\Output\Destination::DOWNLOAD : \Mpdf\Output\Destination::INLINE);
    exit;
}

/** The shared JavaScript (call once, after the charts' canvases). */
function an_js(): void
{
    ?>
<script>
(function () {
    // White background for every chart, so the JPG/PDF pictures are not transparent.
    if (typeof Chart !== 'undefined') {
        Chart.register({ id: 'anWhiteBg', beforeDraw: function (c) { var x = c.ctx; x.save(); x.globalCompositeOperation = 'destination-over'; x.fillStyle = '#fff'; x.fillRect(0, 0, c.width, c.height); x.restore(); } });
        Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
        Chart.defaults.plugins.legend.labels.boxWidth = 12;
    }
    window.rmChartJpg = function (id, title) {
        var cv = document.getElementById(id); if (!cv) { return; }
        var a = document.createElement('a');
        a.href = cv.toDataURL('image/jpeg', 0.92);
        a.download = String(title || id).replace(/[^A-Za-z0-9]+/g, '-').replace(/^-|-$/g, '').toLowerCase() + '.jpg';
        document.body.appendChild(a); a.click(); a.remove();
    };
    window.rmAnalyticsPdf = function (form) {
        form.querySelectorAll('.an-chart-input').forEach(function (i) { i.remove(); });
        var n = 0;
        document.querySelectorAll('canvas[data-an-title]').forEach(function (cv) {
            if (!cv.width || cv.closest('.d-none')) { return; }
            ['title', 'data'].forEach(function (k) {
                var i = document.createElement('input'); i.type = 'hidden'; i.className = 'an-chart-input';
                i.name = 'charts[' + n + '][' + k + ']'; i.value = k === 'title' ? cv.getAttribute('data-an-title') : cv.toDataURL('image/jpeg', 0.85);
                form.appendChild(i);
            });
            n++;
        });
        return true;
    };
    // Clicking a bar/slice opens its records (links[i] of the chart).
    window.rmChartLinks = function (links) {
        return function (evt, els) { if (els && els.length && links[els[0].index]) { window.location.href = links[els[0].index]; } };
    };
    window.rmPalette = ['#1E2FE0', '#0FA968', '#E68A1C', '#7C5CE0', '#159AA8', '#E24B4A', '#3B82F6', '#F0B429', '#8A90A3', '#1E2333', '#C084FC', '#22C55E'];
})();
</script>
    <?php
}

/** Monthly buckets ['YYYY-MM' => 'Mon YYYY'] from one date to another (inclusive). */
function an_months(string $from, string $to): array
{
    $out = [];
    for ($m = date('Y-m-01', strtotime($from)); $m <= $to; $m = date('Y-m-01', strtotime($m . ' +1 month'))) { $out[substr($m, 0, 7)] = date('M Y', strtotime($m)); }
    return $out;
}

function an_rows(mysqli $conn, string $sql, string $types = '', array $params = []): array
{
    $s = mysqli_prepare($conn, $sql);
    if (!$s) { return []; }
    if ($types !== '') { mysqli_stmt_bind_param($s, $types, ...$params); }
    mysqli_stmt_execute($s);
    return mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
}

function an_has_column(mysqli $conn, string $table, string $col): bool
{
    static $cache = [];
    $k = $table . '.' . $col;
    if (!isset($cache[$k])) {
        $r = @mysqli_query($conn, "SHOW COLUMNS FROM `" . preg_replace('/\W/', '', $table) . "` LIKE '" . preg_replace('/\W/', '', $col) . "'");
        $cache[$k] = $r && mysqli_num_rows($r) > 0;
    }
    return $cache[$k];
}

/** Initials avatar or photo. */
function an_avatar(?string $photoUrl, string $name, int $size = 64, string $bg = '#1E2FE0'): string
{
    if ($photoUrl) { return '<img src="' . an_e($photoUrl) . '" alt="" style="width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;object-fit:cover;">'; }
    $parts = preg_split('/\s+/', trim($name)) ?: ['?'];
    $ini = strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
    return '<span style="display:inline-flex;align-items:center;justify-content:center;width:' . $size . 'px;height:' . $size . 'px;border-radius:50%;background:' . $bg . ';color:#fff;font-weight:700;font-size:' . (int) ($size / 2.6) . 'px;">' . an_e($ini) . '</span>';
}