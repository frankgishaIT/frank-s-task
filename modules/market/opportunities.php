<?php
/**
 * NEW FILE: modules/market/opportunities.php — MARKET DEMAND ANALYSIS: every product / service customers asked for or
 * staff discovered, consolidated (one line per product, every request kept), with requests, unique customers, quantity,
 * first / latest request, trend, readiness to buy, the situation (new product, existing out of stock, low stock, research)
 * and the demand level from the thresholds set by management.
 */
require __DIR__ . '/common.php';
mi_require($conn, 'view');
$per = mi_period();
$fk = in_array($_GET['kind'] ?? '', ['product', 'service'], true) ? $_GET['kind'] : '';
$fl = isset(MI_DEMAND_LEVELS[$_GET['level'] ?? '']) ? $_GET['level'] : '';
$fs = in_array($_GET['status'] ?? '', MI_STATUSES, true) ? $_GET['status'] : '';
$fsit = (string) ($_GET['sit'] ?? ''); $fc = trim((string) ($_GET['category'] ?? '')); $q = trim((string) ($_GET['q'] ?? ''));
$fo = isset(MI_ORIGINS[$_GET['origin'] ?? '']) ? $_GET['origin'] : '';
$w = ['o.merged_into IS NULL']; $types = ''; $p = [];
if ($fk) { $w[] = "o.kind = '$fk'"; }
if ($fs) { $w[] = 'o.status = ?'; $types .= 's'; $p[] = $fs; }
if ($fc !== '') { $w[] = 'o.category = ?'; $types .= 's'; $p[] = $fc; }
if ($fo) { $w[] = 'o.origin = ?'; $types .= 's'; $p[] = $fo; }
if ($q !== '') { $w[] = '(o.name LIKE ? OR o.description LIKE ?)'; $types .= 'ss'; $p[] = '%' . $q . '%'; $p[] = '%' . $q . '%'; }
$s = mysqli_prepare($conn, 'SELECT o.* FROM mi_opportunities o WHERE ' . implode(' AND ', $w) . ' ORDER BY o.id DESC LIMIT 2000');
if ($types) { mysqli_stmt_bind_param($s, $types, ...$p); }
mysqli_stmt_execute($s);
$opps = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
$stats = mi_stats($conn, array_column($opps, 'id'), $per[0], $per[1]);
$prods = mi_products($conn, array_column($opps, 'product_id'));
$rows = [];
foreach ($opps as $o) {
    $st = $stats[(int) $o['id']] ?? null;
    // In a period, opportunities without requests in it are hidden (except research items, shown with "Insufficient data").
    if ($per[0] && !$st && $o['origin'] === 'request') { continue; }
    [$lvl, $why] = mi_classify($conn, $st);
    $sit = mi_situation($o, $o['product_id'] ? ($prods[(int) $o['product_id']] ?? null) : null);
    if ($fl && $lvl !== $fl) { continue; }
    if ($fsit && $sit[0] !== $fsit) { continue; }
    $rows[] = $o + ['st' => $st, 'lvl' => $lvl, 'why' => $why, 'sit' => $sit];
}
$rank = array_flip(array_keys(MI_DEMAND_LEVELS));
usort($rows, function ($a, $b) use ($rank) { return [$rank[$a['lvl']], -($a['st']['customers'] ?? 0), -($a['st']['quantity'] ?? 0)] <=> [$rank[$b['lvl']], -($b['st']['customers'] ?? 0), -($b['st']['quantity'] ?? 0)]; });
$counts = array_count_values(array_column($rows, 'lvl'));
$cats = array_values(array_unique(array_filter(array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT category FROM mi_opportunities WHERE category IS NOT NULL ORDER BY category'), MYSQLI_ASSOC), 'category'))));
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="market-demand-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Product / service', 'Type', 'Situation', 'Category', 'Requests', 'Unique customers', 'Quantity', 'Unit', 'First request', 'Latest request', 'Recent', 'Previous', 'Ready to buy', 'Demand level', 'Why', 'Status', 'Origin']);
    foreach ($rows as $r) { $st = $r['st']; fputcsv($f, [$r['name'], $r['kind'], $r['sit'][1], $r['category'], $st['requests'] ?? 0, $st['customers'] ?? 0, $st['quantity'] ?? 0, $r['unit'], $st['first'] ?? '', $st['last'] ?? '', $st['recent'] ?? 0, $st['previous'] ?? 0, $st['ready'] ?? 0, $r['lvl'], $r['why'], $r['status'], MI_ORIGINS[$r['origin']] ?? $r['origin']]); }
    exit;
}
$keep = ['kind' => $fk, 'level' => $fl, 'status' => $fs, 'sit' => $fsit, 'category' => $fc, 'q' => $q, 'origin' => $fo];
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'opportunities.php', 'Market demand analysis', mic($conn, 'request') ? '<a href="request.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Record a request</a>' : '');
echo mi_period_form($per, $keep);
?>
<div class="d-flex flex-wrap gap-1 mb-2 small"><?php foreach (MI_DEMAND_LEVELS as $l => $c) { $qs = http_build_query(array_merge($_GET, ['level' => $fl === $l ? '' : $l])); ?><a href="?<?= mi_e($qs); ?>" class="text-decoration-none"><?= str_replace('badge ', 'badge ' . ($fl === $l ? 'shadow ' : 'opacity-75 '), mi_level_badge($l)); ?> <span class="text-muted me-2"><?= (int) ($counts[$l] ?? 0); ?></span></a><?php } ?></div>
<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 align-items-end small">
    <input type="hidden" name="period" value="<?= mi_e($per[2]); ?>"><?php if ($per[2] === 'custom') { ?><input type="hidden" name="from" value="<?= mi_e($per[0]); ?>"><input type="hidden" name="to" value="<?= mi_e($per[1]); ?>"><?php } ?>
    <div class="col-12 col-md-3"><input name="q" value="<?= mi_e($q); ?>" class="form-control form-control-sm" placeholder="Search a product or service"></div>
    <div class="col-6 col-md-2"><select name="kind" class="form-select form-select-sm"><option value="">Products and services</option><option value="product" <?= $fk === 'product' ? 'selected' : ''; ?>>Products</option><option value="service" <?= $fk === 'service' ? 'selected' : ''; ?>>Services</option></select></div>
    <div class="col-6 col-md-2"><select name="sit" class="form-select form-select-sm"><option value="">Any situation</option><?php foreach (['new' => 'New product (never stocked)', 'research' => 'New product (research)', 'oos' => 'Existing — out of stock', 'low' => 'Existing — low stock', 'in_stock' => 'Existing — in stock', 'service_new' => 'New service', 'service_existing' => 'Existing service'] as $k => $l) { ?><option value="<?= $k; ?>" <?= $fsit === $k ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="category" class="form-select form-select-sm"><option value="">All categories</option><?php foreach ($cats as $c) { ?><option <?= $fc === $c ? 'selected' : ''; ?>><?= mi_e($c); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="status" class="form-select form-select-sm"><option value="">Any status</option><?php foreach (MI_STATUSES as $x) { ?><option <?= $fs === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-12 col-md-1 d-flex gap-1"><button class="btn btn-sm btn-primary">Filter</button></div>
</div></form>
<div class="d-flex justify-content-between small text-muted mb-1"><span><?= count($rows); ?> product(s) / service(s) · requests counted: <?= mi_e($per[3]); ?></span><a href="?<?= mi_e(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">Export (Excel / CSV)</a></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 mi-tbl">
    <tr class="table-light"><th class="ps-3">Product / service</th><th class="d-none d-lg-table-cell">Situation</th><th class="text-end">Requests</th><th class="text-end">Customers</th><th class="text-end">Quantity</th><th class="d-none d-md-table-cell">Latest</th><th class="d-none d-md-table-cell text-center" title="Requests in the last period vs the period before">Trend</th><th>Demand</th><th class="d-none d-md-table-cell">Status</th></tr>
    <?php foreach ($rows as $r) { $st = $r['st']; $tr = !$st ? '' : ($st['recent'] > $st['previous'] ? '<span class="text-success" title="' . $st['recent'] . ' vs ' . $st['previous'] . '">▲</span>' : ($st['recent'] < $st['previous'] ? '<span class="text-danger" title="' . $st['recent'] . ' vs ' . $st['previous'] . '">▼</span>' : '<span class="text-muted">–</span>')); ?>
    <tr style="cursor:pointer" onclick="location.href='opportunity.php?id=<?= (int) $r['id']; ?>'">
        <td class="ps-3"><a href="opportunity.php?id=<?= (int) $r['id']; ?>" class="fw-semibold text-decoration-none"><?= mi_e($r['name']); ?></a><div class="small text-muted"><?= $r['kind'] === 'service' ? 'Service' : 'Product'; ?><?= $r['category'] ? ' · ' . mi_e($r['category']) : ''; ?><span class="d-lg-none"> · <?= mi_e($r['sit'][1]); ?></span></div></td>
        <td class="d-none d-lg-table-cell"><span class="badge bg-<?= $r['sit'][2]; ?> <?= in_array($r['sit'][2], ['warning', 'light'], true) ? 'text-dark' : ''; ?>"><?= mi_e($r['sit'][1]); ?></span></td>
        <td class="text-end"><?= (int) ($st['requests'] ?? 0); ?></td><td class="text-end"><?= (int) ($st['customers'] ?? 0); ?></td><td class="text-end text-nowrap"><?= mi_num($st['quantity'] ?? 0); ?> <span class="text-muted small"><?= mi_e($r['unit']); ?></span></td>
        <td class="d-none d-md-table-cell text-nowrap small"><?= $st ? date('d M Y', strtotime($st['last'])) : '—'; ?></td><td class="d-none d-md-table-cell text-center"><?= $tr; ?></td>
        <td title="<?= mi_e($r['why']); ?>"><?= mi_level_badge($r['lvl']); ?></td><td class="d-none d-md-table-cell"><?= mi_status_badge($r['status']); ?></td></tr>
    <?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-5">No product or service matches. <?= mic($conn, 'request') ? '<a href="request.php">Record the first customer request</a>.' : ''; ?></td></tr><?php } ?>
</table></div></div></div>
<div class="small text-muted mt-2">Demand levels follow the thresholds in Settings (hover a level to see why). A customer request is a sign of demand, not a confirmed sale.</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>