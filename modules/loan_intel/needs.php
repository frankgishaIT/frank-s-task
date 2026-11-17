<?php
/**
 * NEW FILE: modules/loan_intel/needs.php — FINANCING NEEDS ANALYSIS (spec 4).
 * Situations detected in the records (stock, cash, debt, services, branches, equipment, operations). Internal resources
 * are applied FIRST; a need is only a "genuine financing need" when internal funds cannot cover it and it earns a return.
 */
require __DIR__ . '/common.php';
li_require($conn, 'view');
$co = li_company($conn);
$needs = li_needs($conn, $co);
if (($_GET['export'] ?? '') === 'csv') {
    li_audit($conn, 'export', 'needs', null, null, null, 'Financing needs CSV');
    li_csv('financing-needs-' . date('Ymd') . '.csv', ['Need', 'Type', 'Classification', 'Funding required', 'Internal funds used', 'Funding gap', 'Period (months)', 'Extra revenue / month', 'Extra opex / month', 'Extra operating profit / month', 'Cash flow impact', 'ROI %', 'Basis'],
        array_map(function ($n) { return [$n['title'], $n['type'], $n['class'], $n['funding'], $n['internal'], $n['gap'], $n['months'], $n['add_revenue'], $n['add_opex'], $n['add_profit'], $n['cash_impact'], $n['roi'], $n['basis']]; }, $needs),
        [['Internal funds available for investment', $co['internal']], ['Generated', date('Y-m-d H:i')]]);
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'needs.php', 'Financing Needs Analysis');
?>
<div class="row row-cols-2 row-cols-md-4 g-2 mb-3">
    <?= li_kpi('Internal funds available for investment', li_rwf($co['internal']), li_tag('Actual'), 'Investable funds after commitments, reserve and 3 months of repayments'); ?>
    <?= li_kpi('Cash today', li_rwf($co['cash']), li_tag('Actual'), 'Reserve kept: ' . li_rwf($co['reserve'])); ?>
    <?= li_kpi('Cash flow available for debt service', li_rwf($co['cfads']), li_tag('Estimated'), 'Per month, average of recent months'); ?>
    <?= li_kpi('Data confidence', li_e($co['confidence']), '', (int) $co['active_months'] . ' month(s) of sales history'); ?>
</div>
<div class="d-flex justify-content-between align-items-center mb-2">
    <div class="small text-muted">Internal funds are applied to the needs in the order shown (most urgent first). Borrowing is never suggested where internal funds or operational improvements are enough.</div>
    <a href="?export=csv" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a>
</div>
<?php foreach ($needs as $i => $n) {
    $color = strpos($n['class'], 'Genuine') === 0 ? '#1E2FE0' : (strpos($n['class'], 'Unnecessary') === 0 || strpos($n['class'], 'Operational') === 0 ? '#0FA968' : (strpos($n['class'], 'Temporary') === 0 ? '#B7791F' : '#7C5CE0')); ?>
<div class="card border-0 shadow-sm mb-3" style="border-left:4px solid <?= $color; ?> !important;"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2">
        <div><span class="badge bg-light text-dark border"><?= li_e($n['type']); ?></span> <strong><?= li_e($n['title']); ?></strong><div class="small text-muted"><?= li_e($n['need']); ?></div></div>
        <div class="text-end ms-auto"><span class="badge" style="background:<?= $color; ?>"><?= li_e($n['class']); ?></span><div class="small text-muted mt-1"><?= li_e($n['basis']); ?></div></div>
    </div>
    <div class="row row-cols-2 row-cols-md-4 row-cols-xl-6 g-2 my-2 small">
        <div class="col"><div class="text-muted">Estimated funding required</div><strong><?= $n['funding'] === null ? 'enter in a proposal' : li_rwf($n['funding']); ?></strong></div>
        <div class="col"><div class="text-muted">Available internal funds used</div><strong><?= li_rwf($n['internal']); ?></strong></div>
        <div class="col"><div class="text-muted">Funding gap</div><strong class="<?= $n['gap'] ? 'text-danger' : ''; ?>"><?= $n['gap'] === null ? '—' : li_rwf($n['gap']); ?></strong></div>
        <div class="col"><div class="text-muted">Expected investment period</div><strong><?= $n['months'] === null ? '—' : li_n($n['months'], 1) . ' month(s)'; ?></strong></div>
        <div class="col"><div class="text-muted">Extra revenue / month</div><strong><?= li_rwf($n['add_revenue']); ?></strong></div>
        <div class="col"><div class="text-muted">Extra operating expenses / month</div><strong><?= li_rwf($n['add_opex']); ?></strong></div>
        <div class="col"><div class="text-muted">Extra operating profit / month</div><strong><?= li_rwf($n['add_profit']); ?></strong></div>
        <div class="col"><div class="text-muted">Cash flow impact</div><strong><?= li_rwf($n['cash_impact']); ?></strong></div>
        <div class="col"><div class="text-muted">Estimated ROI</div><strong><?= li_pct($n['roi']); ?></strong><?= $n['roi'] !== null && $n['key'] === 'stock' ? ' <span class="text-muted">per stock cycle</span>' : ''; ?></div>
    </div>
    <details><summary class="small">Supporting data (<?= count($n['evidence']); ?>)</summary>
        <div class="table-responsive"><table class="table table-sm small mb-0 mt-2"><tr><?php foreach ($n['evidence_head'] as $h) { ?><th><?= li_e($h); ?></th><?php } ?></tr>
        <?php foreach ($n['evidence'] as $r) { ?><tr><?php foreach ($r as $c) { ?><td><?= li_e(is_float($c) ? li_n($c, 2) : $c); ?></td><?php } ?></tr><?php } ?></table></div>
    </details>
    <?php if ($n['proposal'] && li_can($conn, 'propose')) { ?>
    <form method="GET" action="proposal_edit.php" class="mt-2"><input type="hidden" name="from_need" value="<?= li_e($n['key']); ?>"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-plus-circle"></i> Create an investment proposal from this</button></form>
    <?php } ?>
</div></div>
<?php } ?>
<?php if (!$needs) { ?><div class="card border-0 shadow-sm"><div class="card-body text-muted">No financing need was detected in the records: stock, cash forecast, repayments, services, branches and equipment look covered. Borrowing is not needed now.</div></div><?php } ?>
<div class="small text-muted mt-2">How the stock need is calculated: daily demand = half the last 30 days + half the last 90 days; units to buy = demand for <?= FC_LEAD_DAYS; ?> days of supplier lead time + 30 days, minus stock; sales lost = demand x days without stock x selling price; profit = sales lost x margin (selling price - buying price). Cash gap: the RM Business Forecasting cash forecast (90 days).</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>