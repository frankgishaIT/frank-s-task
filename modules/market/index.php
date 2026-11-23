<?php
/**
 * NEW FILE: modules/market/index.php — RM MARKET INSIGHTS DASHBOARD: demand overview for a period (requests, unique
 * customers, new products, existing products out of stock, urgent and unresolved requests), demand levels, the most
 * requested products and services, outcomes, new discoveries, and — for those with market.finance — the estimated value
 * of the unmet demand next to the Capital Fund available for stock.
 */
require __DIR__ . '/common.php';
mi_require($conn, 'view');
$per = mi_period();
$bs = mi_branch_sql($conn);
$w = [$bs]; if ($per[0]) { $w[] = "r.requested_at >= '" . $per[0] . " 00:00:00'"; } if ($per[1]) { $w[] = "r.requested_at <= '" . $per[1] . " 23:59:59'"; }
$W = implode(' AND ', $w);
$reqs = mysqli_fetch_all(mysqli_query($conn, "SELECT r.id, r.opportunity_id, r.kind, r.customer_id, r.walkin_name, r.walkin_phone, r.quantity, r.expected_price, r.urgency, r.outcome, r.source FROM mi_requests r JOIN mi_opportunities o ON o.id = r.opportunity_id WHERE o.merged_into IS NULL AND $W"), MYSQLI_ASSOC);
$cust = []; $byKind = ['product' => 0, 'service' => 0]; $urgent = 0; $unres = 0; $outcomes = []; $sources = []; $oppIds = [];
foreach ($reqs as $r) { $cust[mi_customer_key($r)] = true; $byKind[$r['kind']]++; if ($r['urgency'] === 'Urgent') { $urgent++; } if ($r['outcome'] === 'Request unresolved') { $unres++; }
    $outcomes[$r['outcome'] ?: '—'] = ($outcomes[$r['outcome'] ?: '—'] ?? 0) + 1; $sources[$r['source']] = ($sources[$r['source']] ?? 0) + 1; $oppIds[(int) $r['opportunity_id']] = true; }
$opps = $oppIds ? mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM mi_opportunities WHERE id IN (' . implode(',', array_keys($oppIds)) . ')'), MYSQLI_ASSOC) : [];
$stats = mi_stats($conn, array_keys($oppIds), $per[0], $per[1]);
$prods = mi_products($conn, array_column($opps, 'product_id'));
$sit = []; $lvls = []; $rows = []; $value = 0.0; $valueN = 0;
foreach ($opps as $o) {
    $st = $stats[(int) $o['id']] ?? null; $p = $o['product_id'] ? ($prods[(int) $o['product_id']] ?? null) : null;
    $s = mi_situation($o, $p); [$l, $why] = mi_classify($conn, $st);
    $sit[$s[0]] = ($sit[$s[0]] ?? 0) + 1; $lvls[$l] = ($lvls[$l] ?? 0) + 1;
    $price = $st['avg_price'] ?? null; if ($price === null && $p) { $price = (float) $p['selling_price'] ?: null; } if ($price === null && $o['est_sell_price']) { $price = (float) $o['est_sell_price']; }
    if ($price !== null && $st) { $value += $price * $st['quantity']; $valueN++; }
    $rows[] = $o + ['st' => $st, 'lvl' => $l, 'why' => $why, 'sit' => $s, 'value' => $price !== null && $st ? $price * $st['quantity'] : null];
}
usort($rows, function ($a, $b) { return [-($a['st']['customers'] ?? 0), -($a['st']['requests'] ?? 0), -($a['st']['quantity'] ?? 0)] <=> [-($b['st']['customers'] ?? 0), -($b['st']['requests'] ?? 0), -($b['st']['quantity'] ?? 0)]; });
$topP = array_slice(array_values(array_filter($rows, function ($r) { return $r['kind'] === 'product'; })), 0, 8);
$topS = array_slice(array_values(array_filter($rows, function ($r) { return $r['kind'] === 'service'; })), 0, 5);
$disc = mysqli_fetch_all(mysqli_query($conn, "SELECT o.id, o.name, o.discovery_source, o.created_at, u.names FROM mi_opportunities o LEFT JOIN users u ON u.id = o.created_by WHERE o.origin = 'research' AND o.merged_into IS NULL"
    . ($per[0] ? " AND o.created_at >= '" . $per[0] . " 00:00:00'" : '') . ($per[1] ? " AND o.created_at <= '" . $per[1] . " 23:59:59'" : '') . ' ORDER BY o.id DESC LIMIT 6'), MYSQLI_ASSOC);
