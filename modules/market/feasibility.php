<?php
/**
 * NEW FILE: modules/market/feasibility.php — FEASIBILITY, PROFITABILITY and the RECOMMENDATION of one opportunity.
 * Products: investment = unit cost × quantity + transport + other costs; landed unit cost; unit gross profit; margin;
 * expected revenue and gross profit; Capital Fund coverage and funding gap; stock risk; suggested quantity.
 * Services: price, direct cost (materials, labour, external provider), volume, capacity, investment (equipment,
 * software, training), staffing; the five assessments (demand, capability, financial, operational, risk).
 * Every figure is an ESTIMATE until the product / service is launched; gross profit is never net profit.
 */
require __DIR__ . '/common.php';
if (!mi_can_evaluate($conn)) { mi_require($conn, 'research'); }
$o = mi_load_opp($conn, 'feasibility.php');
$id = (int) $o['id']; $self = 'feasibility.php?id=' . $id; $svc = $o['kind'] === 'service';
$canEdit = mic($conn, 'research') || mic($conn, 'finance'); $seeFunds = mic($conn, 'finance');
$P = $svc ? ['service_price', 'materials_cost', 'labour_hours', 'labour_rate', 'external_cost', 'expected_customers', 'expected_volume', 'capacity_per_month', 'equipment_cost', 'software_cost', 'training_cost', 'recruitment_cost_month']
          : ['proposed_qty', 'unit_cost', 'transport', 'other_costs', 'selling_price', 'target_margin', 'expected_sales_qty', 'sales_period_days', 'shelf_life_days'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mi_csrf_ok()) { mi_go($self, 'error', 'The page had expired.'); }
    if (!$canEdit) { mi_go($self, 'error', 'You may not change the evaluation.'); }
    $old = mi_eval_get($conn, $id); $new = []; $chg = [];
    foreach ($P as $k) { $v = trim((string) ($_POST[$k] ?? '')); if ($v !== '' && (!is_numeric($v) || (float) $v < 0)) { mi_go($self, 'error', 'Numbers only (' . $k . ').'); } $new[$k] = $v === '' ? '' : (string) (float) $v; }
    if (!$svc) { $new['quote_id'] = (string) (int) ($_POST['quote_id'] ?? 0); $new['storage_notes'] = mb_substr(trim((string) ($_POST['storage_notes'] ?? '')), 0, 255); }
    else { $new['delivery_mode'] = in_array($_POST['delivery_mode'] ?? '', ['internal', 'train', 'recruit', 'outsource'], true) ? $_POST['delivery_mode'] : 'internal'; }
    $new['notes'] = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 2000);
    foreach ($new as $k => $v) { if ((string) ($old[$k] ?? '') !== (string) $v) { $chg[] = $k . ': ' . (($old[$k] ?? '') === '' ? '—' : $old[$k]) . ' → ' . ($v === '' ? '—' : $v); } }
    mi_eval_save($conn, $id, $new);
    if ($chg) { mi_audit($conn, $id, null, 'evaluation', 'Feasibility inputs: ' . implode('; ', $chg)); }
    if (in_array($o['status'], ['New'], true)) { mysqli_query($conn, "UPDATE mi_opportunities SET status = 'Under Review' WHERE id = $id"); }
    mi_go($self, 'success', 'Saved. The figures and the recommendation are updated.');
}
$prod = $o['product_id'] ? (mi_products($conn, [(int) $o['product_id']])[(int) $o['product_id']] ?? null) : null;
$R = $svc ? mi_service_feasibility($conn, $o, $prod) : mi_product_feasibility($conn, $o, $prod);
$e = $R['e'];
$v = function ($k) use ($e) { return mi_e($e[$k] ?? ''); };
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'opportunities.php', $o['name']);
mi_opp_nav($conn, $o, 'feasibility.php');
$row = function ($l, $val, $note = '', $strong = false) { return '<div class="d-flex justify-content-between gap-2 border-bottom py-1"><span>' . $l . ($note ? ' <span class="text-muted">' . $note . '</span>' : '') . '</span><span class="text-end text-nowrap ' . ($strong ? 'fw-bold' : '') . '">' . $val . '</span></div>'; };
$m = function ($x) { return $x === null ? '<span class="text-muted">—</span>' : mi_money($x); };
?>
<div class="row g-3">
<div class="col-xl-7">
    <div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #1E2FE0 !important"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2"><div><div class="small text-muted">MY MOTIVE recommendation</div><div class="fs-5"><?= mi_rec_badge($R['rec']); ?></div></div>
            <div class="text-end small"><?= mi_level_badge($R['level']); ?> <span class="badge bg-<?= ['High' => 'danger', 'Medium' => 'warning text-dark', 'Low' => 'success'][$R['risk']]; ?>">Risk: <?= $R['risk']; ?></span></div></div>
        <div class="small fw-semibold mt-2">Why</div><ul class="small mb-0 ps-3"><?php foreach ($R['why'] as $w) { ?><li><?= mi_e($w); ?></li><?php } ?></ul>
        <?php if (!$svc && $R['risk_why']) { ?><div class="small text-muted mt-1">Stock risk: <?= mi_e(implode('; ', $R['risk_why'])); ?>.</div><?php } ?>
        <div class="small mt-2"><a href="decision.php?id=<?= $id; ?>">Go to the Decision page →</a></div>
    </div></div>
    <?php if (!$svc) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <div class="fw-semibold mb-1">A. Estimated investment <span class="badge bg-light text-dark border">estimate</span></div>
        <?= $row('Unit purchase cost', $m($R['unit_cost']), mi_e($R['cost_source']) . ($R['estimated_cost'] ? ' — <span class="text-danger">not a confirmed cost</span>' : '')); ?>
        <?= $row('Quantity', mi_num($R['qty']) . ' ' . mi_e($o['unit']), mi_e($R['qty_source'])); ?>
        <?= $row('Transport', $m($R['transport'])); ?><?= $row('Other acquisition costs', $m($R['other'])); ?>
        <?= $row('Total investment', $m($R['investment']), '= unit cost × quantity + transport + other', true); ?>
        <?= $row('Landed cost per unit', $m($R['landed'])); ?>
        <div class="text-muted mt-1">Suggested initial quantity: <strong><?= mi_num($R['suggested_qty']); ?></strong> — <?= mi_e($R['sugg_why']); ?></div>
        <div class="fw-semibold mt-3 mb-1">B–C. Price and expected profitability</div>
        <?= $row('Expected selling price', $m($R['price']), mi_e($R['price_source'])); ?>
        <?php if ($R['price_suggestions']) { ?><div class="text-muted py-1">Suggestions: <?= mi_e(implode(' · ', array_map(function ($k, $x) { return $k . ' ' . mi_money(round($x)); }, array_keys($R['price_suggestions']), $R['price_suggestions']))); ?></div><?php } ?>
        <?= $row('Unit gross profit', $m($R['unit_gp']), '= price − landed unit cost'); ?>
        <?= $row('Gross margin', $R['margin'] === null ? '—' : mi_num(round($R['margin'], 1)) . '%'); ?>
        <?= $row('Expected sales quantity', mi_num($R['sales_qty'])); ?>
        <?= $row('Expected revenue', $m($R['revenue'])); ?>
        <?= $row('Expected gross profit', $m($R['gross_profit']), 'before operating expenses — not net profit', true); ?>
        <div class="fw-semibold mt-3 mb-1">D. Financial capacity</div>
        <?php if ($seeFunds) { ?>
            <?= $row('Required investment', $m($R['investment'])); ?>
            <?= $row(mi_e($R['capital']['name']) . ' available', $m($R['capital']['fund']), 'after pending expenses and commitments'); ?>
            <?= $row('Reserved for other approved opportunities', $m($R['capital']['reserved'])); ?>
            <?= $row('Available working capital', $m($R['capital']['available']), '', true); ?>
            <?= $row('Funding gap', $R['gap'] === null ? '—' : ($R['gap'] > 0 ? '<span class="text-danger">' . mi_money($R['gap']) . '</span>' : 'None')); ?>
        <?php } ?>
        <?= $row('Investment covered by available funds', $R['coverage'] === null ? '—' : mi_num(round($R['coverage'])) . '%', mi_e($R['feasible'])); ?>
        <div class="text-muted mt-1">Only the Capital Fund is counted: other funds are not available for stock.</div>
    </div></div>
    <?php } else { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <div class="fw-semibold mb-1">Assessment <span class="badge bg-light text-dark border">estimates</span></div>
        <?php foreach ($R['assess'] as $k => [$lab, $col, $note]) { ?><div class="d-flex justify-content-between gap-2 border-bottom py-1"><span><?= mi_e($k); ?><div class="text-muted"><?= mi_e($note); ?></div></span><span><span class="badge bg-<?= $col; ?> <?= $col === 'warning' ? 'text-dark' : ''; ?>"><?= mi_e($lab); ?></span></span></div><?php } ?>
        <div class="fw-semibold mt-3 mb-1">Figures</div>
        <?= $row('Expected service price', $m($R['price'])); ?><?= $row('Direct cost per service', $m($R['direct']), 'materials + labour' . (($e['delivery_mode'] ?? '') === 'outsource' ? ' + external provider' : '')); ?>
        <?= $row('Gross profit per service', $m($R['unit_gp']), $R['margin'] !== null ? mi_num(round($R['margin'], 1)) . '%' : ''); ?>
        <?= $row('Expected services per month', mi_num($R['volume'])); ?><?= $row('Expected revenue per month', $m($R['revenue_m'])); ?>
        <?php if ($R['staff']) { ?><?= $row('Staffing cost per month (recruitment)', $m($R['staff'])); ?><?php } ?>
        <?= $row('Expected gross profit per month', $m($R['gp_m']), 'before operating expenses — not net profit', true); ?>
        <?= $row('Upfront investment', $m($R['investment']), 'equipment + software' . (($e['delivery_mode'] ?? '') === 'train' ? ' + training' : '')); ?>
        <?php if ($seeFunds) { ?><?= $row('Available working capital', $m($R['capital']['available'])); ?><?php } ?>
    </div></div>
    <?php } ?>
