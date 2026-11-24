<?php
/**
 * NEW FILE: modules/market/monitor.php — IMPLEMENTATION & RESULTS.
 * List: every approved opportunity with its purchase stage, tasks and — once in stock — the results.
 * ?id=… : post-introduction performance of one product / service: original requests, approved vs purchased quantity,
 * actual cost and price, quantity sold, remaining stock, revenue, gross profit, time to sell, and estimated vs actual.
 * Also what MY MOTIVE learned from launched products (real conversion of requests into sales).
 */
require __DIR__ . '/common.php';
if (!mi_can_evaluate($conn)) { mi_require($conn, 'research'); }
$id = (int) ($_GET['id'] ?? 0);
$opps = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mi_opportunities WHERE merged_into IS NULL AND status IN ('Approved', 'In Implementation', 'Launched/Active', 'Completed')" . ($id ? " AND id = $id" : '') . " ORDER BY CASE status WHEN 'Approved' THEN 1 WHEN 'In Implementation' THEN 2 WHEN 'Launched/Active' THEN 3 ELSE 4 END, id DESC"), MYSQLI_ASSOC);
if ($id && !$opps) { mi_go('monitor.php', 'error', 'This opportunity has not been approved.'); }
$rows = [];
foreach ($opps as $o) { mi_sync_status($conn, $o); $L = mi_links($conn, (int) $o['id']); $rows[] = ['o' => $o, 'L' => $L, 'stage' => mi_purchase_stage($o, $L), 'p' => mi_performance($conn, $o), 'dec' => mi_last_decision($conn, (int) $o['id'], 'Approved')]; }
$learn = $id ? null : mi_learning($conn);
$trends = $id ? [] : mi_category_trends($conn, 6);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'monitor.php', $id ? $opps[0]['name'] . ' — results' : 'Implementation & results');
if ($id) { mi_opp_nav($conn, $opps[0], 'decision.php'); }
$m = function ($x) { return $x === null ? '—' : mi_money($x); };
$vc = ['Selling faster than expected' => 'success', 'On track' => 'primary', 'Selling slower than expected' => 'danger', 'Too early to judge' => 'secondary', 'No estimate to compare' => 'secondary'];
?>
<?php if ($id) { $r = $rows[0]; $o = $r['o']; $p = $r['p']; ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3"><?= mi_status_badge($o['status']); ?><?= $r['stage'] ? '<span class="small text-muted">' . mi_e($r['stage']) . '</span>' : ''; ?><?php if ($p) { ?><span class="badge bg-<?= $vc[$p['verdict']]; ?>"><?= mi_e($p['verdict']); ?></span><?php } ?></div>
<?php if (!$p) { ?><div class="card border-0 shadow-sm"><div class="card-body small text-muted"><?= $o['product_id'] ? 'Results start when the purchase order is received into stock' . ($o['kind'] === 'service' ? ' (or the service is marked as introduced)' : '') . '.' : 'Add the ' . $o['kind'] . ' to RM Offerings first: results come from its sales.'; ?> <a href="decision.php?id=<?= (int) $o['id']; ?>">Implementation</a></div></div>
<?php } else { ?>
<div class="row g-3">
<div class="col-xl-7"><div class="card border-0 shadow-sm"><div class="card-body small">
    <div class="fw-semibold mb-1">Estimated vs actual <span class="text-muted fw-normal">· since <?= date('d M Y', strtotime($p['from'])); ?> (<?= (int) $p['days']; ?> days<?= $o['completed_at'] ? ', completed ' . date('d M Y', strtotime($o['completed_at'])) : ''; ?>)</span></div>
    <table class="table table-sm mb-0 mi-tbl"><tr class="table-light"><th></th><th class="text-end">Estimated / approved</th><th class="text-end">Actual</th></tr>
        <tr><td>Customer requests (consolidated)</td><td class="text-end"><?= (int) ($p['requests']['requests'] ?? 0); ?> from <?= (int) ($p['requests']['customers'] ?? 0); ?> customer(s)</td><td class="text-end"><?= mi_num($p['requests']['quantity'] ?? 0); ?> <?= mi_e($o['unit']); ?> requested</td></tr>
        <?php if ($o['kind'] === 'product') { ?><tr><td>Quantity purchased</td><td class="text-end"><?= $p['approved_qty'] !== null ? mi_num($p['approved_qty']) : '—'; ?></td><td class="text-end"><?= mi_num($p['bought']); ?></td></tr>
        <tr><td>Purchase cost per unit</td><td class="text-end"><?= $r['dec'] && $r['dec']['approved_qty'] > 0 && $r['dec']['budget'] !== null ? mi_money($r['dec']['budget'] / $r['dec']['approved_qty']) : '—'; ?></td><td class="text-end"><?= $m($p['unit_cost']); ?></td></tr><?php } ?>
        <tr><td>Selling price</td><td class="text-end"><?= $m($p['exp_price']); ?></td><td class="text-end"><?= $m($p['price']); ?></td></tr>
        <tr><td>Quantity sold</td><td class="text-end"><?= $p['exp_qty'] ? mi_num($p['exp_qty']) . ' in ' . $p['period'] . ' days' : '—'; ?><?= $p['expected_by_now'] !== null ? '<div class="text-muted">' . mi_num(round($p['expected_by_now'])) . ' expected by now</div>' : ''; ?></td><td class="text-end fw-semibold"><?= mi_num($p['sold']); ?> <span class="text-muted fw-normal">(<?= (int) $p['sales']; ?> sale(s))</span></td></tr>
        <tr><td>Revenue</td><td class="text-end"><?= $m($p['exp_revenue']); ?></td><td class="text-end"><?= $m($p['revenue']); ?></td></tr>
        <tr><td>Gross profit <span class="text-muted">(not net profit)</span></td><td class="text-end"><?= $m($p['exp_gp']); ?></td><td class="text-end fw-semibold"><?= $m($p['gross_profit']); ?></td></tr>
        <?php if ($o['kind'] === 'product') { ?><tr><td>Remaining stock</td><td></td><td class="text-end"><?= $p['stock'] !== null ? mi_num($p['stock']) . ' ' . mi_e($o['unit']) : '—'; ?></td></tr>
        <tr><td>Sold through</td><td></td><td class="text-end"><?= $p['sell_through'] !== null ? mi_num(round($p['sell_through'])) . '% of the quantity purchased' : '—'; ?></td></tr>
        <tr><td>Time to sell the stock</td><td class="text-end"><?= (int) $p['period']; ?> days planned</td><td class="text-end"><?= $p['days_to_sell'] !== null ? 'sold out in ' . $p['days_to_sell'] . ' days' : ($p['days_left_est'] !== null ? 'about ' . $p['days_left_est'] . ' more days at this pace' : 'no sale yet'); ?></td></tr><?php } ?>
    </table>
    <?php if ($p['qty_accuracy'] !== null || $p['gp_accuracy'] !== null) { ?><div class="mt-2">Prediction accuracy: <?= $p['qty_accuracy'] !== null ? '<strong>' . mi_num(round($p['qty_accuracy'])) . '%</strong> of the expected quantity sold within the sales period' : ''; ?><?= $p['gp_accuracy'] !== null ? ($p['qty_accuracy'] !== null ? ' · ' : '') . '<strong>' . mi_num(round($p['gp_accuracy'])) . '%</strong> of the expected gross profit' : ''; ?>.</div><?php } ?>
</div></div></div>
<div class="col-xl-5">
    <?php $bt = $o['product_id'] ? mi_bought_together($conn, (int) $o['product_id']) : []; if ($bt) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Often bought together</div><?php foreach ($bt as $b) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= mi_e($b['product_name']); ?></span><span class="text-muted"><?= (int) $b['n']; ?> sale(s)</span></div><?php } ?><div class="text-muted mt-1">Keep these in stock together.</div></div></div><?php } ?>
    <div class="card border-0 shadow-sm"><div class="card-body small"><div class="fw-semibold mb-1">Implementation</div>
        <?php foreach ($r['L']['po'] as $po) { ?><div class="d-flex justify-content-between border-bottom py-1"><a href="../purchase_orders/view.php?id=<?= (int) $po['id']; ?>">PO #<?= (int) $po['id']; ?></a><span><?= mi_money($po['total_amount']); ?> · <?= mi_e($po['status']); ?></span></div><?php } ?>
        <?php foreach ($r['L']['task'] as $tk) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= mi_e($tk['title']); ?></span><span><?= mi_e($tk['status']); ?></span></div><?php } ?>
        <a href="decision.php?id=<?= (int) $o['id']; ?>" class="d-inline-block mt-1">Decision & implementation →</a></div></div>
