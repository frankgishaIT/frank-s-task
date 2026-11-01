<?php
// NEW FILE: portal/my_investments.php — MY INVESTMENTS: totals, each project, updates, results and payouts.
require __DIR__ . '/invest_start.php';
$apps = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, p.name AS project, p.status AS pstatus, p.start_date, p.end_date, p.capital_required, p.expected_revenue, p.expected_direct, p.expected_other, p.mgmt_fee_pct,
    p.results_status, p.actual_revenue, p.actual_direct, p.actual_other, k.status AS kstatus, k.contribution_ref, k.amount AS kamount
    FROM inv_applications a JOIN inv_projects p ON p.id = a.project_id LEFT JOIN inv_contributions k ON k.application_id = a.id
    WHERE a.customer_id = $cid AND a.status <> 'Draft' ORDER BY a.id DESC"), MYSQLI_ASSOC);
$drafts = mysqli_fetch_all(mysqli_query($conn, "SELECT a.id, p.name FROM inv_applications a JOIN inv_projects p ON p.id = a.project_id WHERE a.customer_id = $cid AND a.status = 'Draft'"), MYSQLI_ASSOC);
$dist = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM inv_distributions WHERE customer_id = $cid"), MYSQLI_ASSOC) as $d) { $dist[(int) $d['application_id']] = $d; }
$t = ['invested' => 0, 'active' => 0, 'completed' => 0, 'projected' => 0, 'realized' => 0, 'received' => 0, 'pending' => 0, 'capital_back' => 0];
foreach ($apps as $a) {
    if ($a['kstatus'] !== 'Verified') { continue; }
    $t['invested'] += (float) $a['kamount'];
    if (in_array($a['pstatus'], ['Active', 'Funding Closed', 'Published'], true)) { $t['active']++; } if ($a['pstatus'] === 'Completed') { $t['completed']++; }
    $pr = inv_projection($a + ['capital_required' => $a['capital_required']]);
    $t['projected'] += (float) $a['capital_required'] > 0 ? (float) $a['kamount'] / (float) $a['capital_required'] * $pr['distributable'] : 0;
    if (isset($dist[(int) $a['id']])) { $d = $dist[(int) $a['id']]; $t['realized'] += (float) $d['profit_share'];
        if ($d['status'] === 'Paid') { $t['received'] += (float) $d['total_payout']; $t['capital_back'] += (float) $d['capital_returned']; } else { $t['pending'] += (float) $d['total_payout']; } }
}
$focus = (int) ($_GET['project'] ?? 0);
portal_header('My investments', $me);
portal_alerts();
?>
<h1 class="h5 mb-3">My investments</h1>
<?php if ($drafts) { ?><div class="alert alert-info small">Unfinished applications: <?php foreach ($drafts as $d) { ?><a href="invest_apply.php?id=<?= (int) $d['id']; ?>"><?= portal_e($d['name']); ?></a> <?php } ?></div><?php } ?>
<div class="row g-3 mb-3">
    <?php foreach (['Capital invested' => cl_money($t['invested']), 'Active projects' => $t['active'], 'Completed projects' => $t['completed'], 'Projected profit (estimate)' => cl_money($t['projected']),
                    'Actual realized profit' => cl_money($t['realized']), 'Received' => cl_money($t['received']), 'Pending payouts' => cl_money($t['pending']), 'Capital returned' => cl_money($t['capital_back'])] as $l => $v) { ?>
    <div class="col-6 col-lg-3"><div class="card h-100"><div class="card-body"><div class="small text-muted"><?= $l; ?></div><div class="fs-6 fw-bold"><?= $v; ?></div></div></div></div><?php } ?>
</div>
<?php if (!$apps) { ?><div class="card"><div class="card-body text-muted">You have no investments yet. <a href="invest.php">See the opportunities</a>.</div></div><?php } ?>
<?php foreach ($apps as $a) { $pid = (int) $a['project_id']; $d = $dist[(int) $a['id']] ?? null;
    $updates = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM inv_updates WHERE project_id = $pid ORDER BY id DESC LIMIT 5"), MYSQLI_ASSOC); ?>
<details class="card mb-2" <?= $focus === $pid ? 'open' : ''; ?>><summary class="card-body py-2 d-flex justify-content-between flex-wrap"><span><strong><?= portal_e($a['project']); ?></strong> · <?= inv_app_ref($a); ?></span>
    <span><?= cl_money($a['amount']); ?> · <span class="badge bg-primary"><?= portal_e($a['status']); ?></span></span></summary>
<div class="card-body pt-0 small">
    <div>Investment reference <?= portal_e($a['contribution_ref'] ?? '—'); ?> · invested <?= $a['kstatus'] === 'Verified' ? cl_money($a['kamount']) : 'not yet confirmed'; ?> · project <?= portal_e($a['pstatus']); ?> · <?= $a['start_date'] ? date('d M Y', strtotime($a['start_date'])) : '—'; ?> → <?= $a['end_date'] ? date('d M Y', strtotime($a['end_date'])) : '—'; ?></div>
    <div class="mt-1"><a href="invest_app.php?id=<?= (int) $a['id']; ?>">Application &amp; payment</a> · <a href="invest_agreement.php?app=<?= (int) $a['id']; ?>" target="_blank">Investment agreement</a> · <a href="invest_project.php?id=<?= $pid; ?>">Project details</a></div>
    <?php if ($a['results_status'] === 'Approved') { $res = inv_calculate($conn, mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_projects WHERE id = $pid"))); ?>
    <h3 class="h6 mt-2">Profit / loss report (actual)</h3>
    <div>Revenue <?= cl_money($res['revenue']); ?> − costs <?= cl_money($res['direct'] + $res['other']); ?> = <?= $res['profit'] >= 0 ? 'profit' : '<span class="text-danger">loss</span>'; ?> <strong><?= cl_money($res['profit']); ?></strong> · management cost <?= cl_money($res['fee']); ?> · to investors <?= cl_money($res['distributable']); ?></div>
    <?php if ($d) { ?><div class="mt-1">Your share <?= round((float) $d['share_pct'], 2); ?>%: profit <strong><?= cl_money($d['profit_share']); ?></strong> + capital <?= cl_money($d['capital_returned']); ?> = <strong><?= cl_money($d['total_payout']); ?></strong> ·
        <?= $d['status'] === 'Paid' ? '<span class="badge bg-success">Paid ' . date('d M Y', strtotime($d['paid_at'])) . '</span>' : '<span class="badge bg-secondary">Payout pending</span>'; ?></div><?php } ?>
    <?php } elseif ($a['kstatus'] === 'Verified') { ?><div class="mt-2 text-muted">Results are published when the project ends and management has approved the actual figures.</div><?php } ?>
    <?php if ($updates) { ?><h3 class="h6 mt-2">Project updates</h3><?php foreach ($updates as $u) { ?><div class="border-top py-1"><strong><?= portal_e($u['title']); ?></strong> <span class="text-muted"><?= date('d M Y', strtotime($u['created_at'])); ?></span><div style="white-space:pre-wrap;"><?= portal_e($u['body']); ?></div></div><?php } } ?>
</div></details>
<?php } ?>
<?php portal_footer();