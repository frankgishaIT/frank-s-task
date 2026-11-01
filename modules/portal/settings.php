<?php
// NEW FILE: modules/portal/settings.php — portal on/off, self-activation, SMS on answers, notice to all customers.
require __DIR__ . '/portal_common.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    portal_set_setting($conn, 'enabled', !empty($_POST['enabled']) ? '1' : '0');
    portal_set_setting($conn, 'self_activation', !empty($_POST['self_activation']) ? '1' : '0');
    portal_set_setting($conn, 'sms_on_reply', !empty($_POST['sms_on_reply']) ? '1' : '0');
    portal_set_setting($conn, 'notice', mb_substr(trim((string) ($_POST['notice'] ?? '')), 0, 1000));
    portal_log($conn, null, 'settings_changed', 'by ' . ($_SESSION['user_name'] ?? 'staff'));
    header('Location: settings.php?success=' . urlencode('Settings saved.')); exit;
}
$on = function ($k, $d = '1') use ($conn) { return portal_setting($conn, $k, $d) === '1' ? 'checked' : ''; };
include '../../includes/header.php'; include '../../includes/sidebar.php';
portal_staff_tabs('settings.php'); portal_staff_alerts();
?>
<form method="POST" class="card border-0 shadow-sm" style="max-width:720px;"><div class="card-body">
    <label class="d-block mb-2"><input type="checkbox" name="enabled" value="1" class="form-check-input me-2" <?= $on('enabled'); ?>><strong>Portal open</strong> (when off, customers cannot sign in)</label>
    <label class="d-block mb-2"><input type="checkbox" name="self_activation" value="1" class="form-check-input me-2" <?= $on('self_activation'); ?>><strong>Self-activation</strong>: any customer with a phone number can activate their account by SMS code (blocked customers excepted)</label>
    <label class="d-block mb-3"><input type="checkbox" name="sms_on_reply" value="1" class="form-check-input me-2" <?= $on('sms_on_reply'); ?>><strong>SMS when staff answer a request</strong></label>
    <label class="form-label small text-muted">Notice shown to all customers on their home page (leave empty for none)</label>
    <textarea name="notice" class="form-control mb-3" rows="3" maxlength="1000"><?= $e(portal_setting($conn, 'notice')); ?></textarea>
    <button class="btn btn-primary btn-sm">Save settings</button>
    <hr><div class="small text-muted">Customer address: <strong>customers.risemotive.rw</strong> once the server points it to the <code>portal</code> folder; until then <strong><?= $e(($_SERVER['HTTP_HOST'] ?? '') . (defined('BASE_URL') ? BASE_URL : '')); ?>/portal/</strong>.</div>
</div></form>
<?php include '../../includes/footer.php'; ?>