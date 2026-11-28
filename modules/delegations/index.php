<?php
/** NEW FILE: modules/delegations/index.php — Delegations overview (RM Taskers and coverage of RM Offerings; the delegation KPIs are added with the workflow). */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$today = date('Y-m-d');
$soon = date('Y-m-d', strtotime('+30 days'));
$k = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) n, SUM(CASE WHEN t.status = 'Active' THEN 1 ELSE 0 END) active, SUM(CASE WHEN t.preferred = 1 THEN 1 ELSE 0 END) preferred,
    SUM(CASE WHEN t.status = 'Suspended' THEN 1 ELSE 0 END) suspended FROM dl_taskers t JOIN business_parties bp ON bp.id = t.party_id WHERE bp.type = 'Tasker'"));
$need = mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name, s.fulfilment FROM dl_offering_settings s JOIN products p ON p.id = s.product_id WHERE p.is_active = 1
    AND (s.fulfilment = 'External Only' OR (s.fulfilment = 'Delegatable' AND s.delegation_allowed = 1)) ORDER BY p.product_name"), MYSQLI_ASSOC);
$gaps = array_values(array_filter($need, function ($r) use ($conn) { return !dl_candidates($conn, (int) $r['id'])['eligible']; }));
$na = mysqli_fetch_all(mysqli_query($conn, "SELECT p.id, p.product_name FROM dl_offering_settings s JOIN products p ON p.id = s.product_id WHERE s.fulfilment = 'Not Available' AND p.is_active = 1 ORDER BY p.product_name"), MYSQLI_ASSOC);
$expiring = mysqli_fetch_all(mysqli_query($conn, "SELECT o.*, p.product_name, bp.business_name, bp.name FROM dl_tasker_offerings o JOIN products p ON p.id = o.product_id JOIN business_parties bp ON bp.id = o.party_id
    WHERE o.status = 'Active' AND o.valid_to IS NOT NULL AND o.valid_to >= '$today' AND o.valid_to <= '$soon' ORDER BY o.valid_to"), MYSQLI_ASSOC);

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'index.php', 'Delegations');
?>
<div class="row g-2 mb-3">
    <div class="col-6 col-md-3"><div class="dl-kpi"><div class="v"><?= (int) $k['active']; ?></div><div class="l">Active RM Taskers (<?= (int) $k['n']; ?> registered)</div></div></div>
    <div class="col-6 col-md-3"><div class="dl-kpi"><div class="v"><?= (int) $k['preferred']; ?></div><div class="l">Preferred RM Taskers</div></div></div>
    <div class="col-6 col-md-3"><div class="dl-kpi"><div class="v"><?= count($need); ?></div><div class="l">Offerings that can be delegated</div></div></div>
    <div class="col-6 col-md-3"><div class="dl-kpi"><div class="v <?= $gaps ? 'text-danger' : ''; ?>"><?= count($gaps); ?></div><div class="l">…of which no eligible RM Tasker</div></div></div>
</div>
<div class="row g-3">
<div class="col-lg-6"><div class="card h-100"><div class="card-body py-2"><div class="fw-semibold small mb-1">Need an RM Tasker but none is eligible</div>
    <?php foreach ($gaps as $r) { ?><div class="border-top py-1 small d-flex justify-content-between"><a href="offering.php?id=<?= (int) $r['id']; ?>"><?= dl_e($r['product_name']); ?></a><?= dl_fulfilment_badge($r['fulfilment']); ?></div><?php } ?>
    <?php if (!$gaps) { ?><div class="small text-muted">Every offering that can be delegated has at least one eligible RM Tasker.</div><?php } ?>
    <?php if ($na) { ?><div class="fw-semibold small mt-2 mb-1">Not available at all</div><div class="small"><?= implode(', ', array_map(function ($r) { return '<a href="offering.php?id=' . (int) $r['id'] . '">' . dl_e($r['product_name']) . '</a>'; }, $na)); ?></div><?php } ?>
</div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-body py-2"><div class="fw-semibold small mb-1">Rates ending in the next 30 days</div>
    <?php foreach ($expiring as $r) { ?><div class="border-top py-1 small d-flex justify-content-between"><span><a href="tasker.php?id=<?= (int) $r['party_id']; ?>"><?= dl_e(dl_tasker_name($r)); ?></a> · <?= dl_e($r['product_name']); ?> <?= dl_pct($r['rate_pct']); ?></span><span><?= dl_e(date('d M Y', strtotime($r['valid_to']))); ?></span></div><?php } ?>
    <?php if (!$expiring) { ?><div class="small text-muted">None.</div><?php } ?>
</div></div></div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>