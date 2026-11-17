<?php
/**
 * NEW FILE: modules/loan_intel/proposal_edit.php — create / edit an INVESTMENT PROPOSAL (spec 5).
 * Managers (loan_intel.propose) submit; only Draft or Rejected proposals can be changed. Every change is audited.
 */
require __DIR__ . '/common.php';
li_require($conn, 'propose');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$p = $id ? li_proposal($conn, $id) : null;
if ($id && (!$p || !li_proposal_visible($conn, $p))) { li_deny(); }
if ($p && !in_array($p['status'], ['Draft', 'Rejected'], true)) { li_go('proposal.php?id=' . $id, 'error', 'Only Draft or Rejected proposals can be changed.'); }
$branches = li_branches($conn); $depts = li_departments($conn);
$products = mysqli_fetch_all(mysqli_query($conn, "SELECT id, product_name, item_type FROM products WHERE is_active = 1 ORDER BY product_name"), MYSQLI_ASSOC);
$fields = ['name', 'category', 'justification', 'branch_id', 'department_id', 'investment_cost', 'working_capital', 'impl_months', 'horizon_months', 'revenue', 'cost_of_sales_pct',
           'opex', 'ramp_months', 'risk_level', 'risks', 'assumptions', 'demand_evidence', 'evidence_level', 'product_id', 'measure'];

$v = $p ?: ['name' => '', 'category' => LI_CATEGORIES[0], 'justification' => '', 'branch_id' => br_enabled($conn) ? br_write_branch($conn) : null, 'department_id' => null,
    'investment_cost' => 0, 'working_capital' => 0, 'impl_months' => 1, 'horizon_months' => (int) li_num($conn, 'horizon'), 'revenue' => 0, 'cost_of_sales_pct' => 0, 'opex' => 0,
    'ramp_months' => 0, 'risk_level' => 'Medium', 'risks' => '', 'assumptions' => '', 'demand_evidence' => '', 'evidence_level' => 'assumption', 'product_id' => null, 'measure' => 'manual',
    'source' => 'manual', 'detected_key' => null];
