<?php
// NEW FILE: modules/budget/settings.php — budget alert thresholds (alerts inform; they never replace the fund blocks).
require __DIR__ . '/budget_common.php';
if (!access_can('budget.manage', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $w = (float) $_POST['alert_warn']; $c = (float) $_POST['alert_critical'];
    if ($w <= 0 || $c <= $w || $c >= 100) { header('Location: settings.php?error=' . urlencode('Use a warning level below the critical level, and both below 100%.')); exit; }
    $old = ['warn' => bdg_setting($conn, 'alert_warn', '70'), 'critical' => bdg_setting($conn, 'alert_critical', '90')];
    $s = mysqli_prepare($conn, 'INSERT INTO bdg_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
    foreach (['alert_warn' => $w, 'alert_critical' => $c] as $k => $v) { $v = (string) $v; mysqli_stmt_bind_param($s, 'ss', $k, $v); mysqli_stmt_execute($s); }
    bdg_audit($conn, 'Alert thresholds changed', null, $old, ['warn' => $w, 'critical' => $c], trim((string) ($_POST['reason'] ?? '')) ?: null, $me);
    header('Location: settings.php?success=' . urlencode('Saved.')); exit;
}
include '../../includes/header.php'; include '../../includes/sidebar.php';
bdg_tabs('settings.php');
?>
<form method="POST" class="card border-0 shadow-sm" style="max-width:560px;"><div class="card-body">
    <div class="row g-2 mb-2"><div class="col-6"><label class="form-label small text-muted">Budget Warning at (%)</label><input type="number" step="1" name="alert_warn" class="form-control form-control-sm" value="<?= $e(bdg_setting($conn, 'alert_warn', '70')); ?>"></div>
        <div class="col-6"><label class="form-label small text-muted">Critical Warning at (%)</label><input type="number" step="1" name="alert_critical" class="form-control form-control-sm" value="<?= $e(bdg_setting($conn, 'alert_critical', '90')); ?>"></div></div>
    <div class="small text-muted mb-2">Budget Exhausted is always at 100%, and Overspending Alert above 100%. Each alert is sent once per budget to the Managing Director (Administrators) and the department's managers. Alerts never replace the spending blocks: a fund without enough available money always refuses the expense.</div>
    <input name="reason" class="form-control form-control-sm mb-2" placeholder="Reason for the change">
    <button class="btn btn-primary btn-sm">Save</button>
</div></form>
<?php include '../../includes/footer.php'; ?>