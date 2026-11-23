<?php
/**
 * NEW FILE: modules/market/recommendations.php — THE INTELLIGENCE CENTRE: every product and service opportunity with
 * MY MOTIVE's recommendation (and why), demand, estimated investment, revenue, gross profit, financial feasibility,
 * risk and decision status. Pending management decisions are listed first.
 */
require __DIR__ . '/common.php';
if (!mi_can_evaluate($conn)) { mi_require($conn, 'research'); }
$fk = in_array($_GET['kind'] ?? '', ['product', 'service'], true) ? $_GET['kind'] : '';
$fr = (string) ($_GET['rec'] ?? ''); $fs = in_array($_GET['status'] ?? '', MI_STATUSES, true) ? $_GET['status'] : '';
$closed = !empty($_GET['closed']);
$w = ['merged_into IS NULL']; if ($fk) { $w[] = "kind = '$fk'"; } if ($fs) { $w[] = "status = '" . mysqli_real_escape_string($conn, $fs) . "'"; } elseif (!$closed) { $w[] = "status NOT IN ('Rejected', 'Completed')"; }
$opps = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM mi_opportunities WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT 400'), MYSQLI_ASSOC);
$rows = []; $sum = ['inv' => 0.0, 'gp' => 0.0, 'n' => 0];
foreach ($opps as $o) {
    $R = mi_recommendation($conn, $o);
    if ($fr && $R['rec'] !== $fr) { continue; }
    $inv = $R['investment']; $gp = $o['kind'] === 'service' ? ($R['gp_m'] !== null ? $R['gp_m'] * 12 : null) : $R['gross_profit']; $rev = $o['kind'] === 'service' ? ($R['revenue_m'] !== null ? $R['revenue_m'] * 12 : null) : $R['revenue'];
    if (in_array($R['rec'], ['Recommended to Introduce', 'Increase Stock Quantity', 'Introduce the service'], true)) { $sum['inv'] += (float) $inv; $sum['gp'] += (float) $gp; $sum['n']++; }
    $rows[] = ['o' => $o, 'R' => $R, 'inv' => $inv, 'gp' => $gp, 'rev' => $rev];
}
$order = array_flip(array_merge(array_keys(MI_PRODUCT_RECS), array_keys(MI_SERVICE_RECS)));
usort($rows, function ($a, $b) use ($order) { return [($a['o']['status'] === 'Recommended' ? 0 : 1), $order[$a['R']['rec']] ?? 99, -(float) $a['gp']] <=> [($b['o']['status'] === 'Recommended' ? 0 : 1), $order[$b['R']['rec']] ?? 99, -(float) $b['gp']]; });
$pending = array_filter($rows, function ($r) { return $r['o']['status'] === 'Recommended'; });
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="market-recommendations-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Product / service', 'Type', 'Category', 'Demand', 'Customers', 'Quantity requested', 'Recommendation', 'Why', 'Estimated investment', 'Expected revenue (est.)', 'Expected gross profit (est.)', 'Feasibility', 'Risk', 'Status']);
    foreach ($rows as $r) { $o = $r['o']; $R = $r['R']; fputcsv($f, [$o['name'], $o['kind'], $o['category'], $R['level'], $R['stats']['customers'] ?? 0, $R['stats']['quantity'] ?? 0, $R['rec'], implode(' | ', $R['why']), $r['inv'], $r['rev'], $r['gp'], $R['feasible'] ?? ($R['assess']['Financial feasibility'][0] ?? ''), $R['risk'], $o['status']]); }
    exit;
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'recommendations.php', 'Recommendations');
?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm mi-k"><div class="card-body py-2"><div class="lbl">Pending management decisions</div><div class="val"><?= count($pending); ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm mi-k"><div class="card-body py-2"><div class="lbl">Recommended to introduce / restock</div><div class="val"><?= (int) $sum['n']; ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm mi-k"><div class="card-body py-2"><div class="lbl">Their estimated investment</div><div class="val" style="font-size:1.05rem"><?= mi_money($sum['inv']); ?></div></div></div></div>
    <div class="col-6 col-md-3"><div class="card border-0 shadow-sm mi-k"><div class="card-body py-2"><div class="lbl">Their expected gross profit</div><div class="val" style="font-size:1.05rem"><?= mi_money($sum['gp']); ?></div><div class="sub">estimate, not net profit</div></div></div></div>
