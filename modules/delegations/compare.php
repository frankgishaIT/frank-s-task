<?php
/** NEW FILE: modules/delegations/compare.php — compare the RM Taskers of one service and see the recommendation, with the expected payment and profit. */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$pid = (int) ($_GET['product'] ?? 0);
$offers = mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT p.id, p.product_name, p.selling_price FROM dl_tasker_offerings o JOIN products p ON p.id = o.product_id WHERE p.is_active = 1 ORDER BY p.product_name"), MYSQLI_ASSOC);
$p = $pid ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM products WHERE id = ' . $pid)) : null;
$num = function ($k) { $x = trim(str_replace(',', '', (string) ($_GET[$k] ?? ''))); return $x === '' ? null : max(0, (float) $x); };
$o = $p ? dl_offering($conn, $pid) : null;
$mats = $o ? dl_materials($conn, $o) : [];
$price = $num('price') ?? ($p && (float) $p['selling_price'] > 0 ? (float) $p['selling_price'] : null);
$cogs = $num('cogs') ?? ($mats ? dl_materials_cost($mats) : 0.0);
$direct = $num('direct') ?? 0.0;
$c = $p ? dl_candidates($conn, $pid, $price) : null;
$basis = dl_setting($conn, 'profit_basis');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'compare.php', 'Compare RM Taskers');
?>
<form class="row g-2 align-items-end mb-3" style="max-width:980px">
    <div class="col-md-4"><label class="form-label small mb-0">Service or product</label><select name="product" class="form-select form-select-sm" onchange="this.form.price.value='';this.form.cogs.value='';this.form.submit()"><option value="">Choose…</option><?php foreach ($offers as $x) { ?><option value="<?= (int) $x['id']; ?>" <?= $pid === (int) $x['id'] ? 'selected' : ''; ?>><?= dl_e($x['product_name']); ?></option><?php } ?></select></div>
    <div class="col-md-2"><label class="form-label small mb-0">Customer price (RWF)</label><input name="price" inputmode="numeric" class="form-control form-control-sm" value="<?= $price !== null ? (int) round($price) : ''; ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Cost of sales (RWF)</label><input name="cogs" inputmode="numeric" class="form-control form-control-sm" value="<?= (int) round($cogs); ?>"></div>
    <div class="col-md-2"><label class="form-label small mb-0">Other direct costs</label><input name="direct" inputmode="numeric" class="form-control form-control-sm" value="<?= (int) round($direct); ?>"></div>
    <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Compare</button></div>