if (!$p && ($k = (string) ($_GET['from_need'] ?? '')) !== '') {
    foreach (li_needs($conn, li_company($conn)) as $n) {
        if ($n['key'] === $k && $n['proposal']) { $v = $n['proposal'] + $v; $v['source'] = 'detected'; $v['detected_key'] = $k; $v['justification'] = $n['title'] . ': ' . $n['need']; }
    }
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!li_csrf_ok()) { $error = 'The page had expired. Please try again.'; }
    else {
        $in = [];
        foreach ($fields as $f) { $in[$f] = trim((string) ($_POST[$f] ?? '')); }
        foreach (['investment_cost', 'working_capital', 'revenue', 'cost_of_sales_pct', 'opex'] as $f) { $in[$f] = round(max(0, (float) str_replace(',', '', $in[$f])), 2); }
        foreach (['impl_months', 'horizon_months', 'ramp_months'] as $f) { $in[$f] = max(0, (int) $in[$f]); }
        $in['branch_id'] = isset($branches[(int) $in['branch_id']]) ? (int) $in['branch_id'] : null;
        $in['department_id'] = isset($depts[(int) $in['department_id']]) ? (int) $in['department_id'] : null;
        $in['product_id'] = (int) $in['product_id'] ?: null;
        if (!in_array($in['category'], LI_CATEGORIES, true)) { $in['category'] = 'Other'; }
        if (!in_array($in['risk_level'], ['Low', 'Medium', 'High'], true)) { $in['risk_level'] = 'Medium'; }
        if (!in_array($in['evidence_level'], ['data', 'partial', 'assumption'], true)) { $in['evidence_level'] = 'assumption'; }
        if (!in_array($in['measure'], ['manual', 'product'], true) || !$in['product_id']) { $in['measure'] = 'manual'; }
        if (mb_strlen($in['name']) < 3) { $error = 'Give the investment a name.'; }
        elseif ($in['investment_cost'] + $in['working_capital'] <= 0) { $error = 'Enter the investment cost and/or the working capital needed.'; }
        elseif ($in['horizon_months'] < 1 || $in['horizon_months'] > 120) { $error = 'The evaluation period must be between 1 and 120 months.'; }
        elseif ($in['cost_of_sales_pct'] > 100) { $error = 'Cost of sales cannot be more than 100% of revenue.'; }
        elseif (mb_strlen($in['justification']) < 10) { $error = 'Explain the business justification.'; }
        else {
            $in['horizon_months'] = max(1, $in['horizon_months']);
            $uid = (int) current_user_id();
            $tmap = ['branch_id' => 'i', 'department_id' => 'i', 'impl_months' => 'i', 'horizon_months' => 'i', 'ramp_months' => 'i', 'product_id' => 'i',
                     'investment_cost' => 'd', 'working_capital' => 'd', 'revenue' => 'd', 'cost_of_sales_pct' => 'd', 'opex' => 'd'];
            $types = implode('', array_map(function ($f) use ($tmap) { return $tmap[$f] ?? 's'; }, $fields));
            if ($p) {
                $sets = implode(', ', array_map(function ($f) { return "$f = ?"; }, $fields));
                $s = mysqli_prepare($conn, "UPDATE li_proposals SET $sets, status = 'Draft' WHERE id = ?");
                $vals = array_values($in); $vals[] = $id;
                mysqli_stmt_bind_param($s, $types . 'i', ...$vals);
                mysqli_stmt_execute($s);
                [$a, $b] = li_diff($p, $in);
                li_audit($conn, 'proposal_edit', 'proposal', $id, $a, $b, trim((string) ($_POST['reason'] ?? '')) ?: null);
            } else {
                $cols = implode(', ', $fields) . ', source, detected_key, created_by';
                $s = mysqli_prepare($conn, "INSERT INTO li_proposals ($cols) VALUES (" . implode(',', array_fill(0, count($fields) + 3, '?')) . ')');
                $src = ($_POST['source'] ?? '') === 'detected' ? 'detected' : 'manual'; $dk = mb_substr((string) ($_POST['detected_key'] ?? ''), 0, 60) ?: null;
                $vals = array_values($in); $vals[] = $src; $vals[] = $dk; $vals[] = $uid;
                mysqli_stmt_bind_param($s, $types . 'ssi', ...$vals);
                mysqli_stmt_execute($s);
                $id = (int) mysqli_insert_id($conn);
                mysqli_query($conn, "UPDATE li_proposals SET code = '" . sprintf('INV-%04d', $id) . "' WHERE id = $id");
                li_audit($conn, 'proposal_create', 'proposal', $id, null, $in);
            }
            if (!empty($_POST['submit_now'])) {
                mysqli_query($conn, "UPDATE li_proposals SET status = 'Submitted', submitted_at = NOW() WHERE id = $id");
                li_audit($conn, 'proposal_submit', 'proposal', $id, ['status' => $p['status'] ?? 'Draft'], ['status' => 'Submitted']);
                li_go('proposal.php?id=' . $id, 'success', 'Proposal submitted for management review.');
            }
            li_go('proposal.php?id=' . $id, 'success', 'Proposal saved as a draft.');
        }
    }
    $v = $_POST + $v;
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'proposals.php', $p ? 'Edit proposal ' . ($p['code'] ?: '') : 'New investment proposal');
$sel = function ($a, $b) { return (string) $a === (string) $b ? 'selected' : ''; };
?>
<?php if ($error) { ?><div class="alert alert-danger py-2"><?= li_e($error); ?></div><?php } ?>
<?php if (($v['source'] ?? '') === 'detected') { ?><div class="alert alert-info py-2 small">Pre-filled from a need MY MOTIVE detected in the records. Check every figure; complete what is missing (for example the extra expenses).</div><?php } ?>
<form method="POST" class="row g-3"><?= li_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="source" value="<?= li_e($v['source'] ?? 'manual'); ?>"><input type="hidden" name="detected_key" value="<?= li_e($v['detected_key'] ?? ''); ?>">
<div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body row g-2">
    <div class="li-sec">The investment</div>
    <div class="col-md-8"><label class="form-label small">Investment name</label><input name="name" class="form-control form-control-sm" value="<?= li_e($v['name']); ?>" required maxlength="150"></div>
    <div class="col-md-4"><label class="form-label small">Category</label><select name="category" class="form-select form-select-sm"><?php foreach (LI_CATEGORIES as $c) { ?><option <?= $sel($v['category'], $c); ?>><?= li_e($c); ?></option><?php } ?></select></div>
    <div class="col-12"><label class="form-label small">Business justification</label><textarea name="justification" class="form-control form-control-sm" rows="3" required><?= li_e($v['justification']); ?></textarea></div>
    <?php if ($branches) { ?><div class="col-md-6"><label class="form-label small">Proposed branch</label><select name="branch_id" class="form-select form-select-sm"><?php foreach ($branches as $bid => $bn) { ?><option value="<?= $bid; ?>" <?= $sel($v['branch_id'], $bid); ?>><?= li_e($bn); ?></option><?php } ?></select></div><?php } ?>
    <div class="col-md-6"><label class="form-label small">Department</label><select name="department_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($depts as $did => $dn) { ?><option value="<?= $did; ?>" <?= $sel($v['department_id'], $did); ?>><?= li_e($dn); ?></option><?php } ?></select></div>
    <div class="li-sec mt-2">Money needed</div>
    <div class="col-md-4"><label class="form-label small">Estimated investment cost (RWF)</label><input name="investment_cost" type="number" min="0" step="1" class="form-control form-control-sm li-in" value="<?= li_e((float) $v['investment_cost']); ?>"><div class="form-text">Equipment, setup, renovation…</div></div>
    <div class="col-md-4"><label class="form-label small">Required working capital (RWF)</label><input name="working_capital" type="number" min="0" step="1" class="form-control form-control-sm li-in" value="<?= li_e((float) $v['working_capital']); ?>"><div class="form-text">Stock, cash for operations (released at the end)</div></div>
    <div class="col-md-4"><label class="form-label small">Implementation period (months)</label><input name="impl_months" type="number" min="0" max="60" class="form-control form-control-sm li-in" value="<?= (int) $v['impl_months']; ?>"><div class="form-text">Months before it earns anything</div></div>
    <div class="li-sec mt-2">Expected results (per month, when fully running)</div>
    <div class="col-md-4"><label class="form-label small">Expected revenue (RWF / month)</label><input name="revenue" type="number" min="0" step="1" class="form-control form-control-sm li-in" value="<?= li_e((float) $v['revenue']); ?>"></div>
    <div class="col-md-4"><label class="form-label small">Cost of sales (% of revenue)</label><input name="cost_of_sales_pct" type="number" min="0" max="100" step="0.1" class="form-control form-control-sm li-in" value="<?= li_e((float) $v['cost_of_sales_pct']); ?>"><div class="form-text">Goods bought for resale, materials</div></div>
    <div class="col-md-4"><label class="form-label small">Extra operating expenses (RWF / month)</label><input name="opex" type="number" min="0" step="1" class="form-control form-control-sm li-in" value="<?= li_e((float) $v['opex']); ?>"><div class="form-text">Staff, rent, fuel, maintenance…</div></div>
    <div class="col-md-4"><label class="form-label small">Months to reach full revenue</label><input name="ramp_months" type="number" min="0" max="36" class="form-control form-control-sm li-in" value="<?= (int) $v['ramp_months']; ?>"><div class="form-text">0 = full revenue from the start</div></div>
    <div class="col-md-4"><label class="form-label small">Evaluation period (months)</label><input name="horizon_months" type="number" min="1" max="120" class="form-control form-control-sm li-in" value="<?= (int) $v['horizon_months']; ?>"></div>
    <div class="col-md-4"><label class="form-label small">Risk level</label><select name="risk_level" class="form-select form-select-sm li-in"><?php foreach (['Low', 'Medium', 'High'] as $r) { ?><option <?= $sel($v['risk_level'], $r); ?>><?= $r; ?></option><?php } ?></select></div>
    <div class="li-sec mt-2">Evidence, risks and assumptions</div>
    <div class="col-md-6"><label class="form-label small">Where do the figures come from?</label><select name="evidence_level" class="form-select form-select-sm li-in">
        <option value="data" <?= $sel($v['evidence_level'], 'data'); ?>>MY MOTIVE records (sales, stock, costs)</option><option value="partial" <?= $sel($v['evidence_level'], 'partial'); ?>>Partly records, partly quotes / estimates</option>
        <option value="assumption" <?= $sel($v['evidence_level'], 'assumption'); ?>>Management assumptions</option></select></div>
    <div class="col-md-6"><label class="form-label small">Measure results automatically from a product / service (optional)</label><select name="product_id" class="form-select form-select-sm"><option value="">— enter results by hand —</option>
        <?php foreach ($products as $pr) { ?><option value="<?= (int) $pr['id']; ?>" <?= $sel($v['product_id'], $pr['id']); ?>><?= li_e($pr['product_name'] . ' (' . $pr['item_type'] . ')'); ?></option><?php } ?></select><input type="hidden" name="measure" value="product"></div>
    <div class="col-12"><label class="form-label small">Demand evidence</label><textarea name="demand_evidence" class="form-control form-control-sm" rows="2"><?= li_e($v['demand_evidence']); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small">Risks</label><textarea name="risks" class="form-control form-control-sm" rows="3"><?= li_e($v['risks']); ?></textarea></div>
    <div class="col-md-6"><label class="form-label small">Assumptions</label><textarea name="assumptions" class="form-control form-control-sm" rows="3"><?= li_e($v['assumptions']); ?></textarea></div>
    <?php if ($p) { ?><div class="col-12"><label class="form-label small">Reason for the change (audit trail)</label><input name="reason" class="form-control form-control-sm"></div><?php } ?>
    <div class="col-12 d-flex gap-2"><button class="btn btn-sm btn-outline-primary">Save draft</button><button name="submit_now" value="1" class="btn btn-sm btn-primary">Save and submit for review</button><a href="proposals.php" class="btn btn-sm btn-light border">Cancel</a></div>
