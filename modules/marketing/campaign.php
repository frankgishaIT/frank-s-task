<?php
/**
 * NEW FILE: modules/marketing/campaign.php — one campaign: workflow, TRACKING and PERFORMANCE.
 *   Target Achievement % = actual ÷ target × 100
 *   Lead Conversion %    = verified paying customers ÷ qualified leads × 100
 *   ROI %                = (attributable eligible profit − marketing cost) ÷ marketing cost × 100
 * Inputs are stated on the page. A zero or missing denominator shows N/A.
 */
require __DIR__ . '/mkt_common.php';
$L = mkt_lists($conn);
$id = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
$c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT c.*, u.names AS manager, cr.names AS creator, p.title AS plan_title, p.created_at AS plan_created FROM mkt_campaigns c
    LEFT JOIN users u ON u.id = c.manager_id LEFT JOIN users cr ON cr.id = c.created_by LEFT JOIN mkt_plans p ON p.id = c.plan_id WHERE c.id = $id AND " . mkt_campaign_scope_sql($conn, $me)));
if (!$c) { header('Location: campaigns.php?error=' . urlencode('Campaign not found, or you are not part of it.')); exit; }
$isManager = (int) $c['manager_id'] === $me || mkt_is_manager();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (in_array($_POST['action'] ?? '', ['link_expense', 'unlink_expense'], true)) {
            // NEW (stage 4): campaign spending = references to APPROVED expense transactions (never copies).
            mkt_ensure_studio($conn);
            if (!$isManager) { throw new RuntimeException('Only the campaign manager or a Manager links spending.'); }
            $tx = (int) ($_POST['transaction_id'] ?? 0); $note = trim((string) ($_POST['note'] ?? ''));
            if ($_POST['action'] === 'link_expense') { mkt_link_expense($conn, $id, $tx, $me, $note); $msg = 'Expense linked to the campaign.'; }
            else {
                if (mb_strlen($note) < 5) { throw new RuntimeException('Write why the expense is unlinked.'); }
                mysqli_query($conn, "DELETE FROM mkt_expense_links WHERE transaction_id = $tx AND campaign_id = $id");
                if (mysqli_affected_rows($conn) !== 1) { throw new RuntimeException('That expense is not linked to this campaign.'); }
                mkt_audit($conn, 'Expense TX-' . str_pad((string) $tx, 6, '0', STR_PAD_LEFT) . ' unlinked', mkt_code('campaign', $c), $tx, null, $note, $me); $msg = 'Expense unlinked.';
            }
            header('Location: campaign.php?id=' . $id . '&success=' . urlencode($msg)); exit;
        }
        if (($_POST['action'] ?? '') === 'reach') {
            if (!$isManager || !in_array($c['status'], ['Active', 'Paused', 'Completed'], true)) { throw new RuntimeException('Only the campaign manager records reach and inquiries, for a running or completed campaign.'); }
            $reached = ($_POST['reached'] ?? '') === '' ? null : max(0, (int) $_POST['reached']); $inq = ($_POST['inquiries'] ?? '') === '' ? null : max(0, (int) $_POST['inquiries']);
            $s = mysqli_prepare($conn, 'UPDATE mkt_campaigns SET reached = ?, inquiries = ?, updated_at = NOW() WHERE id = ?'); mysqli_stmt_bind_param($s, 'iii', $reached, $inq, $id); mysqli_stmt_execute($s);
            mkt_audit($conn, 'Reach / inquiries updated', mkt_code('campaign', $c), ['reached' => $c['reached'], 'inquiries' => $c['inquiries']], ['reached' => $reached, 'inquiries' => $inq], trim((string) ($_POST['source'] ?? '')) ?: null, $me);
            $msg = 'Saved.';
        } else { $msg = 'Campaign is now ' . mkt_apply_transition($conn, 'campaign', $c, (string) ($_POST['action'] ?? ''), trim((string) ($_POST['note'] ?? '')), $me, $isAdmin) . '.'; }
        header('Location: campaign.php?id=' . $id . '&success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
mkt_ensure_studio($conn);
$a = mkt_actuals($conn, [$id]); $pf = mkt_performance($c, $a);
$spend = mysqli_fetch_all(mysqli_query($conn, "SELECT t.id, t.amount, t.transaction_date, t.description, t.expense_category, t.status, l.note, u.names FROM mkt_expense_links l JOIN transactions t ON t.id = l.transaction_id LEFT JOIN users u ON u.id = l.linked_by WHERE l.campaign_id = $id ORDER BY t.transaction_date DESC"), MYSQLI_ASSOC);
$mats = mysqli_fetch_all(mysqli_query($conn, "SELECT id, title, type, status, lib_document_id, link FROM mkt_materials WHERE campaign_id = $id AND status <> 'Archived' ORDER BY id DESC"), MYSQLI_ASSOC);
$linkable = $isManager ? mkt_linkable_expenses($conn, trim((string) ($_GET['xq'] ?? ''))) : [];
$behind = $c['status'] === 'Active' && mkt_is_behind($conn, $pf);
$members = mysqli_fetch_all(mysqli_query($conn, "SELECT u.names FROM mkt_campaign_members m JOIN users u ON u.id = m.user_id WHERE m.campaign_id = $id ORDER BY u.names"), MYSQLI_ASSOC);
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM mkt_audit a LEFT JOIN users u ON u.id = a.user_id WHERE a.reference = '" . mkt_code('campaign', $c) . "' ORDER BY a.id DESC"), MYSQLI_ASSOC);
$actions = mkt_transitions('campaign')[$c['status']] ?? [];
$num = function ($v) { return $v === null ? 'N/A' : number_format((float) $v); };
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('campaigns.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<a href="campaigns.php" class="small text-muted text-decoration-none">&larr; Campaigns</a>
<h4 class="mt-1"><?= $e($c['name']); ?> <span class="small text-muted"><?= mkt_code('campaign', $c); ?></span> <?= mkt_badge($c['status']); ?><?= (int) $c['ai_generated'] ? ' <span class="badge bg-dark">AI-generated</span>' : ''; ?></h4>
<?php if ($behind) { ?><div class="alert alert-danger small"><strong>Behind target:</strong> <?= $pf['elapsed']; ?>% of the time has passed; the weakest target is at <?= mkt_pct_text(min(array_filter($pf['achievement'], function ($x) { return $x !== null; }))); ?>.</div><?php } ?>
<div class="row g-3">
<div class="col-lg-8">
    <div class="row g-2 mb-3">
        <?php foreach (['Target achievement' => mkt_pct_text($pf['overall']), 'Lead conversion' => mkt_pct_text($pf['conversion']), 'Marketing ROI' => mkt_pct_text($pf['roi']), 'Cost per paying customer' => $pf['cac'] === null ? 'N/A' : $rwf($pf['cac'])] as $l => $val) { ?>
        <div class="col-6 col-md-3"><div class="card border-0 shadow-sm h-100"><div class="card-body py-2"><div class="small text-muted"><?= $l; ?></div><div class="fs-5 fw-bold"><?= $e($val); ?></div></div></div></div><?php } ?>
    </div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="p-3 pb-1 fw-semibold">Tracking</div><div class="table-responsive"><table class="table table-sm mb-0" style="font-size:13px;">
        <tr><th class="ps-3">Indicator</th><th class="text-end">Target</th><th class="text-end">Actual</th><th class="text-end">Achievement</th><th>Source</th></tr>
        <?php foreach ([['People / organizations reached', null, $num($a['reached']), null, 'entered by the manager, only where reliable data exists'], ['Inquiries', null, $num($a['inquiries']), null, 'entered by the manager'],
                        ['Leads generated', (int) $c['expected_leads'], $a['leads'], $pf['achievement']['leads'], 'leads registered for this campaign'], ['Qualified leads', null, $a['qualified'], null, 'leads at Qualified stage or beyond'],
                        ['Demonstrations', null, $a['demos'], null, 'leads that reached the demonstration stage'], ['Proposals sent', null, $a['proposals'], null, 'leads that reached the proposal stage'],
                        ['Customers acquired', null, $a['acquired'], null, 'won leads converted to customers'], ['Verified paying customers', (int) $c['expected_customers'], $a['paying'], $pf['achievement']['customers'], 'first payment verified in Transactions'],
                        ['Revenue', $rwf($c['expected_revenue']), $rwf($a['revenue']), $pf['achievement']['revenue'], 'fully paid sales, approved income'], ['Eligible profit', $rwf($c['expected_profit']), $rwf($a['profit']), $pf['achievement']['profit'], 'MY MOTIVE profit rule'],
                        ['Marketing expenditure', $rwf($c['budget']), $rwf($a['expenses']), $pf['budget_used'], 'approved expense transactions linked to the campaign']] as [$l, $t, $act2, $ach, $src]) { ?>
        <tr><td class="ps-3"><?= $l; ?></td><td class="text-end"><?= $t === null ? '—' : $e($t); ?></td><td class="text-end fw-semibold"><?= $e($act2); ?></td><td class="text-end"><?= $t === null ? '' : mkt_pct_text($ach); ?></td><td class="small text-muted"><?= $src; ?></td></tr><?php } ?>
    </table></div>
    <div class="small text-muted p-3 pt-2"><strong>How it is calculated.</strong> Achievement = actual ÷ target × 100. Lead conversion = verified paying customers ÷ qualified leads × 100. ROI = (eligible profit − marketing cost) ÷ marketing cost × 100.
        Eligible profit: fully paid sales of the customers this campaign acquired (approved in Transactions), from acquisition to <?= (int) mkt_setting($conn, 'commission_months'); ?> month(s) after their first verified payment, using the MY MOTIVE profit rule. Marketing cost: approved expense transactions linked to this campaign. When a denominator is zero, the result is N/A.</div>
    </div></div>
    <?php if ($isManager && in_array($c['status'], ['Active', 'Paused', 'Completed'], true)) { ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 small align-items-end"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="reach">
        <div class="col-12 fw-semibold">Reach and inquiries (only reliable figures, e.g. from page statistics or event registers)</div>
        <div class="col-6 col-md-3"><label class="text-muted">Reached</label><input type="number" min="0" name="reached" value="<?= $e($c['reached']); ?>" class="form-control form-control-sm" placeholder="unknown"></div>
        <div class="col-6 col-md-3"><label class="text-muted">Inquiries</label><input type="number" min="0" name="inquiries" value="<?= $e($c['inquiries']); ?>" class="form-control form-control-sm" placeholder="unknown"></div>
        <div class="col-md-4"><label class="text-muted">Source of the figures</label><input name="source" class="form-control form-control-sm" placeholder="e.g. Facebook page insights"></div>
        <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Save</button></div></div></form>
    <?php } ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Spending <span class="fw-normal text-muted">(approved expenses from Transactions)</span></h6>
        <div class="mb-2">Spent <strong class="<?= $c['budget'] > 0 && $a['expenses'] > (float) $c['budget'] ? 'text-danger' : ''; ?>"><?= $rwf($a['expenses']); ?></strong> of <?= $rwf($c['budget']); ?> budget (<?= mkt_pct_text($pf['budget_used']); ?>).</div>
        <?php foreach ($spend as $x) { ?><div class="d-flex justify-content-between align-items-center border-top py-1 gap-2"><span>TX-<?= str_pad((string) $x['id'], 6, '0', STR_PAD_LEFT); ?> · <?= date('d M Y', strtotime($x['transaction_date'])); ?> · <?= $e($x['description']); ?><?= $x['status'] !== 'approved' ? ' <span class="badge bg-danger">' . $e($x['status']) . ' — not counted</span>' : ''; ?></span>
            <span class="text-nowrap"><strong><?= $rwf($x['amount']); ?></strong><?php if ($isManager) { ?> <details class="d-inline"><summary class="text-muted d-inline">unlink</summary><form method="POST" class="d-flex gap-1"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="unlink_expense"><input type="hidden" name="transaction_id" value="<?= (int) $x['id']; ?>"><input name="note" class="form-control form-control-sm" placeholder="Reason"><button class="btn btn-sm btn-outline-danger">Unlink</button></form></details><?php } ?></span></div><?php } ?>
        <?php if (!$spend) { ?><div class="text-muted">No spending linked yet.</div><?php } ?>
        <?php if ($isManager) { ?><details class="mt-2"><summary class="text-primary">Link an approved expense</summary>
            <form class="d-flex gap-1 my-1"><input type="hidden" name="id" value="<?= $id; ?>"><input name="xq" value="<?= $e($_GET['xq'] ?? ''); ?>" class="form-control form-control-sm" placeholder="Search description, category or TX number"><button class="btn btn-sm btn-outline-primary">Search</button></form>
            <form method="POST" class="d-flex gap-1"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="link_expense">
                <select name="transaction_id" class="form-select form-select-sm" required><option value="">Choose (not yet linked to any campaign)</option><?php foreach ($linkable as $x) { ?><option value="<?= (int) $x['id']; ?>">TX-<?= str_pad((string) $x['id'], 6, '0', STR_PAD_LEFT); ?> · <?= date('d M Y', strtotime($x['transaction_date'])); ?> · <?= $e(mb_strimwidth((string) $x['description'], 0, 50, '…')); ?> · RWF <?= number_format((float) $x['amount']); ?></option><?php } ?></select>
                <input name="note" class="form-control form-control-sm" placeholder="Note" style="max-width:160px;"><button class="btn btn-sm btn-primary">Link</button></form>
            <div class="text-muted mt-1">Record the expense in Transactions first (with its fund); once approved, link it here. An expense counts for one campaign only.</div></details><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Materials</h6>
        <?php foreach ($mats as $m) { ?><div class="d-flex justify-content-between border-top py-1"><span>MAT-<?= (int) $m['id']; ?> · <?= $e($m['title']); ?> <span class="text-muted">(<?= $e($m['type']); ?>)</span>
            <?php if ($m['lib_document_id']) { ?><a href="../library/document.php?id=<?= (int) $m['lib_document_id']; ?>" target="_blank">file</a><?php } ?><?php if ($m['link']) { ?> <a href="<?= $e($m['link']); ?>" target="_blank" rel="noopener">link</a><?php } ?></span><?= mkt_badge($m['status']); ?></div><?php } ?>
        <?php if (!$mats) { ?><div class="text-muted">None yet. <a href="materials.php">Add materials in the Creative Studio.</a></div><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body small"><h6>Campaign details</h6><div class="row">
        <?php foreach (['Objective' => $c['objective'], 'Description' => $c['description'], 'Products / services' => mkt_offering_names($L['offerings'], $c['product_ids']), 'Target audience' => $c['target_audience'],
                        'Target organizations' => $c['target_orgs'], 'Marketing message' => $c['message'], 'Channels' => $c['channels'], 'Plan' => $c['plan_title'] ? mkt_code('plan', ['id' => $c['plan_id'], 'created_at' => $c['plan_created']]) . ' ' . $c['plan_title'] : '',
                        'Campaign manager' => $c['manager'], 'Team' => implode(', ', array_column($members, 'names')), 'KPIs' => $c['kpis'], 'Evaluation' => $c['evaluation']] as $l => $val) { if ((string) $val === '') { continue; } ?>
        <div class="col-md-6 mb-2"><div class="text-muted"><?= $l; ?></div><div style="white-space:pre-wrap;"><?= $e($val); ?></div></div><?php } ?></div>
        <div class="text-muted"><?= date('d M Y', strtotime($c['start_date'])); ?> – <?= date('d M Y', strtotime($c['end_date'])); ?> · <?= $pf['days_left']; ?> day(s) left · created by <?= $e($c['creator']); ?></div>
    </div></div>
</div>
<div class="col-lg-4">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><h6>Workflow</h6>
        <div class="mb-2 text-muted"><?= implode(' → ', array_map(function ($s) use ($c) { return $s === $c['status'] ? '<strong class="text-body">' . $s . '</strong>' : $s; }, MKT_CAMPAIGN_FLOW)); ?></div>
        <?php if ($c['status'] === 'Draft' && access_can('marketing.create', $conn) && mkt_may_run($c, $me)) { ?><a href="campaigns.php?edit=<?= $id; ?>" class="btn btn-sm btn-outline-primary mb-2">Edit draft</a><?php } ?>
        <?php foreach ($actions as $act3 => [$to, $perm]) { if (!access_can('marketing.' . $perm, $conn) || ($perm === 'create' && !mkt_may_run($c, $me))) { continue; } ?>
        <form method="POST" class="mb-2"><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="action" value="<?= $act3; ?>">
            <?php if (in_array($act3, ['approve', 'reject', 'evaluate', 'activate', 'pause'], true)) { ?><textarea name="note" rows="<?= $act3 === 'evaluate' ? 3 : 1; ?>" class="form-control form-control-sm mb-1" placeholder="<?= $act3 === 'evaluate' ? 'Evaluation: results, lessons, recommendations' : (in_array($act3, ['reject', 'pause'], true) ? 'Reason' : 'Note'); ?>"></textarea><?php } ?>
            <button class="btn btn-sm <?= in_array($act3, ['reject', 'pause'], true) ? 'btn-outline-danger' : 'btn-primary'; ?>"><?= ucfirst($act3); ?> → <?= $e($to); ?></button></form><?php } ?>
        <?php if (!$actions) { ?><div class="text-muted">No further step.</div><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm"><div class="card-body small"><h6>History</h6>
        <?php foreach ($log as $l) { ?><div class="border-bottom py-1"><span class="text-muted"><?= date('d M Y, H:i', strtotime($l['created_at'])); ?></span> · <?= $e($l['names'] ?? 'System'); ?> · <?= $e($l['action']); ?><?= $l['reason'] ? '<div class="text-muted">' . $e($l['reason']) . '</div>' : ''; ?></div><?php } ?>
    </div></div>
</div></div>
<?php include '../../includes/footer.php'; ?>