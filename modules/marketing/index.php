<?php
// NEW FILE: modules/marketing/index.php — Marketing overview (the full dashboard with charts comes in stage 4).
require __DIR__ . '/mkt_common.php';
try { mkt_check_behind($conn); } catch (Throwable $x) { error_log('marketing alerts: ' . $x->getMessage()); }
$scope = mkt_campaign_scope_sql($conn, $me);
$cnt = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) total, SUM(status = 'Active') active, SUM(status IN ('Completed', 'Evaluated', 'Archived')) completed, SUM(status = 'Pending Approval') pending FROM mkt_campaigns c WHERE $scope"));
$active = mysqli_fetch_all(mysqli_query($conn, "SELECT c.*, u.names AS manager FROM mkt_campaigns c LEFT JOIN users u ON u.id = c.manager_id WHERE c.status = 'Active' AND $scope ORDER BY c.end_date"), MYSQLI_ASSOC);
$ids = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM mkt_campaigns c WHERE $scope"), MYSQLI_ASSOC), 'id');
$all = mkt_actuals($conn, $ids);
$plansPending = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_plans p WHERE status = 'Submitted for Approval' AND " . mkt_plan_scope_sql($conn, $me)))[0];
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('index.php');
?>
<div class="row g-3 mb-3">
    <?php foreach ([['Total campaigns', (int) $cnt['total'], 'bi-collection'], ['Active campaigns', (int) $cnt['active'], 'bi-play-circle'], ['Completed campaigns', (int) $cnt['completed'], 'bi-check2-circle'], ['Leads generated', $all['leads'], 'bi-person-plus'],
                    ['Qualified leads', $all['qualified'], 'bi-funnel'], ['Verified paying customers', $all['paying'], 'bi-patch-check'], ['Marketing revenue', $rwf($all['revenue']), 'bi-cash-stack'], ['Eligible profit', $rwf($all['profit']), 'bi-graph-up-arrow']] as [$l, $v, $ic]) { ?>
    <div class="col-6 col-lg-3"><div class="card border-0 shadow-sm h-100"><div class="card-body d-flex gap-3 align-items-center">
        <div class="rounded-3 d-flex align-items-center justify-content-center flex-shrink-0" style="width:46px; height:46px; background:#EEF3FF;"><i class="bi <?= $ic; ?>" style="font-size:1.35rem; color:#1E2FE0;"></i></div>
        <div><div class="small text-muted"><?= $l; ?></div><div class="fw-bold" style="font-size:1.15rem;"><?= $e($v); ?></div></div></div></div></div><?php } ?>
</div>
<?php if ((int) $cnt['pending'] || $plansPending) { ?><div class="alert alert-primary small">Waiting for approval: <?= (int) $cnt['pending']; ?> campaign(s), <?= $plansPending; ?> plan(s).</div><?php } ?>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="p-3 pb-1 fw-semibold">Active campaigns</div><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Campaign</th><th>Manager</th><th class="text-end">Leads</th><th class="text-end">Paying customers</th><th class="text-end">Revenue</th><th class="text-end">Achievement</th><th class="text-end">Days left</th><th></th></tr>
    <?php if (!$active) { ?><tr><td colspan="8" class="text-center text-muted py-4">No active campaign. Campaigns become active once approved and activated.</td></tr><?php } ?>
    <?php foreach ($active as $c) { $a = mkt_actuals($conn, [(int) $c['id']]); $pf = mkt_performance($c, $a); $bh = mkt_is_behind($conn, $pf); ?>
    <tr><td class="ps-3"><strong><?= $e($c['name']); ?></strong><div class="small text-muted"><?= mkt_code('campaign', $c); ?></div></td><td class="small"><?= $e($c['manager']); ?></td>
        <td class="text-end"><?= $a['leads']; ?> / <?= (int) $c['expected_leads']; ?></td><td class="text-end"><?= $a['paying']; ?> / <?= (int) $c['expected_customers']; ?></td><td class="text-end"><?= $rwf($a['revenue']); ?></td>
        <td class="text-end <?= $bh ? 'text-danger fw-bold' : ''; ?>"><?= mkt_pct_text($pf['overall']); ?><?= $bh ? ' · behind' : ''; ?></td><td class="text-end"><?= $pf['days_left']; ?></td><td><a href="campaign.php?id=<?= (int) $c['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">Open</a></td></tr><?php } ?>
</table></div></div></div>
<p class="small text-muted mt-2">Figures come from verified records only: registered leads, converted customers, and fully paid sales approved in Transactions. Leads, team activities, commissions, materials, budget, reports and MY MOTIVE AI are added in the next stages.</p>
<?php include '../../includes/footer.php'; ?>