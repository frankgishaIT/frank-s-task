<?php
/**
 * NEW FILE: modules/market/opportunity.php — ONE product / service opportunity: consolidated demand (requests, unique
 * customers, quantity, first / latest, average days between requests, trend, readiness, outcomes, branches), the
 * situation (new / existing out of stock / low stock / research), the demand level with its reason, every individual
 * request, and the history. Managers (market.research) can correct the details, link it to an RM Offerings item,
 * merge a duplicate into it and move it through review.
 */
require __DIR__ . '/common.php';
mi_require($conn, 'view');
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mi_opportunities WHERE id = $id"));
if (!$o) { mi_go('opportunities.php', 'error', 'Not found.'); }
if ($o['merged_into']) { mi_go('opportunity.php?id=' . (int) $o['merged_into'], 'success', '"' . $o['name'] . '" was merged into this record.'); }
$self = 'opportunity.php?id=' . $id;
mi_sync_status($conn, $o); // follows the purchase order (In Implementation → Launched/Active)
$canRes = mic($conn, 'research');
$reviewStatuses = ['New', 'Under Review', 'More Information Required']; // Recommended / Approved / Rejected / Deferred: Decision page
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!mi_csrf_ok()) { mi_go($self, 'error', 'The page had expired.'); }
    if (!$canRes) { mi_go($self, 'error', 'You may not change this record.'); }
    $do = (string) ($_POST['do'] ?? ''); $now = date('Y-m-d H:i:s'); $uid = (int) current_user_id();
    try {
        if ($do === 'edit') {
            $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 160);
            if ($name === '') { throw new RuntimeException('The name cannot be empty.'); }
            $cat = trim((string) ($_POST['category'] ?? '')); if ($cat !== '' && !in_array($cat, mi_categories($conn, $o['kind'], $o['category']), true)) { $cat = ''; }
            $key = mi_key($name);
            $dup = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM mi_opportunities WHERE kind = '" . $o['kind'] . "' AND name_key = '" . mysqli_real_escape_string($conn, $key) . "' AND id <> $id AND merged_into IS NULL LIMIT 1"));
            if ($dup) { throw new RuntimeException('Another record already has this name (#' . $dup[0] . '): merge them instead.'); }
            $desc = trim((string) ($_POST['description'] ?? '')) ?: null; $unit = mb_substr(trim((string) ($_POST['unit'] ?? '')), 0, 30) ?: null; $catv = $cat ?: null;
            $s = mysqli_prepare($conn, 'UPDATE mi_opportunities SET name = ?, name_key = ?, category = ?, description = ?, unit = ?, updated_by = ?, updated_at = ? WHERE id = ?');
            mysqli_stmt_bind_param($s, 'sssssisi', $name, $key, $catv, $desc, $unit, $uid, $now, $id); mysqli_stmt_execute($s);
            $chg = []; foreach (['name' => $name, 'category' => $catv, 'unit' => $unit, 'description' => $desc] as $k => $v) { if ((string) $o[$k] !== (string) $v) { $chg[] = $k . ': "' . $o[$k] . '" → "' . $v . '"'; } }
            if ($chg) { mi_audit($conn, $id, null, 'edited', implode('; ', $chg)); }
            mi_go($self, 'success', 'Saved.');
        }
        if ($do === 'link') {
            $pid = (int) ($_POST['product_id'] ?? 0);
            if ($pid) {
                $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT id, product_name, item_type FROM products WHERE id = $pid"));
                if (!$p || ($p['item_type'] === 'Service') !== ($o['kind'] === 'service')) { throw new RuntimeException('Choose an RM Offerings ' . ($o['kind'] === 'service' ? 'service' : 'item') . '.'); }
                $other = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM mi_opportunities WHERE product_id = $pid AND id <> $id AND merged_into IS NULL LIMIT 1"));
                if ($other) { throw new RuntimeException('This RM Offerings item already has its own record (#' . $other[0] . '): merge the two instead.'); }
                mysqli_query($conn, "UPDATE mi_opportunities SET product_id = $pid, updated_by = $uid, updated_at = '$now' WHERE id = $id");
                mi_audit($conn, $id, null, 'linked', 'Linked to RM Offerings item "' . $p['product_name'] . '" (#' . $pid . ')');
                mi_go($self, 'success', 'Linked to "' . $p['product_name'] . '": stock and sales are now taken into account.');
            }
            mysqli_query($conn, "UPDATE mi_opportunities SET product_id = NULL, updated_by = $uid, updated_at = '$now' WHERE id = $id");
            mi_audit($conn, $id, null, 'unlinked', 'Link to RM Offerings removed');
            mi_go($self, 'success', 'Link removed.');
        }
        if ($do === 'merge') {
            $from = (int) ($_POST['from_id'] ?? 0);
            mi_merge($conn, $from, $id);
            mi_go($self, 'success', 'Merged: the requests of the duplicate now count here (the duplicate is kept in the history).');
        }
        if ($do === 'status') {
            $stt = (string) ($_POST['status'] ?? ''); $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 500);
            if (!in_array($stt, $reviewStatuses, true)) { throw new RuntimeException('Recommendations and management decisions are made on the Decision page.'); }
            if (in_array($o['status'], ['Approved', 'Rejected', 'Deferred', 'In Implementation', 'Launched/Active', 'Completed'], true) && !mic($conn, 'approve')) { throw new RuntimeException('A management decision was already taken on this record.'); }
            $s = mysqli_prepare($conn, 'UPDATE mi_opportunities SET status = ?, updated_by = ?, updated_at = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'sisi', $stt, $uid, $now, $id); mysqli_stmt_execute($s);
            mi_audit($conn, $id, null, 'status', $o['status'] . ' → ' . $stt . ($note !== '' ? ': ' . $note : ''));
            mi_go($self, 'success', 'Status: ' . $stt . '.');
        }
    } catch (RuntimeException $e) { mi_go($self, 'error', $e->getMessage()); }
}
$st = mi_stats($conn, [$id])[$id] ?? null;
[$lvl, $why] = mi_classify($conn, $st);
$prod = $o['product_id'] ? (mi_products($conn, [(int) $o['product_id']])[(int) $o['product_id']] ?? null) : null;
$sit = mi_situation($o, $prod);
$reqs = mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, c.name cname, c.phone cphone, u.names uname FROM mi_requests r LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN users u ON u.id = r.recorded_by WHERE r.opportunity_id = $id AND " . mi_branch_sql($conn) . ' ORDER BY r.requested_at DESC LIMIT 500'), MYSQLI_ASSOC);
$audit = mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, u.names FROM mi_audit a LEFT JOIN users u ON u.id = a.user_id WHERE a.opportunity_id = $id ORDER BY a.id DESC LIMIT 60"), MYSQLI_ASSOC);
$merged = mysqli_fetch_all(mysqli_query($conn, "SELECT id, name FROM mi_opportunities WHERE merged_into = $id"), MYSQLI_ASSOC);
// weekly requests for the chart (last 26 weeks)
$weeks = []; for ($i = 25; $i >= 0; $i--) { $weeks[date('o-\WW', strtotime("-$i week"))] = 0; }
foreach ($reqs as $r) { $k = date('o-\WW', strtotime($r['requested_at'])); if (isset($weeks[$k])) { $weeks[$k]++; } }
$branches = br_enabled($conn) ? array_column(br_all($conn), 'name', 'id') : [];
$sales = null;
if ($prod && $o['kind'] === 'product') {
    $sales = mi_sold($conn, (int) $prod['id'], 90);
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'opportunities.php', $o['name'], mic($conn, 'request') ? '<a href="request.php?kind=' . $o['kind'] . '&amp;target=opp:' . $id . '&amp;name=' . rawurlencode($o['name']) . '" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Add a request</a>' : '');
?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <span class="badge bg-<?= $sit[2]; ?> <?= in_array($sit[2], ['warning', 'light'], true) ? 'text-dark' : ''; ?>"><?= mi_e($sit[1]); ?></span><?= mi_level_badge($lvl); ?><?= mi_status_badge($o['status']); ?>
    <span class="small text-muted"><?= $o['kind'] === 'service' ? 'Service' : 'Product'; ?><?= $o['category'] ? ' · ' . mi_e($o['category']) : ''; ?> · <?= mi_e(MI_ORIGINS[$o['origin']] ?? $o['origin']); ?> · #<?= $id; ?></span>