// Alerts: products requested N times within D days (thresholds in Settings)
$an = (int) mi_setting($conn, 'alert_repeat_count'); $ad = (int) mi_setting($conn, 'alert_repeat_days');
$alerts = mysqli_fetch_all(mysqli_query($conn, "SELECT o.id, o.name, o.kind, COUNT(*) n FROM mi_requests r JOIN mi_opportunities o ON o.id = r.opportunity_id WHERE o.merged_into IS NULL AND o.status IN ('New', 'Under Review', 'More Information Required')
    AND r.requested_at >= '" . date('Y-m-d H:i:s', time() - $ad * 86400) . "' AND $bs GROUP BY o.id, o.name, o.kind HAVING COUNT(*) >= $an ORDER BY n DESC LIMIT 6"), MYSQLI_ASSOC);
$fin = null;
if (mic($conn, 'finance')) {
    try { require_once __DIR__ . '/../../includes/fund_helpers.php'; $cf = fund_by_code($conn, 'CAPITAL'); if ($cf) { $fin = ['capital' => fund_available($conn, (int) $cf['id']), 'name' => $cf['name'] ?? 'Capital Fund']; } } catch (Throwable $e) { $fin = null; }
}
$biz = null;
if (mi_can_evaluate($conn)) { // C. business opportunities and B. capability gaps (stage 2)
    $biz = ['pending' => 0, 'approved' => 0, 'recP' => 0, 'recS' => 0, 'inv' => 0.0, 'gp' => 0.0, 'train' => [], 'gapTypes' => [], 'recs' => []];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mi_opportunities WHERE merged_into IS NULL AND status NOT IN ('Rejected', 'Completed', 'Launched/Active') ORDER BY id DESC LIMIT 300"), MYSQLI_ASSOC) as $x) {
        if ($x['status'] === 'Recommended') { $biz['pending']++; } if ($x['status'] === 'Approved') { $biz['approved']++; }
        $R = mi_recommendation($conn, $x); $biz['recs'][$R['rec']] = ($biz['recs'][$R['rec']] ?? 0) + 1;
        if (in_array($R['rec'], ['Recommended to Introduce', 'Increase Stock Quantity'], true)) { $biz['recP']++; $biz['inv'] += (float) $R['investment']; $biz['gp'] += (float) $R['gross_profit']; }
        if ($R['rec'] === 'Introduce the service') { $biz['recS']++; $biz['inv'] += (float) $R['investment']; $biz['gp'] += 12 * (float) $R['gp_m']; }
    }
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT g.gap_type, g.recommendation, ge.user_id FROM mi_gaps g LEFT JOIN mi_gap_employees ge ON ge.gap_id = g.id WHERE g.status = 'Open'"), MYSQLI_ASSOC) as $g) {
        $biz['gapTypes'][$g['gap_type']] = true; if ($g['user_id'] && $g['recommendation'] === 'Train existing employees') { $biz['train'][(int) $g['user_id']] = true; }
    }
    $biz['gapsOpen'] = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mi_gaps WHERE status = 'Open'"))[0];
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'index.php', 'RM Market Insights', mic($conn, 'request') ? '<div class="d-flex gap-1"><a href="request.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Record a request</a><a href="discover.php" class="btn btn-outline-primary btn-sm">New discovery</a></div>' : '');
echo mi_period_form($per);
$k = function ($l, $v, $sub = '', $href = '') { return '<div class="col-6 col-md-4 col-xl-2"><' . ($href ? 'a href="' . $href . '" class="text-decoration-none"' : 'div') . '><div class="card border-0 shadow-sm h-100 mi-k"><div class="card-body py-2"><div class="lbl">' . mi_e($l) . '</div><div class="val">' . mi_e($v) . '</div><div class="sub">' . mi_e($sub) . '</div></div></div></' . ($href ? 'a' : 'div') . '></div>'; };
$pq = 'period=' . urlencode($per[2]) . ($per[2] === 'custom' ? '&from=' . $per[0] . '&to=' . $per[1] : '');
?>
<div class="row g-2 mb-3">
    <?= $k('Customer requests', number_format(count($reqs)), $byKind['product'] . ' product · ' . $byKind['service'] . ' service', 'requests.php?' . $pq); ?>
    <?= $k('Unique customers', number_format(count($cust)), 'who asked for something'); ?>
    <?= $k('New products requested', number_format(($sit['new'] ?? 0) + ($sit['research'] ?? 0)), 'never stocked', 'opportunities.php?sit=new&' . $pq); ?>
    <?= $k('Existing — out of stock', number_format($sit['oos'] ?? 0), ($sit['low'] ?? 0) . ' more on low stock', 'opportunities.php?sit=oos&' . $pq); ?>
    <?= $k('Services requested', number_format(($sit['service_new'] ?? 0) + ($sit['service_existing'] ?? 0)), ($sit['service_new'] ?? 0) . ' not offered yet', 'opportunities.php?kind=service&' . $pq); ?>
    <?= $k('Urgent / unresolved', $urgent . ' / ' . $unres, 'requests', 'requests.php?urgent=1&' . $pq); ?>
