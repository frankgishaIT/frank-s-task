<?php
/** NEW FILE: modules/delegations/taskers.php — RM Tasker Directory (searchable). */
require __DIR__ . '/common.php';
dl_require($conn, 'view');
$q = trim((string) ($_GET['q'] ?? ''));
$st = in_array($_GET['status'] ?? '', DL_TASKER_STATUS, true) ? $_GET['status'] : '';
$svc = (int) ($_GET['service'] ?? 0);
$dist = trim((string) ($_GET['district'] ?? ''));
$w = ["bp.type = 'Tasker'"];
if ($st) { $w[] = "t.status = '$st'"; }
if ($svc) { $w[] = "EXISTS (SELECT 1 FROM dl_tasker_offerings o WHERE o.party_id = bp.id AND o.product_id = $svc AND o.status = 'Active')"; }
if ($dist !== '') { $w[] = "bp.district = '" . mysqli_real_escape_string($conn, $dist) . "'"; }
if ($q !== '') {
    $e = mysqli_real_escape_string($conn, mb_strtolower($q));
    $code = preg_match('/^RMST0*(\d+)$/i', $q, $m) ? ' OR t.id = ' . (int) $m[1] : '';
    $w[] = "(LOWER(COALESCE(bp.name, '')) LIKE '%$e%' OR LOWER(COALESCE(bp.business_name, '')) LIKE '%$e%' OR bp.phone LIKE '%$e%' OR LOWER(COALESCE(t.specialization, '')) LIKE '%$e%'
        OR EXISTS (SELECT 1 FROM dl_tasker_offerings o JOIN products p ON p.id = o.product_id WHERE o.party_id = bp.id AND LOWER(p.product_name) LIKE '%$e%')$code)";
}
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT bp.*, t.id tasker_no, t.specialization, t.availability, t.status tasker_status, t.preferred FROM business_parties bp JOIN dl_taskers t ON t.party_id = bp.id WHERE ' . implode(' AND ', $w) . ' ORDER BY t.preferred DESC, t.status, COALESCE(bp.business_name, bp.name)'), MYSQLI_ASSOC);
$svcs = [];
foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT o.party_id, p.product_name, o.rate_pct FROM dl_tasker_offerings o JOIN products p ON p.id = o.product_id WHERE o.status = 'Active' ORDER BY p.product_name"), MYSQLI_ASSOC) as $r) { $svcs[(int) $r['party_id']][] = $r; }
$services = mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT p.id, p.product_name FROM dl_tasker_offerings o JOIN products p ON p.id = o.product_id ORDER BY p.product_name'), MYSQLI_ASSOC);
$districts = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT district FROM business_parties WHERE type = 'Tasker' AND district IS NOT NULL AND district <> '' ORDER BY district"), MYSQLI_NUM), 0);
$hist = dl_tasker_history($conn);
$minH = (int) dl_setting($conn, 'min_history');

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'taskers.php', 'RM Tasker Directory', dlc($conn, 'taskers') ? '<a href="tasker_edit.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg"></i> Register an RM Tasker</a>' : '');
?>
<form class="d-flex flex-wrap gap-2 mb-2">
    <input name="q" value="<?= dl_e($q); ?>" class="form-control form-control-sm" style="max-width:260px" placeholder="Name, RMST number, phone, skill or service">
    <select name="service" class="form-select form-select-sm" style="max-width:220px"><option value="">Any service</option><?php foreach ($services as $s) { ?><option value="<?= (int) $s['id']; ?>" <?= $svc === (int) $s['id'] ? 'selected' : ''; ?>><?= dl_e($s['product_name']); ?></option><?php } ?></select>
    <select name="district" class="form-select form-select-sm" style="max-width:170px"><option value="">Any district</option><?php foreach ($districts as $d) { ?><option <?= $dist === $d ? 'selected' : ''; ?>><?= dl_e($d); ?></option><?php } ?></select>
    <select name="status" class="form-select form-select-sm" style="max-width:150px"><option value="">Any status</option><?php foreach (DL_TASKER_STATUS as $s) { ?><option <?= $st === $s ? 'selected' : ''; ?>><?= $s; ?></option><?php } ?></select>
    <button class="btn btn-outline-primary btn-sm">Search</button>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th>RM Tasker</th><th>Specialization</th><th>Services &amp; rates</th><th>Location</th><th>Record</th><th>Status</th></tr></thead><tbody>
<?php foreach ($rows as $r) { $pid = (int) $r['id']; $h = $hist[$pid] ?? null; $list = $svcs[$pid] ?? []; ?>
<tr><td><a href="tasker.php?id=<?= $pid; ?>" class="fw-semibold text-decoration-none"><?= dl_e(dl_tasker_name($r)); ?></a><div class="small text-muted"><?= dl_code((int) $r['tasker_no']); ?> · <?= dl_e($r['phone']); ?></div></td>
    <td class="small"><?= dl_e($r['specialization']); ?></td>
    <td class="small"><?= $list ? implode(', ', array_map(function ($s) { return dl_e($s['product_name']) . ' <b>' . dl_pct($s['rate_pct']) . '</b>'; }, array_slice($list, 0, 4))) . (count($list) > 4 ? ' +' . (count($list) - 4) : '') : '<span class="text-danger">none yet</span>'; ?></td>
    <td class="small"><?= dl_e($r['district'] ?: '—'); ?></td>
    <td class="small"><?= $h && $h['done'] >= $minH ? (int) $h['done'] . ' done · ' . round(($h['ontime'] ?? 0) * 100) . '% on time' . ($h['quality'] !== null ? ' · ' . number_format($h['quality'], 1) . '/5' : '') : '<span class="dl-st">New / limited history</span>'; ?></td>
    <td class="text-nowrap"><?= dl_status_chip($r['tasker_status']); ?> <?= $r['tasker_status'] === 'Active' ? dl_status_chip($r['availability']) : ''; ?><?= (int) $r['preferred'] ? ' <span class="dl-st dl-gold" title="Preferred RM Tasker"><i class="bi bi-award"></i></span>' : ''; ?></td></tr>
<?php } ?>
<?php if (!$rows) { ?><tr><td colspan="6" class="text-center text-muted py-5"><?= $q || $st || $svc || $dist ? 'No RM Tasker matches.' : 'No RM Tasker registered yet.'; ?></td></tr><?php } ?>
</tbody></table></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>