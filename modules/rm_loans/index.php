<?php
// NEW FILE: modules/rm_loans/index.php — all applications and loans, with counts per status and filters.
require __DIR__ . '/loans_common.php';
cl_refresh_overdue($conn);
$status = in_array($_GET['status'] ?? '', CL_STATUSES, true) ? $_GET['status'] : '';
$q = trim((string) ($_GET['q'] ?? ''));
$w = ["a.status <> 'Draft'"]; $types = ''; $params = [];
if ($status !== '') { $w[] = 'a.status = ?'; $types .= 's'; $params[] = $status; }
elseif (($_GET['group'] ?? 'todo') === 'todo') { $w[] = "a.status IN ('Submitted', 'Under Review', 'Additional Information Required', 'Agreement Signed', 'Overdue')"; }
if ($q !== '') { $w[] = '(c.name LIKE ? OR c.phone LIKE ? OR CONCAT(\'CL-\', YEAR(a.created_at), \'-\', LPAD(a.id, 5, \'0\')) LIKE ?)'; $types .= 'sss'; array_push($params, "%$q%", "%$q%", "%$q%"); }
$s = mysqli_prepare($conn, 'SELECT a.*, c.name, c.phone, (SELECT COALESCE(SUM(amount_due - paid), 0) FROM cl_schedule x WHERE x.application_id = a.id) AS owed,
    (SELECT COUNT(*) FROM cl_payments p WHERE p.application_id = a.id AND p.status = \'Pending verification\') AS to_verify
    FROM cl_applications a JOIN customers c ON c.id = a.customer_id WHERE ' . implode(' AND ', $w) . ' ORDER BY a.updated_at DESC, a.id DESC LIMIT 300');
if ($types) { mysqli_stmt_bind_param($s, $types, ...$params); }
mysqli_stmt_execute($s);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
$counts = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT status, COUNT(*) n FROM cl_applications WHERE status <> 'Draft' GROUP BY status"), MYSQLI_ASSOC) as $r) { $counts[$r['status']] = (int) $r['n']; }
$pending = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM cl_payments WHERE status = 'Pending verification'"))[0];
include '../../includes/header.php'; include '../../includes/sidebar.php';
cl_tabs('index.php');
?>
<?php if ($pending) { ?><div class="alert alert-info small"><?= $pending; ?> repayment(s) reported by customers are waiting for verification (marked in the list).</div><?php } ?>
<div class="d-flex flex-wrap gap-1 mb-3 small">
    <a href="index.php?group=todo" class="btn btn-sm <?= $status === '' && ($_GET['group'] ?? 'todo') === 'todo' ? 'btn-primary' : 'btn-outline-primary'; ?>">To act on</a>
    <a href="index.php?group=all" class="btn btn-sm <?= ($_GET['group'] ?? '') === 'all' && $status === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">All</a>
    <?php foreach (CL_STATUSES as $st) { if (empty($counts[$st])) { continue; } ?><a href="index.php?status=<?= urlencode($st); ?>" class="btn btn-sm <?= $status === $st ? 'btn-primary' : 'btn-outline-secondary'; ?>"><?= $e($st); ?> (<?= $counts[$st]; ?>)</a><?php } ?>
</div>
<form class="d-flex gap-2 mb-3" style="max-width:460px;"><input type="hidden" name="group" value="all"><input name="q" class="form-control form-control-sm" placeholder="Customer, phone or reference" value="<?= $e($q); ?>"><button class="btn btn-primary btn-sm">Search</button></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-hover mb-0 align-middle" style="font-size:13px;">
    <tr><th class="ps-3">Reference</th><th>Customer</th><th class="text-end">Requested</th><th class="text-end">Approved</th><th>Status</th><th class="text-end">Outstanding</th><th>Updated</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">Nothing here.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?>
    <tr><td class="ps-3"><?= cl_ref((int) $r['id'], $r['created_at']); ?></td><td><?= $e($r['name']); ?><div class="small text-muted"><?= $e($r['phone']); ?></div></td>
        <td class="text-end"><?= cl_money($r['requested_amount']); ?><?= (float) $r['requested_amount'] > (float) $r['limit_at_submit'] ? '<div class="small text-danger">above limit ' . cl_money($r['limit_at_submit']) . '</div>' : ''; ?></td>
        <td class="text-end"><?= $r['approved_amount'] !== null ? cl_money($r['approved_amount']) : '—'; ?></td><td><?= cl_badge($r['status']); ?><?= (int) $r['to_verify'] ? ' <span class="badge bg-warning">payment to verify</span>' : ''; ?></td>
        <td class="text-end"><?= (float) $r['owed'] > 0 ? cl_money($r['owed']) : '—'; ?></td><td class="small text-nowrap"><?= date('d M Y', strtotime($r['updated_at'] ?? $r['created_at'])); ?></td>
        <td><a href="application.php?id=<?= (int) $r['id']; ?>" class="rm-btn rm-btn-info rm-btn-sm">Open</a></td></tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>