<?php
/** NEW FILE: modules/delegations/index.php — Delegations overview: the MANAGEMENT DASHBOARD KPIs for a period (reports permission), RM Taskers and coverage of RM Offerings. */
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

$mgmt = dlc($conn, 'reports');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$K = $mgmt ? dlq_kpis($conn, $from, $to) : null;
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'index.php', 'Delegations');
?>
<?php if ($K) { $kp = function ($v, $l, $cls = '', $link = '') { return '<div class="col-6 col-md-4 col-xl-2"><div class="dl-kpi h-100"><div class="v ' . $cls . '">' . ($link ? '<a href="' . $link . '" class="text-reset text-decoration-none">' . $v . '</a>' : $v) . '</div><div class="l">' . $l . '</div></div></div>'; }; ?>
<form class="d-flex flex-wrap gap-2 align-items-end mb-2"><div class="fw-semibold small me-2 align-self-center">Management dashboard</div>
    <div><input type="date" name="from" value="<?= dl_e($from); ?>" class="form-control form-control-sm"></div><div><input type="date" name="to" value="<?= dl_e($to); ?>" class="form-control form-control-sm"></div>
    <button class="btn btn-outline-primary btn-sm">Show</button><a href="reports.php?from=<?= dl_e($from); ?>&to=<?= dl_e($to); ?>" class="btn btn-link btn-sm">All 18 reports →</a></form>
<div class="row g-2 mb-2">
    <?= $kp($K['total'], 'Total delegations' . ($K['reassigned'] ? ' <span class="text-muted">(+' . $K['reassigned'] . ' reassigned)</span>' : ''), '', 'list.php')
      . $kp($K['pending'], 'Pending (not yet verified)', '', 'list.php')
      . $kp($K['completed'], 'Completed (verified)')
      . $kp($K['failed'], 'Failed / cancelled', $K['failed'] ? 'text-danger' : '')
      . $kp(dl_money($K['revenue']), 'Customer revenue from delegated services')
      . $kp($K['active_taskers'] . ' <span class="small text-muted">/ ' . $K['active_registered'] . '</span>', 'Taskers used / active external Taskers', '', 'taskers.php'); ?>
</div>
<div class="small text-muted mb-1">Actual verified figures (<?= (int) $K['completed']; ?> verified delegation<?= $K['completed'] == 1 ? '' : 's'; ?>, revenue <?= dl_money($K['rev_verified']); ?>):</div>
<div class="row g-2 mb-2">
    <?= $kp(dl_money($K['cogs']), 'Total cost of sales')
      . $kp(dl_money($K['tasker']), 'Total Tasker payments <span class="text-muted">(' . dl_money($K['tasker_paid']) . ' paid)</span>')
      . $kp(dl_money($K['direct']), 'Other direct costs')
      . $kp(dl_money($K['profit']), 'Actual gross profit', $K['profit'] < 0 ? 'text-danger' : 'text-success')
      . $kp($K['margin'] !== null ? $K['margin'] . '%' : '—', 'Actual gross profit margin')
      . $kp(dl_money($K['error_cost']), 'Total cost of errors', $K['error_cost'] > 0 ? 'text-danger' : '', 'issues.php'); ?>
</div>
<div class="row g-2 mb-2">
    <?= $kp(dl_money($K['outstanding']), 'Outstanding Tasker payments', '', 'payments.php')
      . $kp($K['overdue'] . ($K['overdue'] ? ' <span class="small">· ' . dl_money($K['overdue_amt']) . '</span>' : ''), 'Overdue payments', $K['overdue'] ? 'text-danger' : '', 'reports.php?r=14')
      . $kp($K['unconfirmed'], 'Unconfirmed payments', $K['unconfirmed'] ? 'text-warning' : '', 'reports.php?r=15&from=' . $from . '&to=' . $to)
      . $kp($K['disputed'], 'Disputed payments', $K['disputed'] ? 'text-danger' : '', 'disputes.php'); ?>
</div>
<div class="row g-3 mb-3">
<?php foreach (['most_delegated' => ['Most delegated services', 'n'], 'most_profitable' => ['Most profitable delegated services', 'profit'], 'highest_cost' => ['Highest-cost delegated services', 'cost']] as $key => [$t, $f]) { ?>
<div class="col-lg-4"><div class="card h-100"><div class="card-body py-2"><div class="fw-semibold small mb-1"><?= $t; ?></div>
    <?php foreach (array_slice($K[$key], 0, 5) as $sv) { ?><div class="border-top py-1 small d-flex justify-content-between"><span><?= dl_e($sv['name']); ?></span><b><?= $f === 'n' ? (int) $sv['n'] . ' · ' . dl_money($sv['revenue']) : dl_money($sv[$f]); ?></b></div><?php } ?>
    <?php if (!$K[$key]) { ?><div class="small text-muted"><?= $f === 'n' ? 'No delegation in this period.' : 'No verified delegation in this period.'; ?></div><?php } ?>
</div></div></div>
<?php } ?>
</div>
<div class="fw-semibold small mb-1">RM Taskers &amp; coverage</div>
<?php } ?>
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