</div></div>
<?php } ?>
<?php } else { ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 mi-tbl">
    <tr class="table-light"><th class="ps-3">Product / service</th><th>Stage</th><th class="text-end">Approved</th><th class="text-end d-none d-md-table-cell">Purchased</th><th class="text-end">Sold</th><th class="text-end d-none d-md-table-cell">Revenue</th><th class="text-end">Gross profit</th><th class="d-none d-lg-table-cell">Against the estimate</th></tr>
    <?php foreach ($rows as $r) { $o = $r['o']; $p = $r['p']; ?><tr style="cursor:pointer" onclick="location.href='<?= $p ? 'monitor.php' : 'decision.php'; ?>?id=<?= (int) $o['id']; ?>'">
        <td class="ps-3"><span class="fw-semibold text-primary"><?= mi_e($o['name']); ?></span><div class="small text-muted"><?= $o['kind'] === 'service' ? 'Service' : 'Product'; ?><?= $r['L']['task'] ? ' · ' . count($r['L']['task']) . ' task(s)' : ''; ?></div></td>
        <td class="small"><?= mi_status_badge($o['status']); ?><div class="text-muted"><?= mi_e($r['stage']); ?></div></td>
        <td class="text-end small"><?= $r['dec'] && $r['dec']['approved_qty'] ? mi_num($r['dec']['approved_qty']) : ''; ?><div class="text-muted"><?= $r['dec'] ? mi_money($r['dec']['budget']) : ''; ?></div></td>
        <td class="text-end d-none d-md-table-cell"><?= $p && $o['kind'] === 'product' ? mi_num($p['bought']) : '—'; ?></td><td class="text-end"><?= $p ? mi_num($p['sold']) : '—'; ?></td>
        <td class="text-end d-none d-md-table-cell"><?= $p ? mi_money($p['revenue']) : '—'; ?></td><td class="text-end"><?= $p ? mi_money($p['gross_profit']) : '—'; ?></td>
        <td class="d-none d-lg-table-cell"><?= $p ? '<span class="badge bg-' . $vc[$p['verdict']] . '">' . mi_e($p['verdict']) . '</span>' : '<span class="small text-muted">not in stock yet</span>'; ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-5">No approved opportunity yet.</td></tr><?php } ?>
</table></div></div></div>
<div class="row g-3">
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body small"><div class="fw-semibold mb-1">What MY MOTIVE learned</div>
        <?php if ($learn['n']) { ?>Across <?= (int) $learn['n']; ?> launched product(s), customers bought <strong><?= mi_num(round($learn['rate'])); ?>%</strong> of the quantity they had requested, within the sales period. The setting used for new estimates is <strong><?= mi_num($learn['setting']); ?>%</strong>.
            <?php if (abs($learn['rate'] - $learn['setting']) >= 10 && mic($conn, 'settings')) { ?><div class="mt-1">Consider updating it in <a href="settings.php">Settings</a> (MY MOTIVE does not change it by itself).</div><?php } ?>
        <?php } else { ?><span class="text-muted">Not enough results yet: this appears once launched products have completed their sales period.</span><?php } ?>
    </div></div></div>
    <div class="col-lg-6"><div class="card border-0 shadow-sm h-100"><div class="card-body small"><div class="fw-semibold mb-1">Categories: sales last 90 days vs the 90 days before</div>
        <?php foreach ($trends as $t) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= mi_e($t['cat']); ?></span><span><?= mi_money($t['recent']); ?> <?= $t['growth'] === null ? '<span class="text-muted">new</span>' : '<span class="' . ($t['growth'] >= 0 ? 'text-success' : 'text-danger') . '">' . ($t['growth'] >= 0 ? '▲' : '▼') . ' ' . mi_num(round(abs($t['growth']))) . '%</span>'; ?></span></div><?php } ?>
        <?php if (!$trends) { ?><span class="text-muted">No sales in the last 180 days.</span><?php } ?>
    </div></div></div>
</div>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>