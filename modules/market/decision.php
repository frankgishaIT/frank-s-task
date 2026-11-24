<?php
/**
 * NEW FILE: modules/market/decision.php — RECOMMENDATION and MANAGEMENT DECISION of one opportunity.
 *   1. A manager (market.research) submits the recommendation → status "Recommended" (pending management approval);
 *      the figures at that moment are kept (snapshot). Administrators are notified.
 *   2. Management (market.approve) approves, rejects, defers or asks for more information.
 *      Approval records date, approver, quantity, supplier, purchase budget, responsible manager, next action,
 *      assigned employee, follow-up date and comments. Reject / defer need a reason. Nobody approves their own
 *      recommendation unless they are an Administrator. Approving beyond the available working capital needs an
 *      explicit confirmation. Every step stays in the history (rejected / deferred records are never deleted).
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/notification_helper.php';
if (!mi_can_evaluate($conn)) { mi_require($conn, 'research'); }
$o = mi_load_opp($conn, 'decision.php');
mi_sync_status($conn, $o); // follows the purchase order: In Implementation → Launched/Active
$id = (int) $o['id']; $self = 'decision.php?id=' . $id; $svc = $o['kind'] === 'service';
$uid = (int) current_user_id(); $isAdmin = current_user_role() === 'Admin';
$canSubmit = mic($conn, 'research'); $canApprove = mic($conn, 'approve');
$R = mi_recommendation($conn, $o);
$recs = array_keys($svc ? MI_SERVICE_RECS : MI_PRODUCT_RECS);
$sub = mi_last_decision($conn, $id, 'Submitted');
$selfBlock = $sub && (int) $sub['user_id'] === $uid && !$isAdmin;
$snap = function () use ($R, $svc) {
    $k = $svc ? ['rec', 'level', 'price', 'direct', 'volume', 'investment', 'gp_m', 'risk'] : ['rec', 'level', 'qty', 'unit_cost', 'cost_source', 'investment', 'price', 'margin', 'revenue', 'gross_profit', 'gap', 'coverage', 'risk'];
    $o = []; foreach ($k as $x) { $o[$x] = $R[$x] ?? null; } if (!$svc && $R['quote']) { $o['supplier'] = $R['quote']['supplier_name']; } $o['why'] = $R['why']; return json_encode($o);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mi_csrf_ok()) { mi_go($self, 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? ''); $now = date('Y-m-d H:i:s');
    $t = function ($k, $n = 255) { $v = trim((string) ($_POST[$k] ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); };
    try {
        if ($do === 'submit') {
            if (!$canSubmit) { throw new RuntimeException('You may not submit recommendations.'); }
            if (in_array($o['status'], ['Approved', 'In Implementation', 'Launched/Active', 'Completed'], true)) { throw new RuntimeException('A decision was already taken.'); }
            $rec = in_array($_POST['recommendation'] ?? '', $recs, true) ? $_POST['recommendation'] : $R['rec'];
            $qty = $t('approved_qty'); $budget = $t('budget');
            mi_record_decision($conn, $id, ['action' => 'Submitted', 'recommendation' => $rec, 'comments' => $t('comments', 2000), 'approved_qty' => $qty, 'budget' => $budget, 'quote_id' => (int) ($_POST['quote_id'] ?? 0) ?: null, 'snapshot' => $snap()]);
            mysqli_query($conn, "UPDATE mi_opportunities SET status = 'Recommended', updated_by = $uid, updated_at = '$now' WHERE id = $id");
            mi_audit($conn, $id, null, 'submitted', 'Recommendation submitted for management approval: ' . $rec . ($rec !== $R['rec'] ? ' (MY MOTIVE suggested: ' . $R['rec'] . ')' : ''));
            $r = mysqli_query($conn, "SELECT id FROM users WHERE role = 'Admin' AND is_active = 1 AND id <> $uid");
            while ($x = mysqli_fetch_assoc($r)) { notifyUser($conn, (int) $x['id'], 'Market opportunity awaiting approval', $o['name'] . ': ' . $rec . '. Open RM Market Insights → Recommendations.'); }
            mi_go($self, 'success', 'Submitted for management approval.');
        }
        if (in_array($do, ['task', 'launch', 'complete'], true)) {
            if (!$canSubmit && !$canApprove) { throw new RuntimeException('You may not change the implementation.'); }
            if (!in_array($o['status'], ['Approved', 'In Implementation', 'Launched/Active'], true)) { throw new RuntimeException('Only approved opportunities can be implemented.'); }
            if ($do === 'task') {
                $pj = (int) ($_POST['project_id'] ?? 0); $title = $t('title', 200); $as = (int) ($_POST['assigned_to'] ?? 0) ?: null;
                $pr = in_array($_POST['priority'] ?? '', ['Low', 'Medium', 'High'], true) ? $_POST['priority'] : 'Medium';
                $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['due_date'] ?? '') ? $_POST['due_date'] : '';
                if (!$pj || !mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM projects WHERE id = $pj AND status != 'Completed'"))) { throw new RuntimeException('Choose the project of the task.'); }
                if (!$title) { throw new RuntimeException('Give the task a title.'); }
                if ($as && !mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM users WHERE id = $as AND is_active = 1"))) { throw new RuntimeException('Choose an active employee.'); }
                $desc = trim(($t('description', 2000) ?? '') . "\n\nRM Market Insights: " . $o['name'] . ' (opportunity #' . $id . ').'); $stt = 'Pending';
                $st = mysqli_prepare($conn, "INSERT INTO tasks (project_id, title, description, assigned_to, priority, status, due_date) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''))");
                mysqli_stmt_bind_param($st, 'ississs', $pj, $title, $desc, $as, $pr, $stt, $due); mysqli_stmt_execute($st); $tid = (int) mysqli_insert_id($conn);
                br_stamp($conn, 'tasks', $tid, br_enabled($conn) ? br_write_branch($conn) : null);
                mi_link_add($conn, $id, 'task', $tid);
                if ($as) { notifyUser($conn, $as, 'New task assigned', 'You have been assigned "' . $title . '"' . ($due ? ' (due ' . date('d M Y', strtotime($due)) . ')' : '') . '.'); }
                mi_audit($conn, $id, null, 'task', 'Implementation task #' . $tid . ' created: ' . $title);
                if ($o['status'] === 'Approved' && $svc) { mysqli_query($conn, "UPDATE mi_opportunities SET status = 'In Implementation' WHERE id = $id"); }
                mi_go($self, 'success', 'Task created and linked.');
            }
            if ($do === 'launch') {
                if (!$svc) { throw new RuntimeException('A product is launched automatically when its purchase order is received into stock.'); }
                mysqli_query($conn, "UPDATE mi_opportunities SET status = 'Launched/Active', launched_at = '$now' WHERE id = $id");
                mi_audit($conn, $id, null, 'launched', 'Service introduced' . ($t('comments') ? ': ' . $t('comments') : ''));
                mi_go($self, 'success', 'Marked as introduced: results are now monitored.');
            }
            if ($do === 'complete') {
                if ($o['status'] !== 'Launched/Active') { throw new RuntimeException('Only launched opportunities can be completed.'); }
                mysqli_query($conn, "UPDATE mi_opportunities SET status = 'Completed', completed_at = '$now' WHERE id = $id");
                mi_audit($conn, $id, null, 'completed', 'Monitoring completed' . ($t('comments') ? ': ' . $t('comments') : ''));
                mi_go($self, 'success', 'Completed: the final results are kept for future recommendations.');
            }
        }
        if ($do === 'decide') {
            if (!$canApprove) { throw new RuntimeException('Only management can take this decision.'); }
            if ($selfBlock) { throw new RuntimeException('You submitted this recommendation: another manager must decide.'); }
            $act = (string) ($_POST['decision'] ?? '');
            $map = ['Approved' => 'Approved', 'Rejected' => 'Rejected', 'Deferred' => 'Deferred', 'More information' => 'More Information Required'];
            if (!isset($map[$act])) { throw new RuntimeException('Choose the decision.'); }
            if (!in_array($o['status'], ['Recommended', 'More Information Required', 'Deferred', 'Under Review', 'New'], true)) { throw new RuntimeException('This opportunity is already ' . $o['status'] . '.'); }
            if ($act === 'Approved' && !$sub) { throw new RuntimeException('A recommendation must be submitted before approval.'); }
            $reason = in_array($_POST['reason'] ?? '', MI_DECISION_REASONS, true) ? $_POST['reason'] : null;
            if (in_array($act, ['Rejected', 'Deferred'], true) && !$reason) { throw new RuntimeException('Give the reason for the ' . strtolower($act === 'Rejected' ? 'rejection' : 'deferral') . '.'); }
            if ($reason === 'Other' && !$t('comments')) { throw new RuntimeException('Explain the reason in the comments.'); }
            $d = ['action' => $act, 'recommendation' => $sub['recommendation'] ?? $R['rec'], 'reason' => $reason, 'comments' => $t('comments', 2000), 'next_action' => $t('next_action'), 'assigned_to' => (int) ($_POST['assigned_to'] ?? 0) ?: null,
                  'follow_up' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['follow_up'] ?? '') ? $_POST['follow_up'] : null, 'responsible_id' => (int) ($_POST['responsible_id'] ?? 0) ?: $uid, 'snapshot' => $snap()];
            if ($act === 'Approved') {
                $d['approved_qty'] = $t('approved_qty'); $d['budget'] = $t('budget'); $d['quote_id'] = (int) ($_POST['quote_id'] ?? 0) ?: null;
                if (!$svc && (!$d['approved_qty'] || (float) $d['approved_qty'] <= 0)) { throw new RuntimeException('Enter the approved quantity.'); }
                if ($d['budget'] === null || (float) $d['budget'] < 0) { throw new RuntimeException('Enter the approved budget (0 if none).'); }
                $cap = mi_capital($conn, $id);
                if (!$svc && $cap['available'] !== null && (float) $d['budget'] > $cap['available'] && empty($_POST['over_capital'])) { throw new RuntimeException('The budget (' . mi_money($d['budget']) . ') is above the available working capital (' . mi_money($cap['available']) . '). Tick the confirmation to approve anyway.'); }
                if (!empty($_POST['over_capital'])) { $d['comments'] = trim('[Approved above the available working capital] ' . (string) $d['comments']); }
            }
            mi_record_decision($conn, $id, $d);
            $st = $map[$act];
            mysqli_query($conn, "UPDATE mi_opportunities SET status = '$st', updated_by = $uid, updated_at = '$now' WHERE id = $id");
            mi_audit($conn, $id, null, 'decision', $act . ($reason ? ' — ' . $reason : '') . ($act === 'Approved' ? ': ' . ($d['approved_qty'] ? mi_num($d['approved_qty']) . ' ' . $o['unit'] . ', ' : '') . 'budget ' . mi_money($d['budget']) : '') . ($d['comments'] ? ' · ' . $d['comments'] : ''));
            $notify = array_unique(array_filter([(int) ($sub['user_id'] ?? 0), (int) ($d['assigned_to'] ?? 0)]));
            foreach ($notify as $n) { if ($n !== $uid) { notifyUser($conn, $n, 'Market opportunity: ' . $act, $o['name'] . ' — ' . $act . ($reason ? ' (' . $reason . ')' : '') . ($d['next_action'] ? '. Next: ' . $d['next_action'] : '') . '.'); } }
            mi_go($self, 'success', 'Decision recorded: ' . $act . '.');
        }
    } catch (RuntimeException $e) { mi_go($self, 'error', $e->getMessage()); }
}
$hist = mysqli_fetch_all(mysqli_query($conn, "SELECT d.*, u.names, r.names rname, a.names aname, q.supplier_name FROM mi_decisions d LEFT JOIN users u ON u.id = d.user_id LEFT JOIN users r ON r.id = d.responsible_id LEFT JOIN users a ON a.id = d.assigned_to LEFT JOIN mi_quotes q ON q.id = d.quote_id WHERE d.opportunity_id = $id ORDER BY d.id DESC"), MYSQLI_ASSOC);
$users = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names, role FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC);
$quotes = $svc ? [] : array_filter($R['compare']['rows'], function ($q) { return !$q['expired']; });
$approved = mi_last_decision($conn, $id, 'Approved');
$seeFunds = mic($conn, 'finance');
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'opportunities.php', $o['name']);
mi_opp_nav($conn, $o, 'decision.php');
$fig = $svc ? ['Service price' => mi_money($R['price']), 'Direct cost / service' => mi_money($R['direct']), 'Services / month' => mi_num($R['volume']), 'Gross profit / month' => $R['gp_m'] === null ? '—' : mi_money($R['gp_m']), 'Investment' => mi_money($R['investment'])]
    : ['Quantity' => mi_num($R['qty']) . ' ' . $o['unit'], 'Supplier' => $R['quote']['supplier_name'] ?? '— (estimate)', 'Unit cost' => $R['unit_cost'] === null ? '—' : mi_money($R['unit_cost']), 'Investment' => $R['investment'] === null ? '—' : mi_money($R['investment']),
       'Selling price' => $R['price'] === null ? '—' : mi_money($R['price']), 'Expected revenue' => $R['revenue'] === null ? '—' : mi_money($R['revenue']), 'Expected gross profit' => $R['gross_profit'] === null ? '—' : mi_money($R['gross_profit']), 'Funds' => $R['feasible'] . ($R['coverage'] !== null ? ' (' . mi_num(round($R['coverage'])) . '%)' : '')];
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3"><span class="small text-muted">Status</span> <?= mi_status_badge($o['status']); ?> <span class="small text-muted ms-2">MY MOTIVE recommends</span> <?= mi_rec_badge($R['rec']); ?></div>
<div class="row g-3">
<div class="col-xl-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <div class="fw-semibold mb-1">Current figures <span class="badge bg-light text-dark border">estimates</span> <a href="feasibility.php?id=<?= $id; ?>" class="fw-normal ms-1">details</a></div>
        <div class="row g-1"><?php foreach ($fig as $l => $x) { ?><div class="col-6 col-md-3"><div class="text-muted"><?= mi_e($l); ?></div><div class="fw-semibold"><?= mi_e($x); ?></div></div><?php } ?></div>
        <ul class="ps-3 mt-2 mb-0"><?php foreach ($R['why'] as $w) { ?><li><?= mi_e($w); ?></li><?php } ?></ul>
    </div></div>
    <?php if ($approved && in_array($o['status'], ['Approved', 'In Implementation', 'Launched/Active', 'Completed'], true)) { $L = mi_links($conn, $id); $stage = mi_purchase_stage($o, $L); ?>
    <div class="card border-0 shadow-sm mb-3" style="border-left:4px solid #0FA968 !important"><div class="card-body small">
        <div class="d-flex justify-content-between flex-wrap gap-2"><div class="fw-semibold">Implementation</div><?= $stage ? '<span class="badge" style="background:#E8F7EF;color:#0B7A4B">' . mi_e($stage) . '</span>' : ''; ?></div>
        <div class="mb-2">Approved by <?= mi_e($approved['names']); ?> on <?= date('d M Y', strtotime($approved['created_at'])); ?>: <?= $approved['approved_qty'] ? mi_num($approved['approved_qty']) . ' ' . mi_e($o['unit']) . ', ' : ''; ?>budget <?= mi_money($approved['budget']); ?>.</div>
        <?php if (!$o['product_id']) { ?>
            <div class="p-2 rounded mb-2" style="background:#FFF8E6">1. <?= $svc ? 'Add the service' : 'Add the product'; ?> to RM Offerings<?= $svc ? '' : ' (stock 0 — stock comes from the purchase order)'; ?>.
                <?php if ($isAdmin) { ?><a href="../products/create.php?mi=<?= $id; ?>" class="btn btn-sm btn-primary py-0 ms-1">Add to RM Offerings</a> <span class="text-muted">name, category, unit and prices are filled in</span><?php } else { ?><span class="text-muted">An Administrator adds items to RM Offerings.</span><?php } ?></div>
        <?php } elseif (!$svc && !$L['po'] && in_array(current_user_role(), ['Admin', 'Manager'], true)) { ?>
            <div class="p-2 rounded mb-2" style="background:#FFF8E6">2. Create the purchase order. <a href="../purchase_orders/create.php?mi=<?= $id; ?>" class="btn btn-sm btn-primary py-0 ms-1">Create purchase order</a> <span class="text-muted">supplier, quantity and price come from the approval</span></div>
        <?php } ?>
        <?php foreach ($L['po'] as $p) { ?><div class="d-flex justify-content-between border-bottom py-1"><a href="../purchase_orders/view.php?id=<?= (int) $p['id']; ?>">Purchase order #<?= (int) $p['id']; ?></a><span><?= mi_e($p['supplier']); ?> · <?= mi_money($p['total_amount']); ?> · <strong><?= mi_e($p['status']); ?></strong></span></div><?php } ?>
        <?php foreach ($L['task'] as $tk) { ?><div class="d-flex justify-content-between border-bottom py-1"><a href="../tasks/view.php?id=<?= (int) $tk['id']; ?>">Task: <?= mi_e($tk['title']); ?></a><span><?= mi_e($tk['names'] ?: 'unassigned'); ?><?= $tk['due_date'] ? ' · due ' . date('d M', strtotime($tk['due_date'])) : ''; ?> · <strong><?= mi_e($tk['status']); ?></strong></span></div><?php } ?>
        <?php if ($o['status'] !== 'Completed' && ($canSubmit || $canApprove)) { $projects = mysqli_fetch_all(mysqli_query($conn, "SELECT id, project_name FROM projects WHERE status != 'Completed' ORDER BY project_name"), MYSQLI_ASSOC); ?>
        <details class="mt-2"><summary class="text-primary fw-semibold">Assign an implementation task</summary><form method="POST" class="row g-2 mt-1"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="task">
            <div class="col-md-6"><input name="title" class="form-control form-control-sm" maxlength="200" required value="<?= mi_e(($approved['next_action'] ?: ($svc ? 'Prepare the launch of' : 'Order and receive')) . ' — ' . $o['name']); ?>"></div>
            <div class="col-md-6"><select name="project_id" class="form-select form-select-sm" required><option value="">Project…</option><?php foreach ($projects as $pj) { ?><option value="<?= (int) $pj['id']; ?>"><?= mi_e($pj['project_name']); ?></option><?php } ?></select></div>
            <div class="col-md-4"><select name="assigned_to" class="form-select form-select-sm"><option value="">Assign to…</option><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= (int) $approved['assigned_to'] === (int) $u['id'] ? 'selected' : ''; ?>><?= mi_e($u['names']); ?></option><?php } ?></select></div>
            <div class="col-md-3"><select name="priority" class="form-select form-select-sm"><option>Medium</option><option>High</option><option>Low</option></select></div>
            <div class="col-md-3"><input type="date" name="due_date" class="form-control form-control-sm" value="<?= mi_e($approved['follow_up'] ?? ''); ?>"></div>
            <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Create</button></div>
            <div class="col-12"><input name="description" class="form-control form-control-sm" maxlength="2000" placeholder="Details (optional)"></div></form></details>
        <?php } ?>
        <?php if ($svc && in_array($o['status'], ['Approved', 'In Implementation'], true) && ($canSubmit || $canApprove)) { ?><form method="POST" class="mt-2 d-flex gap-1"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="launch"><input name="comments" class="form-control form-control-sm" placeholder="Note (optional)"><button class="btn btn-sm btn-success text-nowrap">Service introduced</button></form><?php } ?>
        <?php if ($o['status'] === 'Launched/Active') { ?><div class="mt-2"><a href="monitor.php?id=<?= $id; ?>" class="fw-semibold">See the results since launch →</a></div>
            <?php if ($canSubmit || $canApprove) { ?><form method="POST" class="mt-2 d-flex gap-1" onsubmit="return confirm('Stop monitoring and keep the final results?')"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="complete"><input name="comments" class="form-control form-control-sm" placeholder="Conclusion (optional)"><button class="btn btn-sm btn-light border text-nowrap">Complete monitoring</button></form><?php } } ?>
        <?php if ($o['status'] === 'Completed') { ?><div class="mt-2"><a href="monitor.php?id=<?= $id; ?>" class="fw-semibold">Final results →</a></div><?php } ?>
    </div></div><?php } ?>
    <div class="card border-0 shadow-sm"><div class="card-body small"><div class="fw-semibold mb-1">Decision history</div>
        <?php foreach ($hist as $h) { $s = json_decode((string) $h['snapshot'], true) ?: []; ?><div class="border-bottom py-2">
            <div class="d-flex justify-content-between flex-wrap"><span><strong><?= mi_e($h['action']); ?></strong> · <?= mi_e($h['names']); ?></span><span class="text-muted"><?= mi_e(substr($h['created_at'], 0, 16)); ?></span></div>
            <?php if ($h['recommendation']) { ?><div>Recommendation: <?= mi_e($h['recommendation']); ?></div><?php } ?>
            <?php if ($h['reason']) { ?><div>Reason: <strong><?= mi_e($h['reason']); ?></strong></div><?php } ?>
            <?php if ($h['approved_qty'] || $h['budget'] !== null || $h['supplier_name']) { ?><div><?= $h['approved_qty'] ? 'Quantity ' . mi_num($h['approved_qty']) . ' · ' : ''; ?><?= $h['supplier_name'] ? 'Supplier ' . mi_e($h['supplier_name']) . ' · ' : ''; ?><?= $h['budget'] !== null ? 'Budget ' . mi_money($h['budget']) : ''; ?></div><?php } ?>
            <?php if ($h['rname'] && $h['action'] !== 'Submitted') { ?><div>Responsible: <?= mi_e($h['rname']); ?><?= $h['aname'] ? ' · Assigned: ' . mi_e($h['aname']) : ''; ?><?= $h['follow_up'] ? ' · Follow-up ' . date('d M Y', strtotime($h['follow_up'])) : ''; ?></div><?php } ?>
            <?php if ($h['next_action']) { ?><div>Next action: <?= mi_e($h['next_action']); ?></div><?php } ?>
            <?php if ($h['comments']) { ?><div class="text-muted"><?= mi_e($h['comments']); ?></div><?php } ?>
            <?php if ($s && isset($s['investment'])) { ?><div class="text-muted">At that time: <?= mi_e(($s['rec'] ?? '') . ', investment ' . mi_money($s['investment'] ?? null) . (isset($s['gross_profit']) ? ', gross profit ' . mi_money($s['gross_profit']) : (isset($s['gp_m']) ? ', gross profit/month ' . mi_money($s['gp_m']) : ''))); ?></div><?php } ?>
        </div><?php } ?>
        <?php if (!$hist) { ?><div class="text-muted">No recommendation submitted yet.</div><?php } ?>
    </div></div>
</div>
<div class="col-xl-5">
    <?php if ($canSubmit && !in_array($o['status'], ['Approved', 'In Implementation', 'Launched/Active', 'Completed'], true)) { ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body small row g-2"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="submit">
        <div class="col-12 fw-semibold">1. Submit the recommendation <?= $o['status'] === 'Recommended' ? '<span class="fw-normal text-muted">(submitted — you can submit an updated one)</span>' : ''; ?></div>
        <div class="col-12"><label class="form-label">Recommended action</label><select name="recommendation" class="form-select form-select-sm"><?php foreach ($recs as $x) { ?><option <?= $x === $R['rec'] ? 'selected' : ''; ?>><?= mi_e($x); ?><?= $x === $R['rec'] ? ' (MY MOTIVE)' : ''; ?></option><?php } ?></select></div>
        <?php if (!$svc) { ?><div class="col-6"><label class="form-label">Proposed quantity</label><input type="number" step="any" min="0" name="approved_qty" class="form-control form-control-sm" value="<?= mi_e($R['qty'] ? $R['qty'] : ''); ?>"></div>
        <div class="col-6"><label class="form-label">Supplier</label><select name="quote_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($quotes as $q) { ?><option value="<?= (int) $q['id']; ?>" <?= ($R['quote']['id'] ?? 0) == $q['id'] ? 'selected' : ''; ?>><?= mi_e($q['supplier_name']); ?></option><?php } ?></select></div><?php } ?>
        <div class="col-12"><label class="form-label">Proposed budget</label><input type="number" step="any" min="0" name="budget" class="form-control form-control-sm" value="<?= mi_e($R['investment'] !== null ? round($R['investment']) : ''); ?>"></div>
        <div class="col-12"><label class="form-label">Comments</label><textarea name="comments" rows="2" maxlength="2000" class="form-control form-control-sm"></textarea></div>
        <div class="col-12"><button class="btn btn-primary btn-sm">Submit for management approval</button></div>
    </div></form><?php } ?>
    <?php if ($canApprove && in_array($o['status'], ['Recommended', 'More Information Required', 'Deferred', 'Under Review', 'New'], true)) { ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body small row g-2"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="decide">
        <div class="col-12 fw-semibold">2. Management decision</div>
        <?php if ($selfBlock) { ?><div class="col-12 alert alert-warning py-2 mb-0">You submitted this recommendation: another manager must decide.</div><?php } elseif (!$sub) { ?><div class="col-12 text-muted">No recommendation submitted yet: you can reject, defer or ask for more information; approval needs a submitted recommendation.</div><?php } ?>
        <div class="col-12 d-flex flex-wrap gap-1"><?php foreach (['Approved' => 'success', 'More information' => 'warning', 'Deferred' => 'secondary', 'Rejected' => 'danger'] as $x => $c) { ?><input type="radio" class="btn-check" name="decision" id="d<?= md5($x); ?>" value="<?= $x; ?>" <?= $x === 'Approved' && !$sub ? 'disabled' : ''; ?>><label class="btn btn-sm btn-outline-<?= $c; ?>" for="d<?= md5($x); ?>"><?= $x === 'Approved' ? 'Approve' : ($x === 'Rejected' ? 'Reject' : ($x === 'Deferred' ? 'Defer' : 'Ask for more information')); ?></label><?php } ?></div>
        <div class="col-12 mi-app"><div class="row g-2">
            <?php if (!$svc) { ?><div class="col-6"><label class="form-label">Approved quantity</label><input type="number" step="any" min="0" name="approved_qty" class="form-control form-control-sm" value="<?= mi_e($sub['approved_qty'] ?? ($R['qty'] ?: '')); ?>"></div>
            <div class="col-6"><label class="form-label">Approved supplier</label><select name="quote_id" class="form-select form-select-sm"><option value="">—</option><?php foreach ($quotes as $q) { ?><option value="<?= (int) $q['id']; ?>" <?= (int) ($sub['quote_id'] ?? ($R['quote']['id'] ?? 0)) === (int) $q['id'] ? 'selected' : ''; ?>><?= mi_e($q['supplier_name']); ?> — <?= mi_money($q['unit_price']); ?></option><?php } ?></select></div><?php } ?>
            <div class="col-6"><label class="form-label">Approved budget</label><input type="number" step="any" min="0" name="budget" class="form-control form-control-sm" value="<?= mi_e($sub['budget'] ?? ''); ?>"></div>
            <div class="col-6"><label class="form-label">Responsible manager</label><select name="responsible_id" class="form-select form-select-sm"><?php foreach ($users as $u) { if (!in_array($u['role'], ['Admin', 'Manager'], true)) { continue; } ?><option value="<?= (int) $u['id']; ?>" <?= (int) $u['id'] === $uid ? 'selected' : ''; ?>><?= mi_e($u['names']); ?></option><?php } ?></select></div>
            <?php if (!$svc && $seeFunds) { $cap = mi_capital($conn, $id); ?><div class="col-12 text-muted">Available working capital: <strong><?= mi_money($cap['available']); ?></strong></div><div class="col-12"><label><input type="checkbox" name="over_capital" value="1" class="form-check-input me-1">Approve even if the budget is above the available working capital</label></div><?php } ?>
        </div></div>
        <div class="col-12"><label class="form-label">Reason <span class="text-muted">(required to reject or defer)</span></label><select name="reason" class="form-select form-select-sm"><option value="">—</option><?php foreach (MI_DECISION_REASONS as $x) { ?><option><?= $x; ?></option><?php } ?></select></div>
        <div class="col-12"><label class="form-label">Next action</label><input name="next_action" maxlength="255" class="form-control form-control-sm" placeholder="e.g. Place the order, confirm stock, train two employees"></div>
        <div class="col-6"><label class="form-label">Assigned employee</label><select name="assigned_to" class="form-select form-select-sm"><option value="">—</option><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>"><?= mi_e($u['names']); ?></option><?php } ?></select></div>
        <div class="col-6"><label class="form-label">Follow-up date</label><input type="date" name="follow_up" class="form-control form-control-sm"></div>
        <div class="col-12"><label class="form-label">Comments / supporting information</label><textarea name="comments" rows="2" maxlength="2000" class="form-control form-control-sm"></textarea></div>
        <div class="col-12"><button class="btn btn-primary btn-sm" <?= $selfBlock ? 'disabled' : ''; ?>>Record the decision</button></div>
    </div></form><?php } ?>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>