</form>
<?php if (!$p) { ?><div class="text-muted small">Choose a service: every RM Tasker who offers it is compared on cost, speed, quality, reliability and availability.</div>
<?php } else { $w = $c['weights']; ?>
<?php if (!$c['eligible']) { ?><div class="alert alert-warning py-2 small">No RM Tasker can take <?= dl_e($p['product_name']); ?> now<?= $c['other'] ? ' (see the reasons below)' : ''; ?>. Record the customer's request as a lost opportunity so management sees the demand.</div><?php } else { ?>
<div class="alert alert-primary py-2 small mb-2"><b>Recommended:</b> <?= dl_e(dl_explain($c)); ?></div>
<div class="row g-2 mb-3">
<?php foreach (['cost' => ['Lowest cost', 'bi-cash-coin'], 'speed' => ['Fastest', 'bi-lightning'], 'quality' => ['Highest quality', 'bi-stars'], 'reliability' => ['Most reliable', 'bi-shield-check'], 'best' => ['Best overall', 'bi-trophy']] as $k => [$l, $i]) { $r = $c['picks'][$k]; if (!$r) { ?>
    <div class="col-6 col-lg"><div class="dl-kpi h-100"><div class="l"><i class="bi <?= $i; ?>"></i> <?= $l; ?></div><div class="small text-muted">Not enough history yet</div></div></div>
<?php continue; } ?>
    <div class="col-6 col-lg"><div class="dl-kpi h-100" style="<?= $k === 'best' ? 'border-color:#1E2FE0' : ''; ?>"><div class="l"><i class="bi <?= $i; ?>"></i> <?= $l; ?></div><div class="fw-semibold small"><?= dl_e($r['display']); ?></div>
        <div class="small text-muted"><?= $k === 'cost' ? dl_pct($r['rate']) : ($k === 'speed' ? dl_hours_text($r['hours']) : ($k === 'quality' ? number_format($r['q'], 1) . '/5' . ($r['q_src'] === 'verified' ? '' : ' (' . $r['q_src'] . ')') : ($k === 'reliability' ? round($r['rel'] * 100) . '%' : 'score ' . $r['score']))); ?></div></div></div>
<?php } ?>
</div>
<div class="card mb-2"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>RM Tasker</th><th class="text-end">Rate</th><th>Time</th><th>Quality</th><th>Reliability</th><th>Availability</th><th class="text-end">Score</th><?php if ($price !== null) { ?><th class="text-end">Tasker payment</th><th class="text-end">RISE MOTIVE keeps</th><?php } ?></tr></thead><tbody>
<?php foreach ($c['eligible'] as $n => $r) { $f = $price !== null ? dl_profit($price, $cogs, $direct, $r['rate'], $basis) : null; ?>
<tr class="<?= $n === 0 ? 'table-primary' : ''; ?>"><td><a href="tasker.php?id=<?= (int) $r['party']; ?>"><?= dl_e($r['display']); ?></a> <span class="small text-muted"><?= dl_e($r['code']); ?></span><?= (int) $r['preferred'] ? ' <span class="dl-st dl-gold">Preferred</span>' : ''; ?><?= $r['limited'] ? ' <span class="dl-st">New / limited history</span>' : ''; ?></td>
    <td class="text-end fw-semibold"><?= dl_pct($r['rate']); ?></td><td><?= dl_hours_text($r['hours']); ?></td>
    <td><?= number_format($r['q'], 1); ?>/5<?= $r['q_src'] !== 'verified' ? '<span class="text-muted small"> ' . dl_e($r['q_src']) . '</span>' : ''; ?></td>
    <td><?= round($r['rel'] * 100); ?>%<?= $r['limited'] ? '<span class="text-muted small"> neutral</span>' : ' <span class="text-muted small">(' . (int) $r['hist']['done'] . ' done)</span>'; ?></td>
    <td><?= dl_status_chip($r['avail']); ?></td><td class="text-end fw-semibold"><?= dl_e($r['score']); ?></td>
    <?php if ($f) { ?><td class="text-end"><?= dl_money($f['tasker']); ?></td><td class="text-end"><?= dl_money($f['final']); ?> <span class="small text-muted"><?= dl_e($f['margin']); ?>%</span></td><?php } ?></tr>
<?php } ?>
</tbody></table></div></div>
<div class="small text-muted mb-3">Weights: cost <?= (float) $w['cost']; ?>%, speed <?= (float) $w['speed']; ?>%, quality <?= (float) $w['quality']; ?>%, reliability <?= (float) $w['reliability']; ?>%, availability <?= (float) $w['availability']; ?>%<?= (float) dl_setting($conn, 'preferred_bonus') > 0 ? '; Preferred RM Taskers +' . (float) dl_setting($conn, 'preferred_bonus') . ' points' : ''; ?>.
    <?php if ($price !== null) { $f0 = dl_profit($price, $cogs, $direct, $c['eligible'][0]['rate'], $basis); ?>Profit pool = <?= dl_e(dl_basis_text($basis)); ?> = <b><?= dl_money($f0['pool']); ?></b>; each Tasker's share is taken from this pool, never from the full price.<?php } ?></div>
<?php } ?>
<?php if ($c['other']) { ?>
<h6 class="small fw-semibold mb-1">Not eligible now</h6>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0"><tbody>
<?php foreach ($c['other'] as $r) { ?><tr><td><a href="tasker.php?id=<?= (int) $r['party']; ?>"><?= dl_e($r['display']); ?></a></td><td class="text-end"><?= dl_pct($r['rate_pct']); ?></td><td class="small text-muted"><?= dl_e($r['why']); ?></td></tr><?php } ?>
</tbody></table></div></div>
<?php } ?>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>