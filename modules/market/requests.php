<?php
/**
 * NEW FILE: modules/market/requests.php — EVERY INDIVIDUAL CUSTOMER REQUEST (product and service), newest first, with
 * filters (period, type, source, outcome, urgency, employee, search). Employees without "research" see their own
 * requests only; branch rules apply.
 */
require __DIR__ . '/common.php';
mi_require($conn, 'view');
$per = mi_period();
$all = mic($conn, 'research') || mic($conn, 'reports');
$fk = in_array($_GET['kind'] ?? '', ['product', 'service'], true) ? $_GET['kind'] : '';
$fsrc = in_array($_GET['source'] ?? '', MI_SOURCES, true) ? $_GET['source'] : '';
$fout = in_array($_GET['outcome'] ?? '', array_merge(MI_PRODUCT_OUTCOMES, MI_SERVICE_OUTCOMES), true) ? $_GET['outcome'] : '';
$furg = ($_GET['urgent'] ?? '') === '1';
$fu = $all ? (int) ($_GET['user'] ?? 0) : (int) current_user_id();
$q = trim((string) ($_GET['q'] ?? ''));
$w = [mi_branch_sql($conn), 'o.merged_into IS NULL']; $types = ''; $p = [];
if ($per[0]) { $w[] = 'r.requested_at >= ?'; $types .= 's'; $p[] = $per[0] . ' 00:00:00'; }
if ($per[1]) { $w[] = 'r.requested_at <= ?'; $types .= 's'; $p[] = $per[1] . ' 23:59:59'; }
if ($fk) { $w[] = "r.kind = '$fk'"; }
if ($fsrc) { $w[] = 'r.source = ?'; $types .= 's'; $p[] = $fsrc; }
if ($fout) { $w[] = 'r.outcome = ?'; $types .= 's'; $p[] = $fout; }
if ($furg) { $w[] = "r.urgency = 'Urgent'"; }
if ($fu) { $w[] = 'r.recorded_by = ' . $fu; }
if ($q !== '') { $w[] = '(o.name LIKE ? OR c.name LIKE ? OR r.walkin_name LIKE ? OR r.walkin_phone LIKE ?)'; $types .= 'ssss'; array_push($p, "%$q%", "%$q%", "%$q%", "%$q%"); }
$sql = 'SELECT r.*, o.name oname, o.unit, c.name cname, u.names uname FROM mi_requests r JOIN mi_opportunities o ON o.id = r.opportunity_id LEFT JOIN customers c ON c.id = r.customer_id LEFT JOIN users u ON u.id = r.recorded_by WHERE ' . implode(' AND ', $w) . ' ORDER BY r.requested_at DESC, r.id DESC LIMIT 1000';
$s = mysqli_prepare($conn, $sql); if ($types) { mysqli_stmt_bind_param($s, $types, ...$p); } mysqli_stmt_execute($s);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="customer-requests-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Date', 'Type', 'Product / service', 'Customer', 'Phone', 'Quantity', 'Unit', 'Price customer would pay', 'Source', 'Urgency', 'Reason', 'Outcome', 'Ready to buy', 'Notes', 'Recorded by']);
    foreach ($rows as $r) { fputcsv($f, [$r['requested_at'], $r['kind'], $r['oname'], $r['cname'] ?: ($r['walkin_name'] ?: 'Walk-in'), $r['walkin_phone'], $r['quantity'], $r['unit'], $r['expected_price'], $r['source'], $r['urgency'], $r['reason'], $r['outcome'], $r['ready_to_buy'], trim($r['specification'] . ' ' . $r['skills_required'] . ' ' . $r['notes']), $r['uname']]); }
    exit;
}
$users = $all ? mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT u.id, u.names FROM mi_requests r JOIN users u ON u.id = r.recorded_by ORDER BY u.names'), MYSQLI_ASSOC) : [];
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
mi_tabs($conn, 'requests.php', $all ? 'Customer requests' : 'My recorded requests', mic($conn, 'request') ? '<a href="request.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Record a request</a>' : '');
echo mi_period_form($per, ['kind' => $fk, 'source' => $fsrc, 'outcome' => $fout, 'urgent' => $furg ? '1' : '', 'user' => $all && $fu ? $fu : '', 'q' => $q]);
?>
<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 small">
    <input type="hidden" name="period" value="<?= mi_e($per[2]); ?>"><?php if ($per[2] === 'custom') { ?><input type="hidden" name="from" value="<?= mi_e($per[0]); ?>"><input type="hidden" name="to" value="<?= mi_e($per[1]); ?>"><?php } ?>
    <div class="col-12 col-md-3"><input name="q" value="<?= mi_e($q); ?>" class="form-control form-control-sm" placeholder="Product, customer or phone"></div>
    <div class="col-6 col-md-2"><select name="kind" class="form-select form-select-sm"><option value="">Products and services</option><option value="product" <?= $fk === 'product' ? 'selected' : ''; ?>>Products</option><option value="service" <?= $fk === 'service' ? 'selected' : ''; ?>>Services</option></select></div>
    <div class="col-6 col-md-2"><select name="source" class="form-select form-select-sm"><option value="">Any source</option><?php foreach (MI_SOURCES as $x) { ?><option <?= $fsrc === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="outcome" class="form-select form-select-sm"><option value="">Any outcome</option><?php foreach (array_unique(array_merge(MI_PRODUCT_OUTCOMES, MI_SERVICE_OUTCOMES)) as $x) { ?><option <?= $fout === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <?php if ($all) { ?><div class="col-6 col-md-2"><select name="user" class="form-select form-select-sm"><option value="">Any employee</option><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= $fu === (int) $u['id'] ? 'selected' : ''; ?>><?= mi_e($u['names']); ?></option><?php } ?></select></div><?php } ?>
    <div class="col-6 col-md-1 d-flex align-items-center gap-2"><label class="text-nowrap"><input type="checkbox" name="urgent" value="1" <?= $furg ? 'checked' : ''; ?> class="form-check-input"> Urgent</label></div>
    <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary">Filter</button></div>
</div></form>
<div class="d-flex justify-content-between small text-muted mb-1"><span><?= count($rows); ?> request(s) · <?= mi_e($per[3]); ?></span><a href="?<?= mi_e(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">Export (Excel / CSV)</a></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 mi-tbl">
    <tr class="table-light"><th class="ps-3">Date</th><th>Product / service</th><th>Customer</th><th class="text-end">Qty</th><th class="d-none d-md-table-cell">Source</th><th class="d-none d-md-table-cell">Outcome</th><th class="d-none d-lg-table-cell">Recorded by</th></tr>
    <?php foreach ($rows as $r) { ?><tr>
        <td class="ps-3 text-nowrap small"><?= date('d M Y H:i', strtotime($r['requested_at'])); ?><?= $r['urgency'] === 'Urgent' ? ' <span class="badge bg-danger">urgent</span>' : ''; ?></td>
        <td><a href="opportunity.php?id=<?= (int) $r['opportunity_id']; ?>" class="text-decoration-none fw-semibold"><?= mi_e($r['oname']); ?></a><div class="small text-muted"><?= $r['kind'] === 'service' ? 'Service' : 'Product'; ?><span class="d-md-none"> · <?= mi_e($r['outcome']); ?></span></div></td>
        <td class="small"><?= $r['customer_id'] ? mi_e($r['cname']) : mi_e($r['walkin_name'] ?: 'Walk-in'); ?><?= !$r['customer_id'] && $r['walkin_phone'] ? '<div class="text-muted">' . mi_e($r['walkin_phone']) . '</div>' : ''; ?></td>
        <td class="text-end text-nowrap"><?= mi_num($r['quantity']); ?> <span class="small text-muted"><?= mi_e($r['unit']); ?></span></td>
        <td class="d-none d-md-table-cell small"><?= mi_e($r['source']); ?></td><td class="d-none d-md-table-cell small"><?= mi_e($r['outcome']); ?><?= $r['reason'] ? '<div class="text-muted">' . mi_e($r['reason']) . '</div>' : ''; ?></td>
        <td class="d-none d-lg-table-cell small"><?= mi_e($r['uname']); ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-5">No request in this period.</td></tr><?php } ?>
</table></div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>