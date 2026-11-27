<?php
/** NEW FILE: modules/staff_cards/settings.php — categories on/off, rotation interval, stock display policy, automatic content. */
require __DIR__ . '/common.php';
sdc_require($conn, 'settings');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sdc_csrf_ok()) { sdc_go('settings.php', 'error', 'The form expired. Please try again.'); }
    $new = [];
    foreach (array_keys(SDC_CATS) as $k) { $new['cat_' . $k] = empty($_POST['cat_' . $k]) ? '0' : '1'; }
    if (!array_filter($new)) { sdc_go('settings.php', 'error', 'Keep at least one category switched on (or the carousel disappears from the Dashboard).'); }
    $sec = (int) ($_POST['rotation_seconds'] ?? 120);
    if ($sec < 10 || $sec > 3600) { sdc_go('settings.php', 'error', 'The rotation interval must be between 10 seconds and 60 minutes.'); }
    $new['rotation_seconds'] = (string) $sec;
    $new['stock_policy'] = ($_POST['stock_policy'] ?? '') === 'label' ? 'label' : 'exclude';
    foreach (['auto_products', 'auto_services', 'auto_employee', 'employee_photos'] as $k) { $new[$k] = empty($_POST[$k]) ? '0' : '1'; }
    $new['playlist_size'] = (string) max(4, min(40, (int) ($_POST['playlist_size'] ?? 12)));
    $chg = [];
    foreach ($new as $k => $v) { $old = sdc_get($conn, $k); if ($old !== $v) { sdc_set($conn, $k, $v); $chg[] = $k . ': ' . $old . ' → ' . $v; } }
    if ($chg) { sdc_audit($conn, null, 'settings', implode('; ', $chg)); }
    sdc_go('settings.php', 'success', $chg ? 'Settings saved.' : 'Nothing changed.');
}
$g = function ($k) use ($conn) { return sdc_get($conn, $k); };
$log = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sdc_audit ORDER BY id DESC LIMIT 40"), MYSQLI_ASSOC);
$names = sdc_user_names($conn);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
sdc_tabs($conn, 'settings.php', 'Settings');
?>
<form method="post" class="card mb-3"><div class="card-body" style="max-width:820px"><?= sdc_csrf_field(); ?>
    <h6 class="mb-1">Categories in the carousel</h6>
    <div class="row g-1 mb-3"><?php foreach (SDC_CATS as $k => [$n, $i, $col]) { ?><div class="col-md-6"><label class="small"><input type="checkbox" name="cat_<?= $k; ?>" value="1" <?= $g('cat_' . $k) === '1' ? 'checked' : ''; ?>> <i class="bi <?= $i; ?>" style="color:<?= $col; ?>"></i> <?= sdc_e($n); ?></label></div><?php } ?></div>
    <div class="row g-2 mb-3">
        <div class="col-md-4"><label class="form-label small mb-0">Rotation interval (seconds)</label><input type="number" name="rotation_seconds" min="10" max="3600" class="form-control form-control-sm" value="<?= (int) $g('rotation_seconds'); ?>"><div class="form-text">Default 120 (2 minutes).</div></div>
        <div class="col-md-4"><label class="form-label small mb-0">Items per round</label><input type="number" name="playlist_size" min="4" max="40" class="form-control form-control-sm" value="<?= (int) $g('playlist_size'); ?>"><div class="form-text">After a round, fresh content is loaded.</div></div>
        <div class="col-md-4"><label class="form-label small mb-0">Products with zero stock</label><select name="stock_policy" class="form-select form-select-sm"><option value="exclude" <?= $g('stock_policy') === 'exclude' ? 'selected' : ''; ?>>Hide them</option><option value="label" <?= $g('stock_policy') === 'label' ? 'selected' : ''; ?>>Show them labelled "Out of stock"</option></select><div class="form-text">"Show" applies to products with a card; automatic ones are always in stock.</div></div>
    </div>
    <h6 class="mb-1">Automatic content</h6>
    <label class="small d-block"><input type="checkbox" name="auto_products" value="1" <?= $g('auto_products') === '1' ? 'checked' : ''; ?>> Also show active products in stock that have no card (live name, category, stock, price, description)</label>
    <label class="small d-block"><input type="checkbox" name="auto_services" value="1" <?= $g('auto_services') === '1' ? 'checked' : ''; ?>> Also show active RM Offerings services that have no card</label>
    <label class="small d-block"><input type="checkbox" name="auto_employee" value="1" <?= $g('auto_employee') === '1' ? 'checked' : ''; ?>> Show the final Best Employee result of last month from the approved KPI results, when management has not published a recognition</label>
    <label class="small d-block mb-3"><input type="checkbox" name="employee_photos" value="1" <?= $g('employee_photos') === '1' ? 'checked' : ''; ?>> Show employee profile photos in recognitions (each recognition can also hide it)</label>
    <button class="btn btn-primary btn-sm">Save settings</button>
</div></form>
<h6>Activity (audit trail)</h6>
<div class="card"><div class="table-responsive"><table class="table table-sm sdc-tbl mb-0"><thead><tr><th>When</th><th>Who</th><th>Action</th><th>What</th><th>Reason</th></tr></thead><tbody>
<?php foreach ($log as $a) { ?><tr><td class="text-nowrap"><?= sdc_e(date('d M Y H:i', strtotime($a['created_at']))); ?></td><td><?= sdc_e($a['user_id'] ? ($names[(int) $a['user_id']] ?? '#' . $a['user_id']) : 'System'); ?></td><td><?= sdc_e($a['action']); ?></td><td class="small"><?= sdc_e($a['detail']); ?></td><td class="small"><?= sdc_e($a['reason']); ?></td></tr><?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>