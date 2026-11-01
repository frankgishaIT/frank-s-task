<?php
// NEW FILE: modules/rm_invest/settings.php — RM Invest settings (testing mode, default fee, texts).
require __DIR__ . '/invest_common.php';
if (!access_can('rm_invest.manage', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if (mb_strlen($reason) < 5) { header('Location: settings.php?error=' . urlencode('Write the reason for the change.')); exit; }
    $testing = !empty($_POST['testing_mode']) ? '1' : '0';
    if ($testing === '0' && inv_testing($conn) && ($_POST['confirm_live'] ?? '') !== 'INVESTMENTS AUTHORISED') { header('Location: settings.php?error=' . urlencode('To switch testing mode off, type INVESTMENTS AUTHORISED to confirm legal clearance.')); exit; }
    $vals = ['enabled' => !empty($_POST['enabled']) ? '1' : '0', 'testing_mode' => $testing, 'mgmt_fee_pct' => (string) min(100, max(0, (float) $_POST['mgmt_fee_pct'])),
             'payment_instructions' => trim((string) $_POST['payment_instructions']), 'agreement_general' => trim((string) $_POST['agreement_general'])];
    $s = mysqli_prepare($conn, 'INSERT INTO inv_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    $changed = [];
    foreach ($vals as $k => $v) { if (inv_setting($conn, $k) !== $v) { $changed[] = $k; } mysqli_stmt_bind_param($s, 'ss', $k, $v); mysqli_stmt_execute($s); }
    if ($changed) { inv_audit($conn, null, null, 'Settings changed: ' . implode(', ', $changed), $reason, $me); }
    header('Location: settings.php?success=' . urlencode('Saved.')); exit;
}
include '../../includes/header.php'; include '../../includes/sidebar.php';
inv_tabs('settings.php');
?>
<form method="POST" class="card border-0 shadow-sm" style="max-width:860px;"><div class="card-body">
    <div class="alert <?= inv_testing($conn) ? 'alert-warning' : 'alert-danger'; ?>"><label><input type="checkbox" name="testing_mode" value="1" class="form-check-input me-2" <?= inv_testing($conn) ? 'checked' : ''; ?>><strong>Testing mode</strong>: opportunities can be published and customers can apply, but contributions cannot be confirmed and no payout is made.</label>
        <?php if (inv_testing($conn)) { ?><div class="small mt-2">Inviting customers to invest money in projects is usually regulated (in Rwanda, the Capital Market Authority). Switch this off only with your legal advisor's confirmation: untick and type <strong>INVESTMENTS AUTHORISED</strong> <input name="confirm_live" class="form-control form-control-sm mt-1" style="max-width:260px;" autocomplete="off"></div><?php } ?></div>
    <label class="d-block mb-2"><input type="checkbox" name="enabled" value="1" class="form-check-input me-2" <?= inv_setting($conn, 'enabled') === '1' ? 'checked' : ''; ?>>RM Invest visible in the Customer Portal</label>
    <label class="form-label small text-muted">Default administrative &amp; management cost (% of realized profit) for new projects</label><input type="number" step="0.1" name="mgmt_fee_pct" class="form-control form-control-sm mb-2" style="max-width:160px;" value="<?= $e(inv_setting($conn, 'mgmt_fee_pct')); ?>">
    <label class="form-label small text-muted">Payment instructions (shown to approved investors with their contribution reference)</label><textarea name="payment_instructions" rows="4" class="form-control form-control-sm mb-2"><?= $e(inv_setting($conn, 'payment_instructions')); ?></textarea>
    <label class="form-label small text-muted">General agreement terms (added to every project agreement)</label><textarea name="agreement_general" rows="5" class="form-control form-control-sm mb-2"><?= $e(inv_setting($conn, 'agreement_general')); ?></textarea>
    <input name="reason" class="form-control form-control-sm mb-2" required placeholder="Reason for the change">
    <button class="btn btn-primary btn-sm">Save</button>
</div></form>
<?php include '../../includes/footer.php'; ?>