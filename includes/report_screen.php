<?php
/**
 * On-screen result card shared by every report page.
 * $report   = array built by a *_report_build() function (see report_pdf.php for the shape)
 * $pdfUrl   = path of that module's PDF endpoint, e.g. 'report_pdf.php'
 * $query    = the current filters as a query string
 */
require_once __DIR__ . '/report_helpers.php';

function report_screen_figures(array $pairs, string $extraClass = ''): void
{
    if (!$pairs) {
        return;
    }
    echo '<div class="row g-2 mb-3 ' . report_e($extraClass) . '">';
    foreach ($pairs as $s) {
        echo '<div class="col-6 col-md-3"><div class="p-2 rounded" style="background:#F2F5F9;">'
            . '<div class="small text-muted">' . report_e($s[0]) . '</div><div class="fw-bold">' . report_e($s[1]) . '</div></div></div>';
    }
    echo '</div>';
}

function report_screen_result(array $report, string $pdfUrl, string $query): void
{
    if (!empty($report['error'])) {
        echo '<div class="alert alert-danger" style="border-radius:10px; border:none; font-size:13px;">' . report_e($report['error']) . '</div>';
        return;
    }
    $cols = $report['columns'];
    $right = function ($c) { return ($c['align'] ?? 'L') === 'R' ? 'text-end' : ''; };
    ?>
<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
            <div>
                <h4 class="mb-1"><?= report_e($report['title']); ?></h4>
                <?php foreach ($report['filters'] as $f) { ?>
                    <div class="small text-muted"><strong><?= report_e($f[0]); ?>:</strong> <?= report_e($f[1]); ?></div>
                <?php } ?>
            </div>
            <div class="d-flex gap-2">
                <a class="rm-btn rm-btn-outline-primary rm-btn-sm" target="_blank" href="<?= report_e($pdfUrl . '?' . $query); ?>"><i class="bi bi-printer me-1"></i>View / Print PDF</a>
                <a class="rm-btn rm-btn-primary rm-btn-sm" href="<?= report_e($pdfUrl . '?' . $query . '&download=1'); ?>"><i class="bi bi-download me-1"></i>Download PDF</a>
            </div>
        </div>

        <?php report_screen_figures($report['summary'] ?? []); ?>

        <div class="table-responsive">
            <table class="table table-bordered align-middle" style="font-size:13px;">
                <thead><tr>
                    <?php foreach ($cols as $c) { ?><th class="<?= $right($c); ?>"><?= report_e($c['label']); ?></th><?php } ?>
                </tr></thead>
                <tbody>
                <?php if (empty($report['rows'])) { ?>
                    <tr><td colspan="<?= count($cols); ?>" class="text-center text-muted py-4">No records found for the selected filters.</td></tr>
                <?php } ?>
                <?php foreach ($report['rows'] as $row) { ?>
                    <tr>
                    <?php foreach ($cols as $c) { ?>
                        <td class="<?= $right($c); ?>"><?= report_e(report_format_cell($row[$c['key']] ?? '', $c['type'] ?? 'text')); ?></td>
                    <?php } ?>
                    </tr>
                <?php } ?>
                </tbody>
                <?php if (!empty($report['totals'])) { ?>
                <tfoot><tr class="fw-bold" style="background:#E4E9F1;">
                    <?php foreach ($cols as $i => $c) { ?>
                        <td class="<?= $right($c); ?>"><?php
                            if (array_key_exists($c['key'], $report['totals'])) {
                                echo report_e(report_format_cell($report['totals'][$c['key']], $c['type'] ?? 'text'));
                            } elseif ($i === 0) {
                                echo report_e($report['totals_label'] ?? 'TOTAL');
                            } ?></td>
                    <?php } ?>
                </tr></tfoot>
                <?php } ?>
            </table>
        </div>

        <?php report_screen_figures($report['footer_summary'] ?? [], 'mt-2'); ?>
        <?php foreach ($report['notes'] ?? [] as $n) { ?><p class="small text-muted mt-3 mb-0"><?= report_e($n); ?></p><?php } ?>
        <p class="small text-muted mb-0">Report generated: <?= date('d M Y, H:i'); ?></p>
    </div>
</div>
<?php
}
