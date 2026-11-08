<?php
/**
 * NEW FILE: modules/marketing/plan.php — one marketing plan: details, workflow (submit, approve, activate, complete,
 * evaluate) and MONITORING: planned vs actual, achievement %, remaining targets, time remaining, budget utilisation,
 * performance gaps. Actuals come from the plan's campaigns (verified leads, acquisitions and paid sales).
 */
require __DIR__ . '/mkt_common.php';
$L = mkt_lists($conn);
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT p.*, u.names AS manager, d.name AS department, c.names AS creator FROM mkt_plans p LEFT JOIN users u ON u.id = p.manager_id
    LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN users c ON c.id = p.created_by WHERE p.id = $id AND " . mkt_plan_scope_sql($conn, $me)));
if (!$p) { header('Location: plans.php?error=' . urlencode('Plan not found, or you are not part of it.')); exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try { $to = mkt_apply_transition($conn, 'plan', $p, (string) ($_POST['action'] ?? ''), trim((string) ($_POST['note'] ?? '')), $me, $isAdmin);
        header('Location: plan.php?id=' . $id . '&success=' . urlencode('Plan is now ' . $to . '.')); exit; }
    catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$camps = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mkt_campaigns WHERE plan_id = $id ORDER BY start_date"), MYSQLI_ASSOC);
$act = mkt_actuals($conn, array_column($camps, 'id'));
$perf = mkt_performance($p, $act);
$behind = $p['status'] === 'Active' && mkt_is_behind($conn, $perf);
$members = mysqli_fetch_all(mysqli_query($conn, "SELECT u.names FROM mkt_plan_members m JOIN users u ON u.id = m.user_id WHERE m.plan_id = $id ORDER BY u.names"), MYSQLI_ASSOC);
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM mkt_audit a LEFT JOIN users u ON u.id = a.user_id WHERE a.reference = '" . mkt_code('plan', $p) . "' ORDER BY a.id DESC"), MYSQLI_ASSOC);
$actions = mkt_transitions('plan')[$p['status']] ?? [];
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('plans.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<a href="plans.php" class="small text-muted text-decoration-none">&larr; Plans</a>
<h4 class="mt-1"><?= $e($p['title']); ?> <span class="small text-muted"><?= mkt_code('plan', $p); ?></span> <?= mkt_badge($p['status']); ?></h4>
<?php if ($behind) { ?><div class="alert alert-danger small"><strong>Behind target:</strong> <?= $perf['elapsed']; ?>% of the time has passed, but the weakest target is at <?= mkt_pct_text(min(array_filter($perf['achievement'], function ($x) { return $x !== null; }))); ?>. The manager has been alerted.</div><?php } ?>
<div class="row g-3">
<div class="col-lg-8">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="p-3 pb-1 fw-semibold">Planned vs actual <span class="small text-muted fw-normal">(actuals: verified records only)</span></div>
        <div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
        <tr><th class="ps-3">Target</th><th class="text-end">Planned</th><th class="text-end">Actual</th><th class="text-end">Achievement</th><th class="text-end">Remaining</th></tr>
        <?php foreach ([['Leads', 'expected_leads', 'leads', 'leads', false], ['Paying customers', 'expected_customers', 'paying', 'customers', false], ['Revenue', 'expected_revenue', 'revenue', 'revenue', true], ['Eligible profit', 'expected_profit', 'profit', 'profit', true]] as [$l, $pk, $ak, $rk, $money]) { ?>
        <tr><td class="ps-3"><?= $l; ?></td><td class="text-end"><?= $money ? $rwf($p[$pk]) : (int) $p[$pk]; ?></td><td class="text-end"><?= $money ? $rwf($act[$ak]) : (int) $act[$ak]; ?></td>
            <td class="text-end"><?= mkt_pct_text($perf['achievement'][$rk]); ?></td><td class="text-end"><?= $money ? $rwf($perf['remaining'][$rk]) : $perf['remaining'][$rk]; ?></td></tr><?php } ?>
        </table></div>
        <div class="row g-0 border-top small text-center">
            <?php foreach (['Time elapsed' => $perf['elapsed'] === null ? 'N/A' : $perf['elapsed'] . '%', 'Days remaining' => $perf['days_left'], 'Budget used' => mkt_pct_text($perf['budget_used']) . ' of ' . $rwf($p['budget']),
                            'Lead conversion' => mkt_pct_text($perf['conversion']), 'Marketing ROI' => mkt_pct_text($perf['roi'])] as $l => $val) { ?>
            <div class="col p-2 border-end"><div class="text-muted"><?= $l; ?></div><div class="fw-bold"><?= $e($val); ?></div></div><?php } ?></div>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Campaigns under this plan</h6>
        <?php if (!$camps) { ?><div class="text-muted">None yet. Create a campaign and link it to this plan.</div><?php } ?>
        <?php foreach ($camps as $c) { ?><div class="d-flex justify-content-between border-bottom py-1"><a href="campaign.php?id=<?= (int) $c['id']; ?>"><?= mkt_code('campaign', $c); ?> · <?= $e($c['name']); ?></a><?= mkt_badge($c['status']); ?></div><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body small">
        <h6>Plan details</h6>
        <div class="row">
            <?php foreach (['Main objective' => $p['objective'], 'Description' => $p['description'], 'Products / services' => mkt_offering_names($L['offerings'], $p['product_ids']), 'Target market' => $p['target_market'], 'Customer type' => $p['customer_type'],
                            'Channels' => $p['channels'], 'Department' => $p['department'], 'Marketing manager' => $p['manager'], 'Team' => implode(', ', array_column($members, 'names')), 'KPIs' => $p['kpis'], 'Evaluation' => $p['evaluation']] as $l => $val) { if ((string) $val === '') { continue; } ?>
            <div class="col-md-6 mb-2"><div class="text-muted"><?= $l; ?></div><div style="white-space:pre-wrap;"><?= $e($val); ?></div></div><?php } ?>
        </div>
        <div class="text-muted">Period <?= date('d M Y', strtotime($p['start_date'])); ?> – <?= date('d M Y', strtotime($p['end_date'])); ?> · created by <?= $e($p['creator']); ?><?= $p['approved_at'] ? ' · approved ' . date('d M Y', strtotime($p['approved_at'])) : ''; ?></div>
    </div></div>
</div>
<div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Workflow</h6>
        <div class="mb-2 text-muted"><?= implode(' → ', array_map(function ($s) use ($p) { return $s === $p['status'] ? '<strong class="text-body">' . $s . '</strong>' : $s; }, MKT_PLAN_FLOW)); ?></div>
        <?php if ($p['status'] === 'Draft' && access_can('marketing.create', $conn)) { ?><a href="plans.php?edit=<?= $id; ?>" class="btn btn-sm btn-outline-primary mb-2">Edit draft</a><?php } ?>
        <?php foreach ($actions as $act2 => [$to, $perm]) { if (!access_can('marketing.' . $perm, $conn)) { continue; } ?>
        <form method="POST" class="mb-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="<?= $act2; ?>">
            <?php if (in_array($act2, ['approve', 'reject', 'evaluate', 'activate'], true)) { ?><textarea name="note" rows="<?= $act2 === 'evaluate' ? 3 : 1; ?>" class="form-control form-control-sm mb-1" placeholder="<?= $act2 === 'evaluate' ? 'Evaluation: results, lessons, recommendations' : ($act2 === 'reject' ? 'Reason' : 'Note'); ?>"></textarea><?php } ?>
            <button class="btn btn-sm <?= $act2 === 'reject' ? 'btn-outline-danger' : 'btn-primary'; ?>"><?= ucfirst($act2); ?> → <?= $e($to); ?></button></form><?php } ?>
        <?php if (!$actions) { ?><div class="text-muted">No further step.</div><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body small"><h6>History</h6>
        <?php foreach ($log as $l) { ?><div class="border-bottom py-1"><span class="text-muted"><?= date('d M Y, H:i', strtotime($l['created_at'])); ?></span> · <?= $e($l['names'] ?? 'System'); ?> · <?= $e($l['action']); ?><?= $l['reason'] ? '<div class="text-muted">' . $e($l['reason']) . '</div>' : ''; ?></div><?php } ?>
    </div></div>
</div></div>
<?php include '../../includes/footer.php'; ?>