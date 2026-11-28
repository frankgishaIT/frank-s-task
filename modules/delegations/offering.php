<?php
/** NEW FILE: modules/delegations/offering.php — how one RM Offering is fulfilled: delegation settings, delivery standards, materials (cost of sales), qualified RM Taskers. */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM products WHERE id = ' . $id));
if (!$p) { dl_go('offerings.php', 'error', 'RM Offering not found.'); }
$o = dl_offering($conn, $id);
$back = 'offering.php?id=' . $id;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dl_require($conn, 'taskers');
    if (!dl_csrf_ok()) { dl_go($back, 'error', 'The form expired. Please try again.'); }
    $f = in_array($_POST['fulfilment'] ?? '', DL_FULFILMENT, true) ? $_POST['fulfilment'] : 'Internal';
    $h = function ($k) { $x = trim((string) ($_POST[$k] ?? '')); if ($x === '') { return null; } $v = max(0, (float) $x); return ($_POST[$k . '_unit'] ?? 'hours') === 'days' ? $v * 24 : $v; };
    $std = $h('std'); $max = $h('max'); $urg = $h('urgent');
    $allowed = $f === 'External Only' ? 1 : ($f === 'Delegatable' ? (empty($_POST['delegation_allowed']) ? 0 : 1) : 0);
    $rate = trim((string) ($_POST['expected_rate'] ?? '')) === '' ? null : max(0, min(100, (float) $_POST['expected_rate']));
    $cap = in_array($_POST['internal_capability'] ?? '', ['Yes', 'Partly', 'No'], true) ? $_POST['internal_capability'] : 'Yes';
    $plan = in_array($_POST['internalization_plan'] ?? '', ['None', 'Considering', 'Planned', 'In progress'], true) ? $_POST['internalization_plan'] : 'None';
    $mats = [];
    foreach ((array) ($_POST['mat_product'] ?? []) as $i => $mp) {
        $mp = (int) $mp; $mq = (float) ($_POST['mat_qty'][$i] ?? 0);
        if ($mp && $mq > 0 && $mp !== $id) { $mats[$mp] = ['product_id' => $mp, 'qty' => round(($mats[$mp]['qty'] ?? 0) + $mq, 4)]; }
    }
    $mats = array_values($mats);
    $qr = mb_substr(trim((string) ($_POST['quality_requirements'] ?? '')), 0, 2000) ?: null;
    $note = mb_substr(trim((string) ($_POST['internalization_note'] ?? '')), 0, 255) ?: null;
    $verify = empty($_POST['customer_verification']) ? 0 : 1;
    $rem = trim((string) ($_POST['reminder_hours'] ?? '')) === '' ? null : max(0, (float) $_POST['reminder_hours']);
    $err = '';
    if (in_array($f, ['Delegatable', 'External Only'], true) && (!$std || !$max)) { $err = 'A service that can be delegated needs its standard and maximum completion times.'; }
    elseif ($std && $max && $max < $std) { $err = 'The maximum completion time is shorter than the standard time.'; }
    elseif ($urg && $std && $urg > $std) { $err = 'The urgent completion time should be shorter than the standard time.'; }
    elseif ($f === 'Internal' && $cap === 'No') { $err = '"Internal" needs internal capability. Choose Delegatable, External Only or Not Available.'; }
    if ($err) { dl_go($back, 'error', $err); }
    $json = $mats ? json_encode($mats) : null;
    $now = dl_now(); $u = dl_uid();
    $s = mysqli_prepare($conn, 'INSERT INTO dl_offering_settings (product_id, fulfilment, internal_capability, delegation_allowed, expected_rate, std_hours, max_hours, urgent_hours, quality_requirements, customer_verification, reminder_hours, internalization_plan, internalization_note, materials, updated_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE fulfilment = VALUES(fulfilment), internal_capability = VALUES(internal_capability), delegation_allowed = VALUES(delegation_allowed),
        expected_rate = VALUES(expected_rate), std_hours = VALUES(std_hours), max_hours = VALUES(max_hours), urgent_hours = VALUES(urgent_hours), quality_requirements = VALUES(quality_requirements),
        customer_verification = VALUES(customer_verification), reminder_hours = VALUES(reminder_hours), internalization_plan = VALUES(internalization_plan), internalization_note = VALUES(internalization_note),
        materials = VALUES(materials), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)');
    mysqli_stmt_bind_param($s, 'issiddddsidsssis', $id, $f, $cap, $allowed, $rate, $std, $max, $urg, $qr, $verify, $rem, $plan, $note, $json, $u, $now);
    mysqli_stmt_execute($s);
    $chg = [];
    $new = ['fulfilment' => $f, 'internal_capability' => $cap, 'delegation_allowed' => $allowed, 'expected_rate' => $rate, 'std_hours' => $std, 'max_hours' => $max, 'urgent_hours' => $urg, 'customer_verification' => $verify, 'internalization_plan' => $plan, 'materials' => $json];
    foreach ($new as $k => $v) { if ((string) ($o[$k] ?? '') !== (string) ($v ?? '') && !(is_numeric($o[$k] ?? null) && is_numeric($v) && abs((float) $o[$k] - (float) $v) < 0.005)) { $chg[] = str_replace('_', ' ', $k) . ': ' . ($o[$k] ?? '—') . ' → ' . ($v ?? '—'); } }
    dl_audit($conn, 'offering', $id, 'settings', $p['product_name'] . ($chg ? ': ' . implode('; ', $chg) : ': saved (no change)'));
    dl_go($back, 'success', 'Delegation settings saved.');
}

