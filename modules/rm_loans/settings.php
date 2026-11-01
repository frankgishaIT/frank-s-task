<?php
// NEW FILE: modules/rm_loans/settings.php — every loan figure and text is a setting (no code change needed).
require __DIR__ . '/loans_common.php';
if (!access_can('rm_loans.manage', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$fields = ['point_value' => ['RWF of profit per Loyalty Point', 'number'], 'unsecured_pct' => ['Unsecured limit, % of verified profit', 'number'],
    'monthly_rate' => ['Monthly interest rate %', 'number'], 'interest_method' => ['Interest calculation', 'method'], 'fee_pct' => ['Processing fee, % of the principal', 'number'],
    'late_fee_pct' => ['Late fee, % of the late amount (shown in the agreement)', 'number'], 'min_amount' => ['Minimum loan (RWF)', 'number'], 'max_amount' => ['Maximum loan (RWF)', 'number'],
    'periods' => ['Repayment periods offered (months, comma-separated)', 'text'], 'frequencies' => ['Repayment frequencies (Monthly, Weekly)', 'text'],
    'enabled' => ['RM Loans visible in the Customer Portal', 'bool'], 'terms' => ['Loan terms (shown before submission and in the agreement)', 'textarea'], 'privacy' => ['Privacy notice', 'textarea']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim((string) ($_POST['reason'] ?? ''));
    if (mb_strlen($reason) < 5) { header('Location: settings.php?error=' . urlencode('Write the reason for the change (it is kept in the audit log).')); exit; }
    $changes = [];
    $save = function ($k, $v) use ($conn, &$changes) {
        $old = cl_setting($conn, $k);
        if ((string) $old === (string) $v) { return; }
        $s = mysqli_prepare($conn, 'INSERT INTO cl_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        mysqli_stmt_bind_param($s, 'ss', $k, $v); mysqli_stmt_execute($s);
        $changes[] = $k . ': ' . (strlen($old) > 40 ? '(text)' : $old) . ' → ' . (strlen($v) > 40 ? '(text)' : $v);
    };
    foreach ($fields as $k => [$l, $t]) {
        $v = $t === 'bool' ? (!empty($_POST[$k]) ? '1' : '0') : trim((string) ($_POST[$k] ?? ''));
        if ($t === 'number') { $v = (string) max(0, (float) $v); }
        if ($t === 'method') { $v = $v === 'reducing' ? 'reducing' : 'flat'; }
        $save($k, $v);
    }
    // Testing mode: switching it OFF (real lending) needs an explicit confirmation.
    $testing = !empty($_POST['testing_mode']) ? '1' : '0';
    if ($testing === '0' && cl_testing($conn) && ($_POST['confirm_live'] ?? '') !== 'LENDING AUTHORISED') {
        header('Location: settings.php?error=' . urlencode('To switch testing mode off, type LENDING AUTHORISED to confirm that RISE MOTIVE has regulatory clearance or a licensed financial partner.')); exit;
    }
    $save('testing_mode', $testing);
    if ($changes) { cl_audit($conn, null, 'Settings changed: ' . implode('; ', $changes), null, null, $reason, $me); }
    header('Location: settings.php?success=' . urlencode($changes ? count($changes) . ' setting(s) changed and recorded.' : 'Nothing changed.')); exit;
}
include '../../includes/header.php'; include '../../includes/sidebar.php';
cl_tabs('settings.php');
?>
<form method="POST" class="card border-0 shadow-sm" style="max-width:860px;"><div class="card-body row g-3">
    <div class="col-12"><div class="alert <?= cl_testing($conn) ? 'alert-warning' : 'alert-danger'; ?> mb-0">
        <label><input type="checkbox" name="testing_mode" value="1" class="form-check-input me-2" <?= cl_testing($conn) ? 'checked' : ''; ?>><strong>Testing mode</strong> (assessment only: applications, offers and agreements work, but no money can be disbursed)</label>
        <?php if (cl_testing($conn)) { ?><div class="small mt-2">Switch this off only once RISE MOTIVE holds the required lending authorisation (BNR) or works through a licensed financial partner. To switch it off, untick it and type <strong>LENDING AUTHORISED</strong>: <input name="confirm_live" class="form-control form-control-sm mt-1" style="max-width:240px;" autocomplete="off"></div><?php } ?>
    </div></div>
    <?php foreach ($fields as $k => [$l, $t]) { $v = cl_setting($conn, $k); ?>
    <div class="<?= $t === 'textarea' ? 'col-12' : 'col-md-6'; ?>"><label class="form-label small text-muted mb-1"><?= $e($l); ?></label>
        <?php if ($t === 'textarea') { ?><textarea name="<?= $k; ?>" rows="4" class="form-control form-control-sm"><?= $e($v); ?></textarea>
        <?php } elseif ($t === 'bool') { ?><div><input type="checkbox" name="<?= $k; ?>" value="1" class="form-check-input" <?= $v === '1' ? 'checked' : ''; ?>></div>
        <?php } elseif ($t === 'method') { ?><select name="<?= $k; ?>" class="form-select form-select-sm"><option value="flat" <?= $v === 'flat' ? 'selected' : ''; ?>>Flat (on the principal, per month)</option><option value="reducing" <?= $v === 'reducing' ? 'selected' : ''; ?>>Reducing balance (equal instalments)</option></select>
        <?php } else { ?><input <?= $t === 'number' ? 'type="number" step="0.01" min="0"' : 'type="text"'; ?> name="<?= $k; ?>" class="form-control form-control-sm" value="<?= $e($v); ?>"><?php } ?></div>
    <?php } ?>
    <div class="col-12"><label class="form-label small text-muted mb-1">Reason for the change *</label><input name="reason" class="form-control form-control-sm" required placeholder="e.g. Board decision of 10 Nov 2026"></div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Save settings</button> <span class="small text-muted">Changes apply to new applications and offers; offers already made keep their terms.</span></div>
</div></form>
<?php include '../../includes/footer.php'; ?>