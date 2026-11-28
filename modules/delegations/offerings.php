<?php
/** NEW FILE: modules/delegations/offerings.php — Service-to-Tasker mapping: how every RM Offering is fulfilled and who can provide it. */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$ff = in_array($_GET['f'] ?? '', DL_FULFILMENT, true) ? $_GET['f'] : '';
$type = in_array($_GET['type'] ?? '', ['Service', 'Item'], true) ? $_GET['type'] : '';
$q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
$prods = mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name, p.item_type, p.selling_price, p.is_active, s.fulfilment, s.delegation_allowed, s.expected_rate, s.std_hours, s.internalization_plan
    FROM products p LEFT JOIN dl_offering_settings s ON s.product_id = p.id WHERE p.is_active = 1 ORDER BY p.item_type DESC, p.product_name"), MYSQLI_ASSOC);
$today = date('Y-m-d');
$stats = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT o.product_id, COUNT(*) n, MIN(o.rate_pct) lo, MAX(o.rate_pct) hi, MIN(o.est_hours) fast FROM dl_tasker_offerings o JOIN dl_taskers t ON t.party_id = o.party_id
    JOIN business_parties bp ON bp.id = o.party_id WHERE o.status = 'Active' AND t.status = 'Active' AND bp.is_active = 1 AND o.availability <> 'Unavailable' AND t.availability <> 'Unavailable'
    AND (o.valid_from IS NULL OR o.valid_from <= '$today') AND (o.valid_to IS NULL OR o.valid_to >= '$today') GROUP BY o.product_id"), MYSQLI_ASSOC) as $r) { $stats[(int) $r['product_id']] = $r; }
$counts = array_fill_keys(DL_FULFILMENT, 0);
foreach ($prods as $p) { $counts[$p['fulfilment'] ?: 'Internal']++; }

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'offerings.php', 'Services & RM Taskers');
?>
<div class="d-flex flex-wrap gap-2 mb-2">
    <?php foreach ($counts as $k => $n) { ?><a href="?f=<?= urlencode($k); ?>" class="text-decoration-none border rounded-3 px-2 py-1 small <?= $ff === $k ? 'border-primary' : ''; ?>"><?= dl_fulfilment_badge($k); ?> <span class="text-muted"><?= $n; ?></span></a><?php } ?>
    <?php if ($ff || $type || $q) { ?><a href="offerings.php" class="small align-self-center">Show all</a><?php } ?>
</div>
<form class="d-flex gap-2 mb-2"><?php if ($ff) { ?><input type="hidden" name="f" value="<?= dl_e($ff); ?>"><?php } ?>
    <input name="q" value="<?= dl_e($_GET['q'] ?? ''); ?>" class="form-control form-control-sm" style="max-width:240px" placeholder="Search an offering">
    <select name="type" class="form-select form-select-sm" style="max-width:150px" onchange="this.form.submit()"><option value="">Services and products</option><option value="Service" <?= $type === 'Service' ? 'selected' : ''; ?>>Services</option><option value="Item" <?= $type === 'Item' ? 'selected' : ''; ?>>Products</option></select>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>RM Offering</th><th>Fulfilment</th><th class="text-end">Eligible RM Taskers</th><th class="text-end">Rates</th><th>Fastest</th><th>Standard time</th><th>Internalization</th></tr></thead><tbody>
<?php foreach ($prods as $p) {
    $f = $p['fulfilment'] ?: 'Internal';
    if ($ff && $f !== $ff) { continue; }
    if ($type && $p['item_type'] !== $type) { continue; }
    if ($q !== '' && mb_strpos(mb_strtolower($p['product_name']), $q) === false) { continue; }
    $s = $stats[(int) $p['id']] ?? null;
    $needs = $f === 'External Only' || ($f === 'Delegatable' && (int) $p['delegation_allowed']);
?>
<tr><td><a href="offering.php?id=<?= (int) $p['id']; ?>" class="text-decoration-none"><?= dl_e($p['product_name']); ?></a> <span class="small text-muted"><?= $p['item_type'] === 'Service' ? 'service' : 'product'; ?></span></td>
    <td><?= dl_fulfilment_badge($f); ?><?= $f === 'Delegatable' && !(int) $p['delegation_allowed'] ? ' <span class="small text-muted">not allowed yet</span>' : ''; ?></td>
    <td class="text-end"><?= $s ? (int) $s['n'] : ($needs ? '<span class="dl-st dl-bad">none</span>' : '0'); ?></td>
    <td class="text-end small"><?= $s ? dl_pct($s['lo']) . ($s['hi'] != $s['lo'] ? ' – ' . dl_pct($s['hi']) : '') : '—'; ?><?= $p['expected_rate'] !== null ? '<div class="text-muted">expected ' . dl_pct($p['expected_rate']) . '</div>' : ''; ?></td>
    <td class="small"><?= $s ? dl_hours_text($s['fast']) : '—'; ?></td>
    <td class="small"><?= $p['std_hours'] ? dl_hours_text($p['std_hours']) : ($needs ? '<span class="text-danger">not set</span>' : '—'); ?></td>
    <td class="small"><?= $p['internalization_plan'] && $p['internalization_plan'] !== 'None' ? dl_e($p['internalization_plan']) : '—'; ?></td></tr>
<?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>