</div>
<form class="d-flex flex-wrap gap-1 mb-2 small"><select name="kind" class="form-select form-select-sm" style="width:auto"><option value="">Products and services</option><option value="product" <?= $fk === 'product' ? 'selected' : ''; ?>>Products</option><option value="service" <?= $fk === 'service' ? 'selected' : ''; ?>>Services</option></select>
    <select name="rec" class="form-select form-select-sm" style="width:auto"><option value="">Any recommendation</option><?php foreach (array_merge(array_keys(MI_PRODUCT_RECS), array_keys(MI_SERVICE_RECS)) as $x) { ?><option <?= $fr === $x ? 'selected' : ''; ?>><?= mi_e($x); ?></option><?php } ?></select>
    <select name="status" class="form-select form-select-sm" style="width:auto"><option value="">Open statuses</option><?php foreach (MI_STATUSES as $x) { ?><option <?= $fs === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select>
    <label class="d-flex align-items-center gap-1 px-1"><input type="checkbox" name="closed" value="1" class="form-check-input" <?= $closed ? 'checked' : ''; ?>> include rejected / completed</label><button class="btn btn-sm btn-primary">Filter</button>
    <a class="ms-auto align-self-center" href="?<?= mi_e(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">Export (Excel / CSV)</a></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 mi-tbl">
    <tr class="table-light"><th class="ps-3">Product / service</th><th>Demand</th><th>Recommendation</th><th class="text-end d-none d-md-table-cell">Investment</th><th class="text-end d-none d-md-table-cell">Revenue</th><th class="text-end">Gross profit</th><th class="d-none d-lg-table-cell">Funds</th><th class="d-none d-lg-table-cell">Risk</th><th>Status</th></tr>
    <?php foreach ($rows as $r) { $o = $r['o']; $R = $r['R']; $svc = $o['kind'] === 'service'; ?>
    <tr style="cursor:pointer" onclick="location.href='decision.php?id=<?= (int) $o['id']; ?>'" title="<?= mi_e(implode("\n", $R['why'])); ?>">
        <td class="ps-3"><a href="feasibility.php?id=<?= (int) $o['id']; ?>" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()"><?= mi_e($o['name']); ?></a><div class="small text-muted"><?= $svc ? 'Service' : 'Product'; ?> · <?= (int) ($R['stats']['customers'] ?? 0); ?> customer(s), <?= mi_num($R['stats']['quantity'] ?? 0); ?> <?= mi_e($o['unit']); ?></div></td>
        <td><?= mi_level_badge($R['level']); ?></td><td><?= mi_rec_badge($R['rec']); ?></td>
        <td class="text-end d-none d-md-table-cell"><?= $r['inv'] === null ? '—' : mi_money($r['inv']); ?></td><td class="text-end d-none d-md-table-cell"><?= $r['rev'] === null ? '—' : mi_money($r['rev']); ?><?= $svc && $r['rev'] !== null ? '<div class="small text-muted">12 months</div>' : ''; ?></td>
        <td class="text-end"><?= $r['gp'] === null ? '—' : mi_money($r['gp']); ?></td>
        <td class="d-none d-lg-table-cell small"><?= mi_e($svc ? ($R['assess']['Financial feasibility'][0] ?? '') : $R['feasible']); ?></td><td class="d-none d-lg-table-cell small"><?= mi_e($R['risk']); ?></td><td><?= mi_status_badge($o['status']); ?></td></tr>
    <?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-5">No opportunity matches.</td></tr><?php } ?>
</table></div></div></div>
<div class="small text-muted mt-2">All figures are estimates until the product or service is launched. Hover a line to see why MY MOTIVE recommends it.</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>