</div></div></div>
<div class="col-lg-5"><div class="card border-0 shadow-sm position-sticky" style="top:80px;"><div class="card-body">
    <h6>Live calculation <?= li_tag('Estimated'); ?></h6>
    <table class="table table-sm small mb-2" id="liLive"></table>
    <div class="small text-muted">Gross profit = revenue x (1 - cost of sales %). Net operating profit = gross profit - extra expenses. ROI = (operating profit over the period + working capital released - total cost) / total cost. Payback = months until the money put in is recovered. NPV at <?= li_e(li_setting($conn, 'discount_rate')); ?>% a year. The server recalculates everything when you save.</div>
</div></div></div>
</form>
<script>
(function () {
    var disc = <?= json_encode(li_num($conn, 'discount_rate')); ?>;
    function val(n) { var e = document.querySelector('[name="' + n + '"]'); return e ? parseFloat(e.value || '0') || 0 : 0; }
    function f(v) { return 'RWF ' + Math.round(v).toLocaleString(); }
    function calc() {
        var cost = val('investment_cost'), wc = val('working_capital'), impl = Math.round(val('impl_months')), h = Math.max(1, Math.round(val('horizon_months')));
        var rev = val('revenue'), cos = Math.min(100, val('cost_of_sales_pct')) / 100, opex = val('opex'), ramp = Math.round(val('ramp_months'));
        var total = cost + wc, flows = [-total], np = 0, mr = Math.pow(1 + disc / 100, 1 / 12) - 1, npv = -total;
        for (var m = 1; m <= h; m++) { var run = m - impl, k = run <= 0 ? 0 : (ramp > 0 ? Math.min(1, run / ramp) : 1); var n = rev * k * (1 - cos) - (run > 0 ? opex : 0); np += n; var cf = n + (m === h ? wc : 0); flows.push(cf); npv += cf / Math.pow(1 + mr, m); }
        var cum = 0, pb = null; for (var t = 0; t < flows.length; t++) { var prev = cum; cum += flows[t]; if (t > 0 && cum >= 0 && prev < 0) { pb = (t - 1 + (-prev) / flows[t]).toFixed(1); break; } }
        var roi = total > 0 ? ((np + wc - total) / total * 100).toFixed(1) + '%' : '—';
        var rows = [['Total investment (cost + working capital)', f(total)], ['Expected gross profit / month', f(rev * (1 - cos))], ['Expected net operating profit / month', f(rev * (1 - cos) - opex)],
            ['Expected monthly cash flow', f(rev * (1 - cos) - opex)], ['Operating profit over ' + h + ' months', f(np)], ['Expected ROI', roi], ['Payback period', pb === null ? 'not within ' + h + ' months' : pb + ' months'], ['NPV', f(npv)]];
        document.getElementById('liLive').innerHTML = rows.map(function (r) { return '<tr><td>' + r[0] + '</td><td class="text-end fw-semibold">' + r[1] + '</td></tr>'; }).join('');
    }
    document.querySelectorAll('.li-in').forEach(function (e) { e.addEventListener('input', calc); e.addEventListener('change', calc); });
    calc();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>