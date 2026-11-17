<?php
/**
 * NEW FILE: modules/loan_intel/settings.php — configurable FINANCIAL RISK THRESHOLDS, ranking weights and stress scenarios
 * (spec 6 "Configurable Risk Thresholds"). Recommended defaults are shown; every change is audited with its reason.
 */
require __DIR__ . '/common.php';
li_require($conn, 'configure');
$num = [
    'min_dscr' => ['Minimum DSCR, base case', 'Repayments must be covered this many times by the cash flow in every loan year. Banks commonly ask 1.20-1.50.', 0.5, 5, 0.05],
    'min_dscr_stress' => ['Minimum DSCR, pessimistic case', 'In the bad case, repayments must still be covered at least this many times (1.00 = just covered).', 0.5, 5, 0.05],
    'max_debt_share' => ['Maximum debt-to-cash-flow (%)', 'Share of the monthly cash flow that repayments may take.', 5, 100, 1],
    'reserve_months' => ['Cash reserve (months of operating costs)', 'Cash kept aside and never counted as available for investment or repayments.', 0, 12, 0.5],
    'discount_rate' => ['Discount rate for NPV (% a year)', 'Usually the cost of borrowing or the return the owners expect.', 0, 60, 0.5],
    'min_roi' => ['Minimum ROI over the evaluation period (%)', 'Below this, borrowing for the investment is not recommended.', 0, 500, 1],
    'max_payback' => ['Maximum payback period (months)', 'Used in the ranking.', 3, 120, 1],
    'min_history' => ['Minimum months of sales history', 'Below this, recommendations say MORE DATA REQUIRED.', 1, 36, 1],
    'history_window' => ['Months of history read', 'Monthly Profit & Loss figures used for the analysis.', 3, 36, 1],
    'min_margin' => ['Healthy product margin (%)', 'Only products at or above this gross margin are proposed for stock financing.', 0, 90, 1],
    'horizon' => ['Default evaluation period (months)', 'Used for new proposals.', 6, 120, 1],
];
$weightsL = ['roi' => 'ROI', 'payback' => 'Payback', 'cash_flow' => 'Cash flow impact', 'risk' => 'Risk level', 'evidence' => 'Demand evidence', 'confidence' => 'Data confidence', 'npv' => 'Positive NPV'];
$scenF = ['revenue_pct' => 'Revenue change %', 'opex_pct' => 'Operating expenses change %', 'delay_months' => 'Investment delay (months)', 'slower_pct' => 'Stock sells slower by %', 'rate_up' => 'Variable rate increase (points)', 'shock' => 'Unexpected obligation in month 3 (RWF)'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!li_csrf_ok()) { li_go('settings.php', 'error', 'The page had expired.'); }
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if (mb_strlen($reason) < 3) { li_go('settings.php', 'error', 'Write the reason for the change (company policy, lender requirement…).'); }
    $before = []; $after = [];
    foreach ($num as $k => [$l, $h, $min, $max]) {
        $v = (string) max($min, min($max, (float) ($_POST[$k] ?? li_setting($conn, $k))));
        if ($v !== li_setting($conn, $k)) { $before[$k] = li_setting($conn, $k); $after[$k] = $v; li_setting_set($conn, $k, $v); }
    }
    $funds = array_values(array_intersect((array) ($_POST['funds'] ?? []), array_column(funds_all($conn), 'code')));
    $fv = implode(',', $funds);
    if ($fv !== li_setting($conn, 'investable_funds')) { $before['investable_funds'] = li_setting($conn, 'investable_funds'); $after['investable_funds'] = $fv; li_setting_set($conn, 'investable_funds', $fv ?: 'NONE'); }
    $w = []; foreach ($weightsL as $k => $l) { $w[$k] = max(0, min(100, (float) ($_POST['w'][$k] ?? 0))); }
    if (array_sum($w) <= 0) { li_go('settings.php', 'error', 'At least one ranking weight must be above 0.'); }
    $wj = json_encode($w); if ($wj !== json_encode(li_weights($conn))) { $before['weights'] = li_weights($conn); $after['weights'] = $w; li_setting_set($conn, 'weights', $wj); }
    $sc = []; foreach (li_default_scenarios() as $k => $d) { foreach ($scenF as $f => $l) { $sc[$k][$f] = (float) ($_POST['sc'][$k][$f] ?? $d[$f]); } }
    if ($sc['pessimistic']['revenue_pct'] > $sc['base']['revenue_pct'] || $sc['optimistic']['revenue_pct'] < $sc['base']['revenue_pct']) { li_go('settings.php', 'error', 'The pessimistic case must not be better than the base case, nor the optimistic one worse.'); }
    $sj = json_encode($sc); if ($sj !== li_setting($conn, 'scenarios')) { $before['scenarios'] = li_setting($conn, 'scenarios'); $after['scenarios'] = $sc; li_setting_set($conn, 'scenarios', $sj); }
    if ($after) { li_audit($conn, 'settings', 'settings', null, $before, $after, $reason); li_cache_clear($conn); }
    li_go('settings.php', 'success', $after ? 'Settings saved (' . count($after) . ' change(s)).' : 'Nothing changed.');
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'settings.php', 'Thresholds & Settings');
$inv = explode(',', li_setting($conn, 'investable_funds')); $w = li_weights($conn); $sc = li_scenarios($conn);
?>
<form method="POST" class="row g-3"><?= li_csrf_field(); ?>
<div class="col-xl-6"><div class="card border-0 shadow-sm"><div class="card-body">
    <h6>Financial risk thresholds</h6>
    <?php foreach ($num as $k => [$l, $h, $min, $max, $step]) { ?><div class="row g-2 align-items-center border-top py-2"><div class="col-7"><div class="small fw-semibold"><?= li_e($l); ?></div><div class="small text-muted"><?= li_e($h); ?> Recommended: <?= li_e(LI_DEFAULTS[$k]); ?>.</div></div>
        <div class="col-5"><input name="<?= $k; ?>" type="number" min="<?= $min; ?>" max="<?= $max; ?>" step="<?= $step; ?>" value="<?= li_e(li_setting($conn, $k)); ?>" class="form-control form-control-sm"></div></div><?php } ?>
    <div class="border-top pt-2"><div class="small fw-semibold">Funds that may finance investments (internal funds)</div>
        <?php foreach (funds_all($conn) as $f) { ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="funds[]" value="<?= li_e($f['code']); ?>" id="f<?= li_e($f['code']); ?>" <?= in_array($f['code'], $inv, true) ? 'checked' : ''; ?>><label class="form-check-label small" for="f<?= li_e($f['code']); ?>"><?= li_e($f['name']); ?></label></div><?php } ?>
        <div class="small text-muted">Recommended: RM Capital Fund and Future Plans. The Emergency and Operating funds are normally kept for their own purpose.</div></div>
