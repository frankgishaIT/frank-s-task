<?php
/**
 * NEW FILE: modules/marketing/settings.php — MARKETING SETTINGS: policies not yet final, with their documented defaults.
 * Changes need a reason, are audited, and apply to the future only (approved commissions keep their original basis).
 */
require __DIR__ . '/mkt_common.php';
if (!access_can('marketing.manage', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$F = ['commission_rate' => ['Default acquisition commission (% of eligible net profit)', 'number', 'Proposed default 20%. Rules by product, campaign, role or employee are added in stage 3.'],
      'commission_months' => ['Commission period (months after the first verified payment)', 'number', 'Default 3 months: rewards bringing a customer who keeps buying, without becoming a permanent charge. 0 = first paid sale only.'],
      'partial_policy' => ['Partial payments', 'policy', 'Default: only fully paid sales count (same rule as Loyalty Points).'],
      'ai_enabled' => ['MY MOTIVE AI', 'onoff', 'Switch the AI Copilot on or off for everyone. The service key itself is set on the server (config/ai.php).'],
      'behind_tolerance' => ['"Behind target" alert: points below the share of time elapsed', 'number', 'Default 15: e.g. 50% of the time passed and the weakest target under 35%.'],
      'score_weights' => ['Performance points (one per line: item=points)', 'weights', 'Items: lead, followup, qualified, conversion, paying, activity_on_time, activity_late.'],
      'channels' => ['Marketing channels (one per line)', 'list', ''], 'lead_sources' => ['Lead sources (one per line)', 'list', ''], 'customer_types' => ['Customer types (one per line)', 'list', '']];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim((string) ($_POST['reason'] ?? ''));
    try {
        if (mb_strlen($reason) < 5) { throw new RuntimeException('Write the reason for the change (e.g. management decision of 10 Nov 2026).'); }
        $changed = [];
        $s = mysqli_prepare($conn, 'INSERT INTO mkt_settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
        foreach ($F as $k => [$l, $t]) {
            $v = trim((string) ($_POST[$k] ?? ''));
            if ($t === 'number') { if (!is_numeric($v) || (float) $v < 0 || ($k === 'commission_rate' && (float) $v > 100)) { throw new RuntimeException('Check "' . $l . '".'); } $v = (string) (float) $v; }
            if ($t === 'onoff') { $v = $v === '0' ? '0' : '1'; }
            if ($t === 'policy') { $v = $v === 'proportional' ? 'proportional' : 'full'; }
            if ($t === 'weights') {
                $ok = []; foreach (preg_split('/\r?\n/', $v) as $line) { $line = trim($line); if ($line === '') { continue; }
                    if (!preg_match('/^(lead|followup|qualified|conversion|paying|activity_on_time|activity_late)\s*=\s*\d+(\.\d+)?$/', $line)) { throw new RuntimeException('Performance points: "' . $line . '" is not valid (example: paying=10).'); }
                    $ok[] = preg_replace('/\s+/', '', $line); }
                $v = implode("\n", $ok);
            }
            if ($t === 'list') { $v = implode("\n", array_values(array_unique(array_filter(array_map('trim', preg_split('/\r?\n/', $v)), 'strlen')))); if ($v === '') { throw new RuntimeException('"' . $l . '" cannot be empty.'); } }
            $old = mkt_setting($conn, $k);
            if ($old !== $v) { $changed[$k] = [$old, $v]; mysqli_stmt_bind_param($s, 'ss', $k, $v); mysqli_stmt_execute($s); }
        }
        if (!$changed) { throw new RuntimeException('Nothing changed.'); }
        mkt_audit($conn, 'Marketing settings changed: ' . implode(', ', array_keys($changed)), 'SETTINGS', array_map(function ($x) { return $x[0]; }, $changed), array_map(function ($x) { return $x[1]; }, $changed), $reason, $me);
        header('Location: settings.php?success=' . urlencode(count($changed) . ' setting(s) changed. They apply from now on; approved commissions are not affected.')); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('settings.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<form method="POST" class="card border-0 shadow-sm" style="max-width:900px;"><div class="card-body row g-3">
    <?php foreach ($F as $k => [$l, $t, $help]) { $v = $_POST[$k] ?? mkt_setting($conn, $k); ?>
    <div class="<?= in_array($t, ['list', 'weights'], true) ? 'col-md-4' : 'col-md-6'; ?>"><label class="form-label small fw-semibold mb-1"><?= $e($l); ?></label>
        <?php if ($t === 'list' || $t === 'weights') { ?><textarea name="<?= $k; ?>" rows="8" class="form-control form-control-sm"><?= $e($v); ?></textarea>
        <?php } elseif ($t === 'onoff') { ?><select name="<?= $k; ?>" class="form-select form-select-sm"><option value="1" <?= $v !== '0' ? 'selected' : ''; ?>>On</option><option value="0" <?= $v === '0' ? 'selected' : ''; ?>>Off</option></select>
        <?php } elseif ($t === 'policy') { ?><select name="<?= $k; ?>" class="form-select form-select-sm"><option value="full" <?= $v === 'full' ? 'selected' : ''; ?>>Only fully paid sales count</option><option value="proportional" <?= $v === 'proportional' ? 'selected' : ''; ?>>In proportion to each verified payment</option></select>
        <?php } else { ?><input type="number" step="0.01" min="0" name="<?= $k; ?>" value="<?= $e($v); ?>" class="form-control form-control-sm"><?php } ?>
        <?php if ($help) { ?><div class="form-text"><?= $e($help); ?> Default: <?= $e(str_replace("\n", ', ', MKT_DEFAULTS[$k])); ?>.</div><?php } ?></div><?php } ?>
    <div class="col-12"><label class="form-label small fw-semibold mb-1">Reason for the change *</label><input name="reason" class="form-control form-control-sm" required></div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Save settings</button> <span class="small text-muted">Applies from now on. Approved commissions keep the rate and basis they were approved with.</span></div>
</div></form>
<?php include '../../includes/footer.php'; ?>