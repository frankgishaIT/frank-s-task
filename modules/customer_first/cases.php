<?php
/**
 * NEW FILE: modules/customer_first/cases.php — CUSTOMER REQUESTS list: mine (employees), all (managers),
 * escalated to me, open, overdue; filters by outcome, reason, employee, product / service, period; Excel export.
 */
require __DIR__ . '/common.php';
cf_require($conn, 'record');
$uid = (int) cf_uid(); $view = (string) ($_GET['view'] ?? ''); $now = date('Y-m-d H:i:s');
$w = [cf_scope_sql($conn)];
if ($view === 'escalated') { $w[] = "EXISTS (SELECT 1 FROM cf_escalations e WHERE e.case_id = c.id AND e.to_user = $uid AND e.status IN ('Open', 'More information'))"; }
if ($view === 'open') { $w[] = "c.status <> 'Closed'"; }
if ($view === 'overdue') { $w[] = "EXISTS (SELECT 1 FROM cf_escalations e WHERE e.case_id = c.id AND e.status IN ('Open', 'More information') AND e.due_at < '$now')"; }
if ($view === 'mine') { $w[] = "c.handled_by = $uid"; }
$fo = isset(CF_OUTCOMES[$_GET['outcome'] ?? '']) ? $_GET['outcome'] : ''; if ($fo) { $w[] = "c.outcome = '" . mysqli_real_escape_string($conn, $fo) . "'"; }
if (($_GET['outcome'] ?? '') === 'none') { $w[] = 'c.outcome IS NULL'; }
$fr = in_array($_GET['reason'] ?? '', CF_REASONS, true) ? $_GET['reason'] : ''; if ($fr) { $w[] = "c.unresolved_reason = '" . mysqli_real_escape_string($conn, $fr) . "'"; }
$fu = (int) ($_GET['user'] ?? 0); if ($fu && cfc($conn, 'manage')) { $w[] = "c.handled_by = $fu"; }
$fk = in_array($_GET['kind'] ?? '', ['product', 'service'], true) ? $_GET['kind'] : ''; if ($fk) { $w[] = "c.kind = '$fk'"; }
$q = trim((string) ($_GET['q'] ?? '')); if ($q !== '') { $e = mysqli_real_escape_string($conn, $q); $w[] = "(c.request_text LIKE '%$e%' OR cu.name LIKE '%$e%' OR c.walkin_name LIKE '%$e%' OR c.contact LIKE '%$e%')"; }
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : ''; $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
if ($from) { $w[] = "c.created_at >= '$from 00:00:00'"; } if ($to) { $w[] = "c.created_at <= '$to 23:59:59'"; }
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT c.*, cu.name cname, u.names hname, (SELECT MIN(e.due_at) FROM cf_escalations e WHERE e.case_id = c.id AND e.status IN ('Open', 'More information')) due
    FROM cf_cases c LEFT JOIN customers cu ON cu.id = c.customer_id LEFT JOIN users u ON u.id = c.handled_by WHERE " . implode(' AND ', $w) . ' ORDER BY c.id DESC LIMIT 1000'), MYSQLI_ASSOC);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="customer-requests-' . date('Y-m-d') . '.csv"');
    $f = fopen('php://output', 'w'); fwrite($f, "\xEF\xBB\xBF");
    fputcsv($f, ['Case', 'Date', 'Customer', 'Contact', 'Type', 'Request', 'Quantity', 'Budget', 'Needed by', 'Employee', 'Stock check', 'RM Offerings check', 'Capability check', 'Alternative', 'Sourcing', 'Told to the customer', 'Status', 'Outcome', 'Reason', 'Note', 'Feedback', 'Closed']);
    foreach ($rows as $r) { fputcsv($f, [cf_case_no((int) $r['id']), $r['created_at'], $r['cname'] ?: $r['walkin_name'], $r['contact'], $r['kind'], $r['request_text'], $r['quantity'], $r['budget'], $r['needed_by'], $r['hname'], $r['stock_check'], $r['offering_check'], $r['capability_check'], $r['alternative'], $r['sourcing'], $r['communication'], $r['status'], $r['outcome'], $r['unresolved_reason'], $r['outcome_note'], trim(($r['feedback_rating'] ? $r['feedback_rating'] . '/5 ' : '') . $r['feedback']), $r['closed_at']]); }
    exit;
}
$users = cfc($conn, 'manage') ? cf_users($conn) : [];
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
cf_tabs($conn, $view === 'escalated' ? 'cases.php?view=escalated' : 'cases.php', $view === 'escalated' ? 'Escalated to me' : 'Customer requests', '<a href="new.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>New customer request</a>');
?>
<div class="d-flex flex-wrap gap-1 mb-2 small"><?php foreach (['' => 'All', 'mine' => 'Mine', 'open' => 'Open', 'overdue' => 'Overdue', 'escalated' => 'Escalated to me'] as $k => $l) { ?><a href="?view=<?= $k; ?>" class="btn btn-sm py-0 <?= $view === $k ? 'btn-primary' : 'btn-light border'; ?>"><?= $l; ?></a><?php } ?></div>
<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 small"><input type="hidden" name="view" value="<?= cf_e($view); ?>">
    <div class="col-12 col-md-3"><input name="q" value="<?= cf_e($q); ?>" class="form-control form-control-sm" placeholder="Request, customer, phone"></div>
    <div class="col-6 col-md-2"><select name="outcome" class="form-select form-select-sm"><option value="">Any outcome</option><option value="none" <?= ($_GET['outcome'] ?? '') === 'none' ? 'selected' : ''; ?>>No outcome yet</option><?php foreach (CF_OUTCOMES as $k => $x) { ?><option <?= $fo === $k ? 'selected' : ''; ?>><?= cf_e($k); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><select name="reason" class="form-select form-select-sm"><option value="">Any reason</option><?php foreach (CF_REASONS as $x) { ?><option <?= $fr === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select></div>
    <div class="col-6 col-md-1"><select name="kind" class="form-select form-select-sm"><option value="">All</option><option value="product" <?= $fk === 'product' ? 'selected' : ''; ?>>Products</option><option value="service" <?= $fk === 'service' ? 'selected' : ''; ?>>Services</option></select></div>
    <?php if ($users) { ?><div class="col-6 col-md-2"><select name="user" class="form-select form-select-sm"><option value="">Any employee</option><?php foreach ($users as $u) { ?><option value="<?= (int) $u['id']; ?>" <?= $fu === (int) $u['id'] ? 'selected' : ''; ?>><?= cf_e($u['names']); ?></option><?php } ?></select></div><?php } ?>
    <div class="col-6 col-md-1"><input type="date" name="from" value="<?= cf_e($from); ?>" class="form-control form-control-sm" title="From"></div><div class="col-6 col-md-1"><input type="date" name="to" value="<?= cf_e($to); ?>" class="form-control form-control-sm" title="To"></div>
    <div class="col-12 col-md-auto"><button class="btn btn-sm btn-primary">Filter</button></div>
</div></form>
<div class="d-flex justify-content-between small text-muted mb-1"><span><?= count($rows); ?> request(s)</span><a href="?<?= cf_e(http_build_query(array_merge($_GET, ['export' => 'csv']))); ?>">Export (Excel / CSV)</a></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0 cf-tbl">
    <tr class="table-light"><th class="ps-3">Request</th><th>Customer</th><th class="d-none d-md-table-cell">Employee</th><th>Outcome</th><th class="d-none d-lg-table-cell">Escalation</th></tr>
    <?php foreach ($rows as $r) { $over = $r['due'] && strtotime($r['due']) < time(); ?>
    <tr style="cursor:pointer" onclick="location.href='case.php?id=<?= (int) $r['id']; ?>'"><td class="ps-3"><a href="case.php?id=<?= (int) $r['id']; ?>" class="fw-semibold text-decoration-none"><?= cf_e($r['request_text']); ?></a><div class="small text-muted"><?= cf_case_no((int) $r['id']); ?> · <?= $r['kind'] === 'service' ? 'Service' : 'Product'; ?> · <?= date('d M Y H:i', strtotime($r['created_at'])); ?></div></td>
        <td class="small"><?= cf_e($r['cname'] ?: ($r['walkin_name'] ?: 'Walk-in')); ?></td><td class="small d-none d-md-table-cell"><?= cf_e($r['hname']); ?></td>
        <td><?= cf_outcome_badge($r['outcome']); ?><?= $r['unresolved_reason'] ? '<div class="small text-danger">' . cf_e($r['unresolved_reason']) . '</div>' : ''; ?></td>
        <td class="small d-none d-lg-table-cell"><?= $r['due'] ? '<span class="' . ($over ? 'text-danger fw-semibold' : '') . '">due ' . date('d M H:i', strtotime($r['due'])) . ($over ? ' · overdue' : '') . '</span>' : ($r['status'] === 'Escalated' ? 'escalated' : '—'); ?></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted py-5">No customer request.</td></tr><?php } ?>
</table></div></div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>