</div></div></div>
<div class="col-xl-6">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Ranking weights of investment opportunities</h6>
        <div class="row g-2"><?php foreach ($weightsL as $k => $l) { ?><div class="col-6 col-md-4"><label class="form-label small mb-0"><?= $l; ?></label><input name="w[<?= $k; ?>]" type="number" min="0" max="100" value="<?= (float) ($w[$k] ?? 0); ?>" class="form-control form-control-sm"></div><?php } ?></div>
        <div class="small text-muted mt-1">Relative importance (they do not need to add up to 100). Revenue alone is never a criterion.</div></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Stress-test scenarios</h6>
        <table class="table table-sm small mb-0"><tr><th></th><?php foreach ($sc as $k => $s) { ?><th><?= li_e($s['label']); ?></th><?php } ?></tr>
        <?php foreach ($scenF as $f => $l) { ?><tr><td><?= $l; ?></td><?php foreach ($sc as $k => $s) { ?><td><input name="sc[<?= $k; ?>][<?= $f; ?>]" type="number" step="any" value="<?= (float) $s[$f]; ?>" class="form-control form-control-sm"></td><?php } ?></tr><?php } ?></table></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body"><label class="form-label small fw-semibold">Reason for the change (audit trail)</label><input name="reason" class="form-control form-control-sm mb-2" required placeholder="e.g. new bank policy: DSCR 1.35">
        <button class="btn btn-sm btn-primary">Save settings</button></div></div>
</div>
</form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>