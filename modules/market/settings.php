<?php
/**
 * NEW FILE: modules/market/settings.php — DEMAND THRESHOLDS set by management (no fixed numbers in the code):
 * what counts as High / Moderate / Growing demand, when a product qualifies for market analysis, and when repeated
 * requests raise an alert. Every change is kept in the history.
 */
require __DIR__ . '/common.php';
mi_require($conn, 'settings');
$F = [
    'High demand' => ['high_customers' => 'Different customers (at least)', 'high_requests' => 'Requests (at least)', 'high_quantity' => '… or total quantity (at least)'],
    'Moderate demand' => ['moderate_customers' => 'Different customers (at least)', 'moderate_requests' => '… or requests (at least)'],
    'Growing demand' => ['growth_days' => 'Compare the last … days with the … days before', 'growth_min' => 'Requests in the recent period (at least)', 'growth_factor' => 'Times more than the period before (e.g. 1.5)'],
    'Feasibility and recommendations' => ['target_margin' => 'Target gross margin % (suggested selling price)', 'min_margin' => 'Minimum gross margin % to recommend a product', 'conversion_pct' => '% of requested quantity expected to be bought (requests are not sales)',
        'sales_period_days' => 'Stock should sell within … days', 'busy_tasks' => 'An employee is busy from … open tasks', 'occasional_days' => 'A service is "occasional" when rarely requested in the last … days'],
    'Market analysis and alerts' => ['analysis_min_requests' => 'A product qualifies for market analysis after … requests', 'alert_repeat_count' => 'Alert when requested … times', 'alert_repeat_days' => '… within … days',
        'oos_alert_count' => 'Alert when an existing product was out of stock for … customers (in the same days)', 'approval_wait_days' => 'Remind management when a recommendation waits … days',
        'approve_order_days' => 'Alert when an approved product is not ordered after … days', 'fast_factor' => '"Selling faster than expected" from … × the expected pace'],
];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mi_csrf_ok()) { mi_go('settings.php', 'error', 'The page had expired.'); }
    $chg = [];
    foreach ($F as $g) { foreach ($g as $k => $l) {
        $v = (string) ($_POST[$k] ?? ''); if (!is_numeric($v) || (float) $v <= 0 || (float) $v > 100000) { mi_go('settings.php', 'error', 'Every value must be a positive number.'); }
        $v = rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
        $old = mi_setting($conn, $k); if ($old !== $v) { mi_setting_set($conn, $k, $v); $chg[] = $k . ' ' . $old . ' → ' . $v; }
    } }
    $on = !empty($_POST['alerts_on']) ? '1' : '0'; if (mi_setting($conn, 'alerts_on') !== $on) { mi_setting_set($conn, 'alerts_on', $on); $chg[] = 'alerts ' . ($on === '1' ? 'on' : 'off'); }
    if (($_POST['use_learned'] ?? '') !== '' && is_numeric($_POST['use_learned'])) { $lv = (string) max(1, min(300, round((float) $_POST['use_learned']))); if (mi_setting($conn, 'conversion_pct') !== $lv) { mi_setting_set($conn, 'conversion_pct', $lv); $chg[] = 'conversion_pct → ' . $lv . ' (learned from launched products)'; } }
    if ($chg) { mi_audit($conn, null, null, 'settings', implode('; ', $chg)); }
    mi_go('settings.php', 'success', $chg ? 'Thresholds saved.' : 'Nothing changed.');
}
$hist = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM mi_audit a LEFT JOIN users u ON u.id = a.user_id WHERE a.action = 'settings' ORDER BY a.id DESC LIMIT 15"), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'settings.php', 'Market Insights settings');
?>
<form method="POST" style="max-width:720px"><?= mi_csrf_field(); ?>
<p class="small text-muted">These numbers decide the demand level shown for each product and service. A customer request is a sign of demand, not a confirmed sale: set levels that fit RISE MOTIVE's size.</p>
<?php foreach ($F as $g => $fields) { ?><div class="card border-0 shadow-sm mb-2"><div class="card-body py-2"><div class="fw-semibold small mb-1"><?= $g; ?></div>
    <?php foreach ($fields as $k => $l) { ?><div class="d-flex justify-content-between align-items-center gap-2 py-1 border-bottom small"><label for="<?= $k; ?>"><?= mi_e($l); ?></label><input id="<?= $k; ?>" name="<?= $k; ?>" type="number" step="any" min="0.01" class="form-control form-control-sm text-end" style="width:100px" value="<?= mi_e(mi_setting($conn, $k)); ?>"></div><?php } ?>
</div></div><?php } ?>
<div class="card border-0 shadow-sm mb-2"><div class="card-body py-2 small"><label><input type="checkbox" name="alerts_on" value="1" class="form-check-input me-1" <?= mi_setting($conn, 'alerts_on') !== '0' ? 'checked' : ''; ?>>Send smart alerts to Administrators and Managers (consolidated, at most one notification per hour)</label>
    <?php $lr = mi_learning($conn); if ($lr['n']) { ?><div class="mt-2">Learned from <?= (int) $lr['n']; ?> launched product(s): customers bought <strong><?= mi_num(round($lr['rate'])); ?>%</strong> of what they requested. <label class="ms-1"><input type="checkbox" name="use_learned" value="<?= round($lr['rate']); ?>" class="form-check-input me-1">Use it as the conversion rate</label></div><?php } ?>
</div></div>
<button class="btn btn-primary btn-sm px-4 mt-1">Save</button>
</form>
<?php if ($hist) { ?><div class="small mt-4" style="max-width:720px"><div class="fw-semibold mb-1">Changes</div><?php foreach ($hist as $h) { ?><div class="border-bottom py-1"><span class="text-muted"><?= mi_e(substr($h['created_at'], 0, 16)); ?></span> · <?= mi_e($h['names']); ?> · <?= mi_e($h['details']); ?></div><?php } ?></div><?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>