$c = dl_candidates($conn, $id);
$mats = dl_materials($conn, $o);
$items = mysqli_fetch_all(mysqli_query($conn, "SELECT id, product_name, unit, buying_price FROM products WHERE item_type = 'Item' AND is_active = 1 AND id <> $id ORDER BY product_name"), MYSQLI_ASSOC);
$canEdit = dlc($conn, 'taskers');
$hv = function ($h) { if ($h === null || $h === '') { return ['', 'hours']; } $h = (float) $h; return fmod($h, 24) == 0 && $h >= 24 ? [$h / 24, 'days'] : [$h + 0, 'hours']; };
$unitSel = function ($name, $cur) { return '<select name="' . $name . '_unit" class="form-select" style="max-width:86px"><option value="hours"' . ($cur === 'hours' ? ' selected' : '') . '>hours</option><option value="days"' . ($cur === 'days' ? ' selected' : '') . '>days</option></select>'; };

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'offerings.php', $p['product_name'], dl_fulfilment_badge($o['fulfilment']));
?>
<div class="small text-muted mb-2"><?= $p['item_type'] === 'Service' ? 'Service' : 'Product'; ?> in RM Offerings · selling price <?= (float) $p['selling_price'] > 0 ? dl_money($p['selling_price']) : 'by quotation'; ?><?= (int) $p['is_active'] ? '' : ' · <span class="text-danger">inactive</span>'; ?></div>
<div class="row g-3">
<div class="col-xl-7">
<form method="post" class="card"><div class="card-body py-2"><?= dl_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>">
<fieldset <?= $canEdit ? '' : 'disabled'; ?>>
<div class="fw-semibold small mb-1">How it is fulfilled</div>
<div class="row g-2">
    <div class="col-md-6"><label class="form-label small mb-0">Fulfilment type</label><select name="fulfilment" class="form-select form-select-sm" id="ff"><?php foreach (DL_FULFILMENT as $f) { ?><option <?= $o['fulfilment'] === $f ? 'selected' : ''; ?> title="<?= dl_e(DL_FULFILMENT_HELP[$f]); ?>"><?= $f; ?></option><?php } ?></select><div class="form-text" id="ffHelp"><?= dl_e(DL_FULFILMENT_HELP[$o['fulfilment']]); ?></div></div>
    <div class="col-md-3"><label class="form-label small mb-0">Internal capability</label><select name="internal_capability" class="form-select form-select-sm"><?php foreach (['Yes', 'Partly', 'No'] as $x) { ?><option <?= $o['internal_capability'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small mb-0">Expected Tasker rate (%)</label><input type="number" step="0.01" min="0" max="100" name="expected_rate" class="form-control form-control-sm" value="<?= $o['expected_rate'] !== null ? dl_e((float) $o['expected_rate']) : ''; ?>"></div>
    <div class="col-12"><label class="small"><input type="checkbox" name="delegation_allowed" value="1" <?= (int) $o['delegation_allowed'] ? 'checked' : ''; ?>> Delegation to an RM Tasker is allowed (always on for External Only)</label></div>
</div>
<div class="fw-semibold small mt-2 mb-1">Delivery standard expected from RM Taskers</div>
<div class="row g-2">
    <?php foreach (['std' => ['Standard completion time', $o['std_hours']], 'max' => ['Maximum allowed time', $o['max_hours']], 'urgent' => ['Urgent completion time', $o['urgent_hours']]] as $k => [$l, $val]) { [$n, $un] = $hv($val); ?>
    <div class="col-md-4"><label class="form-label small mb-0"><?= $l; ?></label><div class="input-group input-group-sm"><input type="number" step="0.5" min="0" name="<?= $k; ?>" class="form-control" value="<?= dl_e($n); ?>"><?= $unitSel($k, $un); ?></div></div>
    <?php } ?>
    <div class="col-12"><label class="form-label small mb-0">Quality requirements</label><textarea name="quality_requirements" rows="2" class="form-control form-control-sm" placeholder="Minimum standards the work must meet"><?= dl_e($o['quality_requirements']); ?></textarea></div>
    <div class="col-md-6"><label class="small"><input type="checkbox" name="customer_verification" value="1" <?= (int) $o['customer_verification'] ? 'checked' : ''; ?>> Customer confirmation required before verification</label></div>
    <div class="col-md-6"><label class="form-label small mb-0">Deadline reminder (hours before; empty = <?= dl_e(dl_setting($conn, 'reminder_hours')); ?>)</label><input type="number" step="0.5" min="0" name="reminder_hours" class="form-control form-control-sm" value="<?= $o['reminder_hours'] !== null ? dl_e((float) $o['reminder_hours']) : ''; ?>"></div>
</div>
<div class="fw-semibold small mt-2 mb-1">Materials used per unit sold (cost of sales)</div>
<div id="mats">
<?php foreach (array_merge($mats, [['product_id' => 0, 'qty' => '']]) as $m) { ?>
    <div class="d-flex gap-1 mb-1"><select name="mat_product[]" class="form-select form-select-sm"><option value="">— material from stock —</option><?php foreach ($items as $it) { ?><option value="<?= (int) $it['id']; ?>" <?= (int) $m['product_id'] === (int) $it['id'] ? 'selected' : ''; ?>><?= dl_e($it['product_name']); ?> (<?= dl_money($it['buying_price']); ?>/<?= dl_e($it['unit'] ?: 'unit'); ?>)</option><?php } ?></select>
        <input name="mat_qty[]" type="number" step="0.01" min="0" class="form-control form-control-sm" style="max-width:110px" placeholder="qty" value="<?= dl_e($m['qty']); ?>"></div>
<?php } ?>
</div>
<?php if ($canEdit) { ?><button type="button" class="btn btn-link btn-sm px-0" onclick="var d=document.getElementById('mats'),r=d.lastElementChild.cloneNode(true);r.querySelectorAll('select,input').forEach(function(x){x.value='';});d.appendChild(r);">+ another material</button><?php } ?>
<?php if ($mats) { ?><div class="small text-muted">Cost of sales per unit from current buying prices: <b><?= dl_money(dl_materials_cost($mats)); ?></b>. The delegation uses the actual quantities and costs.</div><?php } ?>
<div class="fw-semibold small mt-2 mb-1">Internalization plan</div>
<div class="row g-2">
    <div class="col-md-4"><select name="internalization_plan" class="form-select form-select-sm"><?php foreach (['None', 'Considering', 'Planned', 'In progress'] as $x) { ?><option <?= $o['internalization_plan'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-md-8"><input name="internalization_note" class="form-control form-control-sm" value="<?= dl_e($o['internalization_note']); ?>" placeholder="e.g. train one designer by June"></div>
</div>
</fieldset>
<?php if ($canEdit) { ?><button class="btn btn-primary btn-sm mt-3">Save settings</button><?php } ?>
<?php if (!$o['configured']) { ?><div class="small text-muted mt-1">Not configured yet: treated as Internal.</div><?php } ?>
</div></form>
</div>
<div class="col-xl-5">
<div class="card"><div class="card-body py-2">
    <div class="d-flex justify-content-between align-items-center mb-1"><span class="fw-semibold small">RM Taskers who can provide it (<?= count($c['eligible']); ?>)</span><?php if ($c['eligible']) { ?><a href="compare.php?product=<?= $id; ?>" class="btn btn-outline-primary btn-sm py-0">Compare</a><?php } ?></div>
    <?php foreach ($c['eligible'] as $r) { ?>
    <div class="border-top py-1 small d-flex justify-content-between gap-2"><span><a href="tasker.php?id=<?= (int) $r['party']; ?>"><?= dl_e($r['display']); ?></a> <span class="text-muted"><?= dl_e($r['code']); ?></span><?= (int) $r['preferred'] ? ' <i class="bi bi-award text-warning" title="Preferred"></i>' : ''; ?><?= $r['limited'] ? ' <span class="dl-st">new</span>' : ''; ?></span>
        <span class="text-nowrap"><b><?= dl_pct($r['rate']); ?></b> · <?= dl_hours_text($r['hours']); ?></span></div>
    <?php } ?>
    <?php foreach ($c['other'] as $r) { ?><div class="border-top py-1 small text-muted d-flex justify-content-between gap-2"><span><a href="tasker.php?id=<?= (int) $r['party']; ?>" class="text-muted"><?= dl_e($r['display']); ?></a></span><span><?= dl_e($r['why']); ?></span></div><?php } ?>
    <?php if (!$c['eligible'] && !$c['other']) { ?><div class="small text-muted">No RM Tasker offers it yet. Add it on an RM Tasker's profile.</div><?php } ?>
    <?php if (dl_needs_tasker($o) && !$c['eligible']) { ?><div class="small text-danger mt-1">This offering needs an RM Tasker and none is eligible now: customers will be recorded as lost opportunities.</div><?php } ?>
</div></div>
</div>
</div>
<script>
(function () { var help = <?= json_encode(DL_FULFILMENT_HELP); ?>, s = document.getElementById('ff'); s.addEventListener('change', function () { document.getElementById('ffHelp').textContent = help[s.value]; }); })();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>