</div>
<div class="col-xl-5">
    <?php if ($canEdit) { ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body small row g-2"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>">
        <div class="col-12 fw-semibold">Inputs <span class="fw-normal text-muted">(empty = use MY MOTIVE's figure)</span></div>
        <?php if (!$svc) { $qs = $R['compare']['rows']; ?>
        <div class="col-12"><label class="form-label">Supplier quotation</label><select name="quote_id" class="form-select form-select-sm"><option value="0">Suggested (best overall cost)</option><?php foreach ($qs as $q) { if ($q['expired']) { continue; } ?><option value="<?= (int) $q['id']; ?>" <?= (int) ($e['quote_id'] ?? 0) === (int) $q['id'] ? 'selected' : ''; ?>><?= mi_e($q['supplier_name']); ?> — <?= mi_money($q['unit_price']); ?></option><?php } ?></select>
            <?php if (!$qs) { ?><div class="text-muted">No quotation: <a href="quotes.php?id=<?= $id; ?>">add one</a> or enter an estimated cost.</div><?php } ?></div>
        <div class="col-6"><label class="form-label">Estimated unit cost <span class="text-muted">(no quotation)</span></label><input type="number" step="any" min="0" name="unit_cost" class="form-control form-control-sm" value="<?= $v('unit_cost'); ?>"></div>
        <div class="col-6"><label class="form-label">Proposed quantity</label><input type="number" step="any" min="0" name="proposed_qty" class="form-control form-control-sm" value="<?= $v('proposed_qty'); ?>" placeholder="<?= mi_e(mi_num($R['suggested_qty'])); ?>"></div>
        <div class="col-6"><label class="form-label">Transport</label><input type="number" step="any" min="0" name="transport" class="form-control form-control-sm" value="<?= $v('transport'); ?>" placeholder="from quotation"></div>
        <div class="col-6"><label class="form-label">Other acquisition costs</label><input type="number" step="any" min="0" name="other_costs" class="form-control form-control-sm" value="<?= $v('other_costs'); ?>" placeholder="from quotation"></div>
        <div class="col-6"><label class="form-label">Expected selling price</label><input type="number" step="any" min="0" name="selling_price" class="form-control form-control-sm" value="<?= $v('selling_price'); ?>"></div>
        <div class="col-6"><label class="form-label">Target margin %</label><input type="number" step="any" min="0" max="95" name="target_margin" class="form-control form-control-sm" value="<?= $v('target_margin'); ?>" placeholder="<?= mi_e(mi_num(mi_eset($conn, 'target_margin'))); ?>"></div>
        <div class="col-6"><label class="form-label">Expected sales quantity</label><input type="number" step="any" min="0" name="expected_sales_qty" class="form-control form-control-sm" value="<?= $v('expected_sales_qty'); ?>" placeholder="= quantity"></div>
        <div class="col-6"><label class="form-label">Sales period (days)</label><input type="number" step="1" min="1" name="sales_period_days" class="form-control form-control-sm" value="<?= $v('sales_period_days'); ?>" placeholder="<?= (int) mi_eset($conn, 'sales_period_days'); ?>"></div>
        <div class="col-6"><label class="form-label">Shelf life (days)</label><input type="number" step="1" min="0" name="shelf_life_days" class="form-control form-control-sm" value="<?= $v('shelf_life_days'); ?>" placeholder="if it expires"></div>
        <div class="col-6"><label class="form-label">Storage requirements</label><input name="storage_notes" maxlength="255" class="form-control form-control-sm" value="<?= $v('storage_notes'); ?>"></div>
        <?php } else { ?>
        <div class="col-6"><label class="form-label">Expected service price</label><input type="number" step="any" min="0" name="service_price" class="form-control form-control-sm" value="<?= $v('service_price'); ?>"></div>
        <div class="col-6"><label class="form-label">How would we deliver it?</label><select name="delivery_mode" class="form-select form-select-sm"><?php foreach (['internal' => 'Existing employees', 'train' => 'After training employees', 'recruit' => 'Recruit an employee', 'outsource' => 'External provider / partner'] as $k => $l) { ?><option value="<?= $k; ?>" <?= ($e['delivery_mode'] ?? 'internal') === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
        <div class="col-6"><label class="form-label">Direct materials per service</label><input type="number" step="any" min="0" name="materials_cost" class="form-control form-control-sm" value="<?= $v('materials_cost'); ?>"></div>
        <div class="col-3"><label class="form-label">Labour hours</label><input type="number" step="any" min="0" name="labour_hours" class="form-control form-control-sm" value="<?= $v('labour_hours'); ?>"></div>
        <div class="col-3"><label class="form-label">Rate / hour</label><input type="number" step="any" min="0" name="labour_rate" class="form-control form-control-sm" value="<?= $v('labour_rate'); ?>"></div>
        <div class="col-6"><label class="form-label">External provider cost / service</label><input type="number" step="any" min="0" name="external_cost" class="form-control form-control-sm" value="<?= $v('external_cost'); ?>"></div>
        <div class="col-6"><label class="form-label">Expected customers</label><input type="number" step="1" min="0" name="expected_customers" class="form-control form-control-sm" value="<?= $v('expected_customers'); ?>"></div>
        <div class="col-6"><label class="form-label">Expected services / month</label><input type="number" step="any" min="0" name="expected_volume" class="form-control form-control-sm" value="<?= $v('expected_volume'); ?>"></div>
        <div class="col-6"><label class="form-label">Delivery capacity / month</label><input type="number" step="any" min="0" name="capacity_per_month" class="form-control form-control-sm" value="<?= $v('capacity_per_month'); ?>"></div>
        <div class="col-6"><label class="form-label">Equipment cost</label><input type="number" step="any" min="0" name="equipment_cost" class="form-control form-control-sm" value="<?= $v('equipment_cost'); ?>"></div>
        <div class="col-6"><label class="form-label">Software / licence cost</label><input type="number" step="any" min="0" name="software_cost" class="form-control form-control-sm" value="<?= $v('software_cost'); ?>"></div>
        <div class="col-6"><label class="form-label">Training cost</label><input type="number" step="any" min="0" name="training_cost" class="form-control form-control-sm" value="<?= $v('training_cost'); ?>"></div>
        <div class="col-6"><label class="form-label">Recruitment: salary / month</label><input type="number" step="any" min="0" name="recruitment_cost_month" class="form-control form-control-sm" value="<?= $v('recruitment_cost_month'); ?>"></div>
        <?php } ?>
        <div class="col-12"><label class="form-label">Notes / supporting information</label><textarea name="notes" rows="2" maxlength="2000" class="form-control form-control-sm"><?= $v('notes'); ?></textarea></div>
        <div class="col-12 d-flex justify-content-between align-items-center"><button class="btn btn-primary btn-sm px-4">Save and recalculate</button><?php if (!empty($e['_updated_at'])) { ?><span class="text-muted">Updated <?= mi_e(substr($e['_updated_at'], 0, 16)); ?></span><?php } ?></div>
    </div></form>
    <?php } ?>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>