</div>
<?php mi_opp_nav($conn, $o, 'opportunity.php'); ?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="row g-2 mb-3">
        <?php foreach ([['Requests', (int) ($st['requests'] ?? 0), ''], ['Unique customers', (int) ($st['customers'] ?? 0), ($st && $st['identified'] < $st['requests']) ? 'some walk-ins not identified' : ''], ['Quantity requested', mi_num($st['quantity'] ?? 0), $o['unit'] ?: 'units'],
            ['Ready to buy', (int) ($st['ready'] ?? 0), 'request(s)'], ['First request', $st ? date('d M Y', strtotime($st['first'])) : '—', ''], ['Latest request', $st ? date('d M Y', strtotime($st['last'])) : '—', $st && $st['avg_gap_days'] !== null ? 'every ' . $st['avg_gap_days'] . ' days on average' : '']] as [$l, $val, $sub]) { ?>
        <div class="col-6 col-md-4 col-xxl-2"><div class="card border-0 shadow-sm h-100 mi-k"><div class="card-body py-2"><div class="lbl"><?= mi_e($l); ?></div><div class="val"><?= mi_e($val); ?></div><div class="sub"><?= mi_e($sub); ?></div></div></div></div><?php } ?>
    </div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body">
        <div class="d-flex justify-content-between flex-wrap gap-2"><div class="fw-semibold">Demand: <?= mi_level_badge($lvl); ?></div><div class="small text-muted">Trend: <?= $st ? (int) $st['recent'] . ' request(s) in the last ' . (int) mi_setting($conn, 'growth_days') . ' days, ' . (int) $st['previous'] . ' before' : '—'; ?></div></div>
        <div class="small mt-1"><?= mi_e($why); ?></div>
        <?php if ($prod && $o['kind'] === 'product') { ?><div class="small mt-2 p-2 rounded" style="background:#F6F8FD;">RM Offerings: <strong><?= mi_e($prod['product_name']); ?></strong> (<?= mi_e($prod['product_code']); ?>) · stock <strong><?= mi_num($prod['quantity']); ?> <?= mi_e($prod['unit']); ?></strong><?= $prod['reorder_level'] !== null ? ' · reorder level ' . mi_num($prod['reorder_level']) : ''; ?><?= $sales ? ' · sold ' . mi_num($sales['q']) . ' in ' . (int) $sales['n'] . ' sale(s) in the last 90 days' : ''; ?>
            <div class="text-muted"><?= $sit[0] === 'oos' || $sit[0] === 'low' ? 'This is a stock replenishment need, not a new product.' : ($sit[0] === 'in_stock' ? 'The product is in stock: check why customers did not get it (shown in the outcomes below).' : ''); ?></div></div><?php } ?>
        <div style="height:170px" class="mt-2"><canvas id="miWeeks"></canvas></div>
    </div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="p-2 px-3 fw-semibold">Individual requests (<?= count($reqs); ?>)</div><div class="table-responsive"><table class="table table-sm mb-0 mi-tbl">
        <tr class="table-light"><th class="ps-3">Date</th><th>Customer</th><th class="text-end">Qty</th><th class="d-none d-md-table-cell text-end">Price</th><th class="d-none d-md-table-cell">Source</th><th>Outcome</th><th class="d-none d-lg-table-cell">Recorded by</th></tr>
        <?php foreach ($reqs as $r) { ?><tr><td class="ps-3 text-nowrap small"><?= date('d M Y H:i', strtotime($r['requested_at'])); ?><?= $r['urgency'] === 'Urgent' ? ' <span class="badge bg-danger">urgent</span>' : ''; ?></td>
            <td><?= $r['customer_id'] ? '<a href="../customers/edit.php?id=' . (int) $r['customer_id'] . '">' . mi_e($r['cname']) . '</a>' : mi_e($r['walkin_name'] ?: 'Walk-in'); ?><div class="small text-muted"><?= mi_e($r['customer_id'] ? 'RMC' . str_pad((string) $r['customer_id'], 6, '0', STR_PAD_LEFT) : ($r['walkin_phone'] ?: '')); ?><?= $r['ready_to_buy'] ? ' · ready: ' . mi_e($r['ready_to_buy']) : ''; ?></div></td>
            <td class="text-end"><?= mi_num($r['quantity']); ?></td><td class="d-none d-md-table-cell text-end small"><?= $r['expected_price'] !== null ? mi_money($r['expected_price']) : '—'; ?></td><td class="d-none d-md-table-cell small"><?= mi_e($r['source']); ?></td>
            <td class="small"><?= mi_e($r['outcome']); ?><?= $r['reason'] ? '<div class="text-muted">' . mi_e($r['reason']) . '</div>' : ''; ?><?= $r['specification'] || $r['skills_required'] || $r['notes'] ? '<div class="text-muted">' . mi_e(trim($r['specification'] . ' ' . $r['skills_required'] . ' ' . $r['equipment_required'] . ' ' . $r['notes'])) . '</div>' : ''; ?></td>
            <td class="d-none d-lg-table-cell small"><?= mi_e($r['uname']); ?><?= $r['branch_id'] && $branches ? '<div class="text-muted">' . mi_e($branches[(int) $r['branch_id']] ?? '') . '</div>' : ''; ?></td></tr><?php } ?>
        <?php if (!$reqs) { ?><tr><td colspan="7" class="text-center text-muted py-4">No customer request yet<?= $o['origin'] === 'research' ? ' — this product was found through market research' : ''; ?>.</td></tr><?php } ?>
    </table></div></div></div>