</div>
<?php if ($alerts) { ?><div class="alert alert-warning py-2 small mb-3"><i class="bi bi-bell me-1"></i><strong>Repeated requests</strong> (<?= $an; ?>+ times in <?= $ad; ?> days, not reviewed yet):
    <?php foreach ($alerts as $i => $a) { ?><?= $i ? ', ' : ' '; ?><a href="opportunity.php?id=<?= (int) $a['id']; ?>" class="fw-semibold"><?= mi_e($a['name']); ?></a> (<?= (int) $a['n']; ?>)<?php } ?></div><?php } ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="d-flex justify-content-between p-2 px-3"><span class="fw-semibold">Most requested products</span><a href="opportunities.php?kind=product&amp;<?= mi_e($pq); ?>" class="small">All</a></div><div class="table-responsive"><table class="table table-sm mb-0 mi-tbl">
        <tr class="table-light"><th class="ps-3">Product</th><th class="d-none d-md-table-cell">Situation</th><th class="text-end">Customers</th><th class="text-end">Requests</th><th class="text-end">Qty</th><th>Demand</th></tr>
        <?php foreach ($topP as $r) { ?><tr><td class="ps-3"><a href="opportunity.php?id=<?= (int) $r['id']; ?>" class="fw-semibold text-decoration-none"><?= mi_e($r['name']); ?></a><div class="small text-muted d-md-none"><?= mi_e($r['sit'][1]); ?></div></td>
            <td class="d-none d-md-table-cell"><span class="badge bg-<?= $r['sit'][2]; ?> <?= in_array($r['sit'][2], ['warning', 'light'], true) ? 'text-dark' : ''; ?>"><?= mi_e($r['sit'][1]); ?></span></td>
            <td class="text-end"><?= (int) $r['st']['customers']; ?></td><td class="text-end"><?= (int) $r['st']['requests']; ?></td><td class="text-end text-nowrap"><?= mi_num($r['st']['quantity']); ?> <span class="small text-muted"><?= mi_e($r['unit']); ?></span></td><td title="<?= mi_e($r['why']); ?>"><?= mi_level_badge($r['lvl']); ?></td></tr><?php } ?>
        <?php if (!$topP) { ?><tr><td colspan="6" class="text-center text-muted py-4">No product request in this period.</td></tr><?php } ?>
    </table></div></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="d-flex justify-content-between p-2 px-3"><span class="fw-semibold">Most requested services</span><a href="opportunities.php?kind=service&amp;<?= mi_e($pq); ?>" class="small">All</a></div><div class="table-responsive"><table class="table table-sm mb-0 mi-tbl">
        <?php foreach ($topS as $r) { ?><tr><td class="ps-3"><a href="opportunity.php?id=<?= (int) $r['id']; ?>" class="fw-semibold text-decoration-none"><?= mi_e($r['name']); ?></a> <span class="small text-muted"><?= mi_e($r['sit'][1]); ?></span></td><td class="text-end"><?= (int) $r['st']['customers']; ?> customer(s)</td><td class="text-end"><?= (int) $r['st']['requests']; ?> request(s)</td><td><?= mi_level_badge($r['lvl']); ?></td></tr><?php } ?>
        <?php if (!$topS) { ?><tr><td class="text-center text-muted py-4">No service request in this period.</td></tr><?php } ?>
    </table></div></div></div>
