<?php
// NEW FILE: modules/rm_invest/preview.php — staff preview of what customers will see (works for drafts too).
require __DIR__ . '/invest_common.php';
$id = (int) ($_GET['id'] ?? 0);
$p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_projects WHERE id = $id"));
if (!$p) { header('Location: index.php'); exit; }
$pr = inv_projection($p); $f = inv_funding($conn, $id);
$photos = mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM inv_project_files WHERE project_id = $id AND kind = 'photo' ORDER BY id"), MYSQLI_ASSOC);
$sec = function ($t, $v) use ($e) { if (trim((string) $v) !== '') { echo '<h6 class="mt-3">' . $t . '</h6><div style="white-space:pre-wrap;">' . $e($v) . '</div>'; } };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="alert alert-secondary small">PREVIEW of <?= inv_project_ref($p); ?> (status <?= $e($p['status']); ?>): what customers will see once it is published.</div>
<h4><?= $e($p['name']); ?></h4><div class="small text-muted mb-2"><?= $e($p['category'] . ' · ' . $p['location']); ?> · risk <?= $e($p['risk_level']); ?></div>
<div class="card border-0 shadow-sm"><div class="card-body small">
    <?php foreach ($photos as $ph) { ?><img src="file.php?id=<?= (int) $ph['id']; ?>" alt="" class="me-2 mb-2 rounded" style="height:120px;"><?php } ?>
    <?php $sec('About the project', $p['description']); $sec('Objectives', $p['objectives']); $sec('Why the capital is required', $p['why_capital']); $sec('How the funds will be used', $p['use_of_funds']); ?>
    <h6 class="mt-3">Capital</h6><div><?= cl_money($p['capital_required']); ?> required · <?= cl_money($p['min_invest']); ?> – <?= (float) $p['max_invest'] > 0 ? cl_money($p['max_invest']) : 'no max'; ?> per investor · max <?= (int) $p['max_investors'] ?: '—'; ?> investors · deadline <?= $p['funding_deadline'] ? date('d M Y', strtotime($p['funding_deadline'])) : '—'; ?> · raised <?= cl_money($f['raised']); ?></div>
    <h6 class="mt-3">Projections (estimates)</h6><div>Revenue <?= cl_money($p['expected_revenue']); ?> − direct <?= cl_money($p['expected_direct']); ?> − other <?= cl_money($p['expected_other']); ?> = net <?= cl_money($pr['net']); ?>; management <?= (float) $p['mgmt_fee_pct']; ?>% = <?= cl_money($pr['fee']); ?>; to investors <?= cl_money($pr['distributable']); ?> (<?= $pr['return_pct'] ?? '—'; ?>%)</div>
    <?php foreach (['risk_business' => 'Business risks', 'risk_market' => 'Market risks', 'risk_delays' => 'Possible delays', 'risk_losses' => 'Potential losses', 'risk_other' => 'Other risks', 'capital_recovery' => 'Capital recovery', 'distribution_conditions' => 'Profit distribution conditions', 'cancellation_conditions' => 'Cancellation conditions', 'agreement_terms' => 'Investment agreement terms'] as $k => $l) { $sec($l, $p[$k]); } ?>
</div></div>
<?php include '../../includes/footer.php'; ?>