</div>
<div class="col-xl-4">
    <?php if ($st && $st['outcomes']) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">What happened to the customers</div>
        <?php arsort($st['outcomes']); foreach ($st['outcomes'] as $k => $n) { ?><div class="d-flex justify-content-between border-bottom py-1"><span><?= mi_e($k); ?></span><strong><?= (int) $n; ?></strong></div><?php } ?>
        <?php if ($st['branches'] && $branches) { ?><div class="fw-semibold mt-2 mb-1">Branches</div><?php foreach ($st['branches'] as $b => $n) { ?><div class="d-flex justify-content-between"><span><?= mi_e($branches[$b] ?? '#' . $b); ?></span><strong><?= (int) $n; ?></strong></div><?php } } ?>
        <?php if ($st['avg_price'] !== null) { ?><div class="mt-2">Average price customers would pay: <strong><?= mi_money($st['avg_price']); ?></strong></div><?php } ?>
    </div></div><?php } ?>
    <?php if ($o['origin'] === 'research' || $o['est_sell_price'] || $o['segment'] || $o['image_ref']) { ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Market research</div>
        <?php if ($o['image_ref']) { ?><img src="file.php?id=<?= $id; ?>" alt="" class="rounded mb-2" style="width:100%;aspect-ratio:1/1;object-fit:cover;background:#EEF3FF"><?php } ?>
        <?php foreach (['discovery_source' => 'Found through', 'est_demand' => 'Estimated demand', 'segment' => 'Customer segment', 'possible_supplier' => 'Possible supplier'] as $k => $l) { if ($o[$k]) { ?><div><?= $l; ?>: <strong><?= mi_e($o[$k]); ?></strong></div><?php } } ?>
        <?php if (mic($conn, 'finance') || mic($conn, 'research')) { if ($o['est_sell_price']) { ?><div>Estimated selling price: <strong><?= mi_money($o['est_sell_price']); ?></strong></div><?php } if ($o['est_buy_price']) { ?><div>Estimated purchase price: <strong><?= mi_money($o['est_buy_price']); ?></strong> <span class="text-muted">(estimate, not a quotation)</span></div><?php } } ?>
        <?php if ($o['notes']) { ?><div class="mt-1 text-muted" style="white-space:pre-line"><?= mi_e($o['notes']); ?></div><?php } ?>
    </div></div><?php } ?>
    <?php if ($canRes) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small">
        <form method="POST" class="mb-3"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="status">
            <div class="fw-semibold mb-1">Review status</div><div class="d-flex gap-1"><select name="status" class="form-select form-select-sm"><?php foreach ($reviewStatuses as $x) { ?><option <?= $o['status'] === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select><button class="btn btn-sm btn-outline-primary">Set</button></div>
            <input name="note" class="form-control form-control-sm mt-1" placeholder="Note (optional)"><div class="text-muted mt-1">To recommend, approve, reject or defer, use the <a href="decision.php?id=<?= $id; ?>">Decision</a> page.</div></form>
        <details><summary class="fw-semibold text-primary">Correct the details</summary><form method="POST" class="mt-2"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="edit">
            <input name="name" class="form-control form-control-sm mb-1" value="<?= mi_e($o['name']); ?>" maxlength="160" required>
            <select name="category" class="form-select form-select-sm mb-1"><option value="">No category</option><?php foreach (mi_categories($conn, $o['kind'], $o['category']) as $c) { ?><option <?= $o['category'] === $c ? 'selected' : ''; ?>><?= mi_e($c); ?></option><?php } ?></select>
            <input name="unit" class="form-control form-control-sm mb-1" value="<?= mi_e($o['unit']); ?>" placeholder="Unit (reams, pieces…)" maxlength="30">
            <textarea name="description" class="form-control form-control-sm mb-1" rows="2" placeholder="Description"><?= mi_e($o['description']); ?></textarea><button class="btn btn-sm btn-primary">Save</button></form></details>
        <details class="mt-2"><summary class="fw-semibold text-primary"><?= $prod ? 'RM Offerings link' : 'Link to an existing RM Offerings item'; ?></summary><form method="POST" class="mt-2" id="miLink"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="link"><input type="hidden" name="product_id" id="miPid" value="">
            <?php if ($prod) { ?><div class="mb-1">Linked to <strong><?= mi_e($prod['product_name']); ?></strong>.</div><button class="btn btn-sm btn-outline-danger" onclick="return confirm('Remove the link?')">Remove the link</button><?php } else { ?>
            <input class="form-control form-control-sm" id="miPFind" placeholder="Search RM Offerings…"><div id="miPRes" class="mt-1"></div><div class="text-muted">Use this when customers asked for something we already have in the catalogue.</div><?php } ?></form></details>
        <details class="mt-2"><summary class="fw-semibold text-primary">Merge a duplicate into this record</summary><form method="POST" class="mt-2" id="miMerge" onsubmit="return confirm('Move every request of the chosen record here?');"><?= mi_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="do" value="merge"><input type="hidden" name="from_id" id="miFrom">
            <input class="form-control form-control-sm" id="miOFind" placeholder="Search the duplicate…"><div id="miORes" class="mt-1"></div></form></details>
    </div></div><?php } ?>
    <?php if ($merged) { ?><div class="small text-muted mb-2">Merged into this record: <?= mi_e(implode(', ', array_column($merged, 'name'))); ?></div><?php } ?>
    <?php $bt = $o['product_id'] && $o['kind'] === 'product' ? mi_bought_together($conn, (int) $o['product_id']) : []; $rb = mi_requesters_buy($conn, $id); if ($bt || $rb) { ?>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="fw-semibold mb-1">Sales patterns</div>
        <?php if ($bt) { ?><div class="text-muted">Often bought in the same sale</div><?php foreach ($bt as $b) { ?><div class="d-flex justify-content-between"><span><?= mi_e($b['product_name']); ?></span><span class="text-muted"><?= (int) $b['n']; ?> sale(s)</span></div><?php } } ?>
        <?php if ($rb) { ?><div class="text-muted <?= $bt ? 'mt-2' : ''; ?>">The customers who asked for it also buy</div><?php foreach ($rb as $b) { ?><div class="d-flex justify-content-between"><span><?= mi_e($b['product_name']); ?></span><span class="text-muted"><?= (int) $b['customers']; ?> customer(s)</span></div><?php } ?><div class="text-muted mt-1">Complementary products: useful for stocking and offers.</div><?php } ?>
    </div></div><?php } ?>
    <?php if (in_array($o['status'], ['Approved', 'In Implementation', 'Launched/Active', 'Completed'], true)) { $L = mi_links($conn, $id); ?><div class="card border-0 shadow-sm mb-3"><div class="card-body small"><div class="d-flex justify-content-between"><span class="fw-semibold">Implementation</span><a href="<?= in_array($o['status'], ['Launched/Active', 'Completed'], true) ? 'monitor' : 'decision'; ?>.php?id=<?= $id; ?>">Open</a></div>
        <div class="text-muted"><?= mi_e(mi_purchase_stage($o, $L) ?: $o['status']); ?></div>
        <?php foreach ($L['po'] as $p) { ?><div>PO #<?= (int) $p['id']; ?> · <?= mi_e($p['status']); ?></div><?php } foreach ($L['task'] as $tk) { ?><div>Task: <?= mi_e($tk['title']); ?> · <?= mi_e($tk['status']); ?></div><?php } ?>
    </div></div><?php } ?>
    <div class="card border-0 shadow-sm"><div class="card-body small"><div class="fw-semibold mb-1">History</div>
        <?php foreach ($audit as $a) { ?><div class="border-bottom py-1"><span class="text-muted"><?= mi_e(substr($a['created_at'], 0, 16)); ?></span> · <strong><?= mi_e($a['names'] ?? 'System'); ?></strong> · <?= mi_e($a['details']); ?></div><?php } ?></div></div>
</div></div>
<script>
(function () {
    if (window.Chart) new Chart(document.getElementById('miWeeks'), { type: 'bar', data: { labels: <?= json_encode(array_map(function ($k) { return substr($k, 5); }, array_keys($weeks))); ?>, datasets: [{ label: 'Requests per week', data: <?= json_encode(array_values($weeks)); ?>, backgroundColor: '#1E2FE0', borderRadius: 3 }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { ticks: { font: { size: 10 } } } } } });
    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function finder(inp, res, kindKey, onPick) { if (!inp) return; var t = null; inp.addEventListener('input', function () { clearTimeout(t); var q = inp.value.trim(); if (q.length < 2) { res.innerHTML = ''; return; }
        t = setTimeout(function () { fetch('lookup.php?kind=<?= $o['kind']; ?>&q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            res.innerHTML = (d[kindKey] || []).filter(function (x) { return kindKey !== 'opportunities' || x.id !== <?= $id; ?>; }).map(function (x) { return '<button type="button" class="btn btn-sm btn-light border w-100 text-start mb-1" data-id="' + x.id + '">' + esc(x.name) + ' <span class="text-muted">' + esc(kindKey === 'catalogue' ? (x.situation + (x.stock ? ' · ' + x.stock : '')) : (x.requests + ' request(s)')) + '</span></button>'; }).join('') || '<div class="text-muted">Nothing found.</div>'; }); }, 220); });
        res.addEventListener('click', function (e) { var b = e.target.closest('[data-id]'); if (b) onPick(b.getAttribute('data-id'), b.textContent); }); }
    finder(document.getElementById('miPFind'), document.getElementById('miPRes'), 'catalogue', function (id, l) { if (confirm('Link to: ' + l + '?')) { document.getElementById('miPid').value = id; document.getElementById('miLink').submit(); } });
    finder(document.getElementById('miOFind'), document.getElementById('miORes'), 'opportunities', function (id, l) { document.getElementById('miFrom').value = id; if (confirm('Merge "' + l + '" into this record? Its requests will count here.')) document.getElementById('miMerge').submit(); });
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>