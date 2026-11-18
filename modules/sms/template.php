<?php
/**
 * NEW FILE: modules/sms/template.php — edit ONE SMS notification: wording with placeholders, live preview (sender ID,
 * characters, SMS segments), recipients and targeting, duplicate / repeat rules, sending hours, on/off, test SMS,
 * version history with restore, and the audit trail of this notification.
 */
require __DIR__ . '/sms_common.php';
$ev = (string) ($_GET['event'] ?? $_POST['event'] ?? '');
$t = sms_tpl($conn, $ev);
if (!$t) { smsm_go('index.php', 'error', 'Notification not found.'); }
$c = $t['cat'];
$canEdit = smsm_can('templates', $conn);
$self = 'template.php?event=' . rawurlencode($ev);
$allowed = array_merge(array_keys($c['vars']), array_keys(sms_common_vars()));
$sample = function () use ($c) { $v = []; foreach ($c['vars'] as $k => $d) { $v[$k] = $d[1]; } return $v; };

/** Checks a wording; returns an error or null. */
$check = function (string $body) use ($c, $allowed, $sample): ?string {
    if (trim($body) === '') { return 'The message cannot be empty.'; }
    preg_match_all('/\{([a-z0-9_]+)\}/i', $body, $m);
    $bad = array_diff(array_unique($m[1]), $allowed);
    if ($bad) { return 'Unknown placeholder(s): {' . implode('}, {', $bad) . '}. Use only the ones listed for this notification.'; }
    foreach ($c['required'] ?? [] as $r) { if (strpos($body, '{' . $r . '}') === false) { return 'This message must contain {' . $r . '}.'; } }
    if (preg_match('/\{[^}]*$|^[^{]*\}/', $body)) { return 'A placeholder is not closed: check the { } signs.'; }
    $len = mb_strlen(sms_render($body, $sample()));
    if ($len > SMS_MAX_LENGTH) { return 'With the sample values the message has ' . $len . ' characters: the maximum is ' . SMS_MAX_LENGTH . ' (3 SMS). Please shorten it.'; }
    return null;
};
$save = function (string $body, int $active, array $rules, string $note, string $action) use ($conn, $ev, $t) {
    $v = (int) $t['version'] + 1; $uid = (int) current_user_id(); $now = date('Y-m-d H:i:s'); $js = json_encode($rules);
    $s = mysqli_prepare($conn, 'UPDATE sms_templates SET body = ?, active = ?, rules = ?, version = ?, updated_by = ?, updated_at = ? WHERE event = ?');
    mysqli_stmt_bind_param($s, 'sisiiss', $body, $active, $js, $v, $uid, $now, $ev); mysqli_stmt_execute($s);
    $s = mysqli_prepare($conn, 'INSERT INTO sms_template_versions (event, version, body, active, rules, note, changed_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'sisissi', $ev, $v, $body, $active, $js, $note, $uid); mysqli_stmt_execute($s);
    // What changed, for the audit trail.
    $chg = [];
    if ($body !== $t['body']) { $chg[] = 'wording'; }
    if ($active !== (int) $t['active']) { $chg[] = $active ? 'switched on' : 'switched off'; }
    foreach ($rules as $k => $x) { if (json_encode($x) !== json_encode($t['rules'][$k] ?? null)) { $chg[] = $k . ': ' . (is_array($x) ? (implode(', ', $x) ?: 'all') : $x); } }
    sms_audit($conn, $ev, $action, 'Version ' . $v . ($chg ? ' — ' . implode('; ', $chg) : ' — no change') . ($note !== '' ? ' — note: ' . $note : ''),
        json_encode(['body' => $t['body'], 'active' => (int) $t['active'], 'rules' => $t['rules']], JSON_UNESCAPED_UNICODE), json_encode(['body' => $body, 'active' => $active, 'rules' => $rules], JSON_UNESCAPED_UNICODE));
    if ($active !== (int) $t['active']) { sms_audit($conn, $ev, $active ? 'activated' : 'deactivated', ($active ? 'Switched on' : 'Switched off') . ' (version ' . $v . ')' . ($note !== '' ? ': ' . $note : ''), $active ? 'off' : 'on', $active ? 'on' : 'off'); }
    return $v;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!smsm_csrf_ok()) { smsm_go($self, 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? '');
    if ($do === 'test') {
        if (!smsm_can('test', $conn)) { smsm_go($self, 'error', 'You may not send test SMS.'); }
        $body = str_replace("\r\n", "\n", (string) ($_POST['body'] ?? $t['body']));
        if (($err = $check($body)) !== null) { smsm_go($self, 'error', 'Test not sent: ' . $err); }
        $phone = (string) ($_POST['phone'] ?? '');
        if (sms_normalize_phone($phone) === null) { smsm_go($self, 'error', 'Enter a valid phone number for the test, e.g. 0788123456.'); }
        $GLOBALS['__sms_meta'] = ['segments' => sms_segments(sms_render($body, $sample()))[1], 'template_version' => (int) $t['version'], 'recipient_type' => 'test', 'module' => 'Test', 'branch_id' => null];
        $ok = sms_send($conn, $phone, sms_render($body, $sample()), 'TEST_' . $ev, 'sms_template', null, (int) current_user_id());
        $GLOBALS['__sms_meta'] = null;
        sms_audit($conn, $ev, 'test', 'Test SMS to ' . sms_normalize_phone($phone) . ($ok ? ' (sent)' : ' (not sent)'));
        smsm_go($self, $ok ? 'success' : 'error', $ok ? 'Test SMS sent to ' . sms_normalize_phone($phone) . ' with the sample values (not saved).' : 'The test SMS was not sent: see the delivery log.');
    }
    if (!$canEdit) { smsm_go($self, 'error', 'You may not change SMS notifications.'); }
    if ($do === 'restore') {
        $vn = (int) ($_POST['version'] ?? 0);
        $old = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM sms_template_versions WHERE event = '" . mysqli_real_escape_string($conn, $ev) . "' AND version = $vn"));
        if (!$old) { smsm_go($self, 'error', 'Version not found.'); }
        $active = $c['kind'] === 'security' ? 1 : (int) $old['active'];
        if (!$active && $c['kind'] === 'transactional' && !smsm_can('essential', $conn)) { $active = (int) $t['active']; }
        $nv = $save((string) $old['body'], $active, array_merge(sms_default_rules($c), json_decode((string) $old['rules'], true) ?: []), 'Restored from version ' . $vn, 'restored');
        smsm_go($self, 'success', 'Version ' . $vn . ' restored as version ' . $nv . '.');
    }
    // Save.
    $body = trim(str_replace("\r\n", "\n", (string) ($_POST['body'] ?? '')));
    if (($err = $check($body)) !== null) { $_SESSION['smsm_draft_' . $ev] = $body; smsm_go($self, 'error', $err); }
    $active = $c['kind'] === 'security' ? 1 : (!empty($_POST['active']) ? 1 : 0);
    $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300);
    if (!$active && (int) $t['active'] && $c['kind'] === 'transactional') {
        if (!smsm_can('essential', $conn)) { smsm_go($self, 'error', 'This is an essential message: only an administrator with "Switch off essential SMS" may switch it off.'); }
        if (mb_strlen($note) < 5) { smsm_go($self, 'error', 'Write in "What changed" why this essential message is switched off.'); }
    }
    $ints = function ($k) { return array_values(array_unique(array_map('intval', array_filter((array) ($_POST[$k] ?? []), 'is_numeric')))); };
    $strs = function ($k, array $ok) { return array_values(array_intersect($ok, (array) ($_POST[$k] ?? []))); };
    $dedupe = in_array($_POST['dedupe'] ?? '', ['once', 'interval', 'none'], true) ? $_POST['dedupe'] : $t['rules']['dedupe'];
    $interval = max(0, min(8760, (int) ($_POST['interval'] ?? 0)));
    if ($dedupe === 'interval' && $interval < 1) { smsm_go($self, 'error', 'Give the minimum number of hours between two messages.'); }
    $tg = $c['targets'];
    $rules = ['dedupe' => $dedupe, 'interval' => $dedupe === 'interval' ? $interval : 0, 'schedule' => ($_POST['schedule'] ?? '') === 'window' && $c['kind'] !== 'security' ? 'window' : 'immediate',
        'branches' => in_array('branch', $tg, true) ? $ints('branches') : [], 'roles' => in_array('role', $tg, true) ? $strs('roles', smsm_roles($conn)) : [],
        'groups' => in_array('customer_group', $tg, true) ? $strs('groups', array_keys(SMS_CUSTOMER_GROUPS)) : [], 'courses' => in_array('course', $tg, true) ? $ints('courses') : [],
        'statuses' => in_array('status', $tg, true) ? $strs('statuses', smsm_statuses($ev)) : [], 'copy_manager' => !empty($c['copy']) && !empty($_POST['copy_manager']) ? 1 : 0];
    if ($body === $t['body'] && $active === (int) $t['active'] && json_encode($rules) === json_encode($t['rules'])) { smsm_go($self, 'success', 'Nothing changed.'); }
    unset($_SESSION['smsm_draft_' . $ev]);
    $nv = $save($body, $active, $rules, $note, 'modified');
    smsm_go($self, 'success', 'Saved as version ' . $nv . '. It is used for the next messages at once.');
}
$body = $_SESSION['smsm_draft_' . $ev] ?? $t['body'];
$r = $t['rules'];
$versions = mysqli_fetch_all(mysqli_query($conn, "SELECT v.*, u.names FROM sms_template_versions v LEFT JOIN users u ON u.id = v.changed_by WHERE v.event = '" . mysqli_real_escape_string($conn, $ev) . "' ORDER BY v.version DESC LIMIT 30"), MYSQLI_ASSOC);
$audit = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM sms_audit a LEFT JOIN users u ON u.id = a.user_id WHERE a.event = '" . mysqli_real_escape_string($conn, $ev) . "' ORDER BY a.id DESC LIMIT 30"), MYSQLI_ASSOC);
$vars = $c['vars'] + sms_common_vars();
$ro = $canEdit ? '' : 'disabled';
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
smsm_tabs($conn, 'index.php', $c['name']);
?>
<style>
    .sm-chip { display: inline-block; font-size: 11.5px; padding: 1px 7px; margin: 0 3px 4px 0; border-radius: 10px; background: #EEF3FF; color: #0B1A8C; border: 0; cursor: pointer; }
    .sm-phone { background: #F1F3F8; border-radius: 18px; padding: 14px; max-width: 360px; } .sm-bubble { background: #fff; border-radius: 14px; padding: 10px 12px; white-space: pre-wrap; font-size: 13.5px; box-shadow: 0 1px 2px rgba(0,0,0,.08); word-break: break-word; }
    .sm-panel { font-size: 13px; } .sm-panel label.form-label { font-size: 12px; color: #5B6275; margin-bottom: 2px; } .sm-check { max-height: 140px; overflow: auto; border: 1px solid #E4E7EF; border-radius: 8px; padding: 4px 8px; }
</style>
<div class="small mb-2"><a href="index.php?module=<?= rawurlencode($c['module']); ?>">← <?= smsm_e($c['module']); ?> notifications</a> · <?= smsm_e($c['trigger']); ?></div>
<form method="POST" class="row g-3 sm-panel" id="smForm"><?= smsm_csrf(); ?><input type="hidden" name="event" value="<?= smsm_e($ev); ?>"><input type="hidden" name="do" value="save" id="smDo">
<div class="col-xl-7">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex justify-content-between align-items-center mb-1"><label class="form-label mb-0 fw-semibold text-dark">Message</label><span class="small text-muted">Version <?= (int) $t['version']; ?><?= $t['updated_at'] ? ' · ' . smsm_e(substr($t['updated_at'], 0, 16)) : ''; ?></span></div>
        <textarea name="body" id="smBody" rows="6" class="form-control" <?= $ro; ?>><?= smsm_e($body); ?></textarea>
        <div class="small mt-1" id="smWarn" style="color:#B42318;"></div>
        <div class="mt-2"><div class="small text-muted mb-1">Placeholders for this notification (click to insert). A missing value prints nothing.</div>
            <?php foreach ($vars as $k => [$d, $s]) { ?><button type="button" class="sm-chip" data-ph="{<?= $k; ?>}" title="<?= smsm_e($d . ' — e.g. ' . $s); ?>">{<?= $k; ?>}</button><?php } ?></div>
        <?php if (!empty($c['required'])) { ?><div class="small text-muted">Must stay in the message: {<?= implode('}, {', $c['required']); ?>}.</div><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">Recipients</div>
        <div class="mb-2">Sent to: <strong><?= smsm_e(implode(', ', array_map(function ($x) { return SMS_RECIPIENTS[$x] ?? $x; }, $c['to']))); ?></strong>
            <span class="small text-muted">— fixed by the trigger, so this message can never go to another kind of person (checked on every SMS).</span></div>
        <?php if (!empty($c['copy'])) { ?><label class="d-block mb-2"><input type="checkbox" class="form-check-input me-1" name="copy_manager" value="1" <?= !empty($r['copy_manager']) ? 'checked' : ''; ?> <?= $ro; ?>> Also send a copy to the <strong>branch manager</strong> of the branch concerned</label><?php } ?>
        <?php if ($c['targets']) { ?><div class="small text-muted mb-1">Only send when (nothing ticked = no limit):</div><div class="row g-2">
            <?php if (in_array('branch', $c['targets'], true) && ($bs = smsm_branches($conn))) { ?><div class="col-md-6"><label class="form-label">Branch</label><div class="sm-check"><?php foreach ($bs as $id => $n) { ?><label class="d-block"><input type="checkbox" class="form-check-input me-1" name="branches[]" value="<?= (int) $id; ?>" <?= in_array((int) $id, array_map('intval', $r['branches']), true) ? 'checked' : ''; ?> <?= $ro; ?>><?= smsm_e($n); ?></label><?php } ?></div></div><?php } ?>
            <?php if (in_array('role', $c['targets'], true)) { ?><div class="col-md-6"><label class="form-label">Employee role</label><div class="sm-check"><?php foreach (smsm_roles($conn) as $n) { ?><label class="d-block"><input type="checkbox" class="form-check-input me-1" name="roles[]" value="<?= smsm_e($n); ?>" <?= in_array($n, $r['roles'], true) ? 'checked' : ''; ?> <?= $ro; ?>><?= smsm_e($n); ?></label><?php } ?></div></div><?php } ?>
            <?php if (in_array('customer_group', $c['targets'], true)) { ?><div class="col-md-6"><label class="form-label">Customer category</label><div class="sm-check"><?php foreach (SMS_CUSTOMER_GROUPS as $k => $n) { ?><label class="d-block"><input type="checkbox" class="form-check-input me-1" name="groups[]" value="<?= $k; ?>" <?= in_array($k, $r['groups'], true) ? 'checked' : ''; ?> <?= $ro; ?>><?= smsm_e($n); ?></label><?php } ?></div></div><?php } ?>
            <?php if (in_array('course', $c['targets'], true) && ($cs = smsm_courses($conn))) { ?><div class="col-md-6"><label class="form-label">Course enrollment</label><div class="sm-check"><?php foreach ($cs as $id => $n) { ?><label class="d-block"><input type="checkbox" class="form-check-input me-1" name="courses[]" value="<?= (int) $id; ?>" <?= in_array((int) $id, array_map('intval', $r['courses']), true) ? 'checked' : ''; ?> <?= $ro; ?>><?= smsm_e($n); ?></label><?php } ?></div></div><?php } ?>
            <?php if (in_array('status', $c['targets'], true) && ($ss = smsm_statuses($ev))) { ?><div class="col-md-6"><label class="form-label">Only for these statuses</label><div class="sm-check"><?php foreach ($ss as $n) { ?><label class="d-block"><input type="checkbox" class="form-check-input me-1" name="statuses[]" value="<?= smsm_e($n); ?>" <?= in_array($n, $r['statuses'], true) ? 'checked' : ''; ?> <?= $ro; ?>><?= smsm_e($n); ?></label><?php } ?></div></div><?php } ?>
        </div><?php } ?>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">When and how often</div>
        <?php $rem = $c['kind'] === 'reminder'; ?>
        <label class="d-block"><input type="radio" class="form-check-input me-1" name="dedupe" value="once" <?= $r['dedupe'] === 'once' ? 'checked' : ''; ?> <?= $ro; ?>><?= $rem ? 'Send once only (no repeated reminders)' : 'Once per record — never twice for the same invoice / payment / task, even if the event happens again'; ?></label>
        <label class="d-block"><input type="radio" class="form-check-input me-1" name="dedupe" value="interval" <?= $r['dedupe'] === 'interval' ? 'checked' : ''; ?> <?= $ro; ?>><?= $rem ? 'Repeat the reminder, at most every' : 'Not again for the same record within'; ?>
            <input type="number" name="interval" min="1" max="8760" value="<?= (int) ($r['interval'] ?: ($c['rules']['interval'] ?: 24)); ?>" class="form-control form-control-sm d-inline-block mx-1" style="width:80px" <?= $ro; ?>> hours</label>
        <label class="d-block"><input type="radio" class="form-check-input me-1" name="dedupe" value="none" <?= $r['dedupe'] === 'none' ? 'checked' : ''; ?> <?= $ro; ?>>Every time the event happens</label>
        <div class="small text-muted mb-2">Always on top: the same text to the same number within <?= (int) sms_setting($conn, 'duplicate_minutes'); ?> minutes is blocked (Settings).</div>
        <?php if ($c['kind'] !== 'security') { ?>
        <label class="d-block"><input type="radio" class="form-check-input me-1" name="schedule" value="immediate" <?= $r['schedule'] !== 'window' ? 'checked' : ''; ?> <?= $ro; ?>>Send immediately</label>
        <label class="d-block"><input type="radio" class="form-check-input me-1" name="schedule" value="window" <?= $r['schedule'] === 'window' ? 'checked' : ''; ?> <?= $ro; ?>>Only during the sending hours (<?= smsm_e(sms_setting($conn, 'window_start') . '–' . sms_setting($conn, 'window_end')); ?>); outside them it is queued until the next opening</label>
        <?php } else { ?><div class="small text-muted">Security codes are always sent immediately.</div><?php } ?>
    </div></div>
    <?php if ($canEdit) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body d-flex flex-wrap align-items-center gap-3">
        <?php if ($c['kind'] === 'security') { ?><span class="badge bg-light text-dark border">Security code: always on</span><?php } else { ?>
        <label class="mb-0"><input type="checkbox" class="form-check-input me-1" name="active" value="1" <?= (int) $t['active'] ? 'checked' : ''; ?>> <strong>Notification on</strong><?= $c['kind'] === 'transactional' ? ' <span class="small text-muted">(essential: switching off needs a reason)</span>' : ''; ?></label><?php } ?>
        <input name="note" class="form-control form-control-sm" style="max-width:320px" placeholder="What changed (kept in the history)">
        <button class="btn btn-primary btn-sm" onclick="document.getElementById('smDo').value='save'">Save</button>
    </div></div><?php } else { ?><div class="alert alert-light border small">You can view this notification. Changing it needs the "Manage SMS templates and rules" permission.</div><?php } ?>
</div>
<div class="col-xl-5">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-2">Preview</div>
        <div class="sm-phone"><div class="small text-muted mb-1">From <strong><?= smsm_e(PINDO_SENDER); ?></strong></div><div class="sm-bubble" id="smPrev"></div></div>
        <div class="small mt-2" id="smCount"></div>
        <details class="mt-2"><summary class="small text-primary">Sample values used in the preview</summary><div class="row g-1 mt-1"><?php foreach ($vars as $k => [$d, $s]) { ?><div class="col-6"><label class="form-label mb-0">{<?= $k; ?>}</label><input class="form-control form-control-sm sm-sample" data-k="<?= $k; ?>" value="<?= smsm_e($s); ?>"></div><?php } ?></div>
            <div class="small text-muted mt-1">Empty a value to see the message when that information is missing.</div></details>
    </div></div>
    <?php if (smsm_can('test', $conn)) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="fw-semibold mb-1">Test SMS</div><div class="small text-muted mb-2">Sends the message as it is in the editor (even unsaved), with the sample values, to the number below.</div>
        <div class="d-flex gap-2"><input name="phone" class="form-control form-control-sm" placeholder="07XXXXXXXX"><button class="btn btn-outline-primary btn-sm text-nowrap" onclick="document.getElementById('smDo').value='test'">Send test</button></div>
    </div></div><?php } ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="p-2 fw-semibold">Versions</div><table class="table table-sm mb-0" style="font-size:12px;">
        <?php foreach ($versions as $v) { ?><tr><td class="ps-2">v<?= (int) $v['version']; ?></td><td><?= smsm_e(substr($v['changed_at'], 0, 16)); ?><div class="text-muted"><?= smsm_e($v['names'] ?? 'System'); ?><?= $v['note'] ? ' · ' . smsm_e($v['note']) : ''; ?></div></td>
            <td><details><summary class="text-primary">text</summary><div style="white-space:pre-wrap;max-width:260px;"><?= smsm_e($v['body']); ?></div></details></td>
            <td class="text-end pe-2"><?php if ($canEdit && (int) $v['version'] !== (int) $t['version']) { ?><button class="btn btn-link btn-sm p-0" name="version" value="<?= (int) $v['version']; ?>" onclick="if(!confirm('Restore version <?= (int) $v['version']; ?>? It becomes a new version.'))return false;document.getElementById('smDo').value='restore'">Restore</button><?php } elseif ((int) $v['version'] === (int) $t['version']) { ?><span class="badge bg-light text-dark border">current</span><?php } ?></td></tr><?php } ?>
    </table></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body small"><div class="fw-semibold mb-1">Audit trail</div>
        <?php foreach ($audit as $a) { ?><div class="border-bottom py-1"><span class="text-muted"><?= smsm_e(substr($a['created_at'], 0, 16)); ?></span> · <strong><?= smsm_e($a['names'] ?? 'System'); ?></strong> · <?= smsm_e($a['action']); ?> — <?= smsm_e($a['details']); ?></div><?php } ?>
        <?php if (!$audit) { ?><div class="text-muted">No change yet.</div><?php } ?></div></div>
</div>
</form>
<script>
(function () {
    var body = document.getElementById('smBody'), prev = document.getElementById('smPrev'), cnt = document.getElementById('smCount'), warn = document.getElementById('smWarn');
    var allowed = <?= json_encode($allowed); ?>, required = <?= json_encode($c['required'] ?? []); ?>, MAX = <?= (int) SMS_MAX_LENGTH; ?>;
    var gsm = /^[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&'()*+,\-.\/0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà^{}\\\[~\]|€]*$/;
    function vals() { var v = {}; document.querySelectorAll('.sm-sample').forEach(function (i) { v[i.getAttribute('data-k')] = i.value; }); return v; }
    function render(t, v) { // same rules as the server (sms_render)
        t = t.replace(/\{([a-z0-9_]+)\}/gi, function (m, k) { return v[k] !== undefined ? v[k] : ''; });
        return t.replace(/[ \t]{2,}/g, ' ').replace(/ +([,.!?:;])/g, '$1').replace(/[ \t]+\n/g, '\n').trim();
    }
    function update() {
        var t = render(body.value.replace(/\r\n/g, '\n'), vals()); prev.textContent = t || '(empty)';
        var isG = gsm.test(t), n = isG ? t.length + (t.match(/[\^{}\\\[~\]|€]/g) || []).length : t.length, seg = !n ? 0 : (isG ? (n <= 160 ? 1 : Math.ceil(n / 153)) : (n <= 70 ? 1 : Math.ceil(n / 67)));
        cnt.innerHTML = '<strong>' + n + '</strong> characters · <strong>' + seg + '</strong> SMS segment' + (seg === 1 ? '' : 's') + ' · ' + (isG ? 'standard characters (160 per SMS)' : '<span class="text-warning">special characters (only 70 per SMS)</span>') + (n > MAX ? ' · <span class="text-danger fw-semibold">too long: max ' + MAX + '</span>' : '');
        var used = (body.value.match(/\{([a-z0-9_]+)\}/gi) || []).map(function (x) { return x.slice(1, -1); }), bad = used.filter(function (x) { return allowed.indexOf(x) < 0; }), miss = required.filter(function (x) { return used.indexOf(x) < 0; });
        warn.textContent = (bad.length ? 'Unknown placeholder(s): {' + bad.join('}, {') + '}. ' : '') + (miss.length ? 'Required: {' + miss.join('}, {') + '}.' : '');
    }
    body.addEventListener('input', update); document.querySelectorAll('.sm-sample').forEach(function (i) { i.addEventListener('input', update); });
    document.querySelectorAll('[data-ph]').forEach(function (b) { b.addEventListener('click', function () { if (body.disabled) return; var a = body.selectionStart, z = body.selectionEnd, p = b.getAttribute('data-ph'); body.value = body.value.slice(0, a) + p + body.value.slice(z); body.focus(); body.selectionStart = body.selectionEnd = a + p.length; update(); }); });
    update();
})();
</script>
<?php unset($_SESSION['smsm_draft_' . $ev]); include __DIR__ . '/../../includes/footer.php'; ?>