<?php
/**
 * NEW FILE: modules/sms/index.php — SMS MANAGEMENT: every SMS notification MY MOTIVE sends, with its trigger, recipients,
 * rules, on/off switch and use in the last 30 days. Click a notification to edit its wording and rules.
 */
require __DIR__ . '/sms_common.php';
$canEdit = smsm_can('templates', $conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!smsm_csrf_ok()) { smsm_go('index.php', 'error', 'The page had expired.'); }
    $ev = (string) ($_POST['event'] ?? ''); $t = sms_tpl($conn, $ev);
    if (!$t || !$canEdit) { smsm_go('index.php', 'error', 'You may not change SMS notifications.'); }
    $on = !empty($_POST['on']) ? 1 : 0; $kind = $t['cat']['kind'];
    if ($kind === 'security' && !$on) { smsm_go('index.php', 'error', $t['cat']['name'] . ' is a security code: it cannot be switched off (people could not sign in).'); }
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if ($kind === 'transactional' && !$on) {
        if (!smsm_can('essential', $conn)) { smsm_go('index.php', 'error', $t['cat']['name'] . ' is an essential (transactional) message: only an administrator with "Switch off essential SMS" may switch it off.'); }
        if (mb_strlen($reason) < 5) { smsm_go('index.php', 'error', 'Give the reason for switching off an essential message.'); }
    }
    if ((int) $t['active'] !== $on) {
        $v = (int) $t['version'] + 1; $uid = (int) current_user_id(); $now = date('Y-m-d H:i:s'); $rules = json_encode($t['rules']);
        $s = mysqli_prepare($conn, 'UPDATE sms_templates SET active = ?, version = ?, updated_by = ?, updated_at = ? WHERE event = ?'); mysqli_stmt_bind_param($s, 'iiiss', $on, $v, $uid, $now, $ev); mysqli_stmt_execute($s);
        $note = ($on ? 'Switched on' : 'Switched off') . ($reason !== '' ? ': ' . mb_substr($reason, 0, 250) : '');
        $s = mysqli_prepare($conn, 'INSERT INTO sms_template_versions (event, version, body, active, rules, note, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)'); mysqli_stmt_bind_param($s, 'sisissi', $ev, $v, $t['body'], $on, $rules, $note, $uid); mysqli_stmt_execute($s);
        sms_audit($conn, $ev, $on ? 'activated' : 'deactivated', $note . ' (version ' . $v . ')', $on ? 'off' : 'on', $on ? 'on' : 'off');
    }
    smsm_go('index.php' . (!empty($_POST['module']) ? '?module=' . rawurlencode($_POST['module']) : ''), 'success', $t['cat']['name'] . ': ' . ($on ? 'on' : 'off') . '. Its wording and settings are kept.');
}
$cat = sms_catalog();
$fm = (string) ($_GET['module'] ?? '');
$since = date('Y-m-d H:i:s', strtotime('-30 days'));
$use = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT event, status, COUNT(*) n, COALESCE(SUM(segments), 0) seg, MAX(created_at) last FROM sms_log WHERE created_at >= '$since' GROUP BY event, status"), MYSQLI_ASSOC) as $r) {
    $ev = $r['event']; if (strpos($ev, 'TIMETABLE_') === 0) { $ev = 'TIMETABLE'; }
    foreach ($cat as $k => $c) { if (($c['legacy'] ?? '') === $ev) { $ev = $k; } }
    $u = &$use[$ev]; $u['n'][$r['status']] = ($u['n'][$r['status']] ?? 0) + (int) $r['n']; $u['seg'] = ($u['seg'] ?? 0) + ($r['status'] === 'sent' ? (int) $r['seg'] : 0); $u['last'] = max($u['last'] ?? '', (string) $r['last']); unset($u);
}
$modules = array_values(array_unique(array_column($cat, 'module')));
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
smsm_tabs($conn, 'index.php', 'SMS Management');
?>
<div class="d-flex flex-wrap gap-1 mb-2 small"><a class="btn btn-sm py-0 <?= $fm === '' ? 'btn-primary' : 'btn-light border'; ?>" href="index.php">All (<?= count($cat); ?>)</a>
    <?php foreach ($modules as $m) { $n = count(array_filter($cat, function ($c) use ($m) { return $c['module'] === $m; })); ?><a class="btn btn-sm py-0 <?= $fm === $m ? 'btn-primary' : 'btn-light border'; ?>" href="index.php?module=<?= rawurlencode($m); ?>"><?= smsm_e($m); ?> (<?= $n; ?>)</a><?php } ?></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:12.5px;">
    <tr class="table-light"><th class="ps-3">Notification</th><th>Trigger</th><th>Recipients</th><th>Rules</th><th class="text-end" title="Last 30 days">Sent · segments</th><th class="text-end">Not sent</th><th class="text-center">On</th></tr>
    <?php foreach ($cat as $ev => $c) { if ($fm !== '' && $c['module'] !== $fm) { continue; } $t = sms_tpl($conn, $ev); $r = $t['rules']; $u = $use[$ev] ?? ['n' => [], 'seg' => 0, 'last' => '']; $sent = ($u['n']['sent'] ?? 0) + ($u['n']['delivered'] ?? 0); $not = array_sum($u['n']) - $sent - ($u['n']['queued'] ?? 0);
        $rl = ['once' => 'once per record', 'interval' => ($c['kind'] === 'reminder' ? 'repeat every ' : 'not again within ') . (int) $r['interval'] . ' h', 'none' => 'every time'][$r['dedupe']] . ($r['schedule'] === 'window' ? ' · sending hours' : '')
            . (array_filter([$r['branches'], $r['roles'], $r['groups'], $r['courses'], $r['statuses']]) ? ' · targeted' : '') . (!empty($r['copy_manager']) ? ' · copy to branch manager' : ''); ?>
    <tr class="<?= (int) $t['active'] || $c['kind'] === 'security' ? '' : 'text-muted'; ?>">
        <td class="ps-3"><a href="template.php?event=<?= rawurlencode($ev); ?>" class="fw-semibold text-decoration-none"><?= smsm_e($c['name']); ?></a>
            <div class="text-muted" style="font-size:11.5px;"><?= smsm_e($c['module']); ?> · <?= ['transactional' => 'Essential', 'security' => 'Security', 'reminder' => 'Reminder', 'info' => 'Information'][$c['kind']]; ?> · v<?= (int) $t['version']; ?></div></td>
        <td><?= smsm_e($c['trigger']); ?></td>
        <td><?= smsm_e(implode(', ', array_map(function ($x) { return SMS_RECIPIENTS[$x] ?? $x; }, $c['to']))); ?></td>
        <td><?= smsm_e($rl); ?></td>
        <td class="text-end"><?= $sent; ?> · <?= (int) $u['seg']; ?><?= $u['last'] ? '<div class="text-muted" style="font-size:11px;">last ' . smsm_e(substr($u['last'], 0, 10)) . '</div>' : ''; ?></td>
        <td class="text-end"><?= $not ? '<a href="log.php?event=' . rawurlencode($ev) . '&amp;status=not_sent">' . $not . '</a>' : '0'; ?></td>
        <td class="text-center"><?php if ($c['kind'] === 'security') { ?><span class="badge bg-light text-dark border" title="Security codes cannot be switched off">Always on</span>
            <?php } elseif ($canEdit) { ?><form method="POST" class="d-inline" <?= (int) $t['active'] && $c['kind'] === 'transactional' ? 'onsubmit="var r=prompt(\'This is an essential message. Why switch it off?\');if(!r)return false;this.reason.value=r;"' : ''; ?>><?= smsm_csrf(); ?><input type="hidden" name="event" value="<?= smsm_e($ev); ?>"><input type="hidden" name="module" value="<?= smsm_e($fm); ?>"><input type="hidden" name="reason"><input type="hidden" name="on" value="<?= (int) $t['active'] ? 0 : 1; ?>">
                <button class="btn btn-sm py-0 <?= (int) $t['active'] ? 'btn-success' : 'btn-outline-secondary'; ?>" title="Click to switch <?= (int) $t['active'] ? 'off' : 'on'; ?>"><?= (int) $t['active'] ? 'On' : 'Off'; ?></button></form>
            <?php } else { echo (int) $t['active'] ? 'On' : 'Off'; } ?></td></tr>
    <?php } ?>
</table></div></div>
<div class="small text-muted mt-2">Manual messages (Send SMS) and tests are not notifications: they appear in the delivery log and the costs. "Not sent" = switched off, duplicates blocked, targeting, wrong audience, missing phone.</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>