<?php
// NEW FILE: portal/invest_project.php — full details of one opportunity (also the staff "Preview" of a draft).
require __DIR__ . '/invest_start.php';
$id = (int) ($_GET['id'] ?? 0);
$preview = false; // drafts are never shown to customers (staff preview: modules/rm_invest/preview.php)
$p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_projects WHERE id = $id"));
if (!$p || (!$preview && !in_array($p['status'], ['Published', 'Paused', 'Funding Closed', 'Active', 'Completed'], true))) { header('Location: invest.php'); exit; }
$f = inv_funding($conn, $id); $pr = inv_projection($p);
$files = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM inv_project_files WHERE project_id = $id ORDER BY kind, id"), MYSQLI_ASSOC);
$open = $p['status'] === 'Published' && (!$p['funding_deadline'] || $p['funding_deadline'] >= date('Y-m-d'));
$sec = function ($t, $v) { if (trim((string) $v) !== '') { echo '<h3 class="h6 mt-3">' . $t . '</h3><div style="white-space:pre-wrap;">' . portal_e($v) . '</div>'; } };
portal_header($p['name'], $me);
?>
<?php if ($preview) { ?><div class="alert alert-secondary small">PREVIEW (status: <?= portal_e($p['status']); ?>): this is how customers will see the project.</div><?php } ?>
<a href="invest.php" class="small">&larr; RM Invest</a>
<h1 class="h4 mt-1"><?= portal_e($p['name']); ?> <span class="small text-muted"><?= inv_project_ref($p); ?></span></h1>
<div class="small text-muted mb-3"><?= portal_e($p['category']); ?> · <?= portal_e($p['location']); ?> · risk <strong><?= portal_e($p['risk_level']); ?></strong></div>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="card-body small">
    <?php foreach ($files as $fl) { if ($fl['kind'] === 'photo') { ?><img src="invest_file.php?id=<?= (int) $fl['id']; ?>" alt="" class="me-2 mb-2 rounded" style="height:120px;"><?php } } ?>
    <?php $sec('About the project', $p['description']); $sec('Objectives', $p['objectives']); $sec('Why the capital is required', $p['why_capital']); $sec('How the funds will be used', $p['use_of_funds']); ?>
    <h3 class="h6 mt-3">Projected financial performance <span class="badge bg-warning">estimates</span></h3>
    <table class="table table-sm"><tr><td>Expected revenue</td><td class="text-end"><?= cl_money($p['expected_revenue']); ?></td></tr><tr><td>Expected direct costs</td><td class="text-end">− <?= cl_money($p['expected_direct']); ?></td></tr>
        <tr><td>Other expected expenses</td><td class="text-end">− <?= cl_money($p['expected_other']); ?></td></tr><tr class="fw-semibold"><td>Expected net project profit</td><td class="text-end"><?= cl_money($pr['net']); ?></td></tr>
        <tr><td>Management cost (<?= (float) $p['mgmt_fee_pct']; ?>% of the realized profit)</td><td class="text-end">− <?= cl_money($pr['fee']); ?></td></tr><tr class="fw-semibold"><td>Expected profit for investors (<?= 100 - (float) $p['mgmt_fee_pct']; ?>%)</td><td class="text-end"><?= cl_money($pr['distributable']); ?></td></tr></table>
    <div class="p-2 rounded" style="background:#EEF3FF;"><strong>Profit-sharing:</strong> your profit = (your verified contribution ÷ total verified capital) × the profit available to investors.
        Example: investing <?= cl_money(max((float) $p['min_invest'], 100000)); ?> in this project would earn about <?= (float) $p['capital_required'] > 0 ? cl_money(max((float) $p['min_invest'], 100000) / (float) $p['capital_required'] * $pr['distributable']) : '—'; ?> <strong>if</strong> the projections are achieved, plus your capital back.</div>
    <?= inv_disclaimer(); ?>
    <h3 class="h6 mt-3">Risks</h3>
    <?php foreach (['risk_business' => 'Business risks', 'risk_market' => 'Market risks', 'risk_delays' => 'Possible delays', 'risk_losses' => 'Potential losses', 'risk_other' => 'Other risks'] as $k => $l) { if ($p[$k]) { ?><div class="mb-1"><strong><?= $l; ?>:</strong> <?= portal_e($p[$k]); ?></div><?php } } ?>
    <?php $sec('Capital recovery', $p['capital_recovery']); $sec('Profit distribution conditions', $p['distribution_conditions']); $sec('Cancellation conditions', $p['cancellation_conditions']); ?>
    <?php $docs = array_filter($files, function ($x) { return $x['kind'] !== 'photo'; }); if ($docs) { ?><h3 class="h6 mt-3">Documents</h3><?php foreach ($docs as $d) { ?><div><a href="invest_file.php?id=<?= (int) $d['id']; ?>" target="_blank"><?= portal_e($d['original_name']); ?></a></div><?php } } ?>
    <div class="mt-2"><a href="invest_agreement.php?project=<?= $id; ?>" target="_blank">Read the investment agreement</a></div>
</div></div></div>
<div class="col-lg-4"><div class="card" style="position:sticky; top:12px;"><div class="card-body small">
    <div style="height:10px; background:#EEF1F7; border-radius:5px;"><div style="width:<?= $f['pct']; ?>%; height:10px; background:#0FA968; border-radius:5px;"></div></div>
    <div class="fs-5 fw-bold mt-2"><?= cl_money($f['raised']); ?></div><div class="text-muted">raised of <?= cl_money($p['capital_required']); ?> · <?= cl_money($f['remaining']); ?> remaining</div>
    <table class="table table-sm mt-2"><tr><td>Minimum</td><td class="text-end"><?= cl_money($p['min_invest']); ?></td></tr><tr><td>Maximum</td><td class="text-end"><?= (float) $p['max_invest'] > 0 ? cl_money($p['max_invest']) : '—'; ?></td></tr>
        <tr><td>Investors</td><td class="text-end"><?= $f['investors']; ?><?= $p['max_investors'] ? ' / ' . (int) $p['max_investors'] : ''; ?></td></tr><tr><td>Funding deadline</td><td class="text-end"><?= $p['funding_deadline'] ? date('d M Y', strtotime($p['funding_deadline'])) : '—'; ?></td></tr>
        <tr><td>Investment period</td><td class="text-end"><?= $p['start_date'] ? date('d M Y', strtotime($p['start_date'])) : '—'; ?> → <?= $p['end_date'] ? date('d M Y', strtotime($p['end_date'])) : '—'; ?></td></tr>
        <tr><td>Projected return</td><td class="text-end"><?= $pr['return_pct'] !== null ? $pr['return_pct'] . '% (estimate)' : '—'; ?></td></tr></table>
    <?php if ($open && !$preview) { ?><a href="invest_apply.php?project=<?= $id; ?>" class="btn btn-primary w-100">INVEST NOW</a>
    <?php } else { ?><div class="alert alert-secondary mb-0"><?= $preview ? 'Preview only.' : 'Applications are closed (' . portal_e($p['status']) . ').'; ?></div><?php } ?>
</div></div></div>
</div>
<?php portal_footer();