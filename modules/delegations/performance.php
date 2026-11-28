<?php
/**
 * NEW FILE: modules/delegations/performance.php — RM TASKER PERFORMANCE, RATINGS & INCENTIVES.
 * Ratings come only from verified, final outcomes (configurable weights in Settings); provisional ratings (review
 * period running or an issue under investigation) are shown but not counted, and uninvestigated complaints never
 * lower a rating. Preferred RM Tasker status is suggested here and decided by management. Incentives (recognition,
 * priority, more opportunities, bonuses) are proposals until a SECOND approver approves them; a bonus is paid by
 * Finance as a separate expense — Tasker payments are never raised automatically.
 */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$appr = dlc($conn, 'approve');
$fin = dlc($conn, 'finance');
$names = dl_users($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dl_csrf_ok()) { dl_go('performance.php', 'error', 'The form expired. Please try again.'); }
    $act = (string) ($_POST['act'] ?? '');
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
    $now = dl_now(); $u = dl_uid();
    $iid = (int) ($_POST['incentive'] ?? 0);
    $inc = $iid ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_incentives WHERE id = ' . $iid)) : null;
    if ($act === 'propose') {
        if (!$appr) { dl_go('performance.php', 'error', 'Only management can propose incentives.'); }
        $pid = (int) ($_POST['party'] ?? 0);
        $t = dl_tasker($conn, $pid);
        $kind = in_array($_POST['kind'] ?? '', DLQ_INCENTIVES, true) ? $_POST['kind'] : '';
        $amt = $kind === 'Performance bonus' ? round((float) str_replace(',', '', (string) ($_POST['amount'] ?? '0')), 2) : null;
        $period = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['period'] ?? '')) ? $_POST['period'] : date('Y-m');
        if (!$t || $kind === '') { dl_go('performance.php', 'error', 'Choose the RM Tasker and the incentive.'); }
        if ($kind === 'Performance bonus' && $amt <= 0) { dl_go('performance.php', 'error', 'Give the bonus amount.'); }
        if (mb_strlen($note) < 10) { dl_go('performance.php', 'error', 'Explain the performance it recognizes (ratings, period, results).'); }
        $s = mysqli_prepare($conn, 'INSERT INTO dl_incentives (party_id, kind, amount, note, period, status, proposed_by, proposed_at) VALUES (?, ?, ?, ?, ?, \'Proposed\', ?, ?)');
        mysqli_stmt_bind_param($s, 'isdssis', $pid, $kind, $amt, $note, $period, $u, $now);
        mysqli_stmt_execute($s);
        $nid = (int) mysqli_insert_id($conn);
        dl_audit($conn, 'incentive', $nid, 'proposed', dl_tasker_name($t) . ' · ' . $kind . ($amt ? ' ' . dl_money($amt) : '') . ' · ' . $period . ' (needs another approver)', $note, 'staff', $pid);
        dl_notify_staff($conn, array_values(array_diff(dl_approvers($conn), [$u])), 'Tasker incentive to approve', dl_tasker_name($t) . ': ' . $kind . ($amt ? ' ' . dl_money($amt) : '') . '.');
        dl_go('performance.php', 'success', 'Proposed. Another approver must approve it' . ($amt ? '; the bonus is not paid until then' : '') . '.');
    }
    if ($act === 'decide') {
        if (!$appr || !$inc || $inc['status'] !== 'Proposed') { dl_go('performance.php', 'error', 'Not possible now.'); }
        if ((int) $inc['proposed_by'] === $u) { dl_go('performance.php', 'error', 'You proposed it: another approver must decide.'); }
        $ok = ($_POST['decision'] ?? '') === 'approve';
        if (!$ok && $note === '') { dl_go('performance.php', 'error', 'Give the reason for rejecting it.'); }
        $st = $ok ? 'Approved' : 'Rejected';
        $s = mysqli_prepare($conn, 'UPDATE dl_incentives SET status = ?, decided_by = ?, decided_at = ?, decision_note = ? WHERE id = ?');
        mysqli_stmt_bind_param($s, 'sissi', $st, $u, $now, $note, $iid);
        mysqli_stmt_execute($s);
        dl_audit($conn, 'incentive', $iid, strtolower($st), $inc['kind'] . ($inc['amount'] ? ' ' . dl_money($inc['amount']) : '') . ' · ' . $inc['period'], $note ?: null, 'staff', (int) $inc['party_id']);
        if ($ok && $inc['kind'] === 'Performance bonus') { dl_notify_staff($conn, dl_users_with($conn, 'delegations.finance'), 'Approved Tasker bonus to pay', dl_money($inc['amount']) . ' for ' . (dl_tasker_name(dl_tasker($conn, (int) $inc['party_id']) ?? []) ?: 'an RM Tasker') . '.'); }
        dl_go('performance.php', 'success', $st . '.');
    }
    if ($act === 'pay_bonus') {
        if (!$fin || !$inc || $inc['status'] !== 'Approved' || $inc['kind'] !== 'Performance bonus') { dl_go('performance.php', 'error', 'Only Finance pays an APPROVED bonus.'); }
        $fund = (int) ($_POST['fund_id'] ?? 0);
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_POST['paid_on'] ?? '')) && $_POST['paid_on'] <= date('Y-m-d') ? $_POST['paid_on'] : '';
        $ref = mb_substr(trim((string) ($_POST['reference'] ?? '')), 0, 80);
        if (!$fund || !$date || $ref === '') { dl_go('performance.php', 'error', 'Give the fund, the date and the payment reference.'); }
        require_once __DIR__ . '/../../includes/fund_helpers.php';
        $f = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT code FROM funds WHERE id = ' . $fund));
        $t = dl_tasker($conn, (int) $inc['party_id']);
        try {
            $tx = fund_post_automatic_expense($conn, $f['code'], 'Service', (float) $inc['amount'], $date, mb_substr('RM Tasker performance bonus · ' . ($t ? dl_tasker_name($t) : '') . ' · ' . $inc['period'] . ' · ref ' . $ref, 0, 255), $u ?: null, 'RM Tasker bonuses', 'DL_BONUS', $iid);
        } catch (Throwable $e) { dl_go('performance.php', 'error', 'Not paid: ' . $e->getMessage()); }
        mysqli_query($conn, "UPDATE dl_incentives SET status = 'Paid', transaction_id = " . (int) $tx . ", paid_at = '$now' WHERE id = $iid");
        dl_audit($conn, 'incentive', $iid, 'bonus_paid', dl_money($inc['amount']) . ' · transaction #' . (int) $tx . ' · ref ' . $ref, null, 'staff', (int) $inc['party_id']);
        dl_go('performance.php', 'success', 'Bonus paid and posted as an expense (RM Tasker bonuses).');
    }
    dl_go('performance.php', 'error', 'Unknown action.');
}

$all = dlq_all_ratings($conn);
$incs = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM dl_incentives ORDER BY id DESC LIMIT 100'), MYSQLI_ASSOC);
$tkName = []; foreach ($all as $t) { $tkName[(int) $t['id']] = $t['display']; }
$w = []; foreach (['quality', 'ontime', 'satisfaction', 'reliability', 'communication'] as $k) { $w[$k] = dl_setting($conn, 'p_' . $k); }
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'performance.php', 'RM Tasker performance');
$fmt = function ($v, $d = 1) { return $v === null ? '—' : number_format((float) $v, $d); };
?>
<div class="small text-muted mb-2">Weights: service quality <?= dl_e($w['quality']); ?>% · on time <?= dl_e($w['ontime']); ?>% · customer satisfaction <?= dl_e($w['satisfaction']); ?>% · reliability (instructions) <?= dl_e($w['reliability']); ?>% · communication <?= dl_e($w['communication']); ?>%.
    Only verified assignments whose <?= (int) dl_setting($conn, 'verify_days'); ?>-day review period has ended with no open issue are counted. −<?= dl_e(dl_setting($conn, 'issue_penalty')); ?> points per issue upheld against the Tasker after investigation; uninvestigated complaints never count.</div>
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>RM Tasker</th><th class="text-end">Rating</th><th>Level</th><th class="text-end">Final / provisional</th><th class="text-end">Quality</th><th class="text-end">On time</th><th class="text-end">Satisfaction</th><th class="text-end">Instructions</th><th class="text-end">Communication</th><th class="text-end">Accepted</th><th class="text-end">Issues (upheld / open / not upheld)</th><th class="text-end">Error cost</th><th>Preferred</th></tr></thead><tbody>
<?php foreach ($all as $t) { $r = $t['rating']; ?>
<tr><td><a href="tasker.php?id=<?= (int) $t['id']; ?>"><?= dl_e($t['display']); ?></a><div class="small text-muted"><?= dl_e($t['code']); ?><?= $t['tasker_status'] !== 'Active' ? ' · ' . dl_e($t['tasker_status']) : ''; ?></div></td>
    <td class="text-end fw-semibold"><?= $r['score'] === null ? '—' : $fmt($r['score']) . '/100'; ?><?= $r['penalty'] ? '<div class="small text-danger">−' . $fmt($r['penalty'], 0) . ' issues</div>' : ''; ?></td>
    <td class="small"><?= dl_e($r['band']); ?></td><td class="text-end small"><?= (int) $r['final']; ?> / <?= (int) $r['provisional']; ?></td>
    <td class="text-end small"><?= $fmt($r['quality']); ?></td><td class="text-end small"><?= $r['ontime'] === null ? '—' : round($r['ontime'] * 100) . '%'; ?></td><td class="text-end small"><?= $fmt($r['satisfaction']); ?></td>
    <td class="text-end small"><?= $fmt($r['instructions']); ?></td><td class="text-end small"><?= $fmt($r['communication']); ?></td><td class="text-end small"><?= $r['accepted'] === null ? '—' : round($r['accepted'] * 100) . '%'; ?></td>
    <td class="text-end small"><?= (int) $r['upheld']; ?> / <?= (int) $r['open_issues']; ?> / <?= (int) $r['not_upheld']; ?></td><td class="text-end small"><?= $r['error_cost'] ? dl_money($r['error_cost']) : '—'; ?></td>
    <td class="small"><?php if ((int) $t['preferred']) { ?><span class="dl-st dl-gold"><i class="bi bi-award"></i> Preferred</span><?php } ?>
        <?php if ($t['suggest'] === 'grant') { ?><span class="dl-st dl-ok">Suggested: Preferred</span><?php } elseif ($t['suggest'] === 'review') { ?><span class="dl-st dl-bad">Review Preferred status</span><?php } ?>
        <?php if ($appr && $t['suggest']) { ?><form method="post" action="tasker.php" class="d-inline" onsubmit="var r=prompt('Reason (management decision)?');if(!r){return false;}this.reason.value=r;"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= (int) $t['id']; ?>"><input type="hidden" name="act" value="preferred"><input type="hidden" name="reason"><button class="btn btn-link btn-sm p-0"><?= (int) $t['preferred'] ? 'Remove' : 'Grant'; ?></button></form><?php } ?></td></tr>
<?php } ?>
<?php if (!$all) { ?><tr><td colspan="13" class="text-center text-muted py-4">No RM Tasker yet.</td></tr><?php } ?>
</tbody></table></div></div>
<div class="small text-muted mb-3">Preferred suggestion: rating ≥ <?= dl_e(dl_setting($conn, 'pref_min_score')); ?>/100 over at least <?= dl_e(dl_setting($conn, 'pref_min_jobs')); ?> final assignments, no upheld or open issue. Preferred status adds <?= dl_e(dl_setting($conn, 'preferred_bonus')); ?> points in recommendations; cost, availability and service requirements still count.</div>

<div class="row g-3">
<?php if ($appr) { ?>
<div class="col-lg-4"><form method="post" class="card"><div class="card-body py-2"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="propose">
    <div class="fw-semibold small mb-1">Propose an incentive</div>
    <select name="party" class="form-select form-select-sm mb-1" required><option value="">RM Tasker…</option><?php foreach ($all as $t) { ?><option value="<?= (int) $t['id']; ?>"><?= dl_e($t['display']); ?><?= $t['rating']['score'] !== null ? ' (' . $fmt($t['rating']['score']) . ')' : ''; ?></option><?php } ?></select>
    <select name="kind" class="form-select form-select-sm mb-1" onchange="this.form.amount.style.display=this.value==='Performance bonus'?'':'none'"><?php foreach (DLQ_INCENTIVES as $x) { ?><option><?= $x; ?></option><?php } ?></select>
    <input name="amount" type="number" min="1" class="form-control form-control-sm mb-1" placeholder="Bonus amount (RWF)" style="display:none">
    <input name="period" type="month" value="<?= date('Y-m'); ?>" class="form-control form-control-sm mb-1">
    <textarea name="note" rows="2" class="form-control form-control-sm mb-1" placeholder="Performance it recognizes" required></textarea>
    <div class="small text-muted mb-1">Needs a second approver. A bonus is never added to assignment payments.</div>
    <button class="btn btn-primary btn-sm">Propose</button></div></form></div>
<?php } ?>
<div class="<?= $appr ? 'col-lg-8' : 'col-12'; ?>"><div class="card"><div class="card-body py-2"><div class="fw-semibold small mb-1">Incentives</div>
<div class="table-responsive"><table class="table table-sm dl-tbl mb-0"><thead><tr><th>RM Tasker</th><th>Incentive</th><th>Period</th><th>Why</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($incs as $x) { ?>
<tr><td class="small"><?= dl_e($tkName[(int) $x['party_id']] ?? '#' . $x['party_id']); ?></td><td class="small"><?= dl_e($x['kind']); ?><?= $x['amount'] ? ' · ' . dl_money($x['amount']) : ''; ?></td><td class="small"><?= dl_e($x['period']); ?></td>
    <td class="small" style="min-width:200px"><?= dl_e($x['note']); ?><div class="text-muted">proposed by <?= dl_e($names[(int) $x['proposed_by']] ?? '—'); ?><?= $x['decided_by'] ? ' · decided by ' . dl_e($names[(int) $x['decided_by']] ?? '') . ($x['decision_note'] ? ': ' . dl_e($x['decision_note']) : '') : ''; ?></div></td>
    <td><span class="dl-st <?= ['Approved' => 'dl-ok', 'Paid' => 'dl-ok', 'Rejected' => 'dl-bad', 'Proposed' => 'dl-gold'][$x['status']] ?? ''; ?>"><?= dl_e($x['status']); ?></span></td>
    <td class="small"><?php if ($appr && $x['status'] === 'Proposed' && (int) $x['proposed_by'] !== dl_uid()) { ?><form method="post" class="d-flex gap-1"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="decide"><input type="hidden" name="incentive" value="<?= (int) $x['id']; ?>"><input name="note" class="form-control form-control-sm" style="width:110px" placeholder="Note"><button name="decision" value="approve" class="btn btn-success btn-sm py-0">Approve</button><button name="decision" value="reject" class="btn btn-outline-danger btn-sm py-0">Reject</button></form><?php } ?>
        <?php if ($fin && $x['status'] === 'Approved' && $x['kind'] === 'Performance bonus') { require_once __DIR__ . '/../../includes/fund_helpers.php'; ?><form method="post" class="d-flex flex-wrap gap-1"><?= dl_csrf_field(); ?><input type="hidden" name="act" value="pay_bonus"><input type="hidden" name="incentive" value="<?= (int) $x['id']; ?>">
            <select name="fund_id" class="form-select form-select-sm" style="width:120px"><?php foreach (funds_all($conn) as $f) { ?><option value="<?= (int) $f['id']; ?>"><?= dl_e($f['name']); ?></option><?php } ?></select><input type="date" name="paid_on" value="<?= date('Y-m-d'); ?>" class="form-control form-control-sm" style="width:130px"><input name="reference" class="form-control form-control-sm" style="width:100px" placeholder="Reference"><button class="btn btn-primary btn-sm py-0">Pay</button></form><?php } ?></td></tr>
<?php } ?>
<?php if (!$incs) { ?><tr><td colspan="6" class="text-center text-muted py-3">None yet.</td></tr><?php } ?>
</tbody></table></div></div></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>