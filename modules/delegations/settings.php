<?php
/** NEW FILE: modules/delegations/settings.php — recommendation and performance weights, the approved profit-sharing basis, follow-up and payment periods. */
require __DIR__ . '/common.php';
dl_require($conn, 'settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dl_csrf_ok()) { dl_go('settings.php', 'error', 'The form expired. Please try again.'); }
    $n = function ($k, $lo, $hi) { return (string) max($lo, min($hi, (float) ($_POST[$k] ?? 0))); };
    $new = [];
    foreach (['w_cost', 'w_speed', 'w_quality', 'w_reliability', 'w_availability', 'p_quality', 'p_ontime', 'p_satisfaction', 'p_reliability', 'p_communication'] as $k) { $new[$k] = $n($k, 0, 100); }
    $sw = $new['w_cost'] + $new['w_speed'] + $new['w_quality'] + $new['w_reliability'] + $new['w_availability'];
    $sp = $new['p_quality'] + $new['p_ontime'] + $new['p_satisfaction'] + $new['p_reliability'] + $new['p_communication'];
    if (abs($sw - 100) > 0.01) { dl_go('settings.php', 'error', 'The recommendation weights must add up to 100% (now ' . $sw . '%).'); }
    if (abs($sp - 100) > 0.01) { dl_go('settings.php', 'error', 'The performance weights must add up to 100% (now ' . $sp . '%).'); }
    $new['min_history'] = (string) (int) $n('min_history', 1, 50);
    $new['preferred_bonus'] = $n('preferred_bonus', 0, 20);
    $new['follow_up_minutes'] = (string) (int) $n('follow_up_minutes', 5, 10080);
    $new['follow_up_urgent_minutes'] = (string) (int) $n('follow_up_urgent_minutes', 5, 1440);
    $new['reminder_hours'] = $n('reminder_hours', 0, 168);
    foreach (['verify_days' => 30, 'confirm_days' => 30, 'pay_terms_days' => 90] as $k => $hi) { $new[$k] = (string) (int) $n($k, 0, $hi); }
    $wa = trim((string) ($_POST['whatsapp_link'] ?? ''));
    if ($wa !== '' && !preg_match('#^https://(wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com)/#i', $wa)) { dl_go('settings.php', 'error', 'The WhatsApp link must be an official https://wa.me/… (or api.whatsapp.com) link.'); }
    $new['whatsapp_link'] = $wa;
    $new['receipt_tasker_name'] = empty($_POST['receipt_tasker_name']) ? '0' : '1';
    $basis = ($_POST['profit_basis'] ?? 'cogs') === 'cogs_direct' ? 'cogs_direct' : 'cogs';
    $reason = mb_substr(trim((string) ($_POST['basis_reason'] ?? '')), 0, 255);
    if ($basis !== dl_setting($conn, 'profit_basis')) {
        if ($reason === '') { dl_go('settings.php', 'error', 'Changing the profit-sharing basis needs the management decision it is based on.'); }
        $new['profit_basis'] = $basis;
        $new['profit_basis_by'] = (string) dl_uid();
        $new['profit_basis_at'] = dl_now();
        dl_audit($conn, 'settings', null, 'profit_basis', 'Profit-sharing basis: ' . dl_basis_text(dl_setting($conn, 'profit_basis')) . ' → ' . dl_basis_text($basis) . ' (applies to delegations created from now on)', $reason);
    }
    $chg = [];
    foreach ($new as $k => $v) { if (in_array($k, ['profit_basis', 'profit_basis_by', 'profit_basis_at'], true)) { dl_set($conn, $k, $v); continue; } $old = dl_setting($conn, $k); if ((string) $old !== (string) $v) { dl_set($conn, $k, $v); $chg[] = $k . ': ' . $old . ' → ' . $v; } }
    if ($chg) { dl_audit($conn, 'settings', null, 'settings', implode('; ', $chg)); }
    dl_go('settings.php', 'success', 'Settings saved.');
}
$g = function ($k) use ($conn) { return dl_e(dl_setting($conn, $k)); };
$names = dl_users($conn);
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM dl_audit WHERE entity IN ('settings', 'offering') ORDER BY id DESC LIMIT 25"), MYSQLI_ASSOC);
$by = (int) dl_setting($conn, 'profit_basis_by');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'settings.php', 'Delegation settings');
$inp = function ($k, $l, $suffix = '%', $step = '1') use ($g) { return '<div class="col-6 col-md"><label class="form-label small mb-0">' . $l . '</label><div class="input-group input-group-sm"><input type="number" step="' . $step . '" min="0" name="' . $k . '" class="form-control" value="' . $g($k) . '"><span class="input-group-text">' . $suffix . '</span></div></div>'; };
?>
<form method="post" class="card mb-3"><div class="card-body" style="max-width:980px"><?= dl_csrf_field(); ?>
    <h6 class="mb-1">Recommendation weights <span class="small text-muted fw-normal">(must add up to 100%)</span></h6>
    <div class="row g-2 mb-3"><?= $inp('w_cost', 'Cost efficiency') . $inp('w_speed', 'Speed') . $inp('w_quality', 'Quality') . $inp('w_reliability', 'Reliability') . $inp('w_availability', 'Availability'); ?></div>
    <div class="row g-2 mb-3"><?= $inp('min_history', 'Completed delegations before own record counts', '', '1') . $inp('preferred_bonus', 'Preferred RM Tasker bonus', 'points', '0.5'); ?><div class="col-md-6 small text-muted align-self-end">Below the minimum, an RM Tasker is shown as "New / limited history" and gets neutral quality and reliability values, not poor ones.</div></div>
    <h6 class="mb-1">Performance rating weights <span class="small text-muted fw-normal">(must add up to 100%)</span></h6>
    <div class="row g-2 mb-3"><?= $inp('p_quality', 'Service quality') . $inp('p_ontime', 'On-time completion') . $inp('p_satisfaction', 'Customer satisfaction') . $inp('p_reliability', 'Instructions followed') . $inp('p_communication', 'Communication'); ?></div>
    <h6 class="mb-1">Profit-sharing basis <span class="small text-muted fw-normal">(management decision)</span></h6>
    <div class="mb-1"><label class="small d-block"><input type="radio" name="profit_basis" value="cogs" <?= dl_setting($conn, 'profit_basis') === 'cogs' ? 'checked' : ''; ?>> <b>Revenue − cost of sales</b> = profit pool; other direct costs are deducted after the Tasker's share</label>
        <label class="small d-block"><input type="radio" name="profit_basis" value="cogs_direct" <?= dl_setting($conn, 'profit_basis') === 'cogs_direct' ? 'checked' : ''; ?>> <b>Revenue − cost of sales − other direct costs</b> = profit pool</label></div>
    <div class="row g-2 mb-1"><div class="col-md-8"><input name="basis_reason" class="form-control form-control-sm" placeholder="Management decision (required to change the basis)"></div></div>
    <div class="small text-muted mb-3">Current basis set <?= $by ? 'by ' . dl_e($names[$by] ?? '#' . $by) . ' on ' . dl_e(date('d M Y H:i', strtotime(dl_setting($conn, 'profit_basis_at')))) : 'by default'; ?>. A delegation keeps the basis in force when it was created. Example: price 100,000, materials 40,000, rate 30% → pool 60,000, Tasker 18,000, RISE MOTIVE 42,000.</div>
    <h6 class="mb-1">Follow-up, verification and payments</h6>
    <div class="row g-2 mb-2"><?= $inp('follow_up_minutes', 'Tasker response follow-up', 'min') . $inp('follow_up_urgent_minutes', 'Follow-up for urgent tasks', 'min') . $inp('reminder_hours', 'Deadline reminder', 'h before', '0.5'); ?></div>
    <div class="row g-2 mb-2"><?= $inp('verify_days', 'Complaint / verification period', 'days') . $inp('pay_terms_days', 'Tasker payment terms', 'days') . $inp('confirm_days', 'Payment confirmation window', 'days'); ?></div>
    <div class="row g-2 mb-3"><div class="col-md-8"><label class="form-label small mb-0">Official RISE MOTIVE WhatsApp chat link (sent to Taskers)</label><input name="whatsapp_link" class="form-control form-control-sm" value="<?= $g('whatsapp_link'); ?>" placeholder="https://wa.me/2507…"></div>
        <div class="col-md-4 small text-muted align-self-end">Only the official business chat: never a staff member's personal number.</div></div>
    <label class="small d-block mb-3"><input type="checkbox" name="receipt_tasker_name" value="1" <?= dl_setting($conn, 'receipt_tasker_name') === '1' ? 'checked' : ''; ?>> Show the RM Tasker's (business) name on the customer receipt, next to "Service fulfilled by an external RISE MOTIVE Tasker". The Tasker's percentage, payment and RISE MOTIVE's profit are never shown.</label>
    <div class="small text-muted mb-3">Monitoring (follow-ups, deadline reminders, missed deadlines) runs every 10 minutes while staff use MY MOTIVE. For exact timing, schedule <code>modules/delegations/cron.php?key=…</code> every 15 minutes with the same secret key as the SMS daily task.</div>
    <button class="btn btn-primary btn-sm">Save settings</button>
</div></form>
<h6>Recent changes</h6>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0"><thead><tr><th>When</th><th>Who</th><th>What</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($log as $a) { ?><tr><td class="text-nowrap small"><?= dl_e(date('d M Y H:i', strtotime($a['created_at']))); ?></td><td class="small"><?= dl_e($names[(int) $a['user_id']] ?? 'System'); ?></td><td class="small"><?= dl_e($a['details']); ?></td><td class="small"><?= dl_e($a['reason']); ?></td></tr><?php } ?>
<?php if (!$log) { ?><tr><td colspan="4" class="text-center text-muted py-3">No change yet.</td></tr><?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>