</div>
<div class="col-xl-4">
    <?php if ($fin) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Financial overview</div>
        <div class="d-flex justify-content-between border-bottom py-1"><span>Estimated value of the demand</span><strong><?= mi_money($value); ?></strong></div>
        <div class="d-flex justify-content-between border-bottom py-1"><span><?= mi_e($fin['name']); ?> available</span><strong><?= mi_money($fin['capital']); ?></strong></div>
        <div class="text-muted mt-1">Estimate = quantity × the price customers would pay (or the selling price) for <?= $valueN; ?> item(s). Not sales: decisions follow supplier quotations and feasibility (next update).</div></div></div><?php } ?>
    <?php if ($biz) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="d-flex justify-content-between"><span class="fw-semibold">Business opportunities</span><a href="recommendations.php">Recommendations</a></div>
        <div class="d-flex justify-content-between border-bottom py-1"><span>Pending management decisions</span><a href="recommendations.php?status=Recommended" class="fw-bold"><?= (int) $biz['pending']; ?></a></div>
        <div class="d-flex justify-content-between border-bottom py-1"><span>Products to introduce / restock</span><strong><?= (int) $biz['recP']; ?></strong></div>
        <div class="d-flex justify-content-between border-bottom py-1"><span>New services to introduce</span><strong><?= (int) $biz['recS']; ?></strong></div>
        <div class="d-flex justify-content-between border-bottom py-1"><span>Approved, not yet implemented</span><a href="recommendations.php?status=Approved" class="fw-bold"><?= (int) $biz['approved']; ?></a></div>
        <?php if (mic($conn, 'finance') || mic($conn, 'research')) { ?><div class="d-flex justify-content-between border-bottom py-1"><span>Estimated investment</span><strong><?= mi_money($biz['inv']); ?></strong></div><div class="d-flex justify-content-between py-1"><span>Expected gross profit <span class="text-muted">(est.)</span></span><strong><?= mi_money($biz['gp']); ?></strong></div><?php } ?>
        <div class="fw-semibold mt-2 d-flex justify-content-between"><span>Capability gaps</span><a href="gaps.php" class="fw-normal">Open</a></div>
        <div class="d-flex justify-content-between py-1"><span>Open gaps</span><strong><?= (int) $biz['gapsOpen']; ?></strong></div>
        <div class="d-flex justify-content-between py-1"><span>Employees needing training</span><strong><?= count($biz['train']); ?></strong></div>
        <?php if ($biz['gapTypes']) { ?><div class="text-muted"><?= mi_e(implode(' · ', array_map(function ($t) { return MI_GAP_TYPES[$t] ?? $t; }, array_keys($biz['gapTypes'])))); ?></div><?php } ?>
    </div></div><?php } ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Demand levels</div>
        <?php foreach (MI_DEMAND_LEVELS as $l => $c) { ?><div class="d-flex justify-content-between py-1"><a href="opportunities.php?level=<?= urlencode($l); ?>&amp;<?= mi_e($pq); ?>" class="text-decoration-none"><?= mi_level_badge($l); ?></a><strong><?= (int) ($lvls[$l] ?? 0); ?></strong></div><?php } ?></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">What happened to the customers</div>
        <?php arsort($outcomes); foreach ($outcomes as $x => $n) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= mi_e($x); ?></span><strong><?= (int) $n; ?></strong></div><?php } ?>
        <?php if (!$outcomes) { ?><div class="text-muted">—</div><?php } ?>
        <?php if ($sources) { arsort($sources); ?><div class="fw-semibold mt-2 mb-1">How requests came</div><?php foreach ($sources as $x => $n) { ?><div class="d-flex justify-content-between"><span><?= mi_e($x); ?></span><strong><?= (int) $n; ?></strong></div><?php } } ?></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="d-flex justify-content-between"><span class="fw-semibold">New discoveries</span><a href="opportunities.php?origin=research&amp;period=all">All</a></div>
        <?php foreach ($disc as $d) { ?><div class="border-bottom py-1"><a href="opportunity.php?id=<?= (int) $d['id']; ?>" class="fw-semibold text-decoration-none"><?= mi_e($d['name']); ?></a><div class="text-muted"><?= mi_e($d['discovery_source']); ?> · <?= mi_e($d['names']); ?> · <?= date('d M', strtotime($d['created_at'])); ?></div></div><?php } ?>
        <?php if (!$disc) { ?><div class="text-muted">None in this period.</div><?php